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
check( 'body-power edad por categoría', array( $A['body-power']['edad_min'], $A['body-power']['fuente_edad'] ), array( 18, 'catalogo' ) );
check( 'gimnasio de musculación cat', $A['gimnasio-de-musculacion']['categoria'], 'deportes-adultos' );
check( 'natación hojas', in_array( 6050, $A['natacion-pileta-primaria']['items'], true ), true );
check( 'categoría no es actividad', isset( $A['natacion-pileta'] ), false );
check( 'titulo matchea hijo', in_array( 4373, $A['gimnasia-artistica-adolescentes']['items'], true ), true );
check( 'transporte fuera de actividades', isset( $A['capital-tigre-maliar'] ), false );
check( 'transporte especial', count( $index['especiales']['transporte'] ) >= 2, true );
check( 'catálogo sin término', $A['newcom-adultas']['tiene_agenda'], false );
check( 'golf-principiantes sin agenda', $A['golf-principiantes']['tiene_agenda'], false );
check( 'olami sedes', $A['olami']['sedes'], array( 'club-de-campo', 'tigre-maliar' ) );
check( 'escuelas CdC nombre', $index['categorias']['escuelas-deportivas-club-de-campo']['nombre'], 'Escuelas Deportivas (Club de Campo)' );

// ACF de WordPress sobre el término de actividad > catalog.json para el mismo slug.
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
check( 'ACF gana sobre catalog.json: edad_min', $acf_a['edad_min'], 99 );
check( 'ACF gana sobre catalog.json: edad_max', $acf_a['edad_max'], 100 );
check( 'ACF gana sobre catalog.json: grupo', $acf_a['grupo'], 'generales' );
check( 'ACF gana sobre catalog.json: fuente_edad', $acf_a['fuente_edad'], 'wordpress' );
// Sin ACF, sigue viniendo del catálogo (no rompe lo existente).
check( 'sin ACF sigue usando catalog.json', $A['golf-escuela-deportiva']['edad_max'], 12 );
check( 'sin ACF fuente_edad catalogo', $A['golf-escuela-deportiva']['fuente_edad'], 'catalogo' );

$q = new Query( $index, $catalog, $sept );

$r     = $q->buscar_actividades( array( 'texto' => 'golf' ) );
$slugs = array_map( function ( $a ) {
	return $a['slug'];
}, $r['actividades'] );
sort( $slugs );
check( 'buscar golf', $slugs, array( 'golf-clases', 'golf-escuela-deportiva', 'golf-para-chicos', 'golf-principiantes', 'golf-salidas' ) );

$r     = $q->buscar_actividades( array( 'texto' => 'golf', 'edad' => 8 ) );
$slugs = array_map( function ( $a ) {
	return $a['slug'];
}, $r['actividades'] );
check( 'golf 8 años', $slugs, array( 'golf-para-chicos', 'golf-escuela-deportiva' ) );
check( 'golf 8 años sin datos', isset( $r['sin_datos_de_edad'] ), false );

$r = $q->buscar_actividades( array( 'texto' => 'basket', 'sede' => 'capital' ) );
check( 'sinónimo basket en CABA', count( $r['actividades'] ) > 0 && ! array_filter( $r['actividades'], function ( $a ) {
	return ! in_array( 'Ben Gurión', $a['sedes'], true );
} ), true );

$r = $q->buscar_actividades( array( 'texto' => 'natacion', 'edad' => 5, 'sede' => 'tigre' ) );
check( 'natación 5 años tigre', count( $r['actividades'] ) >= 1, true );

$r = $q->buscar_actividades( array( 'categoria' => 'hadraja', 'edad' => 7 ) );
check( 'hadrajá 7 años', array_column( $r['actividades'], 'slug' ), array( 'kesher', 'programa-magal' ) );

$r = $q->buscar_actividades( array( 'texto' => 'futbol', 'genero' => 'nena', 'edad' => 7 ) );
check( 'fútbol nena 7', in_array( 'futbol-femenino-primaria', array_column( $r['actividades'], 'slug' ), true ), true );

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

$r = $q->deportes_federados( array( 'deporte' => 'futbol', 'anio_nacimiento' => 2015, 'genero' => 'masculino' ) );
check( 'federados fútbol 2015', array_column( $r['categorias'], 'categoria' ), array( 'Categoría 2015' ) );
$r = $q->deportes_federados( array( 'deporte' => 'cesto', 'edad' => 12 ) );
check( 'federados cesto 12', array_column( $r['categorias'], 'categoria' ), array( 'Mini', 'Escuela Mini' ) );

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

printf( "%d OK, %d fallas\n", $passes, $failures );
exit( $failures ? 1 : 0 );
