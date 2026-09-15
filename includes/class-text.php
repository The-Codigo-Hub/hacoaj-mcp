<?php
/**
 * Helpers de texto sin dependencias de WordPress (testeables en CLI).
 *
 * @package HacoajMCP
 */

namespace HacoajMCP;

defined( 'ABSPATH' ) || defined( 'HACOAJ_MCP_TESTING' ) || exit;

final class Text {

	const DIAS = array( 'lunes', 'martes', 'miercoles', 'jueves', 'viernes', 'sabado', 'domingo' );

	const DIAS_NOMBRE = array(
		'lunes'     => 'Lunes',
		'martes'    => 'Martes',
		'miercoles' => 'Miércoles',
		'jueves'    => 'Jueves',
		'viernes'   => 'Viernes',
		'sabado'    => 'Sábado',
		'domingo'   => 'Domingo',
	);

	const STOPWORDS = array(
		'de', 'del', 'la', 'las', 'el', 'los', 'y', 'o', 'en', 'para', 'con', 'por', 'a', 'al', 'un', 'una',
		'que', 'hay', 'clase', 'clases', 'actividad', 'actividades', 'quiero', 'busco', 'mi', 'mis', 'hijo', 'hija',
		'hijos', 'hijas', 'algo', 'donde', 'cuando', 'como', 'horario', 'horarios', 'hacoaj', 'club',
	);

	/**
	 * Minúsculas sin acentos ni símbolos raros.
	 */
	public static function fold( $s ) {
		$s = (string) $s;
		$map = array(
			'Á' => 'a', 'É' => 'e', 'Í' => 'i', 'Ó' => 'o', 'Ú' => 'u', 'Ü' => 'u', 'Ñ' => 'n',
			'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n',
			'À' => 'a', 'È' => 'e', 'Ì' => 'i', 'Ò' => 'o', 'Ù' => 'u', 'à' => 'a', 'è' => 'e', 'ì' => 'i', 'ò' => 'o', 'ù' => 'u',
			'Ç' => 'c', 'ç' => 'c', '°' => '', 'º' => '', 'ª' => '', '⁰' => '',
		);
		$s = strtr( $s, $map );
		return function_exists( 'mb_strtolower' ) ? mb_strtolower( $s, 'UTF-8' ) : strtolower( $s );
	}

	public static function slugify( $s ) {
		$s = self::fold( $s );
		$s = preg_replace( '/[^a-z0-9]+/', '-', $s );
		return trim( $s, '-' );
	}

	/**
	 * Tokens normalizados para búsqueda (sin stopwords, con singularización básica).
	 *
	 * @return string[]
	 */
	public static function tokens( $s, $keep_stopwords = false ) {
		$s     = self::fold( $s );
		$parts = preg_split( '/[^a-z0-9]+/', $s, -1, PREG_SPLIT_NO_EMPTY );
		$out   = array();
		foreach ( $parts as $p ) {
			if ( ! $keep_stopwords && in_array( $p, self::STOPWORDS, true ) ) {
				continue;
			}
			$out[] = self::stem( $p );
		}
		return array_values( array_unique( $out ) );
	}

	public static function stem( $w ) {
		if ( strlen( $w ) > 4 && substr( $w, -2 ) === 'es' && ! in_array( substr( $w, -3, 1 ), array( 'a', 'e', 'i', 'o', 'u' ), true ) ) {
			return substr( $w, 0, -2 );
		}
		if ( strlen( $w ) > 3 && substr( $w, -1 ) === 's' ) {
			return substr( $w, 0, -1 );
		}
		return $w;
	}

	/**
	 * HTML de WordPress/ACF a líneas de texto plano.
	 *
	 * @return array{lines: string[], links: string[]}
	 */
	public static function html_to_lines( $html ) {
		$html  = (string) $html;
		$links = array();
		if ( preg_match_all( '/<a\s[^>]*href=["\']([^"\']+)["\']/i', $html, $m ) ) {
			foreach ( $m[1] as $href ) {
				$href = html_entity_decode( $href, ENT_QUOTES, 'UTF-8' );
				if ( preg_match( '#^(https?:|mailto:|tel:)#i', $href ) ) {
					$links[] = $href;
				}
			}
		}
		$html = preg_replace( '#<(br|/p|/div|/li|/h[1-6]|/tr)\b[^>]*>#i', "\n", $html );
		$text = html_entity_decode( strip_tags( $html ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$text = str_replace( array( "\r\n", "\r", "\xC2\xA0" ), array( "\n", "\n", ' ' ), $text );
		$lines = array();
		foreach ( explode( "\n", $text ) as $line ) {
			$line = trim( preg_replace( '/[ \t]+/u', ' ', $line ) );
			if ( '' !== $line ) {
				$lines[] = $line;
			}
		}
		return array(
			'lines' => $lines,
			'links' => array_values( array_unique( $links ) ),
		);
	}

	public static function truncate( $s, $max ) {
		$s = (string) $s;
		if ( function_exists( 'mb_strlen' ) ? mb_strlen( $s, 'UTF-8' ) <= $max : strlen( $s ) <= $max ) {
			return $s;
		}
		$cut = function_exists( 'mb_substr' ) ? mb_substr( $s, 0, $max - 1, 'UTF-8' ) : substr( $s, 0, $max - 1 );
		return rtrim( $cut ) . '…';
	}

	/**
	 * Normaliza un día ingresado por el usuario. Devuelve lista de slugs de día.
	 * Acepta "sábado", "sabados", "fin de semana", "semana", "lunes a viernes", "hoy", "mañana".
	 *
	 * @param string|string[] $input
	 * @param \DateTimeInterface|null $now
	 * @return string[]
	 */
	public static function parse_dias_input( $input, $now = null ) {
		$values = is_array( $input ) ? $input : array( $input );
		$out    = array();
		foreach ( $values as $value ) {
			$v = trim( self::fold( $value ) );
			if ( '' === $v ) {
				continue;
			}
			if ( in_array( $v, array( 'hoy', 'manana', 'pasado manana' ), true ) ) {
				$now    = $now ? $now : new \DateTimeImmutable( 'now' );
				$offset = 'hoy' === $v ? 0 : ( 'manana' === $v ? 1 : 2 );
				$n      = ( (int) $now->format( 'N' ) - 1 + $offset ) % 7;
				$out[]  = self::DIAS[ $n ];
				continue;
			}
			if ( preg_match( '/fin(es)? de semana|finde/', $v ) ) {
				$out = array_merge( $out, array( 'sabado', 'domingo' ) );
				continue;
			}
			if ( preg_match( '/^(entre semana|dias? de semana|semana|habiles?|dias habiles)$/', $v ) ) {
				$out = array_merge( $out, array_slice( self::DIAS, 0, 5 ) );
				continue;
			}
			$out = array_merge( $out, self::dias_from_text( $v ) );
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * Extrae días de un texto libre: "Lunes a viernes", "Martes y jueves", "Sábados o domingos".
	 *
	 * @return string[]
	 */
	public static function dias_from_text( $text ) {
		$t   = self::fold( $text );
		$rx  = '(lunes|martes|miercoles|jueves|viernes|sabados?|domingos?)';
		$out = array();
		if ( preg_match_all( '/' . $rx . '\s+a\s+' . $rx . '/', $t, $m, PREG_SET_ORDER ) ) {
			foreach ( $m as $pair ) {
				$a = array_search( self::dia_slug( $pair[1] ), self::DIAS, true );
				$b = array_search( self::dia_slug( $pair[2] ), self::DIAS, true );
				if ( false !== $a && false !== $b ) {
					for ( $i = $a; $i <= $b; $i++ ) {
						$out[] = self::DIAS[ $i ];
					}
				}
				$t = str_replace( $pair[0], ' ', $t );
			}
		}
		if ( preg_match_all( '/' . $rx . '/', $t, $m ) ) {
			foreach ( $m[1] as $d ) {
				$out[] = self::dia_slug( $d );
			}
		}
		$out = array_values( array_unique( $out ) );
		usort(
			$out,
			function ( $a, $b ) {
				return array_search( $a, Text::DIAS, true ) - array_search( $b, Text::DIAS, true );
			}
		);
		return $out;
	}

	private static function dia_slug( $d ) {
		if ( 'lunes' === $d || 'martes' === $d || 'miercoles' === $d || 'jueves' === $d || 'viernes' === $d ) {
			return $d;
		}
		return rtrim( $d, 's' );
	}

	public static function sort_dias( array $dias ) {
		$dias = array_values( array_unique( array_intersect( $dias, self::DIAS ) ) );
		usort(
			$dias,
			function ( $a, $b ) {
				return array_search( $a, Text::DIAS, true ) - array_search( $b, Text::DIAS, true );
			}
		);
		return $dias;
	}
}
