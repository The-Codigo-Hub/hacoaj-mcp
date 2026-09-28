<?php
/**
 * Consultas sobre el índice: búsqueda de actividades, agenda, sedes, categorías, federados, transporte.
 * Sin dependencias de WordPress.
 *
 * @package HacoajMCP
 */

namespace HacoajMCP;

defined( 'ABSPATH' ) || defined( 'HACOAJ_MCP_TESTING' ) || exit;

final class Query {

	/** @var array */
	private $index;
	/** @var Catalog */
	private $catalog;
	/** @var \DateTimeImmutable */
	private $now;

	public function __construct( array $index, Catalog $catalog, \DateTimeImmutable $now ) {
		$this->index   = $index;
		$this->catalog = $catalog;
		$this->now     = $now;
	}

	// ------------------------------------------------------------------ Actividades.

	/**
	 * @param array $f texto, edad, genero, sede, dia, categoria, limite
	 */
	public function buscar_actividades( array $f ) {
		$limit    = self::clamp( isset( $f['limite'] ) ? $f['limite'] : 15, 1, 50 );
		$filtered = $this->filter_activities( $f );
		$acts     = $filtered['actividades'];

		$out = array(
			'total'       => count( $acts ),
			'actividades' => array_map( array( $this, 'activity_summary' ), array_slice( $acts, 0, $limit ) ),
		);
		if ( count( $acts ) > $limit ) {
			$out['aviso'] = sprintf( 'Se muestran %d de %d. Refiná con más filtros.', $limit, count( $acts ) );
		}
		$out = array_merge( $out, $filtered['meta'] );
		if ( ! $acts ) {
			$out['sugerencia'] = 'No hubo resultados. Probá con menos filtros, otra palabra (ej. el deporte sin categoría) o listar_categorias.';
		}
		return $out;
	}

	/**
	 * @param array $f actividad, texto, sede, dia, categoria, edad, genero, desde_hora, hasta_hora, limite
	 */
	public function obtener_agenda( array $f ) {
		$limit = self::clamp( isset( $f['limite'] ) ? $f['limite'] : 25, 1, 80 );
		$meta  = array();
		$acts  = null;

		if ( ! empty( $f['actividad'] ) ) {
			$slug = Text::slugify( $f['actividad'] );
			if ( isset( $this->index['actividades'][ $slug ] ) ) {
				$acts = array( $this->index['actividades'][ $slug ] );
			} else {
				$f['texto'] = trim( ( isset( $f['texto'] ) ? $f['texto'] . ' ' : '' ) . $f['actividad'] );
			}
		}
		$has_activity_filter = ! empty( $f['texto'] ) || isset( $f['edad'] ) || ! empty( $f['genero'] ) || ! empty( $f['categoria'] );
		if ( null === $acts && $has_activity_filter ) {
			$filtered = $this->filter_activities( $f, true );
			$acts     = $filtered['actividades'];
			$meta     = $filtered['meta'];
		}

		$sede = null;
		if ( ! empty( $f['sede'] ) ) {
			$sede = $this->catalog->resolve_sede( $f['sede'] );
			if ( ! $sede ) {
				return array( 'error' => 'Sede desconocida: ' . $f['sede'] . '. Sedes válidas: ' . implode( ', ', $this->sede_slugs() ) );
			}
		}
		$dias = ! empty( $f['dia'] ) ? Text::parse_dias_input( $f['dia'], $this->now ) : array();
		if ( ! empty( $f['dia'] ) && ! $dias ) {
			return array( 'error' => 'No entendí el día "' . ( is_array( $f['dia'] ) ? implode( ', ', $f['dia'] ) : $f['dia'] ) . '". Usá lunes..domingo, "hoy", "mañana" o "fin de semana".' );
		}
		if ( null === $acts && ! $sede && ! $dias ) {
			return array( 'error' => 'Indicá al menos un filtro: actividad, texto, sede, día, categoría o edad.' );
		}

		$item_ids = array();
		if ( null === $acts ) {
			$item_ids = array_keys( $this->index['items'] );
		} else {
			foreach ( $acts as $a ) {
				$item_ids = array_merge( $item_ids, $a['items'] );
			}
			$item_ids = array_values( array_unique( $item_ids ) );
		}

		$desde = isset( $f['desde_hora'] ) ? self::hhmm( $f['desde_hora'] ) : null;
		$hasta = isset( $f['hasta_hora'] ) ? self::hhmm( $f['hasta_hora'] ) : null;

		$items = array();
		foreach ( $item_ids as $id ) {
			$item = $this->index['items'][ $id ];
			if ( Schedule_Parser::is_stale( $item, $this->now ) ) {
				continue;
			}
			if ( $sede && ! in_array( $sede, $item['sedes'], true ) ) {
				continue;
			}
			if ( $dias && ! array_intersect( $dias, $item['dias'] ) ) {
				continue;
			}
			if ( ( $desde || $hasta ) && ! self::overlaps( $item, $desde, $hasta ) ) {
				continue;
			}
			$items[] = $item;
		}

		$now_dow = (int) $this->now->format( 'N' ) - 1;
		usort(
			$items,
			function ( $a, $b ) use ( $dias, $now_dow ) {
				$ka = Query::sort_key( $a, $dias, $now_dow );
				$kb = Query::sort_key( $b, $dias, $now_dow );
				return strcmp( $ka, $kb );
			}
		);

		$out = array(
			'filtros_aplicados' => array_filter(
				array(
					'actividades' => null !== $acts ? array_map( function ( $a ) {
						return $a['slug'];
					}, array_slice( $acts, 0, 15 ) ) : null,
					'sede'        => $sede,
					'dias'        => $dias ? $dias : null,
					'desde_hora'  => $desde,
					'hasta_hora'  => $hasta,
				)
			),
			'total'             => count( $items ),
			'items'             => array_map( array( $this, 'item_output' ), array_slice( $items, 0, $limit ) ),
		);
		if ( ! empty( $f['dia'] ) && preg_match( '/hoy|ma[nñ]ana/iu', is_array( $f['dia'] ) ? implode( ' ', $f['dia'] ) : $f['dia'] ) ) {
			$out['fecha_referencia'] = $this->now->format( 'Y-m-d' ) . ' (' . Text::DIAS_NOMBRE[ Text::DIAS[ $now_dow ] ] . ')';
		}
		if ( count( $items ) > $limit ) {
			$out['aviso'] = sprintf( 'Se muestran %d de %d items. Filtrá por sede, día o actividad para ver el resto.', $limit, count( $items ) );
		}
		if ( 1 === count( (array) $acts ) ) {
			$out['actividad'] = $this->activity_summary( $acts[0] );
			if ( ! $acts[0]['tiene_agenda'] ) {
				$out['aviso'] = 'Esta actividad no tiene horarios cargados en la agenda del sitio. Sugerí contactar a la sede.';
			}
		}
		if ( ! $items && null !== $acts && ! $acts ) {
			$out['sugerencia'] = 'No encontré actividades con esos filtros. Probá buscar_actividades con menos filtros.';
		}
		return array_merge( $out, $meta );
	}

	/**
	 * @return array{actividades: array[], meta: array}
	 */
	private function filter_activities( array $f, $only_with_agenda = false ) {
		// Las federadas (grupo=federadas) se consultan con deportes_federados, no acá:
		// evita que una misma categoría aparezca duplicada en ambas tools.
		$acts = array_values( array_filter( $this->index['actividades'], function ( $a ) {
			return ! isset( $a['grupo'] ) || 'federadas' !== $a['grupo'];
		} ) );
		$meta = array();

		$scores = array();
		if ( ! empty( $f['texto'] ) ) {
			$tokens = array_values( array_filter( Text::tokens( $f['texto'] ), function ( $t ) {
				return ! ctype_digit( $t );
			} ) );
			if ( $tokens ) {
				$expanded = $this->catalog->expand_tokens( $tokens );
				$kept     = array();
				foreach ( $acts as $a ) {
					list( $matched, $score ) = $this->score( $a, $expanded );
					if ( $matched > 0 ) {
						$scores[ $a['slug'] ] = array( $matched, $score );
						$kept[]               = $a;
					}
				}
				$acts = $kept;
				// Nos quedamos con los que matchean la mayor cantidad de palabras.
				if ( $acts ) {
					$best = max( array_map( function ( $s ) {
						return $s[0];
					}, $scores ) );
					$acts = array_values( array_filter( $acts, function ( $a ) use ( $scores, $best ) {
						return $scores[ $a['slug'] ][0] === $best;
					} ) );
				}
			}
		}

		if ( ! empty( $f['categoria'] ) ) {
			$q    = Text::fold( $f['categoria'] );
			$qs   = Text::slugify( $f['categoria'] );
			$acts = array_values( array_filter( $acts, function ( $a ) use ( $q, $qs ) {
				$cat = $a['categoria'] && isset( $this->index['categorias'][ $a['categoria'] ] ) ? $this->index['categorias'][ $a['categoria'] ] : null;
				$hay = array( isset( $a['grupo'] ) ? $a['grupo'] : '', $a['categoria'], $cat ? Text::fold( $cat['nombre'] ) : '' );
				foreach ( $hay as $h ) {
					if ( $h && ( $h === $qs || false !== strpos( Text::fold( $h ), Text::stem( $q ) ) ) ) {
						return true;
					}
				}
				return false;
			} ) );
		}

		if ( ! empty( $f['genero'] ) ) {
			$g    = self::normalize_gender( $f['genero'] );
			$acts = array_values( array_filter( $acts, function ( $a ) use ( $g ) {
				return Catalog::gender_matches( isset( $a['genero'] ) ? $a['genero'] : null, $g );
			} ) );
		}

		if ( isset( $f['edad'] ) && '' !== $f['edad'] && null !== $f['edad'] ) {
			$edad      = (float) $f['edad'];
			$unknown   = array();
			$acts      = array_values( array_filter( $acts, function ( $a ) use ( $edad, &$unknown ) {
				$m = Catalog::age_matches( $a, $edad );
				if ( null === $m ) {
					$unknown[] = $a['nombre'];
					return false;
				}
				return $m;
			} ) );
			if ( $unknown ) {
				$meta['sin_datos_de_edad'] = array(
					'cantidad' => count( $unknown ),
					'ejemplos' => array_slice( array_values( array_unique( $unknown ) ), 0, 8 ),
					'nota'     => 'Estas actividades no tienen edad cargada y se excluyeron del filtro por edad.',
				);
			}
		}

		if ( ! empty( $f['sede'] ) ) {
			$sede = $this->catalog->resolve_sede( $f['sede'] );
			if ( $sede ) {
				$acts = array_values( array_filter( $acts, function ( $a ) use ( $sede ) {
					return in_array( $sede, $a['sedes'], true );
				} ) );
			} else {
				$meta['aviso_sede'] = 'Sede desconocida "' . $f['sede'] . '", no se filtró por sede. Válidas: ' . implode( ', ', $this->sede_slugs() );
			}
		}

		if ( ! empty( $f['dia'] ) ) {
			$dias = Text::parse_dias_input( $f['dia'], $this->now );
			if ( $dias ) {
				$acts = array_values( array_filter( $acts, function ( $a ) use ( $dias ) {
					return (bool) array_intersect( $dias, $a['dias'] );
				} ) );
			}
		}

		if ( $only_with_agenda ) {
			$acts = array_values( array_filter( $acts, function ( $a ) {
				return $a['tiene_agenda'];
			} ) );
		}

		usort(
			$acts,
			function ( $a, $b ) use ( $scores ) {
				$sa = isset( $scores[ $a['slug'] ] ) ? $scores[ $a['slug'] ] : array( 0, 0 );
				$sb = isset( $scores[ $b['slug'] ] ) ? $scores[ $b['slug'] ] : array( 0, 0 );
				if ( $sa !== $sb ) {
					return $sa[0] === $sb[0] ? $sb[1] - $sa[1] : $sb[0] - $sa[0];
				}
				if ( $a['tiene_agenda'] !== $b['tiene_agenda'] ) {
					return $a['tiene_agenda'] ? -1 : 1;
				}
				return strcmp( Text::fold( $a['nombre'] ), Text::fold( $b['nombre'] ) );
			}
		);

		return array(
			'actividades' => $acts,
			'meta'        => $meta,
		);
	}

	/**
	 * @param array<string, string[]> $expanded
	 * @return array{0: int, 1: int} [palabras matcheadas, puntaje]
	 */
	private function score( array $a, array $expanded ) {
		$cat    = $a['categoria'] && isset( $this->index['categorias'][ $a['categoria'] ] ) ? $this->index['categorias'][ $a['categoria'] ]['nombre'] : '';
		$fields = array(
			array( 5, Text::tokens( $a['nombre'] . ' ' . str_replace( '-', ' ', $a['slug'] ), true ) ),
			array( 4, Text::tokens( ( isset( $a['base'] ) ? $a['base'] : '' ) . ' ' . implode( ' ', isset( $a['aliases'] ) ? (array) $a['aliases'] : array() ) . ' ' . ( isset( $a['nombre_bot'] ) ? $a['nombre_bot'] : '' ), true ) ),
			array( 2, Text::tokens( implode( ' ', $a['titulos'] ), true ) ),
			array( 1, Text::tokens( $cat . ' ' . ( isset( $a['grupo'] ) ? $a['grupo'] : '' ), true ) ),
		);
		$matched = 0;
		$score   = 0;
		foreach ( $expanded as $variants ) {
			$best = 0;
			foreach ( $fields as $field ) {
				list( $weight, $hay ) = $field;
				foreach ( $variants as $variant ) {
					$vt = explode( ' ', $variant );
					$ok = true;
					foreach ( $vt as $v ) {
						if ( ! self::token_in( $v, $hay ) ) {
							$ok = false;
							break;
						}
					}
					if ( $ok ) {
						$best = max( $best, $weight );
					}
				}
			}
			if ( $best ) {
				$matched++;
				$score += $best;
			}
		}
		return array( $matched, $score );
	}

	private static function token_in( $needle, array $hay ) {
		if ( in_array( $needle, $hay, true ) ) {
			return true;
		}
		if ( strlen( $needle ) >= 4 ) {
			foreach ( $hay as $h ) {
				if ( strlen( $h ) >= 4 && ( 0 === strpos( $h, $needle ) || 0 === strpos( $needle, $h ) ) ) {
					return true;
				}
			}
		}
		return false;
	}

	public function activity_summary( array $a ) {
		$cat = $a['categoria'] && isset( $this->index['categorias'][ $a['categoria'] ] ) ? $this->index['categorias'][ $a['categoria'] ]['nombre'] : null;
		$out = array(
			'slug'      => $a['slug'],
			'nombre'    => $a['nombre'],
			'categoria' => $cat,
			'grupo'     => isset( $a['grupo'] ) ? $a['grupo'] : null,
			'edades'    => Catalog::age_label( $a ),
			'genero'    => isset( $a['genero'] ) ? $a['genero'] : null,
			'sedes'     => $this->sede_labels( $a['sedes'] ),
			'dias'      => array_map( function ( $d ) {
				return Text::DIAS_NOMBRE[ $d ];
			}, $a['dias'] ),
			'horarios_cargados' => $a['tiene_agenda'],
			'url'       => $a['url'],
		);
		if ( ! empty( $a['fuente_edad'] ) ) {
			$out['fuente_metadata'] = $a['fuente_edad'];
		}
		if ( ! empty( $a['discapacidad'] ) ) {
			$out['inclusion_discapacidad'] = true;
		}
		return array_filter( $out, function ( $v ) {
			return null !== $v && array() !== $v;
		} );
	}

	public function item_output( array $item ) {
		$acts = array();
		foreach ( $item['actividades'] as $slug ) {
			if ( isset( $this->index['actividades'][ $slug ] ) ) {
				$acts[] = $this->index['actividades'][ $slug ]['nombre'];
			}
		}
		$notas = array_map( function ( $n ) {
			return Text::truncate( $n, 280 );
		}, array_slice( $item['notas'], 0, 8 ) );
		$out   = array(
			'titulo'      => $item['titulo'],
			'actividades' => $acts,
			'sedes'       => $this->sede_labels( $item['sedes'] ),
			'dias'        => array_map( function ( $d ) {
				return Text::DIAS_NOMBRE[ $d ];
			}, $item['dias'] ),
			'horarios'    => $item['horarios'],
			'notas'       => $notas,
		);
		foreach ( array( 'lugar', 'profe', 'emails', 'telefonos', 'links', 'precios', 'cupo_completo', 'requiere_inscripcion' ) as $k ) {
			if ( ! empty( $item[ $k ] ) ) {
				$out[ $k ] = $item[ $k ];
			}
		}
		if ( $item['actividades'] ) {
			$first = $this->index['actividades'][ $item['actividades'][0] ];
			if ( $first['url'] ) {
				$out['url'] = $first['url'];
			}
		}
		return array_filter( $out, function ( $v ) {
			return null !== $v && array() !== $v && '' !== $v;
		} );
	}

	public static function sort_key( array $item, array $dias, $now_dow ) {
		$day_rank = 9;
		foreach ( $item['dias'] as $d ) {
			$i = array_search( $d, Text::DIAS, true );
			if ( $dias && ! in_array( $d, $dias, true ) ) {
				continue;
			}
			$rank     = ( $i - $now_dow + 7 ) % 7;
			$day_rank = min( $day_rank, $rank );
		}
		$first = '99:99';
		foreach ( $item['horarios'] as $h ) {
			foreach ( $h['franjas'] as $fr ) {
				$first = min( $first, $fr['inicio'] );
			}
		}
		return $day_rank . '|' . $first . '|' . Text::fold( $item['titulo'] );
	}

	private static function overlaps( array $item, $desde, $hasta ) {
		foreach ( $item['horarios'] as $h ) {
			foreach ( $h['franjas'] as $fr ) {
				$ini = $fr['inicio'];
				$fin = $fr['fin'] ? ( '00:00' === $fr['fin'] ? '24:00' : $fr['fin'] ) : $ini;
				if ( ( ! $desde || $fin >= $desde ) && ( ! $hasta || $ini <= $hasta ) ) {
					return true;
				}
			}
		}
		return false;
	}

	// ------------------------------------------------------------------ Sedes / categorías.

	public function listar_sedes() {
		$counts = array();
		foreach ( $this->index['actividades'] as $a ) {
			foreach ( $a['sedes'] as $s ) {
				$counts[ $s ] = isset( $counts[ $s ] ) ? $counts[ $s ] + 1 : 1;
			}
		}
		$info = array();
		foreach ( $this->index['especiales']['info_sede'] as $item ) {
			$target = $this->catalog->info_sede_target( $item['termino'] );
			$info[ $target ][] = implode( ' ', array_merge( array_map( function ( $h ) {
				return $h['texto'];
			}, $item['horarios'] ), $item['notas'] ) );
		}
		$out = array();
		foreach ( $this->catalog->sedes() as $s ) {
			$row = array(
				'slug'                    => $s['slug'],
				'nombre'                  => $s['nombre'],
				'direccion'               => isset( $s['direccion'] ) ? $s['direccion'] : null,
				'telefono'                => isset( $s['telefono'] ) ? $s['telefono'] : null,
				'contacto'                => isset( $s['contacto'] ) ? $s['contacto'] : null,
				'descripcion'             => isset( $s['descripcion'] ) ? $s['descripcion'] : null,
				'url'                     => isset( $s['url'] ) ? $s['url'] : null,
				'url_agenda'              => isset( $s['url_agenda'] ) ? $s['url_agenda'] : null,
				'actividades_con_horario' => isset( $counts[ $s['slug'] ] ) ? $counts[ $s['slug'] ] : 0,
			);
			if ( ! empty( $info[ $s['slug'] ] ) ) {
				$row['info_agenda'] = array_values( array_filter( $info[ $s['slug'] ] ) );
			}
			$out[] = array_filter( $row, function ( $v ) {
				return null !== $v && array() !== $v;
			} );
		}
		return array( 'sedes' => $out );
	}

	public function listar_categorias() {
		$out = array();
		foreach ( $this->index['categorias'] as $c ) {
			$acts = array();
			foreach ( $c['actividades'] as $slug ) {
				$a      = $this->index['actividades'][ $slug ];
				$acts[] = array_filter( array(
					'slug'   => $slug,
					'nombre' => $a['nombre'],
					'edades' => Catalog::age_label( $a ),
					'sedes'  => $this->sede_labels( $a['sedes'] ),
				) );
			}
			$out[] = array_filter( array(
				'slug'        => $c['slug'],
				'nombre'      => $c['nombre'],
				'grupo'       => $c['grupo'],
				'actividades' => $acts,
			) );
		}
		return array( 'categorias' => $out );
	}

	// ------------------------------------------------------------------ Federados / transporte / institucional.

	/**
	 * Actividades con grupo=federadas del índice (ACF de WordPress), con la misma forma que antes
	 * tenía Catalog::federados_with_ages(): deporte, categoria, genero, edad_min/max, sede, horarios,
	 * dias, url. Si la categoría tiene año de nacimiento en vez de edad fija (ej. fútbol infantil),
	 * la edad se recalcula contra el año en curso, igual que hacía el catálogo.
	 */
	private function federadas_rows( $year ) {
		$rows = array();
		foreach ( $this->index['actividades'] as $a ) {
			if ( ! isset( $a['grupo'] ) || 'federadas' !== $a['grupo'] ) {
				continue;
			}
			$deporte = $a['categoria'] && isset( $this->index['categorias'][ $a['categoria'] ] )
				? $this->index['categorias'][ $a['categoria'] ]['nombre']
				: $a['nombre'];
			$row     = array_filter(
				array(
					'deporte'             => $deporte,
					'categoria'           => $a['nombre'],
					'genero'              => isset( $a['genero'] ) ? $a['genero'] : null,
					'edad_min'            => isset( $a['edad_min'] ) ? $a['edad_min'] : null,
					'edad_max'            => isset( $a['edad_max'] ) ? $a['edad_max'] : null,
					'anio_nacimiento_min' => isset( $a['anio_nacimiento_min'] ) ? $a['anio_nacimiento_min'] : null,
					'anio_nacimiento_max' => isset( $a['anio_nacimiento_max'] ) ? $a['anio_nacimiento_max'] : null,
					'sede'                => implode( ' / ', $this->sede_labels( $a['sedes'] ) ),
					'dias'                => $a['dias'],
					'horarios'            => $this->federada_horarios( $a ),
					'url'                 => $a['url'],
				),
				function ( $v ) {
					return null !== $v;
				}
			);
			if ( isset( $row['anio_nacimiento_min'] ) ) {
				$row['edad_min'] = $year - (int) $row['anio_nacimiento_max'];
				$row['edad_max'] = $year - (int) $row['anio_nacimiento_min'];
			}
			$rows[] = $row;
		}
		return $rows;
	}

	/** Textos de horario (los mismos que muestra obtener_agenda) de todos los items de una actividad. */
	private function federada_horarios( array $a ) {
		$out = array();
		foreach ( $a['items'] as $id ) {
			if ( ! isset( $this->index['items'][ $id ] ) ) {
				continue;
			}
			foreach ( $this->index['items'][ $id ]['horarios'] as $h ) {
				$out[] = $h['texto'];
			}
		}
		return array_values( array_unique( $out ) );
	}

	public function deportes_federados( array $f ) {
		$year = (int) $this->now->format( 'Y' );
		$rows = $this->federadas_rows( $year );

		if ( ! empty( $f['deporte'] ) ) {
			$tokens   = Text::tokens( $f['deporte'] );
			$expanded = $this->catalog->expand_tokens( $tokens );
			$rows     = array_values( array_filter( $rows, function ( $r ) use ( $expanded ) {
				$hay = Text::tokens( str_replace( '-', ' ', $r['deporte'] ) . ' ' . $r['categoria'], true );
				foreach ( $expanded as $variants ) {
					$hit = false;
					foreach ( $variants as $v ) {
						$all = true;
						foreach ( explode( ' ', $v ) as $vt ) {
							$all = $all && self::token_in( $vt, $hay );
						}
						$hit = $hit || $all;
					}
					if ( ! $hit ) {
						return false;
					}
				}
				return true;
			} ) );
		}
		if ( ! empty( $f['genero'] ) ) {
			$g    = self::normalize_gender( $f['genero'] );
			$rows = array_values( array_filter( $rows, function ( $r ) use ( $g ) {
				return Catalog::gender_matches( $r['genero'], $g );
			} ) );
		}
		$edad = null;
		if ( ! empty( $f['anio_nacimiento'] ) ) {
			$edad = $year - (int) $f['anio_nacimiento'];
		} elseif ( isset( $f['edad'] ) && '' !== $f['edad'] && null !== $f['edad'] ) {
			$edad = (float) $f['edad'];
		}
		$sin_edad = 0;
		if ( null !== $edad ) {
			$rows = array_values( array_filter( $rows, function ( $r ) use ( $edad, &$sin_edad ) {
				$m = Catalog::age_matches( $r, $edad );
				if ( null === $m ) {
					$sin_edad++;
				}
				return true === $m;
			} ) );
		}
		if ( ! empty( $f['sede'] ) ) {
			$sede  = $this->catalog->resolve_sede( $f['sede'] );
			$sname = $sede ? Text::fold( $this->catalog->sede( $sede )['nombre'] ) : Text::fold( $f['sede'] );
			$rows  = array_values( array_filter( $rows, function ( $r ) use ( $sname ) {
				return false !== strpos( Text::fold( $r['sede'] ), $sname );
			} ) );
		}
		if ( ! empty( $f['dia'] ) ) {
			$dias = Text::parse_dias_input( $f['dia'], $this->now );
			$rows = array_values( array_filter( $rows, function ( $r ) use ( $dias ) {
				return ! $r['dias'] || array_intersect( $dias, $r['dias'] );
			} ) );
		}

		$limit = self::clamp( isset( $f['limite'] ) ? $f['limite'] : 30, 1, 100 );
		$out   = array(
			'anio_referencia' => $year,
			'total'           => count( $rows ),
			'categorias'      => array_map( function ( $r ) {
				return array_filter( array(
					'deporte'   => $r['deporte'],
					'categoria' => $r['categoria'],
					'genero'    => $r['genero'],
					'edades'    => Catalog::age_label( $r ),
					'anios_nacimiento' => isset( $r['anio_nacimiento_min'] ) ? ( $r['anio_nacimiento_min'] === $r['anio_nacimiento_max'] ? (string) $r['anio_nacimiento_min'] : $r['anio_nacimiento_min'] . '-' . $r['anio_nacimiento_max'] ) : null,
					'sede'      => $r['sede'],
					'horarios'  => $r['horarios'],
					'url'       => $r['url'],
				) );
			}, array_slice( $rows, 0, $limit ) ),
		);
		if ( ! $f && ! empty( $this->index['especiales']['federados_resumen'] ) ) {
			$out['resumen_sitio'] = $this->index['especiales']['federados_resumen'][0]['notas'];
		}
		if ( $sin_edad ) {
			$out['sin_datos_de_edad'] = $sin_edad . ' categorías no tienen edad cargada (ej. hockey por divisiones, vóley) y se excluyeron. Consultá sin edad para verlas.';
		}
		if ( count( $rows ) > $limit ) {
			$out['aviso'] = sprintf( 'Se muestran %d de %d. Filtrá por deporte, género o edad.', $limit, count( $rows ) );
		}
		return $out;
	}

	public function transporte() {
		$out = array();
		foreach ( $this->catalog->transporte() as $route ) {
			$servicios = array();
			foreach ( $this->index['especiales']['transporte'] as $item ) {
				if ( in_array( $item['termino'], $route['terminos'], true ) ) {
					$servicios[] = array_filter( array(
						'titulo'  => $item['titulo'],
						'dias'    => array_map( function ( $d ) {
							return Text::DIAS_NOMBRE[ $d ];
						}, $item['dias'] ),
						'detalle' => array_merge( array_map( function ( $h ) {
							return $h['texto'];
						}, $item['horarios'] ), $item['notas'] ),
						'precios' => isset( $item['precios'] ) ? $item['precios'] : null,
					) );
				}
			}
			$out[] = array(
				'nombre'    => $route['nombre'],
				'recorrido' => $route['recorrido'],
				'servicios' => $servicios,
				'url'       => $route['url'],
			);
		}
		return array( 'transporte' => $out );
	}

	public function info_institucional( array $f ) {
		$rows = array();
		foreach ( $this->catalog->programas() as $p ) {
			$rows[] = array_merge( array( 'seccion' => 'programa' ), $p );
		}
		foreach ( $this->catalog->distrito() as $d ) {
			$rows[] = array_merge( array( 'seccion' => 'distrito' ), $d );
		}
		if ( ! empty( $f['tema'] ) ) {
			$tokens = Text::tokens( $f['tema'] );
			$rows   = array_values( array_filter( $rows, function ( $r ) use ( $tokens ) {
				$hay = Text::tokens( implode( ' ', array_filter( array_map( function ( $v ) {
					return is_string( $v ) ? $v : ( is_array( $v ) ? implode( ' ', array_filter( $v, 'is_string' ) ) : '' );
				}, $r ) ) ), true );
				foreach ( $tokens as $t ) {
					if ( self::token_in( $t, $hay ) ) {
						return true;
					}
				}
				return false;
			} ) );
		}
		return array( 'resultados' => $rows );
	}

	public function stats() {
		return $this->index['stats'];
	}

	// ------------------------------------------------------------------ Helpers.

	private function sede_labels( array $slugs ) {
		$out = array();
		foreach ( $slugs as $s ) {
			$out[] = isset( $this->index['sede_nombres'][ $s ] ) ? $this->index['sede_nombres'][ $s ] : $s;
		}
		return $out;
	}

	private function sede_slugs() {
		return array_map( function ( $s ) {
			return $s['slug'];
		}, $this->catalog->sedes() );
	}

	public static function normalize_gender( $g ) {
		$g = Text::fold( $g );
		if ( preg_match( '/^(f|fem|femenino|mujer|mujeres|nena|nenas|nina|ninas|chica|chicas|damas?|femenina)$/', $g ) ) {
			return 'femenino';
		}
		if ( preg_match( '/^(m|masc|masculino|varon|varones|hombre|hombres|nene|nenes|nino|ninos|chico|chicos|caballeros?|masculina)$/', $g ) ) {
			return 'masculino';
		}
		return null;
	}

	private static function hhmm( $v ) {
		if ( preg_match( '/^(\d{1,2})(?:[:.](\d{2}))?/', trim( (string) $v ), $m ) ) {
			return sprintf( '%02d:%02d', min( 24, (int) $m[1] ), isset( $m[2] ) ? (int) $m[2] : 0 );
		}
		return null;
	}

	private static function clamp( $v, $min, $max ) {
		return max( $min, min( $max, (int) $v ) );
	}
}
