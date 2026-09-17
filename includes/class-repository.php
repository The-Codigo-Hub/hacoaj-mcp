<?php
/**
 * Lee los datos de WordPress (taxonomías + agenda_item + ACF) y mantiene el índice cacheado.
 *
 * @package HacoajMCP
 */

namespace HacoajMCP;

defined( 'ABSPATH' ) || exit;

final class Repository {

	const CACHE_GEN_OPTION = 'hacoaj_mcp_cache_gen';
	const CACHE_TTL        = 12 * HOUR_IN_SECONDS;

	/** Option del sitio (no de este plugin) con la temporada pública: auto | regular | verano. */
	const SITE_SEASON_OPTION = 'hacoaj_agenda_temporada_publica';

	/** @var Catalog */
	private $catalog;
	/** @var array|null */
	private $index;

	public function __construct( Catalog $catalog ) {
		$this->catalog = $catalog;
	}

	public function catalog() {
		return $this->catalog;
	}

	public static function register_invalidation_hooks() {
		$flush = array( __CLASS__, 'flush' );
		add_action(
			'save_post',
			function ( $post_id, $post ) {
				if ( $post && in_array( $post->post_type, array( 'agenda_item' ), true ) ) {
					Repository::flush();
				}
			},
			10,
			2
		);
		add_action(
			'transition_post_status',
			function ( $new, $old, $post ) {
				if ( $new !== $old && $post && 'agenda_item' === $post->post_type ) {
					Repository::flush();
				}
			},
			10,
			3
		);
		add_action(
			'before_delete_post',
			function ( $post_id ) {
				if ( 'agenda_item' === get_post_type( $post_id ) ) {
					Repository::flush();
				}
			}
		);
		add_action(
			'updated_post_meta',
			function ( $meta_id, $post_id ) {
				if ( 'agenda_item' === get_post_type( $post_id ) ) {
					Repository::flush();
				}
			},
			10,
			2
		);
		foreach ( array( 'created_term', 'edited_term', 'delete_term' ) as $hook ) {
			add_action(
				$hook,
				function ( $term_id, $tt_id, $taxonomy ) {
					if ( in_array( $taxonomy, array( 'actividad', 'sede' ), true ) ) {
						Repository::flush();
					}
				},
				10,
				3
			);
		}
		add_action( 'set_object_terms', function ( $object_id ) {
			if ( 'agenda_item' === get_post_type( $object_id ) ) {
				Repository::flush();
			}
		} );
		add_action( 'upgrader_process_complete', $flush );
	}

	/** Invalida el índice (incrementa la generación: no hace falta borrar transients viejos, expiran solos). */
	public static function flush() {
		update_option( self::CACHE_GEN_OPTION, (int) get_option( self::CACHE_GEN_OPTION, 0 ) + 1, false );
	}

	private function cache_key() {
		return 'hacoaj_mcp_idx_' . md5( HACOAJ_MCP_VERSION . '|' . (int) get_option( self::CACHE_GEN_OPTION, 0 ) . '|' . (string) $this->catalog->version() . '|' . (string) $this->season() . '|' . (string) $this->published_since() );
	}

	public function index() {
		if ( null !== $this->index ) {
			return $this->index;
		}
		$key    = $this->cache_key();
		$cached = get_transient( $key );
		if ( is_array( $cached ) && isset( $cached['actividades'] ) ) {
			return $this->index = $cached;
		}
		$this->index = Index_Builder::build(
			$this->raw_from_wordpress(),
			$this->catalog,
			home_url(),
			array(
				'temporada'        => $this->season(),
				'publicados_desde' => $this->published_since(),
			)
		);
		set_transient( $key, $this->index, self::CACHE_TTL );
		return $this->index;
	}

	/**
	 * Temporada de agenda a indexar: 'regular', 'verano', 'todas' o null (el sitio no marca temporadas).
	 * Override del admin > la misma temporada pública que muestra la web.
	 */
	public function season() {
		$opt = get_option( Settings::OPTION_SEASON, '' );
		if ( in_array( $opt, array( 'regular', 'verano', 'todas' ), true ) ) {
			return $opt;
		}
		return self::site_season();
	}

	/**
	 * Temporada pública del sitio, con el mismo criterio que los shortcodes agenda=auto del tema.
	 * null si el sitio no tiene el mecanismo de agenda_version.
	 */
	public static function site_season() {
		$has_option = false !== get_option( self::SITE_SEASON_OPTION, false );
		if ( ! $has_option && ! function_exists( 'ha_resolve_agenda_version' ) && ! function_exists( 'ha_auto_agenda_version' ) ) {
			return null;
		}
		if ( function_exists( 'ha_resolve_agenda_version' ) ) {
			$season = self::call_season_function( 'ha_resolve_agenda_version', array( 'auto' ) );
			if ( $season ) {
				return $season;
			}
		}
		$season = Index_Builder::normalize_season( get_option( self::SITE_SEASON_OPTION, '' ) );
		if ( $season ) {
			return $season;
		}
		if ( function_exists( 'ha_auto_agenda_version' ) ) {
			$season = self::call_season_function( 'ha_auto_agenda_version', array() );
			if ( $season ) {
				return $season;
			}
		}
		return 'regular';
	}

	/** Las funciones son del sitio y no conocemos su firma exacta: cualquier error o valor raro se ignora. */
	private static function call_season_function( $fn, array $args ) {
		try {
			$value = call_user_func_array( $fn, $args );
		} catch ( \Throwable $e ) {
			return null;
		}
		return is_string( $value ) ? Index_Builder::normalize_season( $value ) : null;
	}

	/**
	 * Fecha de corte de temporada (respaldo si el sitio no usa agenda_version): option del admin > catálogo. 'off' desactiva el filtro.
	 */
	public function published_since() {
		$opt = get_option( Settings::OPTION_PUBLISHED_SINCE, '' );
		if ( 'off' === $opt ) {
			return null;
		}
		if ( is_string( $opt ) && preg_match( '/^\\d{4}-\\d{2}-\\d{2}$/', $opt ) ) {
			return $opt;
		}
		return $this->catalog->agenda_published_since();
	}

	public function query() {
		return new Query( $this->index(), $this->catalog, new \DateTimeImmutable( 'now', wp_timezone() ) );
	}

	/**
	 * Datos crudos con la misma forma que tests/fixtures/wp-snapshot.json.
	 */
	public function raw_from_wordpress() {
		$raw = array(
			'actividad'   => $this->terms( 'actividad' ),
			'sede'        => $this->terms( 'sede' ),
			'agenda_item' => array(),
		);
		if ( ! post_type_exists( 'agenda_item' ) ) {
			return $raw;
		}
		$q = new \WP_Query(
			array(
				'post_type'              => 'agenda_item',
				'post_status'            => 'publish',
				'posts_per_page'         => 2000,
				'no_found_rows'          => true,
				'ignore_sticky_posts'    => true,
				'update_post_meta_cache' => true,
				'update_post_term_cache' => true,
				'orderby'                => 'ID',
				'order'                  => 'DESC',
			)
		);
		foreach ( $q->posts as $post ) {
			$meta = array();
			foreach ( array( 'dias', 'detalle', 'hora_inicio', 'hora_fin', 'lugar', 'edad', 'profe', 'agenda_version' ) as $key ) {
				$meta[ $key ] = get_post_meta( $post->ID, $key, true );
			}
			if ( ! is_array( $meta['dias'] ) ) {
				$meta['dias'] = $meta['dias'] ? array( (string) $meta['dias'] ) : array();
			}
			$raw['agenda_item'][] = array(
				'id'        => $post->ID,
				'title'     => html_entity_decode( get_the_title( $post ), ENT_QUOTES, 'UTF-8' ),
				'slug'      => $post->post_name,
				'date'      => $post->post_date,
				'modified'  => $post->post_modified,
				'actividad' => $this->post_term_slugs( $post->ID, 'actividad' ),
				'sede'      => $this->post_term_slugs( $post->ID, 'sede' ),
				'meta'      => $meta,
			);
		}
		return $raw;
	}

	private function terms( $taxonomy ) {
		if ( ! taxonomy_exists( $taxonomy ) ) {
			return array();
		}
		$terms = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'hide_empty' => false,
			)
		);
		if ( is_wp_error( $terms ) ) {
			return array();
		}
		return array_map(
			function ( $t ) {
				return array(
					'id'          => (int) $t->term_id,
					'name'        => html_entity_decode( $t->name, ENT_QUOTES, 'UTF-8' ),
					'slug'        => $t->slug,
					'parent'      => (int) $t->parent,
					'description' => $t->description,
				);
			},
			$terms
		);
	}

	private function post_term_slugs( $post_id, $taxonomy ) {
		$terms = get_the_terms( $post_id, $taxonomy );
		if ( ! $terms || is_wp_error( $terms ) ) {
			return array();
		}
		return array_values( wp_list_pluck( $terms, 'slug' ) );
	}
}
