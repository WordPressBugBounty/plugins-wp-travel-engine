<?php
/**
 * Checkout.
 *
 * @since 6.2.0
 */

use WPTravelEngine\Core\Models\Settings\PluginSettings;

$checkout_fields = array(
	array(
		'field_type' => 'ALERT',
		'content'    => sprintf(
			/* translators: %1$s: link to the Pages settings tab. %2$s: link to the checkout guide. */
			__( 'Your checkout page is set in %1$s. Not sure how it works? Check the %2$s.', 'wp-travel-engine' ),
			'<a href="' . esc_url( PluginSettings::url( 'general_pages' ) ) . '">' . __( 'Pages settings', 'wp-travel-engine' ) . '</a>',
			'<a target="_blank" href="https://docs.wptravelengine.com/article/checkout-settings/">' . __( 'guide', 'wp-travel-engine' ) . '</a>'
		),
	),
	array(
		'field_type' => 'TITLE',
		'title'      => __( 'Traveler details', 'wp-travel-engine' ),
	),
	array(
		'label'       => __( 'Collect Traveler Details', 'wp-travel-engine' ),
		'description' => __( 'When enabled, providing information for all travellers will be mandatory.', 'wp-travel-engine' ),
		'field_type'  => 'SWITCH',
		'name'        => 'display_travellers_info',
		'divider'     => true,
	),
	array(
		'condition'  => 'display_travellers_info === true',
		'field_type' => 'GROUP',
		'fields'     => array(
			array(
				'label'      => __( 'Whose details do you need?', 'wp-travel-engine' ),
				'field_type' => 'SELECT_BUTTON',
				'name'       => 'travellers_details_type',
				'variant'    => 'cards',
				'options'    => array(
					array(
						'label'       => __( 'Everyone on the booking', 'wp-travel-engine' ),
						'value'       => 'all',
						'description' => __( 'One form per traveler. Use this when you need passports for permits, or ages for pricing and safety.', 'wp-travel-engine' ),
					),
					array(
						'label'       => __( 'Just the traveler who books', 'wp-travel-engine' ),
						'value'       => 'only_lead',
						'description' => __( "One form, whatever the group size. Quickest checkout, but you'll follow up for the rest of the names.", 'wp-travel-engine' ),
					),
				),
				'divider'    => true,
			),
			array(
				'label'      => __( 'When do you need traveler details?', 'wp-travel-engine' ),
				'field_type' => 'SELECT_BUTTON',
				'name'       => 'traveler_details_timing',
				'condition'  => 'travellers_details_type === all',
				'variant'    => 'cards',
				'isNew'      => version_compare( WP_TRAVEL_ENGINE_VERSION, '6.9.5', '<' ),
				'options'    => array(
					array(
						'label'       => __( 'Before they pay', 'wp-travel-engine' ),
						'value'       => 'before_pay',
						'description' => __( 'Travelers fill in the form at checkout. The booking lands complete, and you never chase anyone.', 'wp-travel-engine' ),
					),
					array(
						'label'       => __( 'They can pay now and fill in later', 'wp-travel-engine' ),
						'value'       => 'pay_later',
						'tag'         => __( 'Fewer drop-offs', 'wp-travel-engine' ),
						'description' => __( 'Checkout shows an "I\'ll add these later" option. Travelers finish the form from their dashboard, and you can nudge them by email.', 'wp-travel-engine' ),
					),
				),
				'divider'    => true,
			),
			array(
				'field_type' => 'ALERT',
				'status'     => 'warning',
				'condition'  => 'traveler_details_timing === pay_later && account_creation_mode === none',
				'content'    => sprintf(
					__( "This option needs traveler accounts. You've turned them off, so travelers won't have a dashboard to fill in details later. %1\$sChange this in Traveler Dashboard settings%2\$s.", 'wp-travel-engine' ),
					'<a href="' . esc_url( PluginSettings::url( 'dashboard-user-settings' ) ) . '">',
					'</a>'
				),
				'divider'    => true,
			),
			array(
				'label'       => __( 'Fill Later Message to Travelers', 'wp-travel-engine' ),
				'description' => __( 'Shown right under the checkbox when they pick "I\'ll fill these in later."', 'wp-travel-engine' ),
				'field_type'  => 'TEXTAREA',
				'name'        => 'fill_later_message',
				'condition'   => 'travellers_details_type === all && traveler_details_timing === pay_later',
				'default'     => __( "No problem - add them later. You can complete traveler details for this trip from your dashboard after completing checkout. Your booking isn't affected.", 'wp-travel-engine' ),
				'divider'     => true,
			),
			array(
				'label'       => __( 'Ask for an emergency contact?', 'wp-travel-engine' ),
				'description' => __( 'Adds name, relationship and phone fields to the form.', 'wp-travel-engine' ),
				'field_type'  => 'SWITCH',
				'name'        => 'display_emergency_contact',
				'divider'     => true,
			),
		),
	),
	array(
		'field_type' => 'TITLE',
		'title'      => __( 'On the checkout page', 'wp-travel-engine' ),
	),
	array(
		'label'       => __( 'Special Requests/Comment Field', 'wp-travel-engine' ),
		'description' => __( 'Lets travelers add a note or request during checkout.', 'wp-travel-engine' ),
		'field_type'  => 'SWITCH',
		'name'        => 'show_additional_note',
		'divider'     => true,
	),
	array(
		'label'       => __( 'Show Coupon Field', 'wp-travel-engine' ),
		'description' => __( 'Shows a coupon code field at checkout if the trip has one.', 'wp-travel-engine' ),
		'field_type'  => 'SWITCH',
		'name'        => 'show_discount',
		'divider'     => true,
	),
	array(
		'field_type' => 'TITLE',
		'title'      => __( 'Your wording', 'wp-travel-engine' ),
	),
	array(
		'label'       => __( 'What do travelers tick to agree to your terms?', 'wp-travel-engine' ),
		'description' => __( 'Your policy link is added to the end automatically.', 'wp-travel-engine' ),
		'field_type'  => 'TEXTAREA',
		'name'        => 'privacy_policy_msg',
		'default'     => __( "Check the box to confirm you've read and agree to our", 'wp-travel-engine' ),
		'required'    => true,
		'rules'       => array(
			'required' => __( "Write the line travelers tick, or they can't complete a booking.", 'wp-travel-engine' ),
		),
		'divider'     => true,
	),
	array(
		'label'      => __( 'Booking Confirmation Message', 'wp-travel-engine' ),
		'field_type' => 'FIELD_WITH_TAGS',
		'default'    => "Your booking confirmation has been sent to {email}. Please check your spam folder if it doesn't arrive within a few minutes.",
		'name'       => 'booking_confirmation_msg',
		'value'      => array(
			'{email}' => __( "Customer's email address.", 'wp-travel-engine' ),
		),
		'divider'    => true,
	),
	array(
		'label'       => __( 'Footer Copyright Text', 'wp-travel-engine' ),
		'field_type'  => 'FIELD_WITH_TAGS',
		'name'        => 'footer_copyright',
		'default'     => __( 'Copyright © %1$current_year% %2$site_name% . All Rights Reserved.', 'wp-travel-engine' ),
		'placeholder' => __( 'Copyright © %1$current_year% %2$site_name% . All Rights Reserved.', 'wp-travel-engine' ),
		'value'       => array(
			'%1$current_year%' => __( 'Current year.', 'wp-travel-engine' ),
			'%2$site_name%'    => __( 'Your site name.', 'wp-travel-engine' ),
		),
	),
);

return apply_filters(
	'checkout_settings',
	array(
		'title'  => __( 'Checkout', 'wp-travel-engine' ),
		'order'  => 20,
		'id'     => 'checkout_settings',
		'fields' => $checkout_fields,
	)
);
