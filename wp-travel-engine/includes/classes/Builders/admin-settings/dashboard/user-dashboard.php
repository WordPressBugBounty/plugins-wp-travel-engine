<?php
/**
 * Traveler Dashboard Tab Settings.
 *
 * @package WPTravelEngine
 * @since 6.2.0
 */

use WPTravelEngine\Core\Models\Settings\PluginSettings;

$wptravelengine_page_id   = (int) wp_travel_engine_get_dashboard_page_id();
$wptravelengine_page_set  = $wptravelengine_page_id > 0 && 'publish' === get_post_status( $wptravelengine_page_id );
$wptravelengine_pages_url = '<a target="_blank" href="' . esc_url( PluginSettings::url( 'general_pages' ) ) . '" rel="nofollow">' . __( 'here', 'wp-travel-engine' ) . '</a>';

// A traveler with no dashboard page has nowhere to land after checkout, so which of the two
// this is decides what the admin is told to do about it.
$wptravelengine_page_notice = $wptravelengine_page_set
	? sprintf(
		/* translators: %s: link to the page settings. */
		__( 'Your Traveler Dashboard page is set. You can change it from %s.', 'wp-travel-engine' ),
		$wptravelengine_pages_url
	)
	: sprintf(
		/* translators: %s: link to the page settings. */
		__( 'You haven\'t set a Traveler Dashboard page yet. Set one from %s.', 'wp-travel-engine' ),
		$wptravelengine_pages_url
	);

$wptravelengine_account_mode = wptravelengine_account_creation_mode();

return apply_filters(
	'user_dashboard_settings',
	array(
		'title'  => __( 'Traveler Dashboard', 'wp-travel-engine' ),
		'order'  => 5,
		'id'     => 'dashboard-user-settings',
		'fields' => array(
			array(
				'field_type' => 'ALERT',
				'content'    => $wptravelengine_page_notice,
				'status'     => $wptravelengine_page_set ? 'info' : 'warning',
			),
			array(
				'divider'    => true,
				'label'      => __( 'How should traveler accounts get created?', 'wp-travel-engine' ),
				'field_type' => 'SELECT_BUTTON',
				'variant'    => 'cards',
				'name'       => 'account_creation_mode',
				'default'    => $wptravelengine_account_mode,
				'options'    => array(
					array(
						'label'       => __( 'Automatically at checkout', 'wp-travel-engine' ),
						'value'       => 'auto',
						'tag'         => __( 'Recommended', 'wp-travel-engine' ),
						'description' => __( 'An account is created using their billing details. After checkout, they get an email to access it and set a password.', 'wp-travel-engine' ),
					),
					array(
						'label'       => __( 'Travelers register themselves', 'wp-travel-engine' ),
						'value'       => 'self',
						'description' => __( 'At checkout, travelers are sent to a signup page first, then return to finish booking.', 'wp-travel-engine' ),
					),
					array(
						'label'       => __( 'Do not create an account', 'wp-travel-engine' ),
						'value'       => 'none',
						'description' => __( 'No account is created during checkout. Travelers can complete a booking without signing up.', 'wp-travel-engine' ),
					),
				),
			),
			array(
				'divider'     => true,
				'label'       => __( 'Allow travelers to register', 'wp-travel-engine' ),
				'description' => __( 'Adds an option for travelers to register on your website.', 'wp-travel-engine' ),
				'field_type'  => 'SWITCH',
				'name'        => 'enable_account_registration',
			),
			array(
				'divider'     => true,
				'label'       => __( 'Allow travelers to edit their details', 'wp-travel-engine' ),
				'description' => __( 'Lets travelers return to their dashboard and edit these details again, any time.', 'wp-travel-engine' ),
				'field_type'  => 'SWITCH',
				'name'        => 'allow_traveler_details_edit',
				'isNew'       => version_compare( WP_TRAVEL_ENGINE_VERSION, '6.9.5', '<' ),
				'condition'   => 'account_creation_mode !== none || enable_account_registration === true',
			),
			array(
				'label'       => __( 'Login Page Label', 'wp-travel-engine' ),
				'description' => __( 'Shown as the heading on the login page.', 'wp-travel-engine' ),
				'field_type'  => 'TEXT',
				'default'     => __( 'Log into Your Account', 'wp-travel-engine' ),
				'name'        => 'login_page_label',
				'divider'     => true,
			),
			array(
				'label'       => __( 'Forgot Page Label', 'wp-travel-engine' ),
				'description' => __( 'Shown as the heading on the reset password page.', 'wp-travel-engine' ),
				'field_type'  => 'TEXT',
				'default'     => __( 'Reset Your Password', 'wp-travel-engine' ),
				'name'        => 'forgot_page_label',
				'divider'     => true,
			),
			array(
				'label'       => __( 'Forgot Page Description', 'wp-travel-engine' ),
				'description' => __( 'Shown under the heading on the reset password page.', 'wp-travel-engine' ),
				'field_type'  => 'TEXTAREA',
				'default'     => __( 'If an account with that email exist, we’ll send you a link to reset your password. Please check your inbox including spam/junk folder.', 'wp-travel-engine' ),
				'name'        => 'forgot_page_description',
				'divider'     => true,
			),
			array(
				'label'       => __( 'Set Password Page Label', 'wp-travel-engine' ),
				'description' => __( 'Shown as the heading on the set new password page.', 'wp-travel-engine' ),
				'field_type'  => 'TEXT',
				'default'     => __( 'Set New Password', 'wp-travel-engine' ),
				'name'        => 'set_password_page_label',
				'divider'     => true,
			),
		),
	),
);
