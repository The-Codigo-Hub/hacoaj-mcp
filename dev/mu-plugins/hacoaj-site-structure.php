<?php
/**
 * SÓLO PARA DESARROLLO LOCAL: replica los CPT y taxonomías que en hacoaj.org.ar registra CPT UI.
 */

add_action(
	'init',
	function () {
		register_post_type(
			'agenda_item',
			array(
				'label'        => 'Agenda',
				'public'       => true,
				'show_in_rest' => true,
				'supports'     => array( 'title', 'custom-fields' ),
			)
		);
		register_post_type(
			'revista',
			array(
				'label'        => 'Revista',
				'public'       => true,
				'show_in_rest' => true,
				'supports'     => array( 'title', 'editor' ),
			)
		);
		register_taxonomy( 'actividad', array( 'agenda_item' ), array( 'label' => 'Actividades', 'hierarchical' => true, 'show_in_rest' => true ) );
		register_taxonomy( 'sede', array( 'agenda_item' ), array( 'label' => 'Sedes', 'hierarchical' => true, 'show_in_rest' => true ) );
	}
);
