<?php

/**
 * Labels.
 *
 * @since 6.2.0
 */

return apply_filters(
	'modify_labels',
	array(
		'title'  => __( 'Labels', 'wp-travel-engine' ),
		'order'  => 30,
		'id'     => 'modify_labels',
		'fields' => array(
			array(
				'field_type' => 'ALERT',
				'content'    => __( 'The Custom Labels feature lets you personalize static strings on your website. For example, if the default label is "Travelers," you can change it to "Guests." This feature also works as a basic translation tool, but it only supports simple, static strings. If a label does not update, or if you need to translate longer or more complex strings, use a translation plugin instead. See our guides for <a href="https://docs.wptravelengine.com/article/how-to-translate-themes-and-plugins-using-loco-translate/" target="_blank" rel="noopener noreferrer">Loco Translate</a> and <a href="https://docs.wptravelengine.com/article/build-a-multilingual-travel-website/" target="_blank" rel="noopener noreferrer">WPML</a>.', 'wp-travel-engine' ),
				'status'     => 'info',
			),
			array(
				'label'      => __( 'Labels', 'wp-travel-engine' ),
				'field_type' => 'TEXT_REPLACER',
				'name'       => 'custom_strings',
			),
		),
	)
);
