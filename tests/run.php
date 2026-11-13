<?php
/**
 * Tests sin WordPress: php tests/run.php
 * Cubre Text, Schedule_Parser y Catalog contra el snapshot real del sitio.
 */

define( 'HACOAJ_MCP_TESTING', true );
error_reporting( E_ALL );
ini_set( 'display_errors', '1' );
set_error_handler(
	function ( $no, $str, $file, $line ) {
		throw new ErrorException( $str, 0, $no, $file, $line );
	}
);

$root = dirname( __DIR__ );
require $root . '/includes/class-text.php';
require $root . '/includes/class-schedule-parser.php';
require $root . '/includes/class-catalog.php';

use HacoajMCP\Text;
use HacoajMCP\Schedule_Parser;
use HacoajMCP\Catalog;

$failures = 0;
$passes   = 0;

function check( $name, $actual, $expected ) {
	global $failures, $passes;
	if ( $actual === $expected ) {
		$passes++;
		return;
	}
	$failures++;
	fwrite( STDERR, "FAIL: $name\n  esperado: " . json_encode( $expected, JSON_UNESCAPED_UNICODE ) . "\n  obtenido: " . json_encode( $actual, JSON_UNESCAPED_UNICODE ) . "\n" );
}

function times( $line ) {
	return array_map(
		function ( $f ) {
			return $f['inicio'] . ( $f['fin'] ? '-' . $f['fin'] : '' );
		},
		Schedule_Parser::parse_times( $line )
	);
}

// ---------------------------------------------------------------- Text.
check( 'fold', Text::fold( 'Básquet Jardín ÑANDÚ' ), 'basquet jardin nandu' );
check( 'slugify', Text::slugify( 'Gimnasia Artística Adolescentes' ), 'gimnasia-artistica-adolescentes' );
check( 'tokens', Text::tokens( 'Clases de natación para chicos los sábados' ), array( 'natacion', 'chico', 'sabado' ) );
check( 'dias rango', Text::dias_from_text( 'Lunes a viernes de 18 a 21 h' ), array( 'lunes', 'martes', 'miercoles', 'jueves', 'viernes' ) );
check( 'dias lista', Text::dias_from_text( 'Lunes, miércoles y jueves de 20.30 a 22.30 h' ), array( 'lunes', 'miercoles', 'jueves' ) );
check( 'dias plural', Text::dias_from_text( 'Sábados o domingos de 14 a 15 h' ), array( 'sabado', 'domingo' ) );
$monday = new DateTimeImmutable( '2026-09-14 10:00' );
check( 'dia hoy', Text::parse_dias_input( 'hoy', $monday ), array( 'lunes' ) );
check( 'dia mañana', Text::parse_dias_input( 'mañana', $monday ), array( 'martes' ) );
check( 'dia domingo+1', Text::parse_dias_input( 'mañana', new DateTimeImmutable( '2026-09-20' ) ), array( 'lunes' ) );
check( 'finde', Text::parse_dias_input( 'fin de semana' ), array( 'sabado', 'domingo' ) );
check( 'dia acento', Text::parse_dias_input( array( 'Sábado', 'miércoles' ) ), array( 'sabado', 'miercoles' ) );

// ---------------------------------------------------------------- Parser: casos reales.
check( 'rango simple', times( '10 a 11 h | SUM Fitness Center | Diego Melamet' ), array( '10:00-11:00' ) );
check( 'rango minutos', times( '18 a 18.50 h | Sala de 4 años a adolescentes | Pileta de 25 m' ), array( '18:00-18:50' ) );
check( 'rango pegado', times( '10 a 11h | SUM (Fitness Center)' ), array( '10:00-11:00' ) );
check( 'rangos multiples', times( '10.30 a 11.30 h, 14.30 a 15.30 h y 15.30 a 16.30 h | Ampliación Gimnasio' ), array( '10:30-11:30', '14:30-15:30', '15:30-16:30' ) );
check( 'de ... a medianoche', times( 'De 21.30 a 00 h | SEA | Maxi Cabral' ), array( '21:30-00:00' ) );
check( 'h en ambos', times( '19 h a 20:30 h | Gimnasio de artística | Grupo 4' ), array( '19:00-20:30' ) );
check( 'sin h segmento puro', times( '15 a 17  | Sector verde Quinta Goldfeld' ), array( '15:00-17:00' ) );
check( 'sin h mixto', times( '15 a 16  y de 16 a 17h | GUM' ), array( '15:00-16:00', '16:00-17:00' ) );
check( 'minutos sin h', times( '17.30 a 19 | 2° subsuelo y 4° piso' ), array( '17:30-19:00' ) );
check( 'no edades', times( 'Edades de 11 a 13 años.' ), array() );
check( 'no grados', times( 'Martes y jueves Grupo 5 (1° a 6° grado)' ), array() );
check( 'no años categoría', times( 'Cat. 2017 a 2020: 13 h' ), array( '13:00' ) );
check( 'no 24 a 48 hs', times( 'Esperar la respuesta (24 a 48 hs) en la que les indicarán' ), array() );
check( 'lista h final', times( '11, 12 y 14 h clases gratuitas' ), array( '11:00', '12:00', '14:00' ) );
check( 'lista h cada una', times( 'Sport Cycle: 8.15 h, 9 h y 18 h (3 clases) | Sport Club' ), array( '08:15', '09:00', '18:00' ) );
check( 'minutos sueltos', times( 'Pilates Mix: 12.15 | Sport Club' ), array( '12:15' ) );
check( 'no precios', times( 'Socios: $39.900. No socios $46.500' ), array() );
check( 'no telefono', times( 'Reservas al 11 6972-2060 o starter@hacoajgolf.com.ar' ), array() );
check( 'transporte lista guiones', count( times( 'DE MARINAS H A CLUB DE CAMPO: 8.15 - 9.15 -10.15 - 11.15 - 13.15 - 14.15 - 15.15 - 16.15 - 17.15 h' ) ), 9 );
check( 'parada', times( '10.05 h: Córdoba 4589' ), array( '10:05' ) );
check( 'rango con texto previo', times( 'Los 3 primeros miércoles de cada mes de 15:30 a 17 h.' ), array( '15:30-17:00' ) );
check( 'rango meses', times( '18 meses a 2 años: 10 a 12.30 h | SEA' ), array( '10:00-12:30' ) );
check( 'turno ultimo ignorado', times( 'Turnos singles y dobles: 8.30 a 19 h (último turno 18 h)' ), array( '08:30-19:00' ) );

$p = Schedule_Parser::parse_detalle(
	'<strong>19 a 20.30 h</strong> | 9° piso Gascón | Julie Zlotogwiazda' . "\r\n" . 'Socios sin cargo. No socios: $ 25.100 por mes.' . "\r\n" . '<div>Se requiere Inscripción</div><div><a href="https://forms.gle/NZwqMA9nMmtaYtR19">https://forms.gle/NZwqMA9nMmtaYtR19</a></div>'
);
check( 'detalle horarios', count( $p['horarios'] ), 1 );
check( 'detalle notas', $p['notas'][0], 'Socios sin cargo. No socios: $ 25.100 por mes.' );
check( 'detalle inscripcion', $p['requiere_inscripcion'], true );
check( 'detalle link', $p['links'], array( 'https://forms.gle/NZwqMA9nMmtaYtR19' ) );
check( 'detalle precio', $p['precios'], array( '$ 25.100' ) );
$p = Schedule_Parser::parse_detalle( '', array( 'hora_inicio' => '10:00:00', 'hora_fin' => '20:00:00', 'profe' => 'Beit' ) );
check( 'acf fallback', $p['horarios'][0]['franjas'][0], array( 'inicio' => '10:00', 'fin' => '20:00' ) );
check( 'acf profe', $p['profe'], 'Beit' );

// ---------------------------------------------------------------- Parser: cobertura sobre el snapshot real.
$snap     = json_decode( file_get_contents( __DIR__ . '/fixtures/wp-snapshot.json' ), true );
$total    = 0;
$con_hora = 0;
$sin      = array();
foreach ( $snap['agenda_item'] as $item ) {
	$detalle = isset( $item['meta']['detalle'] ) ? $item['meta']['detalle'] : '';
	if ( '' === trim( strip_tags( $detalle ) ) ) {
		continue;
	}
	$total++;
	$parsed = Schedule_Parser::parse_detalle( $detalle, $item['meta'] );
	foreach ( $parsed['horarios'] as $h ) {
		foreach ( $h['franjas'] as $f ) {
			if ( ! preg_match( '/^\d{2}:\d{2}$/', $f['inicio'] ) || ( null !== $f['fin'] && ! preg_match( '/^\d{2}:\d{2}$/', $f['fin'] ) ) ) {
				check( 'formato franja ' . $item['id'], $f, 'HH:MM' );
			}
		}
	}
	if ( $parsed['horarios'] ) {
		$con_hora++;
	} else {
		$sin[] = $item['id'] . ' ' . $item['title'];
	}
}
$coverage = $total ? $con_hora / $total : 0;
printf( "Cobertura parser: %d/%d items con horario estructurado (%.1f%%)\n", $con_hora, $total, $coverage * 100 );
if ( getenv( 'VERBOSE' ) ) {
	echo "Sin horario detectado:\n  " . implode( "\n  ", $sin ) . "\n";
}
check( 'cobertura >= 90%', $coverage >= 0.9, true );

// ---------------------------------------------------------------- Catalog.
$catalog = Catalog::from_file( $root . '/data/catalog.json' );
check( 'catalog sedes', count( $catalog->sedes() ), 5 );
check( 'catalog sede alias caba', $catalog->resolve_sede( 'Capital' ), 'ben-gurion' );
check( 'catalog sede alias tigre', $catalog->resolve_sede( 'tigre' ), 'tigre-maliar' );
check( 'catalog sede slug', $catalog->resolve_sede( 'club-de-campo' ), 'club-de-campo' );
check( 'catalog sede desconocida', $catalog->resolve_sede( 'Rosario' ), null );
check( 'catalog golf meta', $catalog->activity_meta( 'golf-escuela-deportiva' )['edad_max'], 12 );
check( 'catalog sin duplicados', count( array_unique( array_keys( $catalog->raw()['actividades'] ) ) ), count( $catalog->raw()['actividades'] ) );
check( 'edad 8 en 4-12', Catalog::age_matches( array( 'edad_min' => 4, 'edad_max' => 12 ), 8 ), true );
check( 'edad 13 fuera 4-12', Catalog::age_matches( array( 'edad_min' => 4, 'edad_max' => 12 ), 13 ), false );
check( 'edad 12.5 dentro 4-12', Catalog::age_matches( array( 'edad_min' => 4, 'edad_max' => 12 ), 12.5 ), true );
check( 'edad meses olami', Catalog::age_matches( array( 'edad_min_meses' => 6, 'edad_max_meses' => 36 ), 0.25 ), false );
check( 'edad meses olami ok', Catalog::age_matches( array( 'edad_min_meses' => 6, 'edad_max_meses' => 36 ), 2 ), true );
check( 'edad sin datos', Catalog::age_matches( array(), 30 ), null );
check( 'edad solo minimo', Catalog::age_matches( array( 'edad_min' => 18 ), 70 ), true );
check( 'genero mixto acepta femenino', Catalog::gender_matches( 'mixto', 'femenino' ), true );
check( 'genero masculino no acepta femenino', Catalog::gender_matches( 'masculino', 'femenino' ), false );
$fed = $catalog->federados_with_ages( 2026 );
$f15 = null;
foreach ( $fed as $f ) {
	if ( 'futbol-categoria-2015' === $f['slug'] ) {
		$f15 = $f;
	}
}
check( 'federado categoria año -> edad dinámica', array( $f15['edad_min'], $f15['edad_max'] ), array( 11, 11 ) );
check( 'federado 2027', $catalog->federados_with_ages( 2027 )[ array_search( $f15, $fed, true ) ]['edad_min'], 12 );

// ---------------------------------------------------------------- Vigencia.
check( 'vigencia enero', Schedule_Parser::detect_vigencia( 'Sin horarios durante enero.' ), array( 'sin_horarios_mes' => 1 ) );
check( 'vigencia comienza', Schedule_Parser::detect_vigencia( 'Esta actividad comienza en la semana del 2 de marzo 2026.' ), array( 'comienza' => '2026-03-02' ) );
$sept = new DateTimeImmutable( '2026-09-15 10:00' );
check( 'stale enero en septiembre', Schedule_Parser::is_stale( array( 'vigencia' => array( 'sin_horarios_mes' => 1 ), 'horarios' => array() ), $sept ), true );
check( 'no stale enero en enero', Schedule_Parser::is_stale( array( 'vigencia' => array( 'sin_horarios_mes' => 1 ), 'horarios' => array() ), new DateTimeImmutable( '2027-01-10' ) ), false );

// ---------------------------------------------------------------- Index + Query sobre el snapshot real.
require $root . '/includes/class-index-builder.php';
require $root . '/includes/class-query.php';
use HacoajMCP\Index_Builder;
use HacoajMCP\Query;

$t0    = microtime( true );
$index = Index_Builder::build( $snap, $catalog, 'https://hacoaj.org.ar' );
printf( "Índice: %d actividades (%d con agenda), %d items, %.0f ms, %d KB serializado\n",
	$index['stats']['actividades'], $index['stats']['actividades_con_agenda'], $index['stats']['items_actividad'],
	( microtime( true ) - $t0 ) * 1000, strlen( serialize( $index ) ) / 1024 );
if ( getenv( 'VERBOSE' ) ) {
	echo 'Sin catálogo: ' . implode( ', ', $index['stats']['actividades_sin_catalogo'] ) . "\n";
}
check( 'temporada sin agenda_version usa fecha', $index['stats']['criterio_temporada'], 'fecha' );

// Temporadas por agenda_version: mismo criterio que la web (sin valor válido = regular).
$season_snap                = $snap;
$season_snap['agenda_item'] = array();
foreach ( array( 'verano', 'regular', '', 'Verano ', 'otra' ) as $i => $version ) {
	$season_snap['agenda_item'][] = array(
		'id'        => 900 + $i,
		'title'     => 'Golf ' . $i,
		'slug'      => 'golf-' . $i,
		'date'      => '2026-01-10 10:00:00',
		'actividad' => array( 'golf-clases' ),
		'sede'      => array(),
		'meta'      => array( 'detalle' => '10 a 11 h', 'agenda_version' => $version ),
	);
}
$season_ids = function ( $options ) use ( $season_snap, $catalog ) {
	$idx = Index_Builder::build( $season_snap, $catalog, 'https://hacoaj.org.ar', $options );
	return array( array_keys( $idx['items'] ), $idx['stats']['items_excluidos_temporada'], $idx['stats']['criterio_temporada'] );
};
check( 'temporada regular', $season_ids( array( 'temporada' => 'regular' ) ), array( array( 901, 902, 904 ), 2, 'agenda_version' ) );
check( 'temporada verano', $season_ids( array( 'temporada' => 'verano' ) ), array( array( 900, 903 ), 3, 'agenda_version' ) );
check( 'temporada ignora fecha', $season_ids( array( 'temporada' => 'regular', 'publicados_desde' => '2026-02-01' ) )[0], array( 901, 902, 904 ) );
check( 'temporada todas', $season_ids( array( 'temporada' => 'todas' ) ), array( array( 900, 901, 902, 903, 904 ), 0, null ) );
check( 'respaldo fecha', $season_ids( array() ), array( array(), 5, 'fecha' ) );
check( 'respaldo fecha off', $season_ids( array( 'publicados_desde' => null ) ), array( array( 900, 901, 902, 903, 904 ), 0, null ) );

$A = $index['actividades'];
check( 'body-power categoria', $A['body-power']['categoria'], 'deportes-adultos' );
check( 'body-power sin ACF no trae edad', array( isset( $A['body-power']['edad_min'] ), $A['body-power']['fuente_edad'] ), array( false, null ) );
check( 'gimnasio de musculación cat', $A['gimnasio-de-musculacion']['categoria'], 'deportes-adultos' );
check( 'natación hojas', in_array( 6050, $A['natacion-pileta-primaria']['items'], true ), true );
check( 'categoría no es actividad', isset( $A['natacion-pileta'] ), false );
check( 'titulo matchea hijo', in_array( 4373, $A['gimnasia-artistica-adolescentes']['items'], true ), true );
check( 'transporte fuera de actividades', isset( $A['capital-tigre-maliar'] ), false );
check( 'transporte especial', count( $index['especiales']['transporte'] ) >= 2, true );
check( 'catálogo sin término no crea actividad', isset( $A['newcom-adultas'] ), false );
check( 'programa-magal sin término no crea actividad', isset( $A['programa-magal'] ), false );
check( 'stats lista las que sólo están en el catálogo', array( in_array( 'newcom-adultas', $index['stats']['actividades_solo_catalogo'], true ), in_array( 'golf-principiantes', $index['stats']['actividades_solo_catalogo'], true ) ), array( true, false ) );
check( 'nada del índice sale de un slug que no está en WP', array_values( array_diff( array_keys( $A ), array_column( $snap['actividad'], 'slug' ) ) ), array() );
check( 'golf-principiantes sin agenda', $A['golf-principiantes']['tiene_agenda'], false );
check( 'olami sedes', $A['olami']['sedes'], array( 'club-de-campo', 'tigre-maliar' ) );
check( 'escuelas CdC nombre', $index['categorias']['escuelas-deportivas-club-de-campo']['nombre'], 'Escuelas Deportivas (Club de Campo)' );

// edad_min, edad_max, genero y grupo salen EXCLUSIVAMENTE del ACF de WordPress: sin catalog.json
// como respaldo. catalog.json solo sigue aportando lo que no tiene equivalente en ACF (aliases, base, etc.).
$acf_snap = $snap;
foreach ( $acf_snap['actividad'] as &$t ) {
	if ( 'golf-escuela-deportiva' === $t['slug'] ) {
		$t['edad_min'] = 99;
		$t['edad_max'] = 100;
		$t['grupo']    = 'generales';
	}
}
unset( $t );
$acf_index = Index_Builder::build( $acf_snap, $catalog, 'https://hacoaj.org.ar' );
$acf_a     = $acf_index['actividades']['golf-escuela-deportiva'];
check( 'ACF es la fuente: edad_min', $acf_a['edad_min'], 99 );
check( 'ACF es la fuente: edad_max', $acf_a['edad_max'], 100 );
check( 'ACF es la fuente: grupo', $acf_a['grupo'], 'generales' );
check( 'ACF es la fuente: fuente_edad', $acf_a['fuente_edad'], 'wordpress' );
$acf_q       = new Query( $acf_index, $catalog, $sept );
$acf_summary = $acf_q->activity_summary( $acf_a );
check( 'buscar_actividades expone fuente_metadata wordpress', $acf_summary['fuente_metadata'], 'wordpress' );
// Sin ACF, catalog.json ya NO se usa de respaldo: el campo directamente no aparece.
check( 'sin ACF no trae edad_max', isset( $A['golf-escuela-deportiva']['edad_max'] ), false );
check( 'sin ACF fuente_edad null', $A['golf-escuela-deportiva']['fuente_edad'], null );
check( 'sin ACF no expone fuente_metadata', isset( ( new Query( $index, $catalog, $sept ) )->activity_summary( $A['golf-escuela-deportiva'] )['fuente_metadata'] ), false );

// Si el nombre dice el género y el ACF lo deja mixto o vacío, manda el nombre (batería de Pablo, F05:
// "Cesto Adultas" cargada como mixta le salía a un hombre). El CSV sigue marcando el dato a corregir.
$gen_snap = $snap;
foreach ( $gen_snap['actividad'] as &$t ) {
	if ( 'golf-escuela-deportiva' === $t['slug'] ) {
		$t['name']   = 'Golf Adultas';
		$t['genero'] = 'mixto';
	}
	if ( 'golf-clases' === $t['slug'] ) {
		$t['name'] = 'Golf Caballeros';
	}
	if ( 'golf-salidas' === $t['slug'] ) {
		$t['name']   = 'Golf Damas';
		$t['genero'] = 'masculino';
	}
}
unset( $t );
$gen_index = Index_Builder::build( $gen_snap, $catalog, 'https://hacoaj.org.ar' );
$gen_a     = $gen_index['actividades'];
check( 'género del nombre: mixto → femenino', array( $gen_a['golf-escuela-deportiva']['genero'], $gen_a['golf-escuela-deportiva']['genero_acf'] ), array( 'femenino', 'mixto' ) );
check( 'género del nombre: vacío → masculino', array( $gen_a['golf-clases']['genero'], $gen_a['golf-clases']['genero_acf'] ), array( 'masculino', null ) );
check( 'género del nombre: no pisa un género cargado', array( $gen_a['golf-salidas']['genero'], isset( $gen_a['golf-salidas']['genero_acf'] ) ), array( 'masculino', false ) );
check( 'nombre sin género no cambia', isset( $gen_a['golf-para-chicos']['genero'] ), false );
$gen_q = new Query( $gen_index, $catalog, $sept );
$r     = $gen_q->buscar_actividades( array( 'texto' => 'golf', 'genero' => 'masculino' ) );
check( 'Golf Adultas no le sale a un hombre', in_array( 'golf-escuela-deportiva', array_column( $r['actividades'], 'slug' ), true ), false );
$csv = $gen_q->actividades_csv();
check( 'CSV marca el género mixto que contradice el nombre', false !== strpos( $csv, 'El nombre indica femenino y el género no' ), true );
check( 'CSV muestra el género cargado en WordPress, no el del nombre', (bool) preg_match( '/^"?Golf Adultas"?;[^\n]*;mixto;/mu', $csv ), true );
check( 'respuesta no expone genero_acf', array_key_exists( 'genero_acf', $gen_q->activity_summary( $gen_a['golf-escuela-deportiva'] ) ), false );
check( 'CSV marca el género vacío aunque lo tome del nombre', (bool) preg_match( '/^"?Golf Caballeros"?;.*Sin género/mu', $csv ), true );

$q = new Query( $index, $catalog, $sept );

$r     = $q->buscar_actividades( array( 'texto' => 'golf' ) );
$slugs = array_map( function ( $a ) {
	return $a['slug'];
}, $r['actividades'] );
sort( $slugs );
check( 'buscar golf', $slugs, array( 'golf-clases', 'golf-escuela-deportiva', 'golf-para-chicos', 'golf-principiantes', 'golf-salidas' ) );

// "más": con desde=siguiente_desde salen solo las que faltan, sin repetir (batería de Pablo, B11).
$p1 = $q->buscar_actividades( array( 'texto' => 'golf', 'limite' => 2 ) );
$p2 = $q->buscar_actividades( array( 'texto' => 'golf', 'limite' => 2, 'desde' => $p1['siguiente_desde'] ) );
$p3 = $q->buscar_actividades( array( 'texto' => 'golf', 'limite' => 2, 'desde' => $p2['siguiente_desde'] ) );
$pag = array_merge( array_column( $p1['actividades'], 'slug' ), array_column( $p2['actividades'], 'slug' ), array_column( $p3['actividades'], 'slug' ) );
sort( $pag );
check( 'paginación: siguiente_desde', array( $p1['siguiente_desde'], $p2['siguiente_desde'], isset( $p3['siguiente_desde'] ) ), array( 2, 4, false ) );
check( 'paginación: recorre todo sin repetir', $pag, $slugs );
check( 'paginación: la última página no avisa que quedan', isset( $p3['aviso'] ), false );
check( 'paginación: aviso dice cómo pedir más', false !== strpos( $p1['aviso'], 'desde=2' ), true );
$p4 = $q->buscar_actividades( array( 'texto' => 'golf', 'limite' => 2, 'desde' => 100 ) );
check( 'paginación: pasado el final avisa que no quedan', array( $p4['actividades'], false !== strpos( $p4['aviso'], 'no quedan más' ) ), array( array(), true ) );
$r = $q->buscar_actividades( array( 'categoria' => 'hadraja', 'limite' => 5 ) );
check( 'paginación: categoría corta sale entera sin siguiente_desde', isset( $r['siguiente_desde'] ), false );
check( 'género del nombre: los dos géneros o el juego de damas → null', array_map( array( 'HacoajMCP\\Index_Builder', 'gender_from_name' ), array( 'Tango Damas y Caballeros', 'Gimnasia Mujeres y Varones', 'Ajedrez y Damas', 'Taller de Damas', 'Hockey Damas', 'Futbol Recreativo Femenino Adultas' ) ), array( null, null, null, null, 'femenino', 'femenino' ) );

// Sin ACF cargado, golf no tiene edad: el filtro por edad las excluye a todas (no hay respaldo de catalog.json).
$r     = $q->buscar_actividades( array( 'texto' => 'golf', 'edad' => 8 ) );
$slugs = array_map( function ( $a ) {
	return $a['slug'];
}, $r['actividades'] );
check( 'golf 8 años sin ACF: sin resultados', $slugs, array() );
check( 'golf 8 años sin ACF: sin_datos_de_edad', $r['sin_datos_de_edad']['cantidad'], 5 );

$r = $q->buscar_actividades( array( 'texto' => 'basket', 'sede' => 'capital' ) );
check( 'sinónimo basket en CABA', count( $r['actividades'] ) > 0 && ! array_filter( $r['actividades'], function ( $a ) {
	return ! in_array( 'Ben Gurión', $a['sedes'], true );
} ), true );

// Ídem natación, hadrajá y fútbol femenino: sin ACF cargado para esas actividades, un filtro por
// edad las deja afuera hasta que se migren (comportamiento buscado: nada de catalog.json de respaldo).
$r = $q->buscar_actividades( array( 'texto' => 'natacion', 'edad' => 5, 'sede' => 'tigre' ) );
check( 'natación 5 años tigre sin ACF: sin resultados', count( $r['actividades'] ), 0 );
check( 'natación 5 años tigre sin ACF: sin_datos_de_edad', $r['sin_datos_de_edad']['cantidad'] > 0, true );

$r = $q->buscar_actividades( array( 'categoria' => 'hadraja' ) );
check( 'hadrajá sin filtro de edad sigue apareciendo', in_array( 'kesher', array_column( $r['actividades'], 'slug' ), true ), true );
$r = $q->buscar_actividades( array( 'categoria' => 'hadraja', 'edad' => 7 ) );
check( 'hadrajá 7 años sin ACF: sin resultados', $r['actividades'], array() );

$r = $q->buscar_actividades( array( 'texto' => 'futbol', 'genero' => 'nena', 'edad' => 7 ) );
check( 'fútbol nena 7 sin ACF: sin resultados', $r['actividades'], array() );

$r = $q->obtener_agenda( array( 'actividad' => 'golf-clases' ) );
check( 'agenda golf-clases', $r['total'], 1 );
check( 'agenda golf-clases franjas', count( $r['items'][0]['horarios'][0]['franjas'] ), 3 );
check( 'agenda golf-clases telefono', $r['items'][0]['telefonos'], array( '11 6972-2060' ) );

$r = $q->obtener_agenda( array( 'sede' => 'club de campo', 'dia' => 'domingo', 'limite' => 80 ) );
check( 'agenda CdC domingo', $r['total'] > 20, true );
check( 'agenda CdC domingo solo domingo', ! array_filter( $r['items'], function ( $i ) {
	return ! in_array( 'Domingo', $i['dias'], true );
} ), true );

$r = $q->obtener_agenda( array( 'texto' => 'voley', 'sede' => 'tigre' ) );
check( 'agenda sin avisos vencidos', array_filter( $r['items'], function ( $i ) {
	return isset( $i['notas'] ) && false !== strpos( implode( ' ', $i['notas'] ), 'enero' );
} ), array() );

$r = $q->obtener_agenda( array( 'sede' => 'tigre', 'dia' => 'hoy', 'desde_hora' => '19', 'hasta_hora' => '20' ) );
check( 'agenda hoy franja', $r['total'] > 0 && isset( $r['fecha_referencia'] ), true );

check( 'agenda sin filtros', isset( $q->obtener_agenda( array() )['error'] ), true );
check( 'agenda sede inválida', isset( $q->obtener_agenda( array( 'sede' => 'Rosario' ) )['error'] ), true );

// deportes_federados() ahora lee grupo=federadas del ACF de WordPress (índice), no catalog.json.
// Se arma un snapshot sintético con una categoría por banda etaria fija (básquet) y otra por año
// de nacimiento (fútbol, como las reales), cada una con su término padre (deporte) e hijo (categoría).
$fed_snap                = $snap;
$fed_snap['actividad'][] = array( 'id' => 9001, 'name' => 'Básquet Federado', 'slug' => 'basquet-federado', 'parent' => 0, 'description' => '' );
$fed_snap['actividad'][] = array(
	'id' => 9002, 'name' => 'Mosquitos', 'slug' => 'basquet-mosquitos', 'parent' => 9001, 'description' => '',
	'grupo' => 'federadas', 'genero' => 'masculino', 'edad_min' => 4, 'edad_max' => 7,
);
$fed_snap['actividad'][] = array( 'id' => 9003, 'name' => 'Fútbol Federado', 'slug' => 'futbol-federado-test', 'parent' => 0, 'description' => '' );
$fed_snap['actividad'][] = array(
	'id' => 9004, 'name' => 'Categoría 2015', 'slug' => 'futbol-categoria-2015-test', 'parent' => 9003, 'description' => '',
	'grupo' => 'federadas', 'genero' => 'masculino', 'anio_nacimiento_min' => 2015, 'anio_nacimiento_max' => 2015,
);
$fed_snap['agenda_item'][] = array(
	'id' => 9101, 'title' => 'Básquet Mosquitos', 'slug' => 'basquet-mosquitos-agenda', 'date' => '2026-03-01 10:00:00',
	'actividad' => array( 'basquet-mosquitos' ), 'sede' => array( 'tigre-maliar' ),
	'meta' => array( 'detalle' => 'Martes y jueves de 17.30 a 19.30 h' ),
);
$fed_snap['agenda_item'][] = array(
	'id' => 9102, 'title' => 'Fútbol Categoría 2015', 'slug' => 'futbol-cat-2015-agenda', 'date' => '2026-03-01 10:00:00',
	'actividad' => array( 'futbol-categoria-2015-test' ), 'sede' => array( 'club-de-campo' ),
	'meta' => array( 'detalle' => 'Lunes y miércoles de 18 a 20 h' ),
);
// Categoría con ACF completo (tipo_categoria=hija) pero SIN ningún agenda_item todavía: debe
// aparecer igual (tiene_agenda=false), no quedar invisible hasta que carguen un horario.
$fed_snap['actividad'][] = array( 'id' => 9005, 'name' => 'Vóley Federado', 'slug' => 'voley-federado-test', 'parent' => 0, 'description' => '' );
$fed_snap['actividad'][] = array(
	'id' => 9006, 'name' => 'Inferiores Caballeros Sub 11', 'slug' => 'voley-inferiores-sub11-test', 'parent' => 9005, 'description' => '',
	'grupo' => 'federadas', 'genero' => 'masculino', 'edad_max' => 11, 'tipo_categoria' => 'hija',
);
$fed_index = Index_Builder::build( $fed_snap, $catalog, 'https://hacoaj.org.ar' );
$fed_q     = new Query( $fed_index, $catalog, $sept );

$r = $fed_q->deportes_federados( array( 'deporte' => 'basquet' ) );
check( 'federada por edad fija: categoria', array_column( $r['categorias'], 'categoria' ), array( 'Mosquitos (caballeros)' ) );
check( 'federada por edad fija: edades', $r['categorias'][0]['edades'], '4 a 7 años' );

$r = $fed_q->deportes_federados( array( 'deporte' => 'futbol', 'anio_nacimiento' => 2015 ) );
check( 'federada por año de nacimiento: categoria', array_column( $r['categorias'], 'categoria' ), array( 'Categoría 2015 (caballeros)' ) );
check( 'federada por año de nacimiento: edad recalculada (2026-2015)', $r['categorias'][0]['edades'], '11 años' );

// Las federadas no se duplican en buscar_actividades/obtener_agenda (esas usan deportes_federados).
$r = $fed_q->buscar_actividades( array( 'texto' => 'basquet' ) );
check( 'federada no aparece en buscar_actividades', in_array( 'basquet-mosquitos', array_column( $r['actividades'], 'slug' ), true ), false );

// tipo_categoria=hija sin ningún agenda_item: igual entra al índice (tiene_agenda=false), no
// queda invisible hasta que carguen un horario.
check( 'categoria sin agenda entra al indice', isset( $fed_index['actividades']['voley-inferiores-sub11-test'] ), true );
check( 'categoria sin agenda: tiene_agenda false', $fed_index['actividades']['voley-inferiores-sub11-test']['tiene_agenda'], false );
$r = $fed_q->deportes_federados( array( 'deporte' => 'voley' ) );
check( 'federada sin agenda aparece en deportes_federados', array_column( $r['categorias'], 'categoria' ), array( 'Inferiores Caballeros Sub 11' ) );
check( 'federada sin agenda: sin url', isset( $r['categorias'][0]['url'] ), false );
// Un término "padre" (sin tipo_categoria=hija) nunca se cuela como actividad aunque no tenga agenda.
check( 'termino padre no se cuela como actividad', isset( $fed_index['actividades']['voley-federado-test'] ), false );

// Federadas ordenadas en origen: deporte, género (caballeros, damas), edad y nombre. Antes salían
// por slug alfabético y el bot mostraba damas y caballeros intercalados (pruebas de Diego 15/10).
$ord_snap                = $snap;
$ord_snap['actividad'][] = array( 'id' => 9201, 'name' => 'Básquet Federado', 'slug' => 'bf-test', 'parent' => 0, 'description' => '' );
foreach ( array(
	array( 9202, 'Cadetas femenino', 'bf-cadetas-femenino', 'femenino', 14, 15 ),
	array( 9203, 'Cadete Azul masculino', 'bf-cadete-azul-masculino', 'masculino', 14, 15 ),
	array( 9204, 'Infantil femenino', 'bf-infantil-femenino', 'femenino', 12, 13 ),
	array( 9205, 'Infantil Azul masculino', 'bf-infantil-azul-masculino', 'masculino', 12, 13 ),
	array( 9206, 'Mosquitos masculino', 'bf-mosquitos-masculino', 'masculino', 4, 7 ),
) as $c ) {
	$ord_snap['actividad'][] = array(
		'id' => $c[0], 'name' => $c[1], 'slug' => $c[2], 'parent' => 9201, 'description' => '', 'tipo_categoria' => 'hija',
		'grupo' => 'federadas', 'genero' => $c[3], 'edad_min' => $c[4], 'edad_max' => $c[5],
	);
}
// Grupo mal cargado en WP ("f" en vez de "federadas", como Hockey 8va división A): se descarta.
$ord_snap['actividad'][] = array(
	'id' => 9207, 'name' => 'Hockey federado 8va división A', 'slug' => 'hockey-8va-a-test', 'parent' => 0, 'description' => '', 'tipo_categoria' => 'hija',
	'grupo' => 'f', 'genero' => 'femenino', 'edad_min' => 10, 'edad_max' => 11,
);
$ord_snap['agenda_item'][] = array(
	'id' => 9301, 'title' => 'Hockey 8va A', 'slug' => 'hockey-8va-a-agenda', 'date' => '2026-03-01 10:00:00',
	'actividad' => array( 'hockey-8va-a-test' ), 'sede' => array( 'club-de-campo' ),
	'meta' => array( 'detalle' => '10 a 12 h', 'dias' => array( 'domingo' ) ),
);
// Variantes de mayúsculas/tildes de un grupo válido se normalizan, no se descartan.
$ord_snap['actividad'][] = array(
	'id' => 9208, 'name' => 'Grupo Hadrajá Test', 'slug' => 'hadraja-test', 'parent' => 0, 'description' => '', 'tipo_categoria' => 'hija',
	'grupo' => ' Hadrajá ', 'edad_min' => 8, 'edad_max' => 12,
);
$ord_index = Index_Builder::build( $ord_snap, $catalog, 'https://hacoaj.org.ar' );
$ord_q     = new Query( $ord_index, $catalog, $sept );
$r = $ord_q->deportes_federados( array( 'deporte' => 'basquet' ) );
check(
	'federadas ordenadas por género y edad',
	array_values( array_filter( array_column( $r['categorias'], 'categoria' ), function ( $c ) {
		return in_array( $c, array( 'Cadetas femenino', 'Cadete Azul masculino', 'Infantil femenino', 'Infantil Azul masculino', 'Mosquitos masculino' ), true );
	} ) ),
	array( 'Mosquitos masculino', 'Infantil Azul masculino', 'Cadete Azul masculino', 'Infantil femenino', 'Cadetas femenino' )
);
check( 'grupo inválido: marcado en el índice', $ord_index['actividades']['hockey-8va-a-test']['grupo_invalido'], 'f' );
check( 'grupo inválido: sin grupo', isset( $ord_index['actividades']['hockey-8va-a-test']['grupo'] ), false );
check( 'grupo inválido: en stats', $ord_index['stats']['actividades_grupo_invalido'], array( 'hockey-8va-a-test' => 'f' ) );
$r = $ord_q->buscar_actividades( array( 'texto' => 'hockey' ) );
check( 'grupo inválido: no aparece en buscar_actividades', in_array( 'hockey-8va-a-test', array_column( $r['actividades'], 'slug' ), true ), false );
$r = $ord_q->obtener_agenda( array( 'actividad' => 'hockey-8va-a-test' ) );
check( 'grupo inválido: obtener_agenda por slug no devuelve items', $r['total'], 0 );
$r = $ord_q->obtener_agenda( array( 'sede' => 'club-de-campo', 'dia' => 'domingo', 'limite' => 80 ) );
check( 'grupo inválido: obtener_agenda por sede no muestra su item', in_array( 'Hockey 8va A', array_column( $r['items'], 'titulo' ), true ), false );
$r = $ord_q->deportes_federados( array( 'deporte' => 'hockey' ) );
check( 'grupo inválido: no aparece en deportes_federados', in_array( 'Hockey federado 8va división A', array_column( $r['categorias'], 'categoria' ), true ), false );
check( 'grupo normalizado (mayúsculas/tildes)', $ord_index['actividades']['hadraja-test']['grupo'], 'hadraja' );
check( 'normalize_grupo válido', Index_Builder::normalize_grupo( 'Federadas' ), 'federadas' );
check( 'normalize_grupo ACF select', Index_Builder::normalize_grupo( array( 'value' => 'escuelas', 'label' => 'Escuelas' ) ), 'escuelas' );
check( 'normalize_grupo inválido', Index_Builder::normalize_grupo( 'f' ), null );

// "Mi hijo"/"mi hija" es género: antes "fútbol para mi hijo de 18" devolvía categorías femeninas.
check( 'normalize_gender hijo', Query::normalize_gender( 'Hijo' ), 'masculino' );
check( 'normalize_gender hija', Query::normalize_gender( 'hija' ), 'femenino' );
check( 'gender_from_text hijo', Query::gender_from_text( 'fútbol para mi hijo de 18' ), array( 'masculino', 'hijo' ) );
check( 'gender_from_text hija', Query::gender_from_text( 'básquet, mi HIJA tiene 13' ), array( 'femenino', 'hija' ) );
check( 'gender_from_text plural no infiere', Query::gender_from_text( 'algo para mis hijos' ), null );
$r = $ord_q->deportes_federados( array( 'deporte' => 'basquet mi hija' ) );
check(
	'federadas: "mi hija" filtra damas',
	array_values( array_unique( array_column( $r['categorias'], 'genero' ) ) ),
	array( 'femenino' )
);
check( 'federadas: genero_inferido', $r['genero_inferido'], 'femenino (por "hija" en el texto)' );
$gen_snap = $snap;
foreach ( array( array( 9501, 'Fútbol Recreativo Test Damas', 'femenino' ), array( 9502, 'Fútbol Recreativo Test Caballeros', 'masculino' ) ) as $c ) {
	$gen_snap['actividad'][] = array(
		'id' => $c[0], 'name' => $c[1], 'slug' => Text::slugify( $c[1] ), 'parent' => 0, 'description' => '', 'tipo_categoria' => 'hija',
		'grupo' => 'generales', 'genero' => $c[2], 'edad_min' => 18,
	);
}
$gen_q = new Query( Index_Builder::build( $gen_snap, $catalog, 'https://hacoaj.org.ar' ), $catalog, $sept );
$r     = $gen_q->buscar_actividades( array( 'texto' => 'futbol recreativo test para mi hijo', 'limite' => 50 ) );
check( 'buscar_actividades: "mi hijo" excluye femeninas', array_values( array_intersect( array( 'futbol-recreativo-test-damas', 'futbol-recreativo-test-caballeros' ), array_column( $r['actividades'], 'slug' ) ) ), array( 'futbol-recreativo-test-caballeros' ) );
check( 'buscar_actividades: genero_inferido', $r['genero_inferido'], 'masculino (por "hijo" en el texto)' );
$r = $gen_q->buscar_actividades( array( 'texto' => 'futbol recreativo test', 'limite' => 50 ) );
check( 'buscar_actividades: sin "hijo" trae ambos', count( array_intersect( array( 'futbol-recreativo-test-damas', 'futbol-recreativo-test-caballeros' ), array_column( $r['actividades'], 'slug' ) ) ), 2 );

// Sin filtro de género el límite se reparte entre caballeros y damas: con el orden por género y
// un límite fijo el bot mostraba sólo caballeros (pruebas de Mica, 22/10).
$bal_snap                = $snap;
$bal_snap['actividad'][] = array( 'id' => 9401, 'name' => 'Básquet Federado', 'slug' => 'bb-test', 'parent' => 0, 'description' => '' );
for ( $i = 0; $i < 10; $i++ ) {
	$bal_snap['actividad'][] = array(
		'id' => 9410 + $i, 'name' => "Masc $i", 'slug' => "bb-masc-$i", 'parent' => 9401, 'description' => '', 'tipo_categoria' => 'hija',
		'grupo' => 'federadas', 'genero' => 'masculino', 'edad_min' => 5 + $i, 'edad_max' => 6 + $i,
	);
}
for ( $i = 0; $i < 3; $i++ ) {
	$bal_snap['actividad'][] = array(
		'id' => 9430 + $i, 'name' => "Fem $i", 'slug' => "bb-fem-$i", 'parent' => 9401, 'description' => '', 'tipo_categoria' => 'hija',
		'grupo' => 'federadas', 'genero' => 'femenino', 'edad_min' => 5 + $i, 'edad_max' => 6 + $i,
	);
}
$bal_q = new Query( Index_Builder::build( $bal_snap, $catalog, 'https://hacoaj.org.ar' ), $catalog, $sept );
$r     = $bal_q->deportes_federados( array( 'deporte' => 'basquet', 'limite' => 6 ) );
check(
	'federadas balanceadas: cupo repartido y agrupado por género',
	array_column( $r['categorias'], 'categoria' ),
	array( 'Masc 0 (caballeros)', 'Masc 1 (caballeros)', 'Masc 2 (caballeros)', 'Fem 0 (damas)', 'Fem 1 (damas)', 'Fem 2 (damas)' )
);
check( 'federadas balanceadas: total_por_genero', $r['total_por_genero'], array( 'masculino' => 10, 'femenino' => 3 ) );
// Más de 8 categorías: el aviso pide resumir (total + ejemplos de los dos géneros), no listar.
// El aviso viejo decía "mostrá estas" y el bot listaba 12 de 22 (pruebas del 23/10).
check( 'federadas resumen: aviso con total por género', false !== strpos( $r['aviso'], 'Son 13 categorías (10 masculino, 3 femenino). No las listes' ), true );
check( 'federadas resumen: el aviso ya no dice "mostrá estas"', false === strpos( $r['aviso'], 'mostrá estas' ), true );
check(
	'federadas resumen: ejemplos repartidos por género',
	$r['ejemplos_por_genero'],
	array( 'masculino' => array( 'Masc 0 (caballeros)', 'Masc 5 (caballeros)', 'Masc 9 (caballeros)' ), 'femenino' => array( 'Fem 0 (damas)', 'Fem 1 (damas)', 'Fem 2 (damas)' ) )
);
$r = $bal_q->deportes_federados( array( 'deporte' => 'basquet', 'limite' => 20 ) );
check( 'federadas resumen: más de 8 aunque entren en el límite', array( count( $r['categorias'] ), isset( $r['aviso'] ) ), array( 13, true ) );
$r = $bal_q->deportes_federados( array( 'deporte' => 'basquet', 'genero' => 'femenino' ) );
check( 'federadas resumen: 8 o menos sin aviso ni ejemplos', array( isset( $r['aviso'] ), isset( $r['ejemplos_por_genero'] ) ), array( false, false ) );
$r = $bal_q->deportes_federados( array( 'deporte' => 'basquet', 'limite' => 8 ) );
check( 'federadas balanceadas: sobrante va al género con más', count( array_keys( array_column( $r['categorias'], 'genero' ), 'masculino', true ) ), 5 );
$r = $bal_q->deportes_federados( array( 'deporte' => 'basquet', 'genero' => 'masculino', 'limite' => 6 ) );
check( 'federadas con género: sin reparto ni total_por_genero', array( count( $r['categorias'] ), isset( $r['total_por_genero'] ) ), array( 6, false ) );
$r = $bal_q->deportes_federados( array( 'deporte' => 'basquet' ) );
check( 'federadas: límite por defecto 12', count( $r['categorias'] ), 12 );

// La misma actividad en varias sedes sale una sola vez, con variantes: antes "Hockey Primaria" salía
// dos veces y el bot le agregaba la sede al lado (pruebas de Mica, 22/10).
$r  = $q->buscar_actividades( array( 'texto' => 'hockey', 'limite' => 50 ) );
$hp = array_values( array_filter( $r['actividades'], function ( $a ) {
	return 'Hockey Primaria' === $a['nombre'];
} ) );
check( 'agrupadas: Hockey Primaria una sola vez', count( $hp ), 1 );
check( 'agrupadas: slugs de las variantes', array_column( $hp[0]['variantes'], 'slug' ), array( 'hockey-primaria', 'hockey-primaria-cdc' ) );
check( 'agrupadas: sedes combinadas', $hp[0]['sedes'], array( 'Tigre Maliar', 'Club de Campo' ) );
check( 'agrupadas: categoría sin la sede', $hp[0]['categoria'], 'Escuelas Deportivas' );
check( 'agrupadas: sin slug propio', isset( $hp[0]['slug'] ), false );
check( 'agrupadas: variante sin agenda toma la sede de la categoría', $hp[0]['variantes'][1]['sedes'], array( 'Club de Campo' ) );
check( 'agrupadas: total cuenta grupos', $r['total'], count( $r['actividades'] ) );
check( 'agrupadas: nota_variantes', isset( $r['nota_variantes'] ), true );
$r = $q->buscar_actividades( array( 'texto' => 'judo', 'limite' => 50 ) );
check( 'mismo nombre en otra categoría no se agrupa', array( count( $r['actividades'] ), isset( $r['nota_variantes'] ) ), array( 2, false ) );
$r = $q->buscar_actividades( array( 'texto' => 'hockey jardin' ) );
check( 'actividad única: mismo formato de siempre', array( $r['actividades'][0]['slug'], isset( $r['actividades'][0]['variantes'] ) ), array( 'hockey-jardin', false ) );

// La categoría de la web manda sobre el ACF grupo, que no se ve en la web (revisión de Diego, 31/10).
$cul_snap = $snap;
foreach ( array( array( 9401, 'Cultura', 'cultura-test' ), array( 9402, 'Recreación y Tiempo Libre', 'recreacion-test' ), array( 9406, 'Deportes Adultos', 'deportes-adultos-test' ), array( 9407, 'Natación Test', 'natacion-test' ) ) as $c ) {
	$cul_snap['actividad'][] = array( 'id' => $c[0], 'name' => $c[1], 'slug' => $c[2], 'parent' => 0, 'description' => '' );
}
foreach ( array(
	array( 9403, 'Taller de Prueba', 'taller-prueba', 9401, 'culturales' ),
	array( 9404, 'Ciclo de Prueba', 'ciclo-prueba', 9401, 'generales' ),
	array( 9405, 'Noche de Prueba', 'noche-prueba', 9402, 'culturales' ),
	array( 9408, 'Judo de Prueba', 'judo-prueba', 9406, 'escuelas' ),
	array( 9409, 'Pileta de Prueba', 'pileta-prueba', 9407, 'adultos' ),
) as $c ) {
	$cul_snap['actividad'][] = array( 'id' => $c[0], 'name' => $c[1], 'slug' => $c[2], 'parent' => $c[3], 'description' => '', 'grupo' => $c[4], 'tipo_categoria' => 'hija' );
}
$cul_q = new Query( Index_Builder::build( $cul_snap, $catalog, 'https://hacoaj.org.ar' ), $catalog, $sept );
$cul_slugs = function ( array $f ) use ( $cul_q ) {
	return array_column( $cul_q->buscar_actividades( $f + array( 'limite' => 50 ) )['actividades'], 'slug' );
};
foreach ( array( 'culturales', 'cultura', 'Cultural', 'actividades culturales' ) as $c ) {
	$s = $cul_slugs( array( 'categoria' => $c ) );
	check( "categoría ($c): entra lo de Cultura", array( in_array( 'taller-prueba', $s, true ), in_array( 'ciclo-prueba', $s, true ) ), array( true, true ) );
	check( "categoría ($c): no entra Recreación con ACF culturales", in_array( 'noche-prueba', $s, true ), false );
}
check( 'categoría: Recreación entra por su categoría', in_array( 'noche-prueba', $cul_slugs( array( 'categoria' => 'recreacion' ) ), true ), true );
$s = $cul_slugs( array( 'categoria' => 'escuelas' ) );
check( 'categoría: escuelas sin Deportes Adultos con ACF escuelas', array( in_array( 'judo-prueba', $s, true ), in_array( 'hockey-jardin', $s, true ) ), array( false, true ) );
$s = $cul_slugs( array( 'categoria' => 'adultos' ) );
check( 'categoría: adultos entra por su categoría, no por el ACF', array( in_array( 'judo-prueba', $s, true ), in_array( 'pileta-prueba', $s, true ) ), array( true, false ) );
check( 'categoría: sin categoría con ese nombre, entra por grupo', $cul_slugs( array( 'categoria' => 'generales' ) ), array( 'ciclo-prueba' ) );
check( 'categoría: el texto no matchea un ACF que contradice', in_array( 'noche-prueba', $cul_slugs( array( 'texto' => 'culturales' ) ), true ), false );
$grupo_de = function ( $texto ) use ( $cul_q ) {
	$a = $cul_q->buscar_actividades( array( 'texto' => $texto ) )['actividades'][0];
	return array( $a['slug'], isset( $a['grupo'] ) ? $a['grupo'] : null );
};
check( 'grupo: contradice la categoría, no se expone', $grupo_de( 'noche prueba' ), array( 'noche-prueba', null ) );
check( 'grupo: Deportes Adultos con ACF escuelas, no se expone', $grupo_de( 'judo prueba' ), array( 'judo-prueba', null ) );
check( 'grupo: coherente, se conserva', $grupo_de( 'taller prueba' ), array( 'taller-prueba', 'culturales' ) );
check( 'grupo: categoría sin grupo propio, se conserva', $grupo_de( 'pileta prueba' ), array( 'pileta-prueba', 'adultos' ) );
$r = $cul_q->buscar_actividades( array( 'categoria' => 'culturales', 'limite' => 15 ) );
check( 'límite: categoría chica sale entera', array( $r['total'] > 15, count( $r['actividades'] ) === $r['total'], isset( $r['aviso'] ) ), array( true, true, false ) );
$r = $cul_q->buscar_actividades( array( 'categoria' => 'adultos', 'limite' => 5 ) );
check( 'límite: categoría grande lo respeta y avisa', array( $r['total'] > 30, count( $r['actividades'] ), isset( $r['aviso'] ) ), array( true, 5, true ) );

// Export CSV para revisar los datos del ACF con el cliente.
$exp_snap = $cul_snap;
foreach ( array(
	array( 9501, 'Golf Prueba', 'golf-prueba', 93, array( 'grupo' => 'escuelas', 'genero' => 'mixto', 'edad_min' => 16 ) ),
	array( 9502, 'Cesto Adultas Prueba', 'cesto-adultas-prueba', 9406, array( 'grupo' => 'generales', 'genero' => 'mixto', 'edad_min' => 18 ) ),
	array( 9503, 'Verano Prueba', 'verano-prueba', 9407, array( 'grupo' => 'generales', 'genero' => 'mixto', 'edad_min' => 0 ) ),
	array( 9504, 'Judo Menores Prueba', 'judo-menores-prueba', 9406, array( 'grupo' => 'escuelas', 'genero' => 'mixto', 'edad_min' => 4, 'edad_max' => 10 ) ),
	array( 9505, 'Grupo Roto Prueba', 'grupo-roto-prueba', 9407, array( 'grupo' => 'f', 'genero' => 'mixto', 'edad_min' => 10, 'edad_max' => 12 ) ),
	array( 9506, '=Formula', 'formula-prueba', 9407, array( 'grupo' => 'generales', 'genero' => 'mixto', 'edad_min' => 10, 'edad_max' => 12 ) ),
	array( 9507, 'Correcta Prueba', 'correcta-prueba', 9407, array( 'grupo' => 'generales', 'genero' => 'mixto', 'edad_min' => 10, 'edad_max' => 12 ) ),
) as $c ) {
	$exp_snap['actividad'][] = array( 'id' => $c[0], 'name' => $c[1], 'slug' => $c[2], 'parent' => $c[3], 'description' => '', 'tipo_categoria' => 'hija' ) + $c[4];
}
$csv = ( new Query( Index_Builder::build( $exp_snap, $catalog, 'https://hacoaj.org.ar' ), $catalog, $sept ) )->actividades_csv();
check( 'csv: BOM para Excel', substr( $csv, 0, 3 ), "\xEF\xBB\xBF" );
$exp_rows = array();
foreach ( array_filter( explode( "\n", substr( $csv, 3 ) ) ) as $line ) {
	$cols = str_getcsv( $line, ';', '"', '' );
	$exp_rows[ $cols[1] ] = $cols;
}
check( 'csv: columnas', $exp_rows['Slug'], array( 'Nombre', 'Slug', 'Actividad superior', 'Edad mín', 'Edad máx', 'Edad (texto)', 'Año nac. mín', 'Año nac. máx', 'Género', 'Grupo', 'Horarios cargados', 'Observaciones' ) );
check( 'csv: una fila por actividad del índice', count( $exp_rows ) - 1, count( Index_Builder::build( $exp_snap, $catalog, 'https://hacoaj.org.ar' )['actividades'] ) );
check( 'csv: datos de la fila', array_slice( $exp_rows['judo-menores-prueba'], 0, 11 ), array( 'Judo Menores Prueba', 'judo-menores-prueba', 'Deportes Adultos', '4', '10', '4 a 10 años', '', '', 'mixto', 'escuelas', 'no' ) );
check( 'csv: grupo que contradice y edades de menores', $exp_rows['judo-menores-prueba'][11], 'El grupo no coincide con la actividad superior | Edades de menores en una categoría de adultos' );
check( 'csv: escuela sin edad máxima', strpos( $exp_rows['golf-prueba'][11], 'Escuela deportiva sin edad máxima' ) !== false, true );
check( 'csv: nombre femenino y género mixto', $exp_rows['cesto-adultas-prueba'][11], 'El nombre indica femenino y el género no' );
check( 'csv: edad mínima 0', $exp_rows['verano-prueba'][11], 'Edad mínima 0' );
check( 'csv: grupo inválido con su valor', array( $exp_rows['grupo-roto-prueba'][9], $exp_rows['grupo-roto-prueba'][11] ), array( 'f', 'Grupo inválido' ) );
check( 'csv: fórmula neutralizada', $exp_rows['formula-prueba'][0], "'=Formula" );
check( 'csv: datos coherentes sin observaciones', $exp_rows['correcta-prueba'][11], '' );

$r = $q->transporte();
check( 'transporte rutas', count( $r['transporte'] ), 2 );
check( 'transporte servicios', count( $r['transporte'][0]['servicios'] ) > 0 && count( $r['transporte'][1]['servicios'] ) > 0, true );

$r = $q->listar_sedes();
check( 'sedes', count( $r['sedes'] ), 5 );
check( 'isla info', ! empty( $r['sedes'][4]['info_agenda'] ), true );

check( 'categorías', count( $q->listar_categorias()['categorias'] ) >= 8, true );
check( 'institucional magal', array_column( $q->info_institucional( array( 'tema' => 'discapacidad' ) )['resultados'], 'slug' ), array( 'magal-hacoaj' ) );

$json = json_encode( $q->obtener_agenda( array( 'sede' => 'tigre-maliar', 'limite' => 80 ) ), JSON_UNESCAPED_UNICODE );
printf( "Respuesta más pesada (Tigre, 80 items): %d KB\n", strlen( $json ) / 1024 );

// ---------------------------------------------------------------- noticias con detalle=true (carnet de verano, K12).
// HTML real de la nota (content.rendered de la API REST): los precios están en una tabla y el extracto
// escrito a mano ("Pileta + música…") no los trae, por eso el bot no podía darlos.
$nota = file_get_contents( __DIR__ . '/fixtures/noticia-carnet-verano.html' );
$txt  = Text::html_to_text( $nota );
check( 'nota: sin etiquetas ni entidades', preg_match( '/<|&#|&amp;|&nbsp;/', $txt ), 0 );
check( 'nota: fila de la tabla con celdas separadas', strpos( $txt, "30 A 70 AÑOS | \$ 389.000 | \$ 129.700 C/U\n" ) !== false, true );
check( 'nota: otras filas de precios', array( strpos( $txt, "6 A 17 AÑOS | \$ 319.000 | \$ 106.300 C/U" ) !== false, strpos( $txt, "MÁS DE 71 AÑOS | \$ 319.000 | \$ 106.300 C/U" ) !== false ), array( true, true ) );
check( 'nota: encabezado de la tabla', strpos( $txt, 'TEMPORADA | CONTADO | 3 CUOTAS' ) !== false, true );
check( 'nota: sin pipes sueltos al borde de línea', preg_match( '/^\s*\||\|\s*$/m', $txt ), 0 );
check( 'nota: fechas de temporada', strpos( $txt, '14 DE NOVIEMBRE 2026 AL DOMINGO 28 DE MARZO 2027' ) !== false, true );
check( 'nota: beneficio 0 a 5 años', strpos( $txt, 'Socios de 0 a 5 años SIN CARGO' ) !== false, true );
check( 'nota: descuentos familiares', array( strpos( $txt, '30%: primer hijo' ) !== false, strpos( $txt, '100%: tercer y siguientes' ) !== false ), array( true, true ) );
check( 'nota: entidad &#8211; decodificada', strpos( $txt, '2026 – 2027' ) !== false, true );
check( 'nota: párrafos en líneas separadas', count( explode( "\n", $txt ) ) > 8, true );

check( 'html_to_text: celdas sin pegar', Text::html_to_text( '<table><tr><td>A</td><td>B</td></tr><tr><td>C</td><td>D</td></tr></table>' ), "A | B\nC | D" );
check( 'html_to_text: script y style fuera', Text::html_to_text( '<style>.a{color:red}</style><p>Hola</p><script>alert(1)</script>' ), 'Hola' );
check( 'html_to_text: br y listas', Text::html_to_text( 'Uno<br>Dos<ul><li>Tres</li><li>Cuatro</li></ul>' ), "Uno\nDos\nTres\nCuatro" );
check( 'html_to_text: nbsp y espacios', Text::html_to_text( "<p>a&nbsp;&nbsp;  b</p>\n\n\n<p>c</p>" ), "a b\nc" );
check( 'html_to_text: vacío', Text::html_to_text( '' ), '' );
check( 'html_to_text: sin tope devuelve todo', Text::html_to_text( '<p>uno dos tres cuatro</p>', 0 ), 'uno dos tres cuatro' );
check( 'html_to_text: tope corta con …', Text::html_to_text( '<p>uno dos tres cuatro</p>', 2 ), 'uno dos…' );
check( 'html_to_text: tope exacto en la última línea no agrega …', Text::html_to_text( '<p>uno dos</p>', 2 ), 'uno dos' );
check( 'html_to_text: tope exacto con más líneas agrega …', Text::html_to_text( '<p>uno dos</p><p>tres</p>', 2 ), 'uno dos…' );
check( 'html_to_text: tope entre líneas', Text::html_to_text( '<p>uno dos</p><p>tres cuatro cinco</p>', 4 ), "uno dos\ntres cuatro…" );
check( 'nota completa entra en 600 palabras', strpos( Text::html_to_text( $nota, 600 ), '…' ), false );

// Temporada de verano solo si la piden (H06) y listado general cortado en 30 con "más" (B11), 13/11.
$nat = array_column( $q->buscar_actividades( array( 'texto' => 'natacion', 'limite' => 50 ) )['actividades'], 'slug' );
check( 'natación sin "verano": no trae la de verano', in_array( 'natacion-pileta-verano', $nat, true ), false );
check( 'natación sin "verano": trae las demás', in_array( 'natacion-pileta-adolescentes', $nat, true ), true );
$nat = array_column( $q->buscar_actividades( array( 'texto' => 'natacion verano', 'limite' => 50 ) )['actividades'], 'slug' );
check( 'natación con "verano": trae la de verano', in_array( 'natacion-pileta-verano', $nat, true ), true );
// Listado general con edad: el snapshot no trae edades, así que se cargan 0 a 99 en todas las no federadas.
$edad_snap = $snap;
foreach ( $edad_snap['actividad'] as &$t ) {
	if ( empty( $t['grupo'] ) || 'federadas' !== $t['grupo'] ) {
		$t['edad_min'] = 0;
		$t['edad_max'] = 99;
	}
}
unset( $t );
$edad_q = new Query( Index_Builder::build( $edad_snap, $catalog, 'https://hacoaj.org.ar' ), $catalog, $sept );
$todo   = $edad_q->buscar_actividades( array( 'edad' => 8, 'limite' => 50 ) );
check( 'fixture: más de 30 actividades para 8 años', $todo['total'] > Query::LISTA_CORTA, true );
check( 'listado de menor: corta en 30', count( $todo['actividades'] ), Query::LISTA_CORTA );
check( 'listado de menor: ofrece "más"', $todo['siguiente_desde'], Query::LISTA_CORTA );
$resto = $edad_q->buscar_actividades( array( 'edad' => 8, 'limite' => 50, 'desde' => $todo['siguiente_desde'] ) );
check( 'listado de menor: "más" no repite', array_intersect( array_column( $resto['actividades'], 'nombre' ), array_column( $todo['actividades'], 'nombre' ) ), array() );
check( 'listado de adulto: no se corta en 30', count( $edad_q->buscar_actividades( array( 'edad' => 40, 'limite' => 50 ) )['actividades'] ), 50 );
check( 'listado de menor por sede: no se corta en 30', count( $edad_q->buscar_actividades( array( 'edad' => 8, 'sede' => 'tigre', 'limite' => 50 ) )['actividades'] ) > Query::LISTA_CORTA, true );

// En temporada de verano la agenda regular queda vacía y la de verano tiene los horarios: ahí no se oculta.
$ver_snap = $snap;
$plantilla = null;
foreach ( $ver_snap['agenda_item'] as $it ) {
	if ( array_intersect( array( 'natacion-pileta-adolescentes', 'natacion-pileta-libre' ), (array) $it['actividad'] ) ) {
		$plantilla = $it;
		break;
	}
}
$ver_snap['agenda_item'] = array_values( array_filter( $ver_snap['agenda_item'], function ( $it ) {
	foreach ( (array) $it['actividad'] as $slug ) {
		if ( 0 === strpos( $slug, 'natacion' ) ) {
			return false;
		}
	}
	return true;
} ) );
$plantilla['id']           = '9950';
$plantilla['slug']         = 'natacion-verano-test';
$plantilla['actividad']    = array( 'natacion-pileta-verano' );
$ver_snap['agenda_item'][] = $plantilla;
$ver_q = new Query( Index_Builder::build( $ver_snap, $catalog, 'https://hacoaj.org.ar' ), $catalog, $sept );
$nat   = $ver_q->buscar_actividades( array( 'texto' => 'natacion', 'limite' => 50 ) );
check( 'verano con agenda y regulares sin: aparece', in_array( 'natacion-pileta-verano', array_column( $nat['actividades'], 'slug' ), true ), true );
check( 'verano con agenda y regulares sin: sin aviso de temporada', isset( $nat['temporada_oculta'] ), false );
check( 'verano con agenda: obtener_agenda de natación trae horarios', $ver_q->obtener_agenda( array( 'actividad' => 'natación' ) )['total'] > 0, true );
$tv = Index_Builder::build( $snap, $catalog, 'https://hacoaj.org.ar' );
$tv['stats']['temporada'] = 'verano';
check( 'en temporada de verano no se oculta la de verano', in_array( 'natacion-pileta-verano', array_column( ( new Query( $tv, $catalog, $sept ) )->buscar_actividades( array( 'texto' => 'natacion', 'limite' => 50 ) )['actividades'], 'slug' ), true ), true );
check( 'sin verano en el resultado: sin aviso de temporada', isset( $q->buscar_actividades( array( 'texto' => 'golf' ) )['temporada_oculta'] ), false );

// El bot repite el nombre con la etiqueta: se saca y se usa como género.
$r = $fed_q->deportes_federados( array( 'deporte' => 'Mosquitos (caballeros)' ) );
check( 'federada buscada con etiqueta', array_column( $r['categorias'], 'categoria' ), array( 'Mosquitos (caballeros)' ) );
check( 'federada buscada con etiqueta: género', isset( $r['genero_inferido'] ) || 'masculino' === $r['categorias'][0]['genero'], true );
$ej = new ReflectionMethod( Query::class, 'examples_by_gender' );
$ej->setAccessible( true );
check(
	'ejemplos: caballeros antes que damas aunque vengan al revés',
	array_keys( $ej->invoke( null, array( array( 'categoria' => 'Mini', 'genero' => 'femenino' ), array( 'categoria' => 'Mosquitos', 'genero' => 'masculino' ) ), 3 ) ),
	array( 'masculino', 'femenino' )
);
check( 'con_genero: "Ajedrez y Damas" no se etiqueta', Query::con_genero( 'Ajedrez y Damas', 'femenino' ), 'Ajedrez y Damas' );
check( 'con_genero: "Fútbol Nenas" no se etiqueta', Query::con_genero( 'Fútbol Nenas', 'femenino' ), 'Fútbol Nenas' );

// ---------------------------------------------------------------- Etiqueta de género y agenda por sede (batería del 13/11, D01 y C08).
check( 'con_genero: femenino', Query::con_genero( 'Mini', 'femenino' ), 'Mini (damas)' );
check( 'con_genero: masculino', Query::con_genero( 'Mosquitos', 'masculino' ), 'Mosquitos (caballeros)' );
check( 'con_genero: mixto no cambia', Query::con_genero( 'Basquet Primaria', 'mixto' ), 'Basquet Primaria' );
check( 'con_genero: sin dato no cambia', Query::con_genero( 'Judo', null ), 'Judo' );
check( 'con_genero: el nombre ya dice el género', Query::con_genero( 'Básquet Femenino', 'femenino' ), 'Básquet Femenino' );
check( 'con_genero: no repite la etiqueta', Query::con_genero( 'Fútbol Primaria (caballeros)', 'masculino' ), 'Fútbol Primaria (caballeros)' );
check( 'actividad con género sale etiquetada', $gen_q->activity_summary( $gen_a['golf-salidas'] )['nombre'], 'Golf Damas' );
$lab_snap = $snap;
foreach ( $lab_snap['actividad'] as &$t ) {
	if ( 'golf-clases' === $t['slug'] ) {
		$t['genero'] = 'masculino';
	}
}
unset( $t );
$lab_q = new Query( Index_Builder::build( $lab_snap, $catalog, 'https://hacoaj.org.ar' ), $catalog, $sept );
$r     = $lab_q->buscar_actividades( array( 'texto' => 'golf' ) );
$golf  = array_values( array_filter( $r['actividades'], function ( $a ) {
	return isset( $a['slug'] ) && 'golf-clases' === $a['slug'];
} ) );
check( 'buscar_actividades: nombre con (caballeros)', $golf ? substr( $golf[0]['nombre'], -12 ) : null, '(caballeros)' );

$sede_snap = $snap;
$base_item = null;
foreach ( $sede_snap['agenda_item'] as $it ) {
	if ( in_array( 'golf-clases', (array) $it['actividad'], true ) ) {
		$base_item = $it;
		break;
	}
}
if ( $base_item ) {
	$sede_snap['agenda_item'] = array_values( array_filter( $sede_snap['agenda_item'], function ( $it ) {
		return ! in_array( 'golf-clases', (array) $it['actividad'], true );
	} ) );
	$mk = function ( $id, $sede, $dias, $detalle ) use ( $base_item ) {
		$it                    = $base_item;
		$it['id']              = (string) $id;
		$it['slug']            = 'golf-test-' . $id;
		$it['title']           = 'Golf ' . $id;
		$it['sede']            = array( $sede );
		$it['meta']['dias']    = $dias;
		$it['meta']['detalle'] = $detalle;
		return $it;
	};
	$sede_snap['agenda_item'][] = $mk( 9901, 'tigre-maliar', array( 'martes', 'jueves' ), '18.30 a 19.30 h' );
	$sede_snap['agenda_item'][] = $mk( 9902, 'ben-gurion', array( 'miercoles' ), '18.30 a 19.30 h' );
	$sede_snap['agenda_item'][] = $mk( 9903, 'tigre-maliar', array( 'sabado' ), '13.30 a 15 h' );
	$sede_q = new Query( Index_Builder::build( $sede_snap, $catalog, 'https://hacoaj.org.ar' ), $catalog, $sept );
	$r      = $sede_q->obtener_agenda( array( 'actividad' => 'golf-clases' ) );
	$sedes  = array_map( function ( $i ) {
		return $i['sedes'][0];
	}, $r['items'] );
	$cortes = 0;
	foreach ( $sedes as $k => $s_ ) {
		$cortes += ( 0 === $k || $sedes[ $k - 1 ] !== $s_ ) ? 1 : 0;
	}
	check( 'agenda de una actividad: la misma sede sale junta', $cortes, count( array_unique( $sedes ) ) );
	check( 'agenda de una actividad: tres items', count( $r['items'] ), 3 );
	check( 'agenda de una actividad: avisa un bloque por sede', isset( $r['formato'] ), true );
	$r = $sede_q->obtener_agenda( array( 'actividad' => 'golf-clases', 'dia' => 'sabado' ) );
	check( 'agenda con día: sin aviso de bloque por sede', isset( $r['formato'] ), false );
} else {
	check( 'fixture: golf-clases tiene agenda', false, true );
}

// Tools::validate / coerce con el parámetro booleano nuevo (la clase exige ABSPATH; se carga sólo para estos checks).
define( 'ABSPATH', __DIR__ . '/' );
if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( $s ) {
		return trim( strip_tags( (string) $s ) );
	}
}
require $root . '/includes/class-tools.php';
$schema_det = array( 'properties' => array( 'detalle' => array( 'type' => 'boolean' ), 'texto' => array( 'type' => 'string' ) ) );
check( 'validate: detalle booleano no da error', HacoajMCP\Tools::validate( array( 'detalle' => true ), $schema_det ), array() );
$coerce = new ReflectionMethod( HacoajMCP\Tools::class, 'coerce' );
$coerce->setAccessible( true );
check( 'coerce: true', $coerce->invoke( null, array( 'detalle' => true ), $schema_det ), array( 'detalle' => true ) );
check( 'coerce: "false" texto es false', $coerce->invoke( null, array( 'detalle' => 'false' ), $schema_det ), array( 'detalle' => false ) );
check( 'coerce: "true" texto es true', $coerce->invoke( null, array( 'detalle' => 'true' ), $schema_det ), array( 'detalle' => true ) );
check( 'coerce: 0 es false', $coerce->invoke( null, array( 'detalle' => 0 ), $schema_det ), array( 'detalle' => false ) );
check( 'constantes de detalle', array( HacoajMCP\Tools::NOTICIAS_DETALLE_MAX, HacoajMCP\Tools::NOTICIAS_DETALLE_PALABRAS ), array( 3, 600 ) );

printf( "%d OK, %d fallas\n", $passes, $failures );
exit( $failures ? 1 : 0 );
