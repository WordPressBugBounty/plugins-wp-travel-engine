<?php

/**
 * Place order form for personal details.
 *
 * @package    Wp_Travel_Engine
 * @subpackage Wp_Travel_Engine/includes
 * @author
 */
class Wp_Travel_Engine_Order_Confirmation {

	/**
	 * Initialize the final confirmation form shortcode.
	 *
	 * @since 1.0
	 */
	function init() {
//		add_shortcode( 'WP_TRAVEL_ENGINE_BOOK_CONFIRMATION', array( $this, 'wp_travel_engine_confirmation_shortcodes_callback' ) );
	}

	/**
	 * Final confirmation form shortcode callback function.
	 *
	 * @since 1.0
	 */
	function wp_travel_engine_confirmation_shortcodes_callback() {
		if ( is_admin() ) {
			return '';
		}

		if ( defined( 'WTE_USE_OLD_BOOKING_PROCESS' ) && WTE_USE_OLD_BOOKING_PROCESS ) {
			wp_die( new \WP_Error( 'WTE_ERROR', esc_html__( 'WP Travel Engine no more supports old booking process.', 'wp-travel-engine' ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

			return;
		}

		ob_start();
		wte_get_template( 'traveller-information/template-traveler-info.php' );

		return ob_get_clean();

	}

	public function is_customer_exists( $email ) {
		global $wpdb;
		// Sanitize the email for security and consistency
		$sanitized_email = sanitize_email( $email );

		$prepared_statement = $wpdb->prepare( "SELECT `ID` FROM {$wpdb->posts} WHERE `post_title` LIKE %s", '%' . $wpdb->esc_like( $sanitized_email ) . '%' );

		$result = $wpdb->get_row( $prepared_statement );

		return is_null( $result ) ? false : $result->ID;
	}

	public function get_customer_post_object( $email ) {
		if ( $customer_id = $this->is_customer_exists( $email ) ) {
			return get_post( $customer_id );
		}

		return false;
	}

	/**
	 * Insert new customer.
	 *
	 * @since 1.0
	 */
	function insert_customer( &$order_metas ) {
		global $wte_cart;
		if ( ! is_admin() ) {
			require_once ABSPATH . 'wp-admin/includes/post.php';
		}
		$booked_trip_ids = $wte_cart->get_cart_trip_ids();
		$trip            = $booked_trip_ids[ '0' ];
		$trip            = get_post( $trip );

		if ( wp_travel_engine_use_old_booking_process() ) {
			wp_die( new \WP_Error( 'WTE_ERROR', esc_html__( 'WP Travel Engine no more supports old booking process.', 'wp-travel-engine' ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

			return;
		}

		$customer_email = '';

		if ( isset( $order_metas[ 'place_order' ][ 'booking' ][ 'email' ] ) ) {
			$customer_email = $order_metas[ 'place_order' ][ 'booking' ][ 'email' ];
		} else {
			if ( isset( $order_metas[ 'additional_fields' ] ) && isset( $order_metas[ 'additional_fields' ][ 'billing_email' ] ) ) {
				$customer_email = $order_metas[ 'additional_fields' ][ 'billing_email' ];
			}
		}

		$customer_post_object = $this->get_customer_post_object( $customer_email );

		if ( ! $customer_post_object ) {

			$post_id = wp_insert_post(
				array(
					'post_status' => 'publish',
					'post_type'   => 'customer',
					'post_title'  => $customer_email,
				)
			);

			$customer_post_object = get_post( $post_id );

			update_post_meta( $post_id, 'wp_travel_engine_booking_setting', $order_metas );
		}

		/**
		 * Automatically Generate User Account.
		 *
		 * @since 8.5.2
		 */
		$settings              = wptravelengine_settings()->get();
		$generate_user_account = $settings[ 'generate_user_account' ] ?? 'no';

		if ( ! is_user_logged_in() ) {
			if ( 'yes' === $generate_user_account && ! empty( $customer_email ) ) {
				$results = $this->create_subscriber( $customer_email );

				if ( is_wp_error( $results ) ) {
					$error_message = $results->get_error_message();
					echo '<div class="error">' . esc_html( $error_message ) . '</div>';
				}

				$saved_booking_ids = get_user_meta( $results, 'wp_travel_engine_user_bookings', true );
				if ( ! $saved_booking_ids || ! is_array( $saved_booking_ids ) ) {
					$saved_booking_ids = array( $order_metas[ 0 ] );
				} else {
					$saved_booking_ids[] = $order_metas[ 0 ];
				}
				update_user_meta( $results, 'wp_travel_engine_user_bookings', $saved_booking_ids );
			}
		}

		$booked_trip_setting = get_post_meta( $customer_post_object->ID, 'wp_travel_engine_booked_trip_setting', true );

		if ( ! is_array( $booked_trip_setting ) ) {
			$booked_trip_setting = array(
				'traveler' => array(),
			);
		}

		$size = count( $booked_trip_setting[ 'traveler' ] );

		$order_data = array();
		foreach ( $order_metas[ 'place_order' ] as $key => $value ) {
			$order_data[ $key ][ $size + 1 ] = $value;
		}

		unset( $order_data[ 'booking' ] ); // Remove Booking Data.

		$updated_booked_trip_setting = array_merge_recursive( $booked_trip_setting, $order_data );

		update_post_meta( $customer_post_object->ID, 'wp_travel_engine_booked_trip_setting', $updated_booked_trip_setting );

		$customer_bookings = get_post_meta( $customer_post_object->ID, 'wp_travel_engine_bookings', true );

		if ( ! is_array( $customer_bookings ) ) {
			$customer_bookings = array();
		}

		if ( isset( $order_metas[ 0 ] ) && is_numeric( $order_metas[ 0 ] ) ) {
			$customer_bookings[] = $order_metas[ 0 ];
		}
		update_post_meta( $customer_post_object->ID, 'wp_travel_engine_bookings', $customer_bookings );

		if ( is_user_logged_in() ) {
			$user              = wp_get_current_user();
			$saved_booking_ids = get_user_meta( $user->ID, 'wp_travel_engine_user_bookings', true );
			if ( ! is_array( $saved_booking_ids ) ) {
				$saved_booking_ids = array();
			}
			$saved_booking_ids[] = $order_metas[ 0 ];
			update_user_meta( $user->ID, 'wp_travel_engine_user_bookings', $saved_booking_ids );
		}
	}

	/**
	 * Generate User Account.
	 *
	 * @since 8.5.2
	 */
	function create_subscriber( $email ) {
		// Check the email address.
		if ( empty( $email ) || ! is_email( $email ) ) {
			return new WP_Error( 'registration-error-invalid-email', __( 'Please provide a valid email address.', 'wp-travel-engine' ) );
		}

		// Check if email address exists.
		if ( email_exists( $email ) ) {
			$exists_id = email_exists( $email );

			return $exists_id;
		}

		// Generate Password.
		$password           = wp_generate_password();
		$password_generated = true;

		$new_customer_data = apply_filters(
			'wp_travel_engine_new_customer_data',
			array(
				'user_login' => $email,
				'user_pass'  => $password,
				'user_email' => $email,
				'role'       => 'wp-travel-engine-customer',
			)
		);

		$customer_id = wp_insert_user( $new_customer_data );

		if ( is_wp_error( $customer_id ) ) {
			return new WP_Error( 'registration-error', __( 'Error:', 'wp-travel-engine' ) . __( 'Couldn&#8217;t register you&hellip; please contact us if you continue to have problems.', 'wp-travel-engine' ) );
		}

		do_action( 'wp_travel_engine_created_customer', $customer_id, $new_customer_data, $password_generated, $template = 'emails/customer-new-account.php' );

		return $customer_id;

	}

}
