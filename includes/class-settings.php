<?php
/**
 * Pantalla Ajustes > Hacoaj MCP: token, conexión con n8n, actualizaciones, caché y diagnóstico.
 *
 * @package HacoajMCP
 */

namespace HacoajMCP;

defined( 'ABSPATH' ) || exit;

final class Settings {

	const PAGE                    = 'hacoaj-mcp';
	const OPTION_PUBLISHED_SINCE  = 'hacoaj_mcp_publicados_desde';
	const OPTION_DELETE_UNINSTALL = 'hacoaj_mcp_delete_data_on_uninstall';

	/** @var Plugin */
	private $plugin;

	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	public function register() {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_post_hacoaj_mcp', array( $this, 'handle_action' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( HACOAJ_MCP_FILE ), array( $this, 'action_links' ) );
	}

	public function menu() {
		add_options_page( 'Hacoaj MCP', 'Hacoaj MCP', 'manage_options', self::PAGE, array( $this, 'render' ) );
	}

	public function action_links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( self::url() ) . '">Ajustes</a>' );
		return $links;
	}

	public static function url( $args = array() ) {
		return add_query_arg( array_merge( array( 'page' => self::PAGE ), $args ), admin_url( 'options-general.php' ) );
	}

	public function handle_action() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Sin permisos.' );
		}
		check_admin_referer( 'hacoaj_mcp_action' );
		$do     = isset( $_POST['do'] ) ? sanitize_key( wp_unslash( $_POST['do'] ) ) : '';
		$notice = '';

		switch ( $do ) {
			case 'generate_token':
				$token = Auth::generate();
				set_transient( 'hacoaj_mcp_new_token_' . get_current_user_id(), $token, 10 * MINUTE_IN_SECONDS );
				$notice = 'token';
				break;
			case 'revoke_token':
				Auth::revoke();
				$notice = 'revoked';
				break;
			case 'flush_cache':
				Repository::flush();
				$notice = 'flushed';
				break;
			case 'check_updates':
				$this->plugin->updater()->check_now();
				$notice = 'checked';
				break;
			case 'save_settings':
				update_option( Updater::OPTION_AUTO, empty( $_POST['auto_update'] ) ? '0' : '1', false );
				update_option( self::OPTION_DELETE_UNINSTALL, empty( $_POST['delete_on_uninstall'] ) ? '0' : '1', false );
				$since = isset( $_POST['publicados_desde'] ) ? sanitize_text_field( wp_unslash( $_POST['publicados_desde'] ) ) : '';
				if ( '' === $since || 'off' === $since || preg_match( '/^\d{4}-\d{2}-\d{2}$/', $since ) ) {
					update_option( self::OPTION_PUBLISHED_SINCE, $since, false );
				}
				Repository::flush();
				$notice = 'saved';
				break;
		}
		wp_safe_redirect( self::url( array( 'notice' => $notice ) ) );
		exit;
	}

	private static function form_button( $do, $label, $class = 'button', $confirm = '' ) {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline-block;margin:0 6px 6px 0">';
		wp_nonce_field( 'hacoaj_mcp_action' );
		echo '<input type="hidden" name="action" value="hacoaj_mcp"><input type="hidden" name="do" value="' . esc_attr( $do ) . '">';
		echo '<button type="submit" class="' . esc_attr( $class ) . '"' . ( $confirm ? ' onclick="return confirm(\'' . esc_js( $confirm ) . '\')"' : '' ) . '>' . esc_html( $label ) . '</button></form>';
	}

	public function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$notice   = isset( $_GET['notice'] ) ? sanitize_key( wp_unslash( $_GET['notice'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$endpoint = MCP_Server::endpoint_url();
		$new      = get_transient( 'hacoaj_mcp_new_token_' . get_current_user_id() );
		if ( $new ) {
			delete_transient( 'hacoaj_mcp_new_token_' . get_current_user_id() );
		}
		$updater  = $this->plugin->updater();
		$release  = get_site_transient( Updater::CACHE_KEY );
		$repo     = $this->plugin->repository();
		$stats    = null;
		$stats_error = null;
		try {
			$stats = $repo->index()['stats'];
		} catch ( \Throwable $e ) {
			$stats_error = $e->getMessage();
		}
		?>
		<div class="wrap">
			<h1>Hacoaj MCP <small style="font-size:13px;color:#666">v<?php echo esc_html( HACOAJ_MCP_VERSION ); ?></small></h1>
			<p>Servidor MCP de sólo lectura con los datos de actividades, agenda, sedes y federados de este sitio, para conectar a n8n u otros agentes.</p>

			<?php if ( 'saved' === $notice || 'flushed' === $notice || 'checked' === $notice || 'revoked' === $notice ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php echo esc_html( array( 'saved' => 'Ajustes guardados.', 'flushed' => 'Caché vaciada.', 'checked' => 'Actualizaciones consultadas.', 'revoked' => 'Token revocado. El MCP queda inaccesible hasta generar uno nuevo.' )[ $notice ] ); ?></p></div>
			<?php endif; ?>

			<?php if ( $new ) : ?>
				<div class="notice notice-warning" style="padding:12px">
					<p><strong>Copiá el token ahora: no se vuelve a mostrar.</strong></p>
					<p><input type="text" readonly value="<?php echo esc_attr( $new ); ?>" style="width:100%;font-family:monospace" onclick="this.select()"></p>
					<p>Opcional, para que sobreviva incluso a una restauración de base de datos, agregá esta línea a <code>wp-config.php</code>:</p>
					<p><input type="text" readonly value="<?php echo esc_attr( "define( 'HACOAJ_MCP_TOKEN_HASH', '" . Auth::hash( $new ) . "' );" ); ?>" style="width:100%;font-family:monospace" onclick="this.select()"></p>
				</div>
			<?php endif; ?>

			<h2>1. Token de acceso</h2>
			<table class="form-table" role="presentation">
				<tr><th>Estado</th><td>
					<?php if ( Auth::is_configured() ) : ?>
						<span style="color:#008a20">● Configurado</span> (origen: <?php echo esc_html( Auth::source() ); ?>)
						<?php $created = (int) get_option( Auth::OPTION_CREATED ); ?>
						<?php if ( $created ) : ?> · creado <?php echo esc_html( wp_date( 'Y-m-d H:i', $created ) ); ?><?php endif; ?>
						<?php $last = (int) get_option( Auth::OPTION_LAST_USED ); ?>
						· último uso: <?php echo $last ? esc_html( wp_date( 'Y-m-d H:i', $last ) ) : 'nunca'; ?>
					<?php else : ?>
						<span style="color:#b32d2e">● Sin token</span> — el MCP rechaza todas las conexiones.
					<?php endif; ?>
				</td></tr>
				<tr><th>Acciones</th><td>
					<?php
					self::form_button( 'generate_token', Auth::is_configured() ? 'Rotar token' : 'Generar token', 'button button-primary', Auth::is_configured() ? 'El token actual deja de funcionar. Vas a tener que actualizarlo en n8n. ¿Continuar?' : '' );
					if ( 'base de datos' === Auth::source() ) {
						self::form_button( 'revoke_token', 'Revocar', 'button', '¿Revocar el token? n8n pierde acceso.' );
					}
					?>
					<p class="description">El token se guarda hasheado. Sobrevive a updates del plugin, desactivaciones y cambios de salts. Si está definido en wp-config.php, tiene prioridad.</p>
				</td></tr>
			</table>

			<h2>2. Conectar desde n8n</h2>
			<table class="form-table" role="presentation">
				<tr><th>Endpoint (HTTP Streamable)</th><td><input type="text" readonly value="<?php echo esc_attr( $endpoint ); ?>" style="width:100%;font-family:monospace" onclick="this.select()"></td></tr>
				<tr><th>En n8n</th><td>
					<ol style="margin:0 0 0 18px">
						<li>Nodo <strong>MCP Client Tool</strong> conectado al AI Agent.</li>
						<li>Endpoint: la URL de arriba · Server Transport: <strong>HTTP Streamable</strong>.</li>
						<li>Authentication: <strong>Bearer Auth</strong> con el token. (Si tu hosting bloquea el header Authorization, usá <strong>Header Auth</strong> con nombre <code>X-Hacoaj-Token</code>.)</li>
						<li>Tools to include: All.</li>
					</ol>
				</td></tr>
				<tr><th>Diagnóstico</th><td>
					<?php $this->render_health_probe(); ?>
				</td></tr>
			</table>

			<h2>3. Ajustes</h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'hacoaj_mcp_action' ); ?>
				<input type="hidden" name="action" value="hacoaj_mcp"><input type="hidden" name="do" value="save_settings">
				<table class="form-table" role="presentation">
					<tr><th>Actualizaciones automáticas</th><td>
						<label><input type="checkbox" name="auto_update" value="1" <?php checked( $updater->auto_enabled() ); ?>> Instalar nuevas versiones desde GitHub automáticamente</label>
						<p class="description">Repo: <a href="https://github.com/<?php echo esc_attr( Updater::repo() ); ?>" target="_blank" rel="noopener"><?php echo esc_html( Updater::repo() ); ?></a>.
						<?php if ( is_array( $release ) && $release ) : ?>Último release: <strong>v<?php echo esc_html( $release['version'] ); ?></strong>.<?php endif; ?>
						<?php $err = get_option( 'hacoaj_mcp_update_error' ); if ( $err ) : ?><br><span style="color:#b32d2e">Error consultando GitHub: <?php echo esc_html( $err ); ?></span><?php endif; ?>
						<?php if ( ! wp_is_file_mod_allowed( 'automatic_updater' ) || ( defined( 'AUTOMATIC_UPDATER_DISABLED' ) && AUTOMATIC_UPDATER_DISABLED ) ) : ?><br><span style="color:#b32d2e">Atención: este WordPress tiene deshabilitadas las actualizaciones automáticas (DISALLOW_FILE_MODS o AUTOMATIC_UPDATER_DISABLED).</span><?php endif; ?>
						</p>
					</td></tr>
					<tr><th><label for="publicados_desde">Temporada de agenda</label></th><td>
						<input type="text" id="publicados_desde" name="publicados_desde" placeholder="<?php echo esc_attr( (string) $repo->catalog()->agenda_published_since() ); ?>" value="<?php echo esc_attr( (string) get_option( self::OPTION_PUBLISHED_SINCE, '' ) ); ?>" style="width:140px">
						<p class="description">Se ignoran los agenda_item publicados antes de esta fecha (AAAA-MM-DD). Vacío = valor del catálogo (<code><?php echo esc_html( (string) $repo->catalog()->agenda_published_since() ); ?></code>). <code>off</code> = no filtrar.</p>
					</td></tr>
					<tr><th>Desinstalación</th><td>
						<label><input type="checkbox" name="delete_on_uninstall" value="1" <?php checked( '1', get_option( self::OPTION_DELETE_UNINSTALL, '0' ) ); ?>> Borrar token y ajustes al <em>eliminar</em> el plugin</label>
						<p class="description">Desactivado por defecto para que el token sobreviva a reinstalaciones.</p>
					</td></tr>
				</table>
				<?php submit_button( 'Guardar ajustes' ); ?>
			</form>
			<?php self::form_button( 'check_updates', 'Buscar actualizaciones ahora' ); ?>
			<?php self::form_button( 'flush_cache', 'Vaciar caché de datos' ); ?>

			<h2>4. Datos</h2>
			<?php if ( $stats_error ) : ?>
				<p style="color:#b32d2e">Error armando el índice: <?php echo esc_html( $stats_error ); ?></p>
			<?php elseif ( $stats ) : ?>
				<table class="widefat striped" style="max-width:720px">
					<tbody>
						<tr><td>Items de agenda publicados</td><td><?php echo (int) $stats['items']; ?></td></tr>
						<tr><td>Excluidos por temporada (antes de <?php echo esc_html( (string) $stats['publicados_desde'] ); ?>)</td><td><?php echo (int) $stats['items_excluidos_temporada']; ?></td></tr>
						<tr><td>Items de actividades indexados</td><td><?php echo (int) $stats['items_actividad']; ?></td></tr>
						<tr><td>…con horario estructurado</td><td><?php echo (int) $stats['items_con_horario']; ?></td></tr>
						<tr><td>Actividades (con agenda)</td><td><?php echo (int) $stats['actividades']; ?> (<?php echo (int) $stats['actividades_con_agenda']; ?>)</td></tr>
						<tr><td>Catálogo</td><td><?php echo esc_html( (string) $repo->catalog()->version() ); ?></td></tr>
						<tr><td>Actividades sin metadata en el catálogo</td><td><?php echo esc_html( $stats['actividades_sin_catalogo'] ? implode( ', ', $stats['actividades_sin_catalogo'] ) : '—' ); ?></td></tr>
					</tbody>
				</table>
				<?php $this->render_meta_keys(); ?>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Llama a /health por loopback con un Authorization de prueba para ver si el hosting lo deja pasar.
	 */
	private function render_health_probe() {
		$res = wp_remote_get(
			rest_url( MCP_Server::REST_NAMESPACE . '/health' ),
			array(
				'timeout'   => 8,
				'headers'   => array( 'Authorization' => 'Bearer probe' ),
				'sslverify' => apply_filters( 'https_local_ssl_verify', false ),
			)
		);
		if ( is_wp_error( $res ) ) {
			echo '<span style="color:#996800">No se pudo hacer la prueba loopback (' . esc_html( $res->get_error_message() ) . '). Probá desde afuera: <code>' . esc_html( rest_url( MCP_Server::REST_NAMESPACE . '/health' ) ) . '</code></span>';
			return;
		}
		$body = json_decode( wp_remote_retrieve_body( $res ), true );
		if ( 200 !== wp_remote_retrieve_response_code( $res ) || empty( $body['ok'] ) ) {
			echo '<span style="color:#b32d2e">El endpoint respondió HTTP ' . (int) wp_remote_retrieve_response_code( $res ) . '. Revisá Wordfence / firewall del hosting.</span>';
			return;
		}
		if ( ! empty( $body['authorization_visible'] ) ) {
			echo '<span style="color:#008a20">● REST API accesible y el header Authorization llega a PHP.</span>';
		} else {
			echo '<span style="color:#996800">● REST API accesible, pero el hosting NO pasa el header Authorization. En n8n usá Header Auth con <code>X-Hacoaj-Token</code>, o agregá al .htaccess: <code>SetEnvIf Authorization "(.*)" HTTP_AUTHORIZATION=$1</code></span>';
		}
	}

	/**
	 * Diagnóstico: meta keys que usan los agenda_item fuera de ACF (para detectar cómo el sitio marca temporadas).
	 */
	private function render_meta_keys() {
		global $wpdb;
		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			"SELECT pm.meta_key, COUNT(*) AS c FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
			 WHERE p.post_type = 'agenda_item' AND p.post_status = 'publish' GROUP BY pm.meta_key ORDER BY c DESC LIMIT 40"
		);
		if ( ! $rows ) {
			return;
		}
		$known = array( 'dias', 'detalle', 'hora_inicio', 'hora_fin', 'lugar', 'edad', 'profe', '_edit_lock', '_edit_last', '_dias', '_detalle', '_hora_inicio', '_hora_fin', '_lugar', '_edad', '_profe' );
		$other = array();
		foreach ( $rows as $r ) {
			if ( ! in_array( $r->meta_key, $known, true ) ) {
				$other[] = $r->meta_key . ' (' . (int) $r->c . ')';
			}
		}
		echo '<p class="description" style="max-width:720px">Otras meta keys en agenda_item: ' . esc_html( $other ? implode( ', ', $other ) : 'ninguna' ) . '</p>';
	}
}
