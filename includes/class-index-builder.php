<?php
/**
 * Arma el índice normalizado (actividades, items de agenda, sedes) a partir de datos crudos de WordPress
 * + el catálogo. No usa funciones de WordPress: recibe arrays con la forma de tests/fixtures/wp-snapshot.json.
 *
 * @package HacoajMCP
 */

namespace HacoajMCP;

defined( 'ABSPATH' ) || defined( 'HACOAJ_MCP_TESTING' ) || exit;

final class Index_Builder {

	/**
	 * @param array   $raw     ['actividad' => terms[], 'sede' => terms[], 'agenda_item' => items[]]
	 * @param Catalog $catalog
	 * @param string  $site_url Sin barra final.
	 * @return array
	 */
	public static function build( array $raw, Catalog $catalog, $site_url, array $options = array() ) {
		$site_url         = rtrim( $site_url, '/' );
		// Temporada: con 'regular'/'verano' se filtra por el meta agenda_version (mismo criterio que la web);
		// 'todas' no filtra; sin temporada (sitio sin agenda_version) se usa la fecha de publicación como respaldo.
		$temporada        = isset( $options['temporada'] ) ? $options['temporada'] : null;
		$publicados_desde = array_key_exists( 'publicados_desde', $options ) ? $options['publicados_desde'] : $catalog->agenda_published_since();
		if ( null !== $temporada ) {
			$publicados_desde = null;
		}
		$criterio  = 'todas' === $temporada ? null : ( $temporada ? 'agenda_version' : ( $publicados_desde ? 'fecha' : null ) );
		$excluidos = 0;
		$terms    = array();
		$children = array();
		foreach ( $raw['actividad'] as $t ) {
			$terms[ $t['slug'] ] = $t;
		}
		$id_to_slug = array();
		foreach ( $terms as $slug => $t ) {
			$id_to_slug[ $t['id'] ] = $slug;
		}
		foreach ( $terms as $slug => $t ) {
			if ( ! empty( $t['parent'] ) && isset( $id_to_slug[ $t['parent'] ] ) ) {
				$children[ $id_to_slug[ $t['parent'] ] ][] = $slug;
			}
		}
		$parent_of = function ( $slug ) use ( $terms, $id_to_slug ) {
			$p = isset( $terms[ $slug ] ) ? (int) $terms[ $slug ]['parent'] : 0;
			return $p && isset( $id_to_slug[ $p ] ) ? $id_to_slug[ $p ] : null;
		};
		$root_of = function ( $slug ) use ( $parent_of ) {
			$guard = 0;
			while ( ( $p = $parent_of( $slug ) ) && $guard++ < 10 ) {
				$slug = $p;
			}
			return $slug;
		};
		$ancestors = function ( $slug ) use ( $parent_of ) {
			$out   = array();
			$guard = 0;
			while ( ( $slug = $parent_of( $slug ) ) && $guard++ < 10 ) {
				$out[] = $slug;
			}
			return $out;
		};
		$descendants = function ( $slug ) use ( &$descendants, $children ) {
			$out = array();
			foreach ( isset( $children[ $slug ] ) ? $children[ $slug ] : array() as $c ) {
				$out[] = $c;
				$out   = array_merge( $out, $descendants( $c ) );
			}
			return $out;
		};

		$sede_names = array();
		foreach ( $raw['sede'] as $s ) {
			$cat_sede                = $catalog->sede( $s['slug'] );
			$sede_names[ $s['slug'] ] = $cat_sede ? $cat_sede['nombre'] : $s['name'];
		}

		$items      = array();
		$activities = array();
		$special    = array(
			'transporte'        => array(),
			'info_sede'         => array(),
			'federados_resumen' => array(),
		);

		foreach ( $raw['agenda_item'] as $raw_item ) {
			if ( 'agenda_version' === $criterio && self::item_season( $raw_item ) !== $temporada ) {
				$excluidos++;
				continue;
			}
			if ( 'fecha' === $criterio && ! empty( $raw_item['date'] ) && substr( $raw_item['date'], 0, 10 ) < $publicados_desde ) {
				$excluidos++;
				continue;
			}
			$slugs   = array_values( array_filter( (array) $raw_item['actividad'], function ( $s ) use ( $terms ) {
				return isset( $terms[ $s ] );
			} ) );
			$regular = array();
			$kinds   = array();
			foreach ( $slugs as $s ) {
				$kind = $catalog->term_kind( $s );
				if ( null === $kind ) {
					$regular[] = $s;
				} elseif ( 'oculto' !== $kind ) {
					$kinds[ $kind ] = $s;
				}
			}

			// Hojas: términos del item que no son ancestros de otro término del item.
			// Una categoría (término con hijos) que viene junto a un término "actividad" es sólo una etiqueta:
			// ej. [escuelas-deportivas, patin-artistico] aunque patin-artistico cuelgue de otra categoría.
			$has_plain_term = false;
			foreach ( $regular as $s ) {
				$has_plain_term = $has_plain_term || empty( $children[ $s ] );
			}
			$leaves = array();
			foreach ( $regular as $s ) {
				$is_ancestor = false;
				foreach ( $regular as $other ) {
					if ( $other !== $s && in_array( $s, $ancestors( $other ), true ) ) {
						$is_ancestor = true;
						break;
					}
				}
				if ( $is_ancestor || ( $has_plain_term && ! empty( $children[ $s ] ) ) ) {
					continue;
				}
				// Si la "hoja" es una categoría con hijos, probamos matchear el título con un hijo.
				if ( ! empty( $children[ $s ] ) ) {
					$title_slug = Text::slugify( $raw_item['title'] );
					foreach ( $descendants( $s ) as $d ) {
						if ( $d === $title_slug || Text::fold( $terms[ $d ]['name'] ) === Text::fold( $raw_item['title'] ) ) {
							$s = $d;
							break;
						}
					}
				}
				$leaves[] = $s;
			}
			$leaves = array_values( array_unique( $leaves ) );

			// Un término raíz sin hijos (ej. gimnasio-de-musculacion) que viene junto a una categoría del item
			// se considera actividad de esa categoría.
			$item_categories = array();
			foreach ( $regular as $s ) {
				if ( ! empty( $children[ $s ] ) && null === $parent_of( $s ) ) {
					$item_categories[] = $s;
				}
			}

			$meta   = isset( $raw_item['meta'] ) && is_array( $raw_item['meta'] ) ? $raw_item['meta'] : array();
			$parsed = Schedule_Parser::parse_detalle( isset( $meta['detalle'] ) ? (string) $meta['detalle'] : '', $meta );
			$dias   = Text::sort_dias( array_map( array( Text::class, 'fold' ), isset( $meta['dias'] ) ? (array) $meta['dias'] : array() ) );
			$sedes  = array_values( array_filter( (array) $raw_item['sede'], function ( $s ) use ( $sede_names ) {
				return isset( $sede_names[ $s ] );
			} ) );

			$item = array_merge(
				array(
					'id'          => (int) $raw_item['id'],
					'titulo'      => trim( $raw_item['title'] ),
					'actividades' => $leaves,
					'sedes'       => $sedes,
					'dias'        => $dias,
				),
				$parsed,
				array( 'modificado' => isset( $raw_item['modified'] ) ? $raw_item['modified'] : null )
			);
			if ( '' === $item['titulo'] && $leaves ) {
				$item['titulo'] = $terms[ $leaves[0] ]['name'];
			}

			if ( ! $leaves ) {
				foreach ( $kinds as $kind => $term_slug ) {
					if ( '' === $item['titulo'] ) {
						$item['titulo'] = $terms[ $term_slug ]['name'];
					}
					$item['termino']    = $term_slug;
					$special[ $kind ][] = $item;
				}
				continue;
			}

			$items[ $item['id'] ] = $item;

			foreach ( $leaves as $leaf ) {
				if ( ! isset( $activities[ $leaf ] ) ) {
					$root      = $root_of( $leaf );
					$categoria = $root !== $leaf ? $root : ( $item_categories ? $item_categories[0] : null );
					$activities[ $leaf ] = array(
						'slug'      => $leaf,
						'nombre'    => $terms[ $leaf ]['name'],
						'categoria' => $categoria,
						'sedes'     => array(),
						'dias'      => array(),
						'items'     => array(),
						'titulos'   => array(),
					);
				}
				$a = &$activities[ $leaf ];
				if ( null === $a['categoria'] && $item_categories && $root_of( $leaf ) === $leaf ) {
					$a['categoria'] = $item_categories[0];
				}
				$a['sedes']               = array_values( array_unique( array_merge( $a['sedes'], $sedes ) ) );
				$a['dias']                = Text::sort_dias( array_merge( $a['dias'], $dias ) );
				$a['items'][]             = $item['id'];
				$a['titulos'][]           = $item['titulo'];
				$a['titulos']             = array_values( array_unique( $a['titulos'] ) );
				unset( $a );
			}
		}

		// Actividades del catálogo sin agenda (término sin items o sin término en WP).
		foreach ( $catalog->raw()['actividades'] as $slug => $meta ) {
			if ( isset( $activities[ $slug ] ) ) {
				continue;
			}
			$in_wp = isset( $terms[ $slug ] );
			$root  = $in_wp ? $root_of( $slug ) : null;
			$activities[ $slug ] = array(
				'slug'      => $slug,
				'nombre'    => $in_wp ? $terms[ $slug ]['name'] : ( isset( $meta['nombre_bot'] ) ? $meta['nombre_bot'] : $slug ),
				'categoria' => $root && $root !== $slug ? $root : null,
				'sedes'     => array(),
				'dias'      => array(),
				'items'     => array(),
				'titulos'   => array(),
			);
		}

		// Metadata: ACF de WordPress > catálogo de la actividad > defaults de su categoría.
		$sin_meta = array();
		foreach ( $activities as $slug => &$a ) {
			$meta      = $catalog->activity_meta( $slug );
			$cat_meta  = $a['categoria'] ? $catalog->category_meta( $a['categoria'] ) : $catalog->category_meta( $slug );
			$own_cat   = $catalog->category_meta( $slug );
			$defaults  = array_merge( $cat_meta ? $cat_meta : array(), $own_cat ? $own_cat : array() );
			unset( $defaults['nombre'] );
			$wp_term  = isset( $terms[ $slug ] ) ? $terms[ $slug ] : null;
			$wp_meta  = array();
			if ( $wp_term ) {
				foreach ( array( 'edad_min', 'edad_max', 'genero', 'grupo' ) as $k ) {
					if ( isset( $wp_term[ $k ] ) ) {
						$wp_meta[ $k ] = $wp_term[ $k ];
					}
				}
			}
			$fuente    = null;
			$has_age   = function ( $m ) {
				return is_array( $m ) && ( isset( $m['edad_min'] ) || isset( $m['edad_max'] ) || isset( $m['edad_min_meses'] ) || isset( $m['edad_max_meses'] ) );
			};
			if ( $has_age( $wp_meta ) ) {
				$fuente = 'wordpress';
			} elseif ( $has_age( $meta ) ) {
				$fuente = 'catalogo';
			} elseif ( $has_age( $defaults ) ) {
				$fuente = 'categoria';
			}
			$merged = array_merge( $defaults, $meta ? $meta : array(), $wp_meta );
			if ( 'categoria' === $fuente ) {
				foreach ( array( 'edad_min', 'edad_max', 'edad_min_meses', 'edad_max_meses' ) as $k ) {
					if ( isset( $defaults[ $k ] ) ) {
						$merged[ $k ] = $defaults[ $k ];
					}
				}
			}
			foreach ( array( 'base', 'grupo', 'genero', 'edad_min', 'edad_max', 'edad_min_meses', 'edad_max_meses', 'rango', 'discapacidad', 'aliases', 'revisar', 'nombre_bot' ) as $k ) {
				if ( isset( $merged[ $k ] ) ) {
					$a[ $k ] = $merged[ $k ];
				}
			}
			$a['fuente_edad']  = $fuente;
			$a['tiene_agenda'] = ! empty( $a['items'] );
			$a['url']          = $a['tiene_agenda'] ? $site_url . '/agenda-por-actividad/?actividad=' . rawurlencode( $slug ) : null;
			if ( ! $meta ) {
				$sin_meta[] = $slug;
			}
		}
		unset( $a );
		ksort( $activities );

		$categories = array();
		foreach ( $terms as $slug => $t ) {
			if ( null !== $parent_of( $slug ) || $catalog->term_kind( $slug ) ) {
				continue;
			}
			$cat_meta = $catalog->category_meta( $slug );
			$acts     = array();
			foreach ( $activities as $a ) {
				if ( $a['categoria'] === $slug || ( $a['slug'] === $slug && null === $a['categoria'] ) ) {
					$acts[] = $a['slug'];
				}
			}
			if ( ! $acts ) {
				continue;
			}
			$categories[ $slug ] = array(
				'slug'        => $slug,
				'nombre'      => $cat_meta && ! empty( $cat_meta['nombre'] ) ? $cat_meta['nombre'] : $t['name'],
				'grupo'       => $cat_meta && ! empty( $cat_meta['grupo'] ) ? $cat_meta['grupo'] : null,
				'actividades' => $acts,
			);
		}

		return array(
			'generado'    => gmdate( 'c' ),
			'sede_nombres'=> $sede_names,
			'actividades' => $activities,
			'categorias'  => $categories,
			'items'       => $items,
			'especiales'  => $special,
			'stats'       => array(
				'terminos'              => count( $terms ),
				'items'                 => count( $raw['agenda_item'] ),
				'items_excluidos_temporada' => $excluidos,
				'criterio_temporada'    => $criterio,
				'temporada'             => $temporada,
				'publicados_desde'      => $publicados_desde,
				'items_actividad'       => count( $items ),
				'actividades'           => count( $activities ),
				'actividades_con_agenda'=> count( array_filter( $activities, function ( $a ) {
					return $a['tiene_agenda'];
				} ) ),
				'items_con_horario'     => count( array_filter( $items, function ( $i ) {
					return ! empty( $i['horarios'] );
				} ) ),
				'actividades_sin_catalogo' => $sin_meta,
			),
		);
	}

	/** 'regular' | 'verano' | null si el valor no es una temporada válida. */
	public static function normalize_season( $value ) {
		$value = is_string( $value ) ? strtolower( trim( $value ) ) : '';
		return in_array( $value, array( 'regular', 'verano' ), true ) ? $value : null;
	}

	/** Temporada de un agenda_item: como en el sitio, sin un valor válido cuenta como regular. */
	public static function item_season( array $raw_item ) {
		$value = isset( $raw_item['meta']['agenda_version'] ) ? $raw_item['meta']['agenda_version'] : '';
		$season = self::normalize_season( $value );
		return $season ? $season : 'regular';
	}
}
