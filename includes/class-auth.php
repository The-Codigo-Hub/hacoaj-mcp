<?php
/**
 * Token de acceso al MCP.
 *
 * Diseño para que sobreviva resets y updates:
 * - Se guarda SOLO el hash sha256 (el token tiene 256 bits de entropía, no necesita salt).
 * - No depende de wp_salt() (Wordfence puede regenerar salts), ni de usuarios ni application passwords.
 * - La option no se borra al desactivar/actualizar. uninstall.php sólo la borra si se tildó explícitamente.
 * - Override opcional con la constante HACOAJ_MCP_TOKEN_HASH en wp-config.php (sobrevive incluso a un restore de DB).
 *
 * @package HacoajMCP
 */

namespace HacoajMCP;

defined( 'ABSPATH' ) || exit;

final class Auth {

	const OPTION_HASH      = 'hacoaj_mcp_token_hash';
	const OPTION_CREATED   = 'hacoaj_mcp_token_created';
	const OPTION_LAST_USED = 'hacoaj_mcp_token_last_used';
	const TOKEN_PREFIX     = 'hmcp_';

	/**
	 * Genera un token nuevo, guarda su hash y devuelve el token en claro (única vez que se ve).
	 */
	public static function generate() {
		$token = self::TOKEN_PREFIX . rtrim( strtr( base64_encode( random_bytes( 32 ) ), '+/', '-_' ), '=' );
		update_option( self::OPTION_HASH, self::hash( $token ), false );
		update_option( self::OPTION_CREATED, time(), false );
		delete_option( self::OPTION_LAST_USED );
		return $token;
	}

	public static function hash( $token ) {
		return hash( 'sha256', (string) $token );
	}

	public static function revoke() {
		delete_option( self::OPTION_HASH );
		delete_option( self::OPTION_CREATED );
		delete_option( self::OPTION_LAST_USED );
	}

	/** @return string|null 'constante' | 'base de datos' | null */
	public static function source() {
		if ( self::constant_hash() ) {
			return 'constante';
		}
		return get_option( self::OPTION_HASH ) ? 'base de datos' : null;
	}

	public static function is_configured() {
		return null !== self::source();
	}

	private static function constant_hash() {
		if ( defined( 'HACOAJ_MCP_TOKEN_HASH' ) && is_string( HACOAJ_MCP_TOKEN_HASH ) && preg_match( '/^[a-f0-9]{64}$/', HACOAJ_MCP_TOKEN_HASH ) ) {
			return HACOAJ_MCP_TOKEN_HASH;
		}
		return null;
	}

	/** Hashes aceptados (constante y/o option: permite migrar a wp-config sin cortar el servicio). */
	private static function valid_hashes() {
		$hashes = array();
		if ( self::constant_hash() ) {
			$hashes[] = self::constant_hash();
		}
		$opt = get_option( self::OPTION_HASH );
		if ( is_string( $opt ) && preg_match( '/^[a-f0-9]{64}$/', $opt ) ) {
			$hashes[] = $opt;
		}
		return $hashes;
	}

	/**
	 * Extrae el token del request: Authorization: Bearer <token> o X-Hacoaj-Token: <token>.
	 */
	public static function token_from_request( \WP_REST_Request $request ) {
		$header = $request->get_header( 'authorization' );
		if ( ! $header ) {
			// Algunos hostings Apache/CGI no pasan Authorization a PHP.
			foreach ( array( 'HTTP_AUTHORIZATION', 'REDIRECT_HTTP_AUTHORIZATION' ) as $k ) {
				if ( ! empty( $_SERVER[ $k ] ) ) {
					$header = wp_unslash( $_SERVER[ $k ] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
					break;
				}
			}
		}
		if ( ! $header && function_exists( 'getallheaders' ) ) {
			foreach ( (array) getallheaders() as $name => $value ) {
				if ( 'authorization' === strtolower( $name ) ) {
					$header = $value;
				}
			}
		}
		if ( $header && preg_match( '/^\s*Bearer\s+(\S+)\s*$/i', $header, $m ) ) {
			return $m[1];
		}
		$alt = $request->get_header( 'x_hacoaj_token' );
		return $alt ? trim( $alt ) : null;
	}

	/**
	 * @return true|\WP_Error
	 */
	public static function authorize( \WP_REST_Request $request ) {
		$ip = self::client_ip();

		if ( Rate_Limiter::is_blocked( 'fail', $ip, 20, 10 * MINUTE_IN_SECONDS ) ) {
			return new \WP_Error( 'hacoaj_mcp_rate_limited', 'Demasiados intentos fallidos. Probá en unos minutos.', array( 'status' => 429 ) );
		}

		$hashes = self::valid_hashes();
		if ( ! $hashes ) {
			return new \WP_Error( 'hacoaj_mcp_not_configured', 'El MCP no tiene token configurado. Generalo en Ajustes > Hacoaj MCP.', array( 'status' => 503 ) );
		}

		$token = self::token_from_request( $request );
		$valid = false;
		if ( $token ) {
			$candidate = self::hash( $token );
			foreach ( $hashes as $h ) {
				$valid = hash_equals( $h, $candidate ) || $valid;
			}
		}
		if ( ! $valid ) {
			Rate_Limiter::hit( 'fail', $ip, 10 * MINUTE_IN_SECONDS );
			return new \WP_Error( 'hacoaj_mcp_unauthorized', 'Token inválido o ausente. Enviá "Authorization: Bearer <token>".', array( 'status' => 401 ) );
		}

		$per_minute = (int) apply_filters( 'hacoaj_mcp_rate_limit_per_minute', 720 );
		if ( $per_minute > 0 && Rate_Limiter::is_blocked( 'ok', 'token', $per_minute, MINUTE_IN_SECONDS ) ) {
			return new \WP_Error( 'hacoaj_mcp_rate_limited', 'Límite de requests por minuto alcanzado.', array( 'status' => 429 ) );
		}
		Rate_Limiter::hit( 'ok', 'token', MINUTE_IN_SECONDS );

		$last = (int) get_option( self::OPTION_LAST_USED, 0 );
		if ( time() - $last > 5 * MINUTE_IN_SECONDS ) {
			update_option( self::OPTION_LAST_USED, time(), false );
		}
		return true;
	}

	public static function client_ip() {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		return (string) apply_filters( 'hacoaj_mcp_client_ip', $ip );
	}
}

/**
 * Rate limiting de ventana fija con transients.
 */
final class Rate_Limiter {

	private static function key( $bucket, $who, $window ) {
		return 'hacoaj_mcp_rl_' . $bucket . '_' . md5( $who ) . '_' . (int) floor( time() / max( 1, $window ) );
	}

	public static function is_blocked( $bucket, $who, $max, $window ) {
		return (int) get_transient( self::key( $bucket, $who, $window ) ) >= $max;
	}

	public static function hit( $bucket, $who, $window ) {
		$key = self::key( $bucket, $who, $window );
		set_transient( $key, (int) get_transient( $key ) + 1, $window * 2 );
	}
}
