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
		return 'hacoaj_mcp_idx_' . md5( HACOAJ_MCP_VERSION . '|' . (int) get_option( self::CACHE_GEN_OPTION, 0 ) . '|' . (string) $this->catalog->version() . '|' . (string) $this->published_since() );
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
		$this->index = Index_Builder::build( $this->raw_from_wordpress(), $this->catalog, home_url(), array( 'publicados_desde' => $this->published_since() ) );
		set_transient( $key, $this->index, self::CACHE_TTL );
		return $this->index;
	}

	/**
	 * Fecha de corte de temporada: option del admin > catálogo. 'off' desactiva el filtro.
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
			foreach ( array( 'dias', 'detalle', 'hora_inicio', 'hora_fin', 'lugar', 'edad', 'profe' ) as $key ) {
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
