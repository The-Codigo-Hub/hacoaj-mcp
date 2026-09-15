<?php
/**
 * Parser del campo ACF "detalle" de agenda_item (texto libre con HTML) a datos estructurados.
 * No depende de WordPress: se testea con tests/run.php contra los 361 items reales.
 *
 * @package HacoajMCP
 */

namespace HacoajMCP;

defined( 'ABSPATH' ) || defined( 'HACOAJ_MCP_TESTING' ) || exit;

final class Schedule_Parser {

	const NUM = '(\d{1,2})(?:[.:](\d{2}))?';

	/**
	 * @param string $html  Valor crudo de "detalle".
	 * @param array  $meta  Otros campos ACF (hora_inicio, hora_fin, lugar, profe, edad).
	 * @return array
	 */
	public static function parse_detalle( $html, array $meta = array() ) {
		$conv     = Text::html_to_lines( $html );
		$horarios = array();
		$notas    = array();

		foreach ( $conv['lines'] as $line ) {
			$franjas = self::parse_times( $line );
			if ( $franjas ) {
				$horarios[] = array(
					'texto'   => $line,
					'franjas' => $franjas,
				);
			} else {
				$notas[] = $line;
			}
		}

		$inicio = self::normalize_acf_time( isset( $meta['hora_inicio'] ) ? $meta['hora_inicio'] : '' );
		$fin    = self::normalize_acf_time( isset( $meta['hora_fin'] ) ? $meta['hora_fin'] : '' );
		if ( ! $horarios && $inicio ) {
			$horarios[] = array(
				'texto'   => $inicio . ( $fin ? ' a ' . $fin : '' ) . ' h',
				'franjas' => array( array( 'inicio' => $inicio, 'fin' => $fin ) ),
			);
		}

		$full = implode( "\n", $conv['lines'] );
		$out  = array(
			'horarios' => $horarios,
			'notas'    => $notas,
		);

		$emails = self::match_all( '/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', $full );
		$links  = array();
		foreach ( array_merge( $conv['links'], self::match_all( '#https?://[^\s<>"|)]+#i', $full ) ) as $l ) {
			if ( stripos( $l, 'mailto:' ) === 0 ) {
				$emails[] = substr( $l, 7 );
			} elseif ( stripos( $l, 'tel:' ) !== 0 ) {
				$links[] = rtrim( $l, '.,;' );
			}
		}
		$telefonos = self::match_all( '/(?<![\d\-])(?:11[\s\-]?)?\d{4}[\s\-]\d{4}(?![\d\-])/', $full );
		$precios   = self::match_all( '/\$\s?\d{1,3}(?:\.\d{3})+/', $full );

		if ( $emails ) {
			$out['emails'] = array_values( array_unique( array_map( 'strtolower', $emails ) ) );
		}
		if ( $links ) {
			$out['links'] = array_values( array_unique( $links ) );
		}
		if ( $telefonos ) {
			$out['telefonos'] = array_values( array_unique( $telefonos ) );
		}
		if ( $precios ) {
			$out['precios'] = array_values( array_unique( $precios ) );
		}
		if ( preg_match( '/cupo\s+completo|cupo\s+(para\s+nuevos\s+integrantes\s+)?est[aá]\s+cerrado/iu', $full ) ) {
			$out['cupo_completo'] = true;
		}
		if ( preg_match( '/inscripci[oó]n\s+(obligatoria|previa)|se\s+requiere\s+inscripci[oó]n|inscripciones\s*:/iu', $full ) ) {
			$out['requiere_inscripcion'] = true;
		}
		$vigencia = self::detect_vigencia( $full );
		if ( $vigencia ) {
			$out['vigencia'] = $vigencia;
		}
		foreach ( array( 'lugar', 'profe', 'edad' ) as $k ) {
			if ( ! empty( $meta[ $k ] ) && is_string( $meta[ $k ] ) ) {
				$out[ $k ] = trim( $meta[ $k ] );
			}
		}
		return $out;
	}

	/**
	 * Detecta franjas horarias en una línea.
	 *
	 * @return array<int, array{inicio: string, fin: string|null}>
	 */
	public static function parse_times( $line ) {
		$n       = self::NUM;
		$franjas = array();

		// Rangos: "18 a 18.50 h", "De 19 a 21 h", "19 h a 20:30 h", "15 a 16 y de 16 a 17h".
		$rx_range = '/(?<![\d.:°º⁰$])(?:de\s+)?' . $n . '\s*(hs?\b)?\s*a\s+(?:las\s+)?' . $n
			. '(?![\d°º⁰])(?!\s*(?:a[ñn]os?|mes(?:es)?|grados?|min))\s*(hs?\b)?/iu';

		$candidates = array();
		if ( preg_match_all( $rx_range, $line, $m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE ) ) {
			foreach ( $m as $g ) {
				$h1 = (int) $g[1][0];
				$m1 = isset( $g[2] ) && '' !== $g[2][0] && $g[2][1] >= 0 ? (int) $g[2][0] : 0;
				$h2 = (int) $g[4][0];
				$m2 = isset( $g[5] ) && '' !== $g[5][0] && $g[5][1] >= 0 ? (int) $g[5][0] : 0;
				if ( ! self::valid( $h1, $m1 ) || ! self::valid( $h2, $m2 ) ) {
					continue;
				}
				$has_h   = ( isset( $g[3] ) && '' !== $g[3][0] && $g[3][1] >= 0 ) || ( isset( $g[6] ) && '' !== $g[6][0] && $g[6][1] >= 0 );
				$has_min = ( isset( $g[2] ) && $g[2][1] >= 0 && '' !== $g[2][0] ) || ( isset( $g[5] ) && $g[5][1] >= 0 && '' !== $g[5][0] );
				$candidates[] = array(
					'inicio' => self::fmt( $h1, $m1 ),
					'fin'    => self::fmt( $h2, $m2 ),
					'strong' => $has_h || $has_min,
					'match'  => $g[0][0],
				);
			}
		}

		if ( $candidates ) {
			$any_strong = false;
			foreach ( $candidates as $c ) {
				$any_strong = $any_strong || $c['strong'];
			}
			if ( ! $any_strong ) {
				// Sin "h" ni minutos: sólo lo aceptamos si el segmento es básicamente el rango ("15 a 17 | Sala").
				$rest = $line;
				foreach ( $candidates as $c ) {
					$rest = str_replace( $c['match'], '', $rest );
				}
				$first_segment = trim( explode( '|', $rest )[0] );
				if ( '' !== trim( $first_segment, " \t-–,.y" ) ) {
					$candidates = array();
				}
			}
			foreach ( $candidates as $c ) {
				$franjas[] = array(
					'inicio' => $c['inicio'],
					'fin'    => $c['fin'],
				);
			}
			if ( $franjas ) {
				return self::unique( $franjas );
			}
		}

		// Horarios sueltos: "11, 12 y 14 h", "8.15 h, 9 h y 18 h", "8.15 - 9.15 - 10.15 h", "10.05 h: Córdoba".
		$rx_list = '/(?<![\d.:°º⁰$])((?:\d{1,2}(?:[.:]\d{2})?\s*(?:hs?\b)?\s*(?:,|\by\b|-|–)\s*)*\d{1,2}(?:[.:]\d{2})?)\s*hs?\b/iu';
		$rest    = $line;
		if ( preg_match_all( $rx_list, $line, $m ) ) {
			foreach ( $m[1] as $i => $list ) {
				preg_match_all( '/(\d{1,2})(?:[.:](\d{2}))?/', $list, $nums, PREG_SET_ORDER );
				foreach ( $nums as $num ) {
					$h  = (int) $num[1];
					$mm = isset( $num[2] ) && '' !== $num[2] ? (int) $num[2] : 0;
					if ( self::valid( $h, $mm ) ) {
						$franjas[] = array(
							'inicio' => self::fmt( $h, $mm ),
							'fin'    => null,
						);
					}
				}
				$rest = str_replace( $m[0][ $i ], ' ', $rest );
			}
		}
		// Hora con minutos sin "h": "Pilates Mix: 12.15".
		if ( preg_match_all( '/(?<![\d.:$\/])(\d{1,2})[.:](\d{2})(?![\d.,])/', $rest, $m, PREG_SET_ORDER ) ) {
			foreach ( $m as $g ) {
				if ( self::valid( (int) $g[1], (int) $g[2] ) ) {
					$franjas[] = array(
						'inicio' => self::fmt( (int) $g[1], (int) $g[2] ),
						'fin'    => null,
					);
				}
			}
		}
		return self::unique( $franjas );
	}

	/**
	 * Avisos de temporada que quedan publicados: "Sin horarios durante enero", "comienza en la semana del 2 de marzo 2026".
	 *
	 * @return array|null ['sin_horarios_mes' => 1] | ['comienza' => '2026-03-02']
	 */
	public static function detect_vigencia( $text ) {
		$meses = array( 'enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre' );
		$t     = Text::fold( $text );
		$rx    = implode( '|', $meses );
		if ( preg_match( '/sin horarios? (?:durante|en) (' . $rx . ')/', $t, $m ) ) {
			return array( 'sin_horarios_mes' => array_search( $m[1], $meses, true ) + 1 );
		}
		if ( preg_match( '/comienza (?:en |a partir de )?(?:la semana del |el )?(\d{1,2}) de (' . $rx . ')(?: de)? (\d{4})/', $t, $m ) ) {
			return array( 'comienza' => sprintf( '%04d-%02d-%02d', (int) $m[3], array_search( $m[2], $meses, true ) + 1, (int) $m[1] ) );
		}
		return null;
	}

	/**
	 * ¿El item es un aviso de temporada vencido para la fecha dada?
	 */
	public static function is_stale( array $item, \DateTimeInterface $now ) {
		if ( empty( $item['vigencia'] ) || ! empty( $item['horarios'] ) ) {
			return false;
		}
		$v = $item['vigencia'];
		if ( isset( $v['sin_horarios_mes'] ) ) {
			return (int) $now->format( 'n' ) !== (int) $v['sin_horarios_mes'];
		}
		if ( isset( $v['comienza'] ) ) {
			return $now->format( 'Y-m-d' ) >= $v['comienza'];
		}
		return false;
	}

	private static function valid( $h, $m ) {
		return $h >= 0 && $h <= 24 && $m >= 0 && $m < 60;
	}

	private static function fmt( $h, $m ) {
		return sprintf( '%02d:%02d', 24 === $h ? 0 : $h, $m );
	}

	private static function unique( array $franjas ) {
		$seen = array();
		$out  = array();
		foreach ( $franjas as $f ) {
			$k = $f['inicio'] . '-' . $f['fin'];
			if ( ! isset( $seen[ $k ] ) ) {
				$seen[ $k ] = true;
				$out[]      = $f;
			}
		}
		return $out;
	}

	private static function normalize_acf_time( $v ) {
		if ( ! is_string( $v ) || ! preg_match( '/^(\d{1,2}):(\d{2})/', trim( $v ), $m ) ) {
			return null;
		}
		return sprintf( '%02d:%02d', (int) $m[1], (int) $m[2] );
	}

	private static function match_all( $rx, $text ) {
		return preg_match_all( $rx, $text, $m ) ? array_map( 'trim', $m[0] ) : array();
	}
}
