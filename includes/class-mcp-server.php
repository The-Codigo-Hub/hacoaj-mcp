<?php
/**
 * Servidor MCP sobre la REST API de WordPress (transporte Streamable HTTP, modo stateless con respuestas JSON).
 *
 * POST /wp-json/hacoaj-mcp/v1/mcp   JSON-RPC 2.0 (initialize, ping, tools/list, tools/call, notificaciones)
 * GET  /wp-json/hacoaj-mcp/v1/mcp   405 (no hay stream SSE server→client)
 * GET  /wp-json/hacoaj-mcp/v1/health  público, sin datos: versión y diagnóstico del header Authorization
 *
 * @package HacoajMCP
 */

namespace HacoajMCP;

defined( 'ABSPATH' ) || exit;

final class MCP_Server {

	const REST_NAMESPACE      = 'hacoaj-mcp/v1';
	const SUPPORTED_VERSIONS  = array( '2025-11-25', '2025-06-18', '2025-03-26', '2024-11-05' );

	/** @var Tools */
	private $tools;

	public function __construct( Tools $tools ) {
		$this->tools = $tools;
	}

	public static function endpoint_url() {
		return rest_url( self::REST_NAMESPACE . '/mcp' );
	}

	public function register_routes() {
		register_rest_route(
			self::REST_NAMESPACE,
			'/mcp',
			array(
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'handle_post' ),
					'permission_callback' => array( $this, 'permission' ),
				),
				array(
					'methods'             => array( 'GET', 'DELETE' ),
					'callback'            => array( $this, 'handle_not_allowed' ),
					'permission_callback' => array( $this, 'permission' ),
				),
			)
		);
		register_rest_route(
			self::REST_NAMESPACE,
			'/health',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'handle_health' ),
				'permission_callback' => '__return_true',
			)
		);
		add_filter( 'rest_pre_serve_request', array( $this, 'serve_raw' ), 10, 4 );
		add_filter( 'rest_post_dispatch', array( $this, 'add_auth_headers' ), 10, 3 );
	}

	public function permission( \WP_REST_Request $request ) {
		$origin = $request->get_header( 'origin' );
		if ( $origin ) {
			$allowed = (array) apply_filters( 'hacoaj_mcp_allowed_origins', array( untrailingslashit( home_url() ) ) );
			if ( ! in_array( untrailingslashit( $origin ), $allowed, true ) ) {
				return new \WP_Error( 'hacoaj_mcp_forbidden_origin', 'Origin no permitido.', array( 'status' => 403 ) );
			}
		}
		return Auth::authorize( $request );
	}

	public function handle_not_allowed() {
		$response = new \WP_REST_Response( array( 'error' => 'Este servidor MCP no ofrece stream SSE; usá POST.' ), 405 );
		$response->header( 'Allow', 'POST' );
		return $response;
	}

	public function handle_health( \WP_REST_Request $request ) {
		$saw_auth = (bool) Auth::token_from_request( $request );
		return new \WP_REST_Response(
			array(
				'ok'                    => true,
				'plugin'                => 'hacoaj-mcp',
				'version'               => HACOAJ_MCP_VERSION,
				'token_configurado'     => Auth::is_configured(),
				'authorization_visible' => $saw_auth,
			),
			200
		);
	}

	public function handle_post( \WP_REST_Request $request ) {
		$body = $request->get_body();
		$msg  = json_decode( $body, true );
		if ( null === $msg && JSON_ERROR_NONE !== json_last_error() ) {
			return $this->json( self::error_response( null, -32700, 'Parse error' ), 400 );
		}
		if ( ! is_array( $msg ) ) {
			return $this->json( self::error_response( null, -32600, 'Invalid Request' ), 400 );
		}

		$is_batch  = array_keys( $msg ) === range( 0, count( $msg ) - 1 ) && $msg;
		$messages  = $is_batch ? $msg : array( $msg );
		$responses = array();
		foreach ( $messages as $m ) {
			$r = $this->dispatch( $m );
			if ( null !== $r ) {
				$responses[] = $r;
			}
		}
		if ( ! $responses ) {
			return $this->json( null, 202 );
		}
		return $this->json( $is_batch ? $responses : $responses[0], 200 );
	}

	/**
	 * @return array|null Respuesta JSON-RPC, o null para notificaciones/respuestas.
	 */
	public function dispatch( $m ) {
		if ( ! is_array( $m ) || ! isset( $m['jsonrpc'] ) || '2.0' !== $m['jsonrpc'] ) {
			return self::error_response( isset( $m['id'] ) ? $m['id'] : null, -32600, 'Invalid Request' );
		}
		$has_id = array_key_exists( 'id', $m );
		if ( ! isset( $m['method'] ) ) {
			return null; // Respuesta de un cliente a un request nuestro (no enviamos ninguno).
		}
		if ( ! $has_id ) {
			return null; // Notificación (notifications/initialized, notifications/cancelled...).
		}
		$id     = $m['id'];
		$params = isset( $m['params'] ) && is_array( $m['params'] ) ? $m['params'] : array();

		switch ( $m['method'] ) {
			case 'initialize':
				$requested = isset( $params['protocolVersion'] ) ? $params['protocolVersion'] : '';
				$version   = in_array( $requested, self::SUPPORTED_VERSIONS, true ) ? $requested : self::SUPPORTED_VERSIONS[0];
				return self::result(
					$id,
					array(
						'protocolVersion' => $version,
						'capabilities'    => array(
							'tools' => array( 'listChanged' => false ),
						),
						'serverInfo'      => array(
							'name'    => 'hacoaj-mcp',
							'title'   => 'Club Náutico Hacoaj',
							'version' => HACOAJ_MCP_VERSION,
						),
						'instructions'    => self::instructions(),
					)
				);

			case 'ping':
				return self::result( $id, new \stdClass() );

			case 'tools/list':
				return self::result( $id, array( 'tools' => $this->tools->list_for_mcp() ) );

			case 'tools/call':
				$name = isset( $params['name'] ) ? (string) $params['name'] : '';
				if ( ! $this->tools->exists( $name ) ) {
					return self::error_response( $id, -32602, 'Tool desconocida: ' . $name );
				}
				$args   = isset( $params['arguments'] ) ? $params['arguments'] : array();
				$result = $this->tools->call( $name, $args );
				$text   = $result['ok'] ? wp_json_encode( $result['data'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) : $result['error'];
				return self::result(
					$id,
					array(
						'content' => array(
							array(
								'type' => 'text',
								'text' => (string) $text,
							),
						),
						'isError' => ! $result['ok'],
					)
				);

			case 'resources/list':
				return self::result( $id, array( 'resources' => array() ) );

			case 'prompts/list':
				return self::result( $id, array( 'prompts' => array() ) );

			default:
				return self::error_response( $id, -32601, 'Method not found: ' . $m['method'] );
		}
	}

	private static function instructions() {
		return 'Datos oficiales del Club Náutico Hacoaj (hacoaj.org.ar), leídos en vivo de su WordPress. '
			. 'Flujo recomendado: buscar_actividades (qué hay según edad/sede/día) → obtener_agenda (horarios concretos). '
			. 'Competencia: deportes_federados. Traslados: transporte. Direcciones y contactos: listar_sedes. '
			. 'Los días "hoy"/"mañana" se resuelven con la hora de Argentina. Si un dato no está, sugerí contactar a la sede; no inventes horarios ni precios.';
	}

	private static function result( $id, $result ) {
		return array(
			'jsonrpc' => '2.0',
			'id'      => $id,
			'result'  => $result,
		);
	}

	private static function error_response( $id, $code, $message ) {
		return array(
			'jsonrpc' => '2.0',
			'id'      => $id,
			'error'   => array(
				'code'    => $code,
				'message' => $message,
			),
		);
	}

	private function json( $data, $status ) {
		$response = new \WP_REST_Response( $data, $status );
		$response->header( 'Cache-Control', 'no-store' );
		$response->header( 'X-Hacoaj-MCP', '1' );
		return $response;
	}

	/**
	 * Emite nuestras respuestas sin escapar unicode y sin cuerpo en los 202.
	 */
	public function serve_raw( $served, $result, $request, $server ) {
		if ( $served || ! ( $request instanceof \WP_REST_Request ) || 0 !== strpos( $request->get_route(), '/' . self::REST_NAMESPACE . '/mcp' ) ) {
			return $served;
		}
		if ( ! ( $result instanceof \WP_REST_Response ) || ! $result->get_headers() || empty( $result->get_headers()['X-Hacoaj-MCP'] ) ) {
			return $served; // Errores de WP (401/403/429) siguen el flujo normal.
		}
		$status = $result->get_status();
		if ( 202 === $status ) {
			status_header( 202 );
			return true;
		}
		header( 'Content-Type: application/json; charset=' . get_option( 'blog_charset' ) );
		echo wp_json_encode( $result->get_data(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		return true;
	}

	public function add_auth_headers( $response, $server, $request ) {
		if ( $response instanceof \WP_REST_Response && 401 === $response->get_status() && 0 === strpos( $request->get_route(), '/' . self::REST_NAMESPACE . '/mcp' ) ) {
			$response->header( 'WWW-Authenticate', 'Bearer realm="hacoaj-mcp"' );
		}
		return $response;
	}
}
