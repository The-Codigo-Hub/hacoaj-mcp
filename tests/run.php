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

$q = new Query( $index, $catalog, $sept );

$r     = $q->buscar_actividades( array( 'texto' => 'golf' ) );
$slugs = array_map( function ( $a ) {
	return $a['slug'];
}, $r['actividades'] );
sort( $slugs );
check( 'buscar golf', $slugs, array( 'golf-clases', 'golf-escuela-deportiva', 'golf-para-chicos', 'golf-principiantes', 'golf-salidas' ) );

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
check( 'federada por edad fija: categoria', array_column( $r['categorias'], 'categoria' ), array( 'Mosquitos' ) );
check( 'federada por edad fija: edades', $r['categorias'][0]['edades'], '4 a 7 años' );

$r = $fed_q->deportes_federados( array( 'deporte' => 'futbol', 'anio_nacimiento' => 2015 ) );
check( 'federada por año de nacimiento: categoria', array_column( $r['categorias'], 'categoria' ), array( 'Categoría 2015' ) );
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
	array( 'Masc 0', 'Masc 1', 'Masc 2', 'Fem 0', 'Fem 1', 'Fem 2' )
);
check( 'federadas balanceadas: total_por_genero', $r['total_por_genero'], array( 'masculino' => 10, 'femenino' => 3 ) );
check( 'federadas balanceadas: aviso con detalle', false !== strpos( $r['aviso'], 'Se muestran 6 de 13 categorías (3 de 10 masculino, 3 de 3 femenino)' ), true );
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
