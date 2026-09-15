<?php
/**
 * Al ELIMINAR el plugin (no al desactivar ni actualizar).
 * Por defecto conserva el token para que sobreviva a una reinstalación.
 *
 * @package HacoajMCP
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

if ( '1' !== get_option( 'hacoaj_mcp_delete_data_on_uninstall', '0' ) ) {
	return;
}

foreach ( array(
	'hacoaj_mcp_token_hash',
	'hacoaj_mcp_token_created',
	'hacoaj_mcp_token_last_used',
	'hacoaj_mcp_cache_gen',
	'hacoaj_mcp_auto_update',
	'hacoaj_mcp_publicados_desde',
	'hacoaj_mcp_update_error',
	'hacoaj_mcp_delete_data_on_uninstall',
) as $option ) {
	delete_option( $option );
}
delete_site_transient( 'hacoaj_mcp_release' );
