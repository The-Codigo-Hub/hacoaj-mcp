<?php
/**
 * Definición de las tools MCP (nombre, descripción, JSON Schema) y su ejecución.
 * Todas son de sólo lectura.
 *
 * @package HacoajMCP
 */

namespace HacoajMCP;

defined( 'ABSPATH' ) || exit;

final class Tools {

	/** @var Repository */
	private $repo;

	public function __construct( Repository $repo ) {
		$this->repo = $repo;
	}

	/**
	 * @return array<string, array{title: string, description: string, inputSchema: array, handler: callable}>
	 */
	public function definitions() {
		$edad   = array(
			'type'        => 'number',
			'minimum'     => 0,
			'maximum'     => 110,
			'description' => 'Edad de la persona en años (admite decimales, ej. 1.5 para 18 meses).',
		);
		$genero = array(
			'type'        => 'string',
			'enum'        => array( 'femenino', 'masculino' ),
			'description' => 'Filtra actividades exclusivas de un género. Las mixtas siempre se incluyen.',
		);
		$sede   = array(
			'type'        => 'string',
			'description' => 'Sede: tigre-maliar, club-de-campo, ben-gurion (CABA), marinas-h o isla-hacoaj. Acepta nombres coloquiales ("Tigre", "Capital", "Club de Campo").',
		);
		$dia    = array(
			'type'        => 'string',
			'description' => 'Día: lunes..domingo, "hoy", "mañana", "fin de semana" o "semana". Se resuelve con la fecha actual de Argentina.',
		);
		$limite = function ( $default, $max ) {
			return array(
				'type'        => 'integer',
				'minimum'     => 1,
				'maximum'     => $max,
				'default'     => $default,
				'description' => 'Máximo de resultados.',
			);
		};

		$defs = array(
			'buscar_actividades' => array(
				'title'       => 'Buscar actividades',
				'description' => 'Busca actividades del Club Náutico Hacoaj (deportes, escuelas deportivas, cultura, Hadrajá, natación, náuticas, recreación) y devuelve un resumen: nombre, categoría, edades, género, sedes, días y link. '
					. 'Usala primero cuando alguien pregunta "¿qué hay para...?" o por un deporte. Combiná texto con edad/sede/día. Para ver horarios concretos usá obtener_agenda con el slug devuelto. '
					. 'Los deportes federados (competitivos) están en deportes_federados.',
				'inputSchema' => self::schema(
					array(
						'texto'     => array(
							'type'        => 'string',
							'description' => 'Palabras clave: deporte o actividad ("golf", "natación", "zumba", "básquet femenino"). Tolera acentos y sinónimos.',
						),
						'edad'      => $edad,
						'genero'    => $genero,
						'sede'      => $sede,
						'dia'       => $dia,
						'categoria' => array(
							'type'        => 'string',
							'description' => 'Categoría o grupo: "generales", "federadas", "hadrajá", "escuelas", "culturales".',
						),
						'limite'    => $limite( 15, 50 ),
					)
				),
				'handler'     => function ( $args ) {
					return $this->repo->query()->buscar_actividades( $args );
				},
			),
			'obtener_agenda'     => array(
				'title'       => 'Obtener agenda / horarios',
				'description' => 'Devuelve los horarios publicados en la agenda del sitio: día, franjas horarias (HH:MM), lugar, profesor, notas, precios, contactos e inscripción. '
					. 'Filtrá por actividad (slug de buscar_actividades o texto libre), sede, día ("hoy", "sábado"), categoría, edad y rango horario. Requiere al menos un filtro. '
					. 'Cada item trae "horarios": [{texto original, franjas:[{inicio, fin}]}]; citá el texto original si hay dudas.',
				'inputSchema' => self::schema(
					array(
						'actividad'  => array(
							'type'        => 'string',
							'description' => 'Slug exacto (ej. "golf-clases") o nombre de la actividad.',
						),
						'texto'      => array(
							'type'        => 'string',
							'description' => 'Palabras clave si no tenés el slug.',
						),
						'sede'       => $sede,
						'dia'        => $dia,
						'categoria'  => array(
							'type'        => 'string',
							'description' => 'Categoría o grupo (ver buscar_actividades).',
						),
						'edad'       => $edad,
						'genero'     => $genero,
						'desde_hora' => array(
							'type'        => 'string',
							'description' => 'Hora mínima HH:MM (ej. "18:00").',
						),
						'hasta_hora' => array(
							'type'        => 'string',
							'description' => 'Hora máxima HH:MM.',
						),
						'limite'     => $limite( 25, 80 ),
					)
				),
				'handler'     => function ( $args ) {
					return $this->repo->query()->obtener_agenda( $args );
				},
			),
			'listar_sedes'       => array(
				'title'       => 'Listar sedes',
				'description' => 'Sedes del club con dirección, teléfono/WhatsApp, descripción, link y link a la agenda de cada sede.',
				'inputSchema' => self::schema( array() ),
				'handler'     => function () {
					return $this->repo->query()->listar_sedes();
				},
			),
			'listar_categorias'  => array(
				'title'       => 'Listar categorías',
				'description' => 'Árbol de categorías (Deportes Adultos, Escuelas Deportivas, Cultura, Hadrajá, Natación, Náuticas...) con sus actividades, edades y sedes. Útil para ofrecer opciones cuando la consulta es muy general.',
				'inputSchema' => self::schema( array() ),
				'handler'     => function () {
					return $this->repo->query()->listar_categorias();
				},
			),
			'deportes_federados' => array(
				'title'       => 'Deportes federados',
				'description' => 'Categorías de deportes federados/competitivos (básquet, fútbol, hockey, cestoball, vóley, gimnasia artística, judo, remo, canotaje, tenis) con género, edades o años de nacimiento, sede, horarios de entrenamiento y link. '
					. 'Las categorías por año ("Categoría 2015") se recalculan con el año actual.',
				'inputSchema' => self::schema(
					array(
						'deporte'         => array(
							'type'        => 'string',
							'description' => 'Deporte ("fútbol", "hockey", "cesto"...).',
						),
						'genero'          => $genero,
						'edad'            => $edad,
						'anio_nacimiento' => array(
							'type'        => 'integer',
							'minimum'     => 1930,
							'maximum'     => 2030,
							'description' => 'Año de nacimiento (preferible a edad para fútbol por categorías).',
						),
						'sede'            => $sede,
						'dia'             => $dia,
						'limite'          => $limite( 30, 100 ),
					)
				),
				'handler'     => function ( $args ) {
					return $this->repo->query()->deportes_federados( $args );
				},
			),
			'transporte'         => array(
				'title'       => 'Transporte entre sedes',
				'description' => 'Micro CABA - Tigre Maliar y combi Club de Campo - Marinas H - Tigre Maliar: días, horarios, paradas, precio y reglas.',
				'inputSchema' => self::schema( array() ),
				'handler'     => function () {
					return $this->repo->query()->transporte();
				},
			),
			'info_institucional' => array(
				'title'       => 'Información institucional',
				'description' => 'Programas del club (Tikun voluntariado, Magal inclusión, Bitnuah longevidad) y Distrito Hacoaj / Polo Educativo.',
				'inputSchema' => self::schema(
					array(
						'tema' => array(
							'type'        => 'string',
							'description' => 'Tema opcional para filtrar ("inclusión", "voluntariado", "colegio").',
						),
					)
				),
				'handler'     => function ( $args ) {
					return $this->repo->query()->info_institucional( $args );
				},
			),
			'noticias'           => array(
				'title'       => 'Noticias',
				'description' => 'Últimas noticias publicadas en hacoaj.org.ar (título, fecha, resumen, link). Opcionalmente filtra por texto.',
				'inputSchema' => self::schema(
					array(
						'texto'    => array(
							'type'        => 'string',
							'description' => 'Texto a buscar en las noticias.',
						),
						'cantidad' => $limite( 5, 20 ),
					)
				),
				'handler'     => array( $this, 'noticias' ),
			),
			'revista'            => array(
				'title'       => 'Revista Hacoaj',
				'description' => 'Últimos números de la Revista Hacoaj con link para leerlos.',
				'inputSchema' => self::schema( array( 'cantidad' => $limite( 3, 20 ) ) ),
				'handler'     => array( $this, 'revista' ),
			),
			'buscar_en_sitio'    => array(
				'title'       => 'Buscar en el sitio',
				'description' => 'Búsqueda de texto en páginas, noticias y revista de hacoaj.org.ar. Usala como último recurso para temas que no cubren las otras tools (socios, servicios, contacto, apps, beneficios).',
				'inputSchema' => self::schema(
					array(
						'texto'    => array(
							'type'        => 'string',
							'description' => 'Texto a buscar.',
						),
						'cantidad' => $limite( 8, 20 ),
					),
					array( 'texto' )
				),
				'handler'     => array( $this, 'buscar_en_sitio' ),
			),
		);

		/**
		 * Permite agregar, quitar o modificar tools.
		 */
		return apply_filters( 'hacoaj_mcp_tools', $defs, $this->repo );
	}

	public function list_for_mcp() {
		$out = array();
		foreach ( $this->definitions() as $name => $def ) {
			$out[] = array(
				'name'        => $name,
				'title'       => $def['title'],
				'description' => $def['description'],
				'inputSchema' => $def['inputSchema'],
				'annotations' => array(
					'title'           => $def['title'],
					'readOnlyHint'    => true,
					'destructiveHint' => false,
					'idempotentHint'  => true,
					'openWorldHint'   => false,
				),
			);
		}
		return $out;
	}

	public function exists( $name ) {
		return array_key_exists( $name, $this->definitions() );
	}

	/**
	 * @return array{ok: bool, data?: mixed, error?: string}
	 */
	public function call( $name, $args ) {
		$defs = $this->definitions();
		$def  = $defs[ $name ];
		$args = is_array( $args ) ? $args : array();

		$errors = self::validate( $args, $def['inputSchema'] );
		if ( $errors ) {
			return array(
				'ok'    => false,
				'error' => 'Argumentos inválidos: ' . implode( '; ', $errors ),
			);
		}
		$args = self::coerce( $args, $def['inputSchema'] );

		try {
			$data = call_user_func( $def['handler'], $args );
		} catch ( \Throwable $e ) {
			error_log( '[hacoaj-mcp] tool ' . $name . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine() ); // phpcs:ignore
			return array(
				'ok'    => false,
				'error' => 'Error interno ejecutando ' . $name . '.',
			);
		}
		if ( is_array( $data ) && isset( $data['error'] ) && 1 === count( $data ) ) {
			return array(
				'ok'    => false,
				'error' => $data['error'],
			);
		}
		return array(
			'ok'   => true,
			'data' => $data,
		);
	}

	// ------------------------------------------------------------------ Tools que consultan WordPress directo.

	public function noticias( array $args ) {
		$q     = new \WP_Query(
			array(
				'post_type'           => 'post',
				'post_status'         => 'publish',
				'posts_per_page'      => isset( $args['cantidad'] ) ? (int) $args['cantidad'] : 5,
				's'                   => isset( $args['texto'] ) ? $args['texto'] : '',
				'ignore_sticky_posts' => true,
				'no_found_rows'       => true,
			)
		);
		$items = array();
		foreach ( $q->posts as $post ) {
			$items[] = array(
				'titulo'     => html_entity_decode( get_the_title( $post ), ENT_QUOTES, 'UTF-8' ),
				'fecha'      => get_the_date( 'Y-m-d', $post ),
				'resumen'    => self::excerpt( $post, 45 ),
				'categorias' => wp_list_pluck( get_the_category( $post->ID ), 'name' ),
				'url'        => get_permalink( $post ),
			);
		}
		return array( 'noticias' => $items );
	}

	public function revista( array $args ) {
		if ( ! post_type_exists( 'revista' ) ) {
			return array( 'error' => 'El tipo de contenido "revista" no existe en este sitio.' );
		}
		$q     = new \WP_Query(
			array(
				'post_type'      => 'revista',
				'post_status'    => 'publish',
				'posts_per_page' => isset( $args['cantidad'] ) ? (int) $args['cantidad'] : 3,
				'no_found_rows'  => true,
			)
		);
		$items = array();
		foreach ( $q->posts as $post ) {
			$haystack = $post->post_content . ' ' . $post->post_name;
			foreach ( get_post_meta( $post->ID ) as $key => $values ) {
				if ( '_' !== substr( $key, 0, 1 ) ) {
					$haystack .= ' ' . implode( ' ', array_filter( $values, 'is_string' ) );
				}
			}
			$lectura = null;
			if ( preg_match( '#https?://[^\s"\'<>]*flippingbook[^\s"\'<>]*#i', $haystack, $m ) ) {
				$lectura = $m[0];
			} elseif ( preg_match( '#https?://[^\s"\'<>]+#i', $post->post_content, $m ) ) {
				$lectura = $m[0];
			}
			$items[] = array_filter(
				array(
					'titulo'      => html_entity_decode( get_the_title( $post ), ENT_QUOTES, 'UTF-8' ),
					'fecha'       => get_the_date( 'Y-m-d', $post ),
					'url'         => get_permalink( $post ),
					'url_lectura' => $lectura,
				)
			);
		}
		return array(
			'revistas'   => $items,
			'url_indice' => home_url( '/revista-hacoaj/' ),
		);
	}

	public function buscar_en_sitio( array $args ) {
		$types = array_values( array_filter( array( 'page', 'post', 'revista' ), 'post_type_exists' ) );
		$q     = new \WP_Query(
			array(
				'post_type'      => $types,
				'post_status'    => 'publish',
				's'              => $args['texto'],
				'posts_per_page' => isset( $args['cantidad'] ) ? (int) $args['cantidad'] : 8,
				'no_found_rows'  => true,
			)
		);
		$items = array();
		foreach ( $q->posts as $post ) {
			if ( post_password_required( $post ) ) {
				continue;
			}
			$items[] = array(
				'titulo'  => html_entity_decode( get_the_title( $post ), ENT_QUOTES, 'UTF-8' ),
				'tipo'    => 'page' === $post->post_type ? 'página' : ( 'post' === $post->post_type ? 'noticia' : $post->post_type ),
				'resumen' => self::excerpt( $post, 40 ),
				'url'     => get_permalink( $post ),
			);
		}
		return array(
			'resultados' => $items,
			'nota'       => $items ? null : 'Sin resultados. Probá con otras palabras.',
		);
	}

	private static function excerpt( \WP_Post $post, $words ) {
		$text = $post->post_excerpt ? $post->post_excerpt : $post->post_content;
		$text = strip_shortcodes( $text );
		$text = wp_strip_all_tags( html_entity_decode( $text, ENT_QUOTES, 'UTF-8' ), true );
		return wp_trim_words( $text, $words, '…' );
	}

	// ------------------------------------------------------------------ Schema helpers.

	private static function schema( array $properties, array $required = array() ) {
		$schema = array(
			'type'       => 'object',
			'properties' => $properties ? $properties : new \stdClass(),
		);
		if ( $required ) {
			$schema['required'] = $required;
		}
		return $schema;
	}

	/**
	 * Validación básica de JSON Schema (tipos, enum, required, min/max). Tolerante: ignora propiedades extra.
	 *
	 * @return string[]
	 */
	public static function validate( array $args, array $schema ) {
		$errors = array();
		$props  = is_array( $schema['properties'] ) ? $schema['properties'] : array();
		foreach ( isset( $schema['required'] ) ? $schema['required'] : array() as $req ) {
			if ( ! isset( $args[ $req ] ) || '' === $args[ $req ] ) {
				$errors[] = "falta \"$req\"";
			}
		}
		foreach ( $args as $key => $value ) {
			if ( ! isset( $props[ $key ] ) || null === $value ) {
				continue;
			}
			$p = $props[ $key ];
			switch ( $p['type'] ) {
				case 'string':
					if ( ! is_scalar( $value ) ) {
						$errors[] = "\"$key\" debe ser texto";
					}
					break;
				case 'integer':
				case 'number':
					if ( ! is_numeric( $value ) ) {
						$errors[] = "\"$key\" debe ser numérico";
					} elseif ( ! in_array( $key, array( 'limite', 'cantidad' ), true ) ) {
						$too_low  = isset( $p['minimum'] ) && $value < $p['minimum'];
						$too_high = isset( $p['maximum'] ) && $value > $p['maximum'];
						if ( $too_low || $too_high ) {
							$errors[] = "\"$key\" fuera de rango";
						}
					}
					break;
			}
			if ( isset( $p['enum'] ) && is_scalar( $value ) && ! in_array( Text::fold( $value ), $p['enum'], true ) ) {
				if ( 'genero' !== $key || null === Query::normalize_gender( $value ) ) {
					$errors[] = "\"$key\" debe ser uno de: " . implode( ', ', $p['enum'] );
				}
			}
		}
		return $errors;
	}

	private static function coerce( array $args, array $schema ) {
		$props = is_array( $schema['properties'] ) ? $schema['properties'] : array();
		$out   = array();
		foreach ( $args as $key => $value ) {
			if ( ! isset( $props[ $key ] ) || null === $value || '' === $value ) {
				continue;
			}
			$type = $props[ $key ]['type'];
			if ( 'integer' === $type ) {
				$value = (int) $value;
				if ( isset( $props[ $key ]['maximum'] ) ) {
					$value = min( $value, (int) $props[ $key ]['maximum'] );
				}
			} elseif ( 'number' === $type ) {
				$value = (float) $value;
			} elseif ( 'string' === $type ) {
				$value = sanitize_text_field( (string) $value );
			}
			$out[ $key ] = $value;
		}
		return $out;
	}
}
