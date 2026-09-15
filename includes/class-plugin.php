<?php
/**
 * Bootstrap del plugin.
 *
 * @package HacoajMCP
 */

namespace HacoajMCP;

defined( 'ABSPATH' ) || exit;

final class Plugin {

	/** @var Plugin|null */
	private static $instance;
	/** @var Repository|null */
	private $repository;
	/** @var Tools|null */
	private $tools;
	/** @var Updater */
	private $updater;

	public static function instance() {
		if ( ! self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function boot() {
		$this->updater = new Updater( HACOAJ_MCP_FILE );
		$this->updater->register();

		Repository::register_invalidation_hooks();

		add_action(
			'rest_api_init',
			function () {
				( new MCP_Server( $this->tools() ) )->register_routes();
			}
		);

		if ( is_admin() ) {
			( new Settings( $this ) )->register();
		}

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command( 'hacoaj-mcp', new CLI( $this ) );
		}
	}

	public function repository() {
		if ( ! $this->repository ) {
			$catalog          = Catalog::from_file( (string) apply_filters( 'hacoaj_mcp_catalog_path', HACOAJ_MCP_DIR . 'data/catalog.json' ) );
			$this->repository = new Repository( $catalog );
		}
		return $this->repository;
	}

	public function tools() {
		if ( ! $this->tools ) {
			$this->tools = new Tools( $this->repository() );
		}
		return $this->tools;
	}

	public function updater() {
		return $this->updater;
	}
}
