<?php
/**
 * Textos listos para mandar por WhatsApp. Cuando una tool devuelve "respuesta", el bot la manda tal cual:
 * así el formato (largo, orden, "más", subtítulos) no depende de que el modelo siga reglas del prompt.
 * Sin dependencias de WordPress.
 *
 * @package HacoajMCP
 */

namespace HacoajMCP;

defined( 'ABSPATH' ) || defined( 'HACOAJ_MCP_TESTING' ) || exit;

final class Formato {

	/** Largo máximo de un mensaje armado. WhatsApp corta en 1024 y el bot suma a veces una línea de cierre. */
	const MAX = 900;

	/** Nombres por mensaje en un listado. */
	const MAX_NOMBRES = 30;

	/** Orden y emoji de las categorías en un listado (por palabra clave del nombre). */
	const CATEGORIAS = array(
		'escuela'    => array( 1, '📚' ),
		'adulto'     => array( 2, '🧑' ),
		'natacion'   => array( 3, '🏊' ),
		'nautic'     => array( 4, '⛵' ),
		'hadraja'    => array( 5, '🧑‍🤝‍🧑' ),
		'cultura'    => array( 6, '🎭' ),
		'recreacion' => array( 7, '🎈' ),
	);

	/** Sedes que el bot no muestra en una agenda salvo que las pidan (horarios a cargo del departamento de socios). */
	const SEDES_POR_MAIL = array( 'isla-hacoaj', 'marinas-h' );

	const MAIL_SEDES_POR_MAIL = 'Para más información sobre horarios en Isla Hacoaj y Marinas H envianos un correo a sociosdepartamento@hacoaj.org.ar 😊';

	/**
	 * @return array{0: int, 1: string} orden y emoji de una categoría.
	 */
	public static function categoria( $nombre ) {
		$n = Text::fold( (string) $nombre );
		foreach ( self::CATEGORIAS as $clave => $v ) {
			if ( false !== strpos( $n, $clave ) ) {
				return $v;
			}
		}
		return array( 9, '🎽' );
	}

	/**
	 * Listado de nombres agrupado por categoría, paginado por largo. Los nombres van en una sola línea por
	 * categoría separados por " · ". Si no entran todos, corta en un nombre completo y avisa cuántos quedan.
	 *
	 * @param array<int, array{categoria: string, nombre: string}> $filas ya ordenadas (categoría, relevancia).
	 * @param int         $desde     posición del primer nombre de esta página.
	 * @param string|null $federadas línea de federadas (solo en la primera página).
	 * @param string      $cierre    pregunta final si no quedan más.
	 * @return array{texto: string, mostradas: int, quedan: int}
	 */
	public static function listado( array $filas, $desde, $federadas, $cierre ) {
		$total = count( $filas );
		$pie   = function ( $quedan ) use ( $cierre ) {
			return $quedan > 0
				? sprintf( 'Quedan %d %s más: escribí «más» para verlas, o contame cuál te interesa 😊', $quedan, 1 === $quedan ? 'actividad' : 'actividades' )
				: $cierre;
		};
		$fed   = $federadas ? "\n🏅 *Federadas*\n" . $federadas : '';
		$lineas = array( '🎽 *Actividades*' );
		$cat    = null;
		$i      = $desde;
		for ( ; $i < $total; $i++ ) {
			$f      = $filas[ $i ];
			$prueba = $lineas;
			if ( $f['categoria'] !== $cat ) {
				$e        = self::categoria( $f['categoria'] );
				$prueba[] = $e[1] . ' *' . $f['categoria'] . '*';
				$prueba[] = $f['nombre'];
			} else {
				$prueba[ count( $prueba ) - 1 ] .= ' · ' . $f['nombre'];
			}
			$largo = self::largo( implode( "\n", $prueba ) . $fed . "\n\n" . $pie( $total - $i - 1 ) );
			// Hasta MAX caracteres y hasta MAX_NOMBRES nombres por mensaje: más de 30 nombres juntos no se leen (B11).
			if ( $i > $desde && ( $largo > self::MAX || $i - $desde >= self::MAX_NOMBRES ) ) {
				break;
			}
			$lineas = $prueba;
			$cat    = $f['categoria'];
		}
		$quedan = $total - $i;
		return array(
			'texto'     => implode( "\n", $lineas ) . $fed . "\n\n" . $pie( $quedan ),
			'mostradas' => $i - $desde,
			'quedan'    => $quedan,
		);
	}

	/**
	 * Listado de una búsqueda: subtítulo por categoría y una viñeta por nombre; si no entra, un renglón por
	 * categoría con los nombres separados por " · "; si tampoco, las federadas pasan a un resumen.
	 *
	 * @param array<string, string[]> $cats      categoría => nombres.
	 * @param string[]                $federadas nombres de federadas.
	 * @param string|null             $resumen   resumen de federadas (en lugar de los nombres).
	 */
	public static function listado_secciones( array $cats, array $federadas, $resumen, $cierre ) {
		foreach ( array( 'vinetas', 'compacto', 'resumen' ) as $modo ) {
			$lineas = array();
			if ( $cats ) {
				$lineas[] = '🎽 *Actividades*';
				foreach ( $cats as $cat => $nombres ) {
					$e        = self::categoria( $cat );
					$lineas[] = $e[1] . ' *' . $cat . '*';
					if ( 'vinetas' === $modo ) {
						foreach ( $nombres as $n ) {
							$lineas[] = ' • ' . $n;
						}
					} else {
						$lineas[] = implode( ' · ', $nombres );
					}
				}
			}
			if ( $federadas && 'resumen' !== $modo ) {
				$lineas[] = '🏅 *Federadas*';
				if ( 'vinetas' === $modo ) {
					foreach ( $federadas as $n ) {
						$lineas[] = ' • ' . $n;
					}
				} else {
					$lineas[] = implode( ' · ', $federadas );
				}
			} elseif ( $federadas || $resumen ) {
				$lineas[] = '🏅 *Federadas*';
				$lineas[] = $resumen ? $resumen : sprintf( 'Hay %d categorías federadas: decime cuál te interesa y te paso los horarios.', count( $federadas ) );
			}
			$texto = implode( "\n", $lineas ) . "\n\n" . $cierre;
			if ( self::largo( $texto ) <= self::MAX ) {
				return $texto;
			}
		}
		return $texto;
	}

	/** "08:30" → "8.30", "19:00" → "19". */
	public static function hora( $hhmm ) {
		if ( ! preg_match( '/^(\d{1,2}):(\d{2})$/', (string) $hhmm, $m ) ) {
			return (string) $hhmm;
		}
		return (int) $m[1] . ( '00' === $m[2] ? '' : '.' . $m[2] );
	}

	/** Franjas de un horario: "8.30 a 19 h", "18 y 20 h". */
	public static function franjas( array $franjas ) {
		$partes = array();
		foreach ( $franjas as $fr ) {
			$partes[] = $fr['fin'] ? self::hora( $fr['inicio'] ) . ' a ' . self::hora( $fr['fin'] ) : self::hora( $fr['inicio'] );
		}
		$partes = array_values( array_unique( $partes ) );
		if ( ! $partes ) {
			return '';
		}
		$ultimo = array_pop( $partes );
		return ( $partes ? implode( ', ', $partes ) . ' y ' : '' ) . $ultimo . ' h';
	}

	/**
	 * Agenda de una sede en uno o más días, resumida: un renglón por actividad con sus horarios de ese día,
	 * y las federadas en un renglón.
	 *
	 * @param string   $titulo   "Tigre Maliar — hoy (lunes)".
	 * @param string[] $filas    "Tenis: 8.30 a 19 h".
	 * @param string[] $deportes deportes federados que entrenan.
	 */
	public static function agenda_dia( $titulo, array $filas, array $deportes ) {
		// Todas las que entren en un mensaje (B13 pedía la agenda completa del día), con un mínimo de 3.
		for ( $max = count( $filas ); ; $max-- ) {
			$texto = '📍 *' . $titulo . '*';
			if ( $filas ) {
				$texto .= "\n🎽 *Actividades*\n" . implode( "\n", array_slice( $filas, 0, $max ) );
				if ( count( $filas ) > $max ) {
					$texto .= "\n" . sprintf( 'Y %d más ese día.', count( $filas ) - $max );
				}
			}
			if ( $deportes ) {
				$texto .= "\n🏅 *Federadas*\nEntrenan " . self::enumerar( $deportes ) . '.';
			}
			$texto .= "\n\n¿Querés el detalle de alguna? Decime cuál 😊";
			if ( self::largo( $texto ) <= self::MAX || $max <= 3 ) {
				return $texto;
			}
		}
	}

	/** "a, b y c". */
	public static function enumerar( array $xs ) {
		$xs = array_values( $xs );
		if ( count( $xs ) < 2 ) {
			return implode( '', $xs );
		}
		$ultimo = array_pop( $xs );
		return implode( ', ', $xs ) . ' y ' . $ultimo;
	}

	/** Largo en caracteres (no bytes). */
	public static function largo( $s ) {
		return function_exists( 'mb_strlen' ) ? mb_strlen( $s, 'UTF-8' ) : strlen( utf8_decode( $s ) );
	}
}
