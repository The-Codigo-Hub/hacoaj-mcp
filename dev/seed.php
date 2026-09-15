<?php
/**
 * Importa tests/fixtures/wp-snapshot.json al WordPress local.
 *   wp eval-file /plugin/dev/seed.php
 */

$snap = json_decode( file_get_contents( '/plugin/tests/fixtures/wp-snapshot.json' ), true );

foreach ( array( 'actividad', 'sede' ) as $tax ) {
	$old_to_new = array();
	$pending    = $snap[ $tax ];
	$guard      = 0;
	while ( $pending && $guard++ < 10 ) {
		foreach ( $pending as $i => $t ) {
			if ( $t['parent'] && ! isset( $old_to_new[ $t['parent'] ] ) ) {
				continue;
			}
			$existing = get_term_by( 'slug', $t['slug'], $tax );
			$args     = array(
				'slug'        => $t['slug'],
				'parent'      => $t['parent'] ? $old_to_new[ $t['parent'] ] : 0,
				'description' => $t['description'],
			);
			$res      = $existing ? wp_update_term( $existing->term_id, $tax, $args ) : wp_insert_term( $t['name'], $tax, $args );
			if ( is_wp_error( $res ) ) {
				WP_CLI::warning( $tax . ' ' . $t['slug'] . ': ' . $res->get_error_message() );
			} else {
				$old_to_new[ $t['id'] ] = $res['term_id'];
			}
			unset( $pending[ $i ] );
		}
	}
	WP_CLI::log( $tax . ': ' . count( $old_to_new ) . ' términos' );
}

foreach ( $snap['agenda_item'] as $item ) {
	$id = wp_insert_post(
		array(
			'post_type'   => 'agenda_item',
			'post_status' => 'publish',
			'post_title'  => $item['title'],
			'post_name'   => $item['slug'],
			'post_date'   => str_replace( 'T', ' ', $item['date'] ),
			'meta_input'  => $item['meta'],
		),
		true
	);
	if ( is_wp_error( $id ) ) {
		WP_CLI::warning( $item['title'] . ': ' . $id->get_error_message() );
		continue;
	}
	wp_set_object_terms( $id, $item['actividad'], 'actividad' );
	wp_set_object_terms( $id, $item['sede'], 'sede' );
}
WP_CLI::log( 'agenda_item: ' . count( $snap['agenda_item'] ) );

foreach ( $snap['post'] as $p ) {
	wp_insert_post( array( 'post_type' => 'post', 'post_status' => 'publish', 'post_title' => $p['title'], 'post_excerpt' => wp_strip_all_tags( $p['excerpt'] ), 'post_content' => wp_strip_all_tags( $p['excerpt'] ), 'post_date' => str_replace( 'T', ' ', $p['date'] ) ) );
}
foreach ( $snap['revista'] as $r ) {
	wp_insert_post( array( 'post_type' => 'revista', 'post_status' => 'publish', 'post_title' => $r['title'], 'post_name' => substr( $r['slug'], 0, 190 ), 'post_date' => str_replace( 'T', ' ', $r['date'] ) ) );
}
WP_CLI::success( 'Seed listo.' );
