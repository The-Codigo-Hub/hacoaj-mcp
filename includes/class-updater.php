<?php
/**
 * Auto-update desde GitHub Releases, usando el mecanismo nativo de WordPress (header "Update URI" +
 * filtro update_plugins_{hostname}, WP >= 5.8). Sin librerías externas.
 *
 * - Lee el último release de https://api.github.com/repos/{repo}/releases/latest
 * - Usa el asset "hacoaj-mcp.zip" (lo arma el workflow de release); si no existe, el zipball del tag.
 * - Fuerza el auto-update de este plugin (desactivable en Ajustes).
 * - Repo privado: definir HACOAJ_MCP_GITHUB_TOKEN en wp-config.php.
 *
 * @package HacoajMCP
 */

namespace HacoajMCP;

defined( 'ABSPATH' ) || exit;

final class Updater {

	const DEFAULT_REPO   = 'The-Codigo-Hub/hacoaj-mcp';
	const ASSET_NAME     = 'hacoaj-mcp.zip';
	const CACHE_KEY      = 'hacoaj_mcp_release';
	const OPTION_AUTO    = 'hacoaj_mcp_auto_update';
	const CACHE_TTL      = 6 * HOUR_IN_SECONDS;

	/** @var string */
	private $plugin_file;
	/** @var string */
	private $basename;

	public function __construct( $plugin_file ) {
		$this->plugin_file = $plugin_file;
		$this->basename    = plugin_basename( $plugin_file );
	}

	public static function repo() {
		return defined( 'HACOAJ_MCP_GITHUB_REPO' ) ? HACOAJ_MCP_GITHUB_REPO : self::DEFAULT_REPO;
	}

	private static function token() {
		return defined( 'HACOAJ_MCP_GITHUB_TOKEN' ) && HACOAJ_MCP_GITHUB_TOKEN ? HACOAJ_MCP_GITHUB_TOKEN : null;
	}

	public function register() {
		add_filter( 'update_plugins_github.com', array( $this, 'filter_update' ), 10, 4 );
		add_filter( 'plugins_api', array( $this, 'plugins_api' ), 10, 3 );
		add_filter( 'auto_update_plugin', array( $this, 'auto_update' ), 10, 2 );
		add_filter( 'upgrader_source_selection', array( $this, 'fix_source_dir' ), 10, 4 );
		add_filter( 'http_request_args', array( $this, 'auth_download' ), 10, 2 );
		add_action( 'requests-requests.before_redirect', array( $this, 'strip_auth_on_redirect' ), 10, 5 );
		add_action( 'upgrader_process_complete', array( $this, 'after_upgrade' ), 10, 2 );
	}

	/**
	 * Último release (cacheado). null si no hay o falló.
	 */
	public function latest_release( $force = false ) {
		$cached = get_site_transient( self::CACHE_KEY );
		if ( ! $force && is_array( $cached ) ) {
			return $cached ? $cached : null;
		}
		$headers = array(
			'Accept'     => 'application/vnd.github+json',
			'User-Agent' => 'hacoaj-mcp/' . HACOAJ_MCP_VERSION . '; ' . home_url(),
		);
		if ( self::token() ) {
			$headers['Authorization'] = 'Bearer ' . self::token();
		}
		$res = wp_remote_get(
			'https://api.github.com/repos/' . self::repo() . '/releases/latest',
			array(
				'timeout' => 10,
				'headers' => $headers,
			)
		);
		if ( is_wp_error( $res ) || 200 !== wp_remote_retrieve_response_code( $res ) ) {
			$error = is_wp_error( $res ) ? $res->get_error_message() : 'HTTP ' . wp_remote_retrieve_response_code( $res );
			update_option( 'hacoaj_mcp_update_error', $error . ' (' . gmdate( 'Y-m-d H:i' ) . ' UTC)', false );
			set_site_transient( self::CACHE_KEY, array(), HOUR_IN_SECONDS );
			return null;
		}
		delete_option( 'hacoaj_mcp_update_error' );
		$data    = json_decode( wp_remote_retrieve_body( $res ), true );
		$version = isset( $data['tag_name'] ) ? ltrim( $data['tag_name'], 'vV' ) : null;
		if ( ! $version ) {
			set_site_transient( self::CACHE_KEY, array(), HOUR_IN_SECONDS );
			return null;
		}
		$package = null;
		foreach ( isset( $data['assets'] ) ? $data['assets'] : array() as $asset ) {
			if ( self::ASSET_NAME === $asset['name'] ) {
				// Con token usamos la URL de API (sirve para repos privados); sin token, la pública.
				$package = self::token() ? $asset['url'] : $asset['browser_download_url'];
			}
		}
		if ( ! $package && ! empty( $data['zipball_url'] ) ) {
			$package = $data['zipball_url'];
		}
		$release = array(
			'version'   => $version,
			'package'   => $package,
			'url'       => isset( $data['html_url'] ) ? $data['html_url'] : 'https://github.com/' . self::repo(),
			'notes'     => isset( $data['body'] ) ? (string) $data['body'] : '',
			'published' => isset( $data['published_at'] ) ? $data['published_at'] : null,
		);
		set_site_transient( self::CACHE_KEY, $release, self::CACHE_TTL );
		return $release;
	}

	public function filter_update( $update, $plugin_data, $plugin_file, $locales ) {
		if ( $plugin_file !== $this->basename ) {
			return $update;
		}
		$release = $this->latest_release();
		if ( ! $release || ! $release['package'] ) {
			return $update;
		}
		return array(
			'id'           => $plugin_data['UpdateURI'],
			'slug'         => dirname( $this->basename ),
			'plugin'       => $this->basename,
			'version'      => $release['version'],
			'new_version'  => $release['version'],
			'url'          => $release['url'],
			'package'      => $release['package'],
			'requires_php' => '7.4',
			'autoupdate'   => $this->auto_enabled(),
		);
	}

	public function plugins_api( $result, $action, $args ) {
		if ( 'plugin_information' !== $action || empty( $args->slug ) || dirname( $this->basename ) !== $args->slug ) {
			return $result;
		}
		$release = $this->latest_release();
		$info    = get_plugin_data( $this->plugin_file, false, false );
		return (object) array(
			'name'          => $info['Name'],
			'slug'          => $args->slug,
			'version'       => $release ? $release['version'] : HACOAJ_MCP_VERSION,
			'author'        => $info['Author'],
			'homepage'      => 'https://github.com/' . self::repo(),
			'requires_php'  => '7.4',
			'last_updated'  => $release ? $release['published'] : null,
			'download_link' => $release ? $release['package'] : null,
			'sections'      => array(
				'description' => wp_kses_post( wpautop( $info['Description'] ) ),
				'changelog'   => $release ? '<pre>' . esc_html( $release['notes'] ) . '</pre>' : '',
			),
		);
	}

	public function auto_enabled() {
		return '0' !== get_option( self::OPTION_AUTO, '1' );
	}

	public function auto_update( $update, $item ) {
		if ( isset( $item->plugin ) && $item->plugin === $this->basename ) {
			return $this->auto_enabled();
		}
		return $update;
	}

	/**
	 * Si el zip no trae la carpeta "hacoaj-mcp/" (zipball de GitHub), la renombra.
	 */
	public function fix_source_dir( $source, $remote_source, $upgrader, $hook_extra = array() ) {
		global $wp_filesystem;
		$is_ours = ( isset( $hook_extra['plugin'] ) && $hook_extra['plugin'] === $this->basename )
			|| ( isset( $upgrader->skin->plugin_info['Name'] ) && 'Hacoaj MCP' === $upgrader->skin->plugin_info['Name'] );
		if ( ! $is_ours || ! $wp_filesystem ) {
			return $source;
		}
		$wanted = trailingslashit( $remote_source ) . dirname( $this->basename ) . '/';
		if ( untrailingslashit( $source ) === untrailingslashit( $wanted ) ) {
			return $source;
		}
		if ( $wp_filesystem->move( $source, $wanted, true ) ) {
			return $wanted;
		}
		return new \WP_Error( 'hacoaj_mcp_rename_failed', 'No se pudo renombrar la carpeta del update.' );
	}

	public function auth_download( $args, $url ) {
		if ( self::token() && 0 === strpos( $url, 'https://api.github.com/repos/' . self::repo() . '/' ) ) {
			$args['headers']['Authorization'] = 'Bearer ' . self::token();
			if ( false !== strpos( $url, '/releases/assets/' ) ) {
				$args['headers']['Accept'] = 'application/octet-stream';
			}
		}
		return $args;
	}

	/**
	 * GitHub redirige los assets a un storage firmado que rechaza el header Authorization.
	 */
	public function strip_auth_on_redirect( &$location, &$req_headers, &$req_data, &$options, $return = null ) {
		if ( self::token() && isset( $req_headers['Authorization'] ) && false === strpos( (string) $location, 'api.github.com' ) ) {
			unset( $req_headers['Authorization'] );
		}
	}

	public function after_upgrade( $upgrader, $extra ) {
		if ( isset( $extra['type'], $extra['plugins'] ) && 'plugin' === $extra['type'] && in_array( $this->basename, (array) $extra['plugins'], true ) ) {
			delete_site_transient( self::CACHE_KEY );
			Repository::flush();
		}
	}

	/**
	 * Botón "Buscar actualizaciones ahora".
	 */
	public function check_now() {
		delete_site_transient( self::CACHE_KEY );
		$release = $this->latest_release( true );
		delete_site_transient( 'update_plugins' );
		wp_update_plugins();
		return $release;
	}
}
