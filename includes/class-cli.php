<?php
/**
 * Comandos WP-CLI: wp hacoaj-mcp <token|tools|call|cache|update|stats>
 *
 * @package HacoajMCP
 */

namespace HacoajMCP;

defined( 'ABSPATH' ) || exit;

final class CLI {

	/** @var Plugin */
	private $plugin;

	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	/**
	 * Gestiona el token.
	 *
	 * ## OPTIONS
	 *
	 * <accion>
	 * : generate | status | revoke
	 *
	 * ## EXAMPLES
	 *
	 *     wp hacoaj-mcp token generate
	 */
	public function token( $args ) {
		$action = isset( $args[0] ) ? $args[0] : 'status';
		switch ( $action ) {
			case 'generate':
				$token = Auth::generate();
				\WP_CLI::line( 'Token (guardalo, no se vuelve a mostrar):' );
				\WP_CLI::line( $token );
				\WP_CLI::line( "Línea opcional para wp-config.php:\ndefine( 'HACOAJ_MCP_TOKEN_HASH', '" . Auth::hash( $token ) . "' );" );
				break;
			case 'revoke':
				Auth::revoke();
				\WP_CLI::success( 'Token revocado.' );
				break;
			default:
				\WP_CLI::line( Auth::is_configured() ? 'Configurado (' . Auth::source() . ')' : 'Sin token' );
		}
	}

	/**
	 * Lista las tools.
	 */
	public function tools() {
		foreach ( $this->plugin->tools()->list_for_mcp() as $t ) {
			\WP_CLI::line( $t['name'] . ' — ' . $t['title'] );
		}
	}

	/**
	 * Ejecuta una tool localmente.
	 *
	 * ## OPTIONS
	 *
	 * <tool>
	 * : Nombre de la tool.
	 *
	 * [--args=<json>]
	 * : Argumentos en JSON.
	 *
	 * ## EXAMPLES
	 *
	 *     wp hacoaj-mcp call buscar_actividades --args='{"texto":"golf","edad":8}'
	 */
	public function call( $args, $assoc ) {
		$name  = $args[0];
		$input = isset( $assoc['args'] ) ? json_decode( $assoc['args'], true ) : array();
		if ( ! $this->plugin->tools()->exists( $name ) ) {
			\WP_CLI::error( 'Tool desconocida: ' . $name );
		}
		$res = $this->plugin->tools()->call( $name, is_array( $input ) ? $input : array() );
		if ( ! $res['ok'] ) {
			\WP_CLI::error( $res['error'] );
		}
		\WP_CLI::line( wp_json_encode( $res['data'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) );
	}

	/**
	 * Vacía la caché del índice.
	 */
	public function cache( $args ) {
		Repository::flush();
		\WP_CLI::success( 'Caché invalidada.' );
	}

	/**
	 * Consulta GitHub por nuevas versiones.
	 */
	public function update() {
		$release = $this->plugin->updater()->check_now();
		if ( ! $release ) {
			\WP_CLI::warning( 'No se pudo leer el último release: ' . get_option( 'hacoaj_mcp_update_error', 'sin releases' ) );
			return;
		}
		\WP_CLI::line( 'Instalada: ' . HACOAJ_MCP_VERSION . ' · Última: ' . $release['version'] );
	}

	/**
	 * Estadísticas del índice.
	 */
	public function stats() {
		\WP_CLI::line( wp_json_encode( $this->plugin->repository()->index()['stats'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) );
	}
}
