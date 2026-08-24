<?php

/**
 * Appearance Display Settings.
 *
 * @since 6.6.1
 */

return apply_filters(
	'display-appearance',
	array(
		'title'  => __( 'Appearance', 'wp-travel-engine' ),
		'order'  => 5,
		'id'     => 'display_appearance',
		'fields' => array(
			array(
				'field_type' => 'TITLE',
				'title'      => __( 'Color Settings', 'wp-travel-engine' ),
			),
			array(
				'field_type'  => 'COLOR_PICKER',
				'name'        => 'appearance.primary_color',
				'label'       => __( 'Primary Color', 'wp-travel-engine' ),
				'enableReset' => true,
			),
			array(
				'field_type'  => 'COLOR_PICKER',
				'name'        => 'appearance.discount_color',
				'label'       => __( 'Discount Ribbon Color', 'wp-travel-engine' ),
				'enableReset' => true,
			),
			array(
				'field_type'  => 'COLOR_PICKER',
				'name'        => 'appearance.featured_color',
				'label'       => __( 'Featured Ribbon Color', 'wp-travel-engine' ),
				'enableReset' => true,
			),
			array(
				'field_type'  => 'COLOR_PICKER',
				'name'        => 'appearance.icon_color',
				'label'       => __( 'Icon Color', 'wp-travel-engine' ),
				'enableReset' => true,
			),
			array(
				'field_type'    => 'COLOR_PICKER',
				'name'          => 'appearance.warning_message_color',
				'label'         => __( 'Warning Message', 'wp-travel-engine' ),
				'enableReset'   => true,
				'defaultValue'  => '#F79009',
				'enablePreview' => true,
				'preview'       => array(
					'type'    => 'alert',
					'status'  => 'warning',
					'title'   => __( 'Preview', 'wp-travel-engine' ),
					'content' => __( 'This is how your warning banner will look on trip pages, for things like capacity alerts and booking cutoffs.', 'wp-travel-engine' ),
				),
				'isNew'         => version_compare( WP_TRAVEL_ENGINE_VERSION, '6.8.8', '<' ),
			),
			array(
				'field_type'    => 'COLOR_PICKER',
				'name'          => 'appearance.info_notice_color',
				'label'         => __( 'Info Notice', 'wp-travel-engine' ),
				'enableReset'   => true,
				'defaultValue'  => '#2578EB',
				'enablePreview' => true,
				'preview'       => array(
					'type'    => 'alert',
					'title'   => __( 'Preview', 'wp-travel-engine' ),
					'content' => __( 'This is how your info banner will look on trip pages, for things like booking tips and helpful notes.', 'wp-travel-engine' ),
				),
				'isNew'         => version_compare( WP_TRAVEL_ENGINE_VERSION, '6.8.8', '<' ),
			),
		),
	),
);
