<?php
/**
 * Plugin Name:       Hacoaj MCP
 * Plugin URI:        https://github.com/The-Codigo-Hub/hacoaj-mcp
 * Description:       Servidor MCP (Model Context Protocol) de sólo lectura con actividades, agenda, sedes, deportes federados y transporte del Club Náutico Hacoaj, para conectar a n8n y otros agentes de IA. Se actualiza solo desde GitHub.
 * Version:           1.2.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            The Codigo Hub
 * Author URI:        https://github.com/The-Codigo-Hub
 * License:           GPL-2.0-or-later
 * Update URI:        https://github.com/The-Codigo-Hub/hacoaj-mcp
 * Text Domain:       hacoaj-mcp
 *
 * @package HacoajMCP
 */

defined( 'ABSPATH' ) || exit;

define( 'HACOAJ_MCP_VERSION', '1.2.0' );
define( 'HACOAJ_MCP_FILE', __FILE__ );
define( 'HACOAJ_MCP_DIR', plugin_dir_path( __FILE__ ) );

if ( version_compare( PHP_VERSION, '7.4', '<' ) ) {
	add_action(
		'admin_notices',
		function () {
			echo '<div class="notice notice-error"><p>Hacoaj MCP requiere PHP 7.4 o superior.</p></div>';
		}
	);
	return;
}

foreach ( array( 'text', 'schedule-parser', 'catalog', 'index-builder', 'query', 'repository', 'auth', 'tools', 'mcp-server', 'updater', 'settings', 'cli', 'plugin' ) as $hacoaj_mcp_file ) {
	require_once HACOAJ_MCP_DIR . 'includes/class-' . $hacoaj_mcp_file . '.php';
}
unset( $hacoaj_mcp_file );

// Marca el plugin como "auto-update habilitado" en la UI de WordPress (el filtro del Updater igual lo fuerza).
register_activation_hook(
	__FILE__,
	function () {
		$auto = (array) get_site_option( 'auto_update_plugins', array() );
		if ( ! in_array( plugin_basename( __FILE__ ), $auto, true ) ) {
			$auto[] = plugin_basename( __FILE__ );
			update_site_option( 'auto_update_plugins', $auto );
		}
	}
);

\HacoajMCP\Plugin::instance()->boot();
