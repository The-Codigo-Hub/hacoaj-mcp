<?php
/**
 * Catálogo curado (data/catalog.json): la metadata que WordPress no tiene.
 * Viaja con el plugin, así que se actualiza con cada release.
 *
 * @package HacoajMCP
 */

namespace HacoajMCP;

defined( 'ABSPATH' ) || defined( 'HACOAJ_MCP_TESTING' ) || exit;

final class Catalog {

	/** @var array */
	private $data;

	public function __construct( array $data ) {
		$defaults   = array(
			'sedes'            => array(),
			'sedes_no_fisicas' => array(),
			'terminos'         => array(),
			'categorias'       => array(),
			'sinonimos'        => array(),
			'actividades'      => array(),
			'federados'        => array(),
			'transporte'       => array(),
			'programas'        => array(),
			'distrito'         => array(),
		);
		$this->data = array_merge( $defaults, $data );
		$this->data['terminos'] = array_merge(
			array(
				'transporte'        => array(),
				'info_sede'         => array(),
				'federados_resumen' => array(),
				'ocultos'           => array(),
			),
			$this->data['terminos']
		);
	}

	public static function from_file( $path ) {
		$json = is_readable( $path ) ? file_get_contents( $path ) : '';
		$data = $json ? json_decode( $json, true ) : null;
		return new self( is_array( $data ) ? $data : array() );
	}

	public function raw() {
		return $this->data;
	}

	public function agenda_published_since() {
		return isset( $this->data['agenda']['publicados_desde'] ) ? $this->data['agenda']['publicados_desde'] : null;
	}

	public function version() {
		return isset( $this->data['actualizado'] ) ? $this->data['actualizado'] : null;
	}

	/** @return array[] */
	public function sedes() {
		return $this->data['sedes'];
	}

	public function sede( $slug ) {
		foreach ( $this->data['sedes'] as $s ) {
			if ( $s['slug'] === $slug ) {
				return $s;
			}
		}
		return null;
	}

	public function is_physical_sede( $slug ) {
		return ! in_array( $slug, $this->data['sedes_no_fisicas'], true );
	}

	/**
	 * Resuelve lo que escribe un usuario ("Capital", "tigre", "CdC") a un slug de sede.
	 */
	public function resolve_sede( $input ) {
		$q = Text::fold( trim( (string) $input ) );
		if ( '' === $q ) {
			return null;
		}
		$qs = Text::slugify( $q );
		foreach ( $this->data['sedes'] as $s ) {
			if ( $s['slug'] === $qs || Text::fold( $s['nombre'] ) === $q ) {
				return $s['slug'];
			}
		}
		foreach ( $this->data['sedes'] as $s ) {
			$aliases = isset( $s['aliases'] ) ? $s['aliases'] : array();
			foreach ( $aliases as $a ) {
				if ( Text::fold( $a ) === $q ) {
					return $s['slug'];
				}
			}
		}
		foreach ( $this->data['sedes'] as $s ) {
			if ( false !== strpos( Text::fold( $s['nombre'] ), $q ) || false !== strpos( $q, Text::fold( $s['nombre'] ) ) ) {
				return $s['slug'];
			}
		}
		return null;
	}

	public function activity_meta( $slug ) {
		return isset( $this->data['actividades'][ $slug ] ) ? $this->data['actividades'][ $slug ] : null;
	}

	/** Actividades del catálogo que no existen como término en WordPress. */
	public function activities_without_term( array $wp_slugs ) {
		$out = array();
		foreach ( $this->data['actividades'] as $slug => $meta ) {
			if ( ! in_array( $slug, $wp_slugs, true ) ) {
				$out[ $slug ] = $meta;
			}
		}
		return $out;
	}

	public function category_meta( $slug ) {
		return isset( $this->data['categorias'][ $slug ] ) ? $this->data['categorias'][ $slug ] : null;
	}

	/** Tipo especial de un término: transporte | info_sede | federados_resumen | oculto | null. */
	public function term_kind( $slug ) {
		$t = $this->data['terminos'];
		if ( in_array( $slug, $t['transporte'], true ) ) {
			return 'transporte';
		}
		if ( isset( $t['info_sede'][ $slug ] ) ) {
			return 'info_sede';
		}
		if ( in_array( $slug, $t['federados_resumen'], true ) ) {
			return 'federados_resumen';
		}
		if ( in_array( $slug, $t['ocultos'], true ) ) {
			return 'oculto';
		}
		return null;
	}

	public function info_sede_target( $slug ) {
		return isset( $this->data['terminos']['info_sede'][ $slug ] ) ? $this->data['terminos']['info_sede'][ $slug ] : null;
	}

	/**
	 * Expande tokens de búsqueda con sinónimos ("basket" => "basquet").
	 *
	 * @param string[] $tokens
	 * @return array<string, string[]> token original => variantes
	 */
	public function expand_tokens( array $tokens ) {
		$groups = array();
		foreach ( $this->data['sinonimos'] as $canon => $alts ) {
			$group    = array_merge( array( $canon ), $alts );
			$groups[] = array_map(
				function ( $w ) {
					return implode( ' ', Text::tokens( $w, true ) );
				},
				$group
			);
		}
		$out = array();
		foreach ( $tokens as $tok ) {
			$variants = array( $tok );
			foreach ( $groups as $group ) {
				if ( in_array( $tok, $group, true ) ) {
					$variants = array_merge( $variants, $group );
				}
			}
			$out[ $tok ] = array_values( array_unique( $variants ) );
		}
		return $out;
	}

	public function transporte() {
		return $this->data['transporte'];
	}

	public function programas() {
		return $this->data['programas'];
	}

	public function distrito() {
		return $this->data['distrito'];
	}

	/**
	 * Federados con edades calculadas para el año dado (las "Categoría 2015" cambian de edad cada año).
	 */
	public function federados_with_ages( $year ) {
		$out = array();
		foreach ( $this->data['federados'] as $f ) {
			if ( isset( $f['anio_nacimiento_min'] ) ) {
				$f['edad_min'] = (int) $year - (int) $f['anio_nacimiento_max'];
				$f['edad_max'] = (int) $year - (int) $f['anio_nacimiento_min'];
			}
			$f['dias'] = array();
			foreach ( isset( $f['horarios'] ) ? $f['horarios'] : array() as $h ) {
				$f['dias'] = array_merge( $f['dias'], Text::dias_from_text( $h ) );
			}
			$f['dias'] = Text::sort_dias( $f['dias'] );
			$out[]     = $f;
		}
		return $out;
	}

	/**
	 * ¿Una edad (en años, admite decimales) entra en el rango? null = sin datos.
	 */
	public static function age_matches( array $meta, $edad ) {
		$has_months = isset( $meta['edad_min_meses'] ) || isset( $meta['edad_max_meses'] );
		$has_years  = isset( $meta['edad_min'] ) || isset( $meta['edad_max'] );
		if ( ! $has_months && ! $has_years ) {
			return null;
		}
		$months = (int) floor( (float) $edad * 12 + 1e-9 );
		$min    = null;
		$max    = null;
		if ( isset( $meta['edad_min_meses'] ) ) {
			$min = (int) $meta['edad_min_meses'];
		} elseif ( isset( $meta['edad_min'] ) ) {
			$min = (int) $meta['edad_min'] * 12;
		}
		if ( isset( $meta['edad_max_meses'] ) ) {
			$max = (int) $meta['edad_max_meses'];
		} elseif ( isset( $meta['edad_max'] ) ) {
			$max = ( (int) $meta['edad_max'] + 1 ) * 12 - 1;
		}
		if ( null !== $min && $months < $min ) {
			return false;
		}
		if ( null !== $max && $months > $max ) {
			return false;
		}
		return true;
	}

	public static function gender_matches( $activity_gender, $wanted ) {
		if ( ! $wanted || ! $activity_gender || 'mixto' === $activity_gender ) {
			return true;
		}
		return $activity_gender === $wanted;
	}

	/** Texto legible de un rango etario. */
	public static function age_label( array $meta ) {
		if ( ! empty( $meta['rango'] ) ) {
			return $meta['rango'];
		}
		$min = isset( $meta['edad_min'] ) ? (int) $meta['edad_min'] : null;
		$max = isset( $meta['edad_max'] ) ? (int) $meta['edad_max'] : null;
		if ( isset( $meta['edad_min_meses'] ) && null === $min ) {
			$min_label = $meta['edad_min_meses'] . ' meses';
		} else {
			$min_label = null === $min ? null : $min . ' años';
		}
		if ( null === $min && null === $max && ! isset( $meta['edad_min_meses'] ) ) {
			return null;
		}
		if ( null !== $max && $max >= 99 ) {
			$max = null;
		}
		if ( null === $max ) {
			return $min_label ? 'Desde ' . $min_label : null;
		}
		if ( null === $min_label ) {
			return 'Hasta ' . $max . ' años';
		}
		if ( $min === $max ) {
			return $max . ' años';
		}
		return str_replace( ' años', '', $min_label ) . ' a ' . $max . ' años';
	}
}
