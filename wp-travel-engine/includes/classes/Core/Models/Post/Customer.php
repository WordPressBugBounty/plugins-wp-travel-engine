<?php
/**
 * Customer Model.
 *
 * @package WPTravelEngine/Core/Models
 * @since 6.0.0
 */

namespace WPTravelEngine\Core\Models\Post;

use WPTravelEngine\Abstracts\PostModel;
use WPTravelEngine\Core\Models\User\BillingDetails;
use WPTravelEngine\Utilities\ArrayUtility;

/**
 * Class Customer.
 * This class represents a customer to the WP Travel Engine plugin.
 *
 * @since 6.0.0
 */
class Customer extends PostModel {

	/**
	 * User Role.
	 */
	const USER_ROLE = 'wp-travel-engine-customer';

	/**
	 * Post type name.
	 *
	 * @var string
	 */
	protected string $post_type = 'customer';

	/**
	 * Customer data.
	 *
	 * @var ?ArrayUtility
	 */
	protected $my_data = null;

	/**
	 * Retrieves customer meta.
	 *
	 * @return array Customer meta
	 */
	public function get_customer_meta() {
		return $this->get_meta( 'wp_travel_engine_booking_setting' ) ?? array();
	}

	/**
	 * Retrieves customer details.
	 *
	 * @return array Customer details
	 */
	public function get_customer_details() {
		$customer_meta    = $this->get_customer_meta();
		$customer_details = $customer_meta['place_order']['booking'] ?? array();

		return $customer_details;
	}

	/**
	 * Retrieves customer first name.
	 *
	 * @return string Customer First Name
	 * @since 6.9.0 Prefers the account's billing record, falling back to the value captured at checkout.
	 */
	public function get_customer_fname() {
		return $this->billing_first( 'first_name', $this->detail( 'fname' ) );
	}

	/**
	 * Retrieves customer last name.
	 *
	 * @return string Customer Last Name
	 * @since 6.9.0 Prefers the account's billing record, falling back to the value captured at checkout.
	 */
	public function get_customer_lname() {
		return $this->billing_first( 'last_name', $this->detail( 'lname' ) );
	}

	/**
	 * Retrieves customer email.
	 *
	 * Read from the customer post alone, never from the billing record: it is the key that
	 * record is found by, so reading it the other way round would not terminate.
	 *
	 * @return string Customer Email
	 */
	public function get_customer_email() {
		return $this->detail( 'email' );
	}

	/**
	 * One value as the customer post itself recorded it.
	 *
	 * @param string $key Detail key, as the checkout submission keys it.
	 * @return string
	 * @since 6.9.0
	 */
	public function detail( string $key ): string {
		$details = $this->get_customer_details();

		return isset( $details[ $key ] ) ? (string) $details[ $key ] : '';
	}

	/**
	 * One value, preferring the billing record — the one the traveler can edit — over what the
	 * checkout captured at the time of booking.
	 *
	 * @param string $slot     Billing slot name.
	 * @param string $fallback Value recorded on the customer post.
	 * @return string
	 * @since 6.9.0
	 */
	public function billing_first( string $slot, string $fallback ): string {
		$billing = $this->billing_details();

		return $billing ? $billing->get( $slot, $fallback ) : $fallback;
	}

	/**
	 * The inverse priority of the getters above: the booking's own values win, and the account
	 * record only fills what the booking never captured.
	 *
	 * @param ?string $user_email Fill the gaps from this account's record rather than from
	 *                            the customer's own; see `billing_details()`.
	 * @return array<string, string> Keyed as the checkout submission keys them.
	 * @since 6.9.0
	 */
	public function get_checkout_defaults( ?string $user_email = null ): array {
		$billing  = $this->billing_details( $user_email );
		$slots    = BillingDetails::slots();
		$defaults = array();

		foreach ( BillingDetails::fields() as $field_key => $field ) {
			$param = BillingDetails::param_for( (string) $field_key );
			$key   = rtrim( substr( strrchr( '[' . ( $field['name'] ?? '' ), '[' ), 1 ), ']' );

			if ( '' === $param || '' === $key ) {
				continue;
			}

			$recorded         = $this->detail( $key );
			$defaults[ $key ] = '' !== $recorded || ! $billing ? $recorded : $billing->get( $slots[ $param ] );
		}

		if ( isset( $defaults['email'] ) && '' === $defaults['email'] && $user_email ) {
			$defaults['email'] = $user_email;
		}

		$details = $this->get_customer_details();
		$choices = BillingDetails::choice_slots();

		foreach ( BillingDetails::custom_params() as $field_key => $meta_key ) {
			$recorded = $details[ $field_key ] ?? '';

			if ( ! isset( $choices[ $meta_key ] ) ) {
				$value                  = is_scalar( $recorded ) ? (string) $recorded : '';
				$defaults[ $field_key ] = '' !== $value || ! $billing ? $value : $billing->get( $meta_key );

				continue;
			}

			$keys = BillingDetails::to_keys( $recorded );

			if ( ! $keys && $billing ) {
				$keys = $billing->get_keys( $meta_key );
			}

			// A select matches one key against each option; a checkbox or radio is rendered
			// against the whole list, so each gets the shape its field type compares.
			$defaults[ $field_key ] = 'select' === $choices[ $meta_key ] ? ( $keys[0] ?? '' ) : $keys;
		}

		return $defaults;
	}

	/**
	 * Retrieves customer address.
	 *
	 * @return string Customer Address
	 * @since 6.9.0 Prefers the account's billing record, falling back to the value captured at checkout.
	 */
	public function get_customer_address() {
		return $this->billing_first( 'address', $this->detail( 'address' ) );
	}

	/**
	 * Retrieves customer city.
	 *
	 * @return string Customer City
	 * @since 6.9.0 Prefers the account's billing record, falling back to the value captured at checkout.
	 */
	public function get_customer_city() {
		return $this->billing_first( 'city', $this->detail( 'city' ) );
	}

	/**
	 * Retrives customer country.
	 *
	 * @since 6.9.0 Prefers the account's billing record, falling back to the value captured at checkout.
	 */
	public function get_customer_country() {
		return $this->billing_first( 'country', $this->detail( 'country' ) );
	}

	/**
	 * Retrieves customer post code.
	 *
	 * @return string Customer Post Code
	 * @since 6.9.0 Prefers the account's billing record, falling back to the value captured at checkout.
	 */
	public function get_customer_postcode() {
		return $this->billing_first( 'zip_code', $this->detail( 'postcode' ) );
	}

	/**
	 * Retrieves customer avatar.
	 *
	 * @return string Customer Avatar
	 * @since 6.4.0
	 */
	public function get_customer_avatar() {
		return get_avatar_url( $this->get_customer_email(), array( 'default' => 'mm' ) );
	}

	/**
	 * Retrieves customer phone.
	 *
	 * @return string Customer Phone
	 * @since 6.4.0
	 * @since 6.9.0 Prefers the account's billing record, falling back to the value captured at checkout.
	 */
	public function get_customer_phone() {
		return $this->billing_first( 'phone', $this->detail( 'phone' ) );
	}

	/**
	 * Retrieves customer state.
	 *
	 * @return string Customer State
	 * @since 6.4.0
	 * @since 6.9.0 Prefers the account's billing record, falling back to the value captured at checkout.
	 */
	public function get_customer_state() {
		return $this->billing_first( 'state', $this->detail( 'state' ) );
	}

	/**
	 * Retrieves customer notes.
	 *
	 * @return string Customer Notes
	 */
	public function get_customer_notes() {
		$customer_details = $this->get_customer_details();

		return $customer_details['notes'] ?? '';
	}

	/**
	 * Retrieves the customer's bookings.
	 *
	 * @return array List of Booking models.
	 *
	 * @since 6.9.0 primed the post cache alongside the meta cache.
	 */
	public function get_customer_bookings() {
		$bookings_ids = $this->get_meta( 'wp_travel_engine_bookings' );
		$bookings     = array();

		if ( is_array( $bookings_ids ) && ! empty( $bookings_ids ) ) {
			_prime_post_caches( $bookings_ids, false, false );
			update_meta_cache( 'post', $bookings_ids );
		}

		foreach ( is_array( $bookings_ids ) ? $bookings_ids : array() as $booking_id ) {
			$booking = wptravelengine_get_booking( $booking_id );
			if ( $booking instanceof Booking ) {
				$bookings[] = $booking;
			}
		}

		return $bookings;
	}

	/**
	 * Retrieves customer booked trip details.
	 *
	 * @return array Customer Booked Trip Details
	 */
	public function get_customer_booked_trip_settings() {
		return $this->get_meta( 'wp_travel_engine_booked_trip_setting' ) ?? array();
	}

	/**
	 * Customer detail info.
	 *
	 * @return array Customer Detail Info
	 * @since 6.4.0
	 * @since 6.9.0 Reads through the model getters, so the account's billing record wins over the user profile.
	 */
	public function get_customer_info() {
		return array(
			'fname' => $this->get_customer_fname(),
			'lname' => $this->get_customer_lname(),
			'email' => $this->get_customer_email(),
			'phone' => $this->get_customer_phone(),
		);
	}

	/**
	 * The billing record of the user this customer is, when they have an account at all.
	 *
	 * @param ?string $email Read a given account's record instead of the customer's own —
	 *                       the checkout does this, where the traveler filling the form is
	 *                       not necessarily the email the customer post is titled with.
	 * @return ?BillingDetails Null when no user is registered under that email.
	 * @since 6.9.0
	 */
	public function billing_details( ?string $email = null ): ?BillingDetails {
		$email = $email ?? ( $this->get_customer_email() ? $this->get_customer_email() : $this->get_title() );

		return $email ? wptravelengine_get_user( $email ) : null;
	}

	/**
	 * Retrieves customer all address info.
	 *
	 * @return array Customer Address Info
	 * @since 6.4.0
	 * @since 6.9.0 Reads through the model getters, so the account's billing record wins over the user profile.
	 */
	public function get_customer_addresses() {
		return array(
			'address'  => $this->get_customer_address(),
			'city'     => $this->get_customer_city(),
			'state'    => $this->get_customer_state(),
			'postcode' => $this->get_customer_postcode(),
			'country'  => $this->get_customer_country(),
		);
	}

	/**
	 * Register the customer as a WP user account when enabled.
	 *
	 * @param bool $force Force registration regardless of the generate_user_account setting.
	 * @return bool True when a new user account was created, false otherwise.
	 * @since 6.9.0 Returns whether a new account was created (previously void).
	 */
	public function maybe_register_as_user( bool $force = false ): bool {
		$register_user = $force || wptravelengine_settings()->is( 'generate_user_account', 'yes' );

		if ( $register_user ) {
			$email_address = trim( $this->get_title() );
			$email_address = sanitize_email( $email_address );

			if ( is_email( $email_address ) && ! email_exists( $email_address ) ) {
				$userdata = apply_filters(
					'wp_travel_engine_new_customer_data',
					array(
						'user_login' => $email_address,
						'user_pass'  => '',
						'user_email' => $email_address,
						'role'       => static::USER_ROLE,
						'first_name' => $this->get_customer_fname(),
						'last_name'  => $this->get_customer_lname(),
					)
				);

				$user_id = wp_insert_user( $userdata );

				if ( is_wp_error( $user_id ) ) {
					error_log(
						sprintf(
							'Failed to create user for customer %d: %s',
							$this->get_id(),
							$user_id->get_error_message()
						)
					);
					return false;
				}

				update_user_meta( $user_id, 'customer_id', $this->get_id() );
				$record = wptravelengine_get_user( $user_id );

				if ( $record ) {
					$record->put(
						array(
							'address'  => $this->get_customer_address(),
							'city'     => $this->get_customer_city(),
							'zip_code' => $this->get_customer_postcode(),
							'country'  => $this->get_customer_country(),
							'phone'    => $this->get_customer_phone(),
						)
					);
				}
				do_action( 'wp_travel_engine_created_customer', $user_id, $userdata, true, 'emails/customer-new-account.php' );

				return true;
			}
		}

		return false;
	}

	/**
	 * Create a new post for this post-type.
	 *
	 * @return $this
	 */
	public static function create_post( array $postarr ): Customer {
		/* @var $model Customer */
		$model = parent::create_post( $postarr );

		return $model;
	}

	/**
	 * Update customer bookings.
	 *
	 * @param int $booking_id
	 *
	 * @return $this
	 */
	public function update_customer_bookings( int $booking_id ): Customer {
		$customer_bookings   = $this->get_meta( 'wp_travel_engine_bookings' );
		$customer_bookings   = ! is_array( $customer_bookings ) ? array() : $customer_bookings;
		$customer_bookings[] = $booking_id;

		return $this->set_meta( 'wp_travel_engine_bookings', array_unique( $customer_bookings ) );
	}

	/**
	 * Update customer user meta for bookings.
	 *
	 * @param int $booking_id
	 *
	 * @return void
	 */
	public function update_customer_meta( int $booking_id ): void {

		$meta_mappings = array(
			'wp_travel_engine_bookings' => 'wp_travel_engine_user_bookings',
		);

		$billing_info  = get_post_meta( $booking_id, 'wptravelengine_billing_details', true );
		$billing_email = $billing_info['email'] ?? $this->get_customer_email();

		if ( empty( $billing_email ) || ! is_email( $billing_email ) ) {
			return;
		}

		$user           = get_user_by( 'email', $billing_email );
		$logged_in_user = null;

		if ( is_user_logged_in() ) {
			$logged_in_user = wp_get_current_user();
		}

		// Collect users to update (avoid duplicates).
		$users_to_update = array();
		if ( $user instanceof \WP_User ) {
			$users_to_update[ $user->ID ] = $user;
		}
		if ( $logged_in_user instanceof \WP_User && ! isset( $users_to_update[ $logged_in_user->ID ] ) ) {
			$users_to_update[ $logged_in_user->ID ] = $logged_in_user;
		}

		// Update user meta for all relevant users.
		if ( ! empty( $users_to_update ) ) {
			foreach ( $this->data['__changes'] as $meta_key => $meta_value ) {
				if ( isset( $meta_mappings[ $meta_key ] ) && 'wp_travel_engine_bookings' === $meta_key ) {
					// Get the new bookings array from the customer meta changes
					$new_bookings = is_array( $meta_value ) ? $meta_value : array();

					foreach ( $users_to_update as $user_to_update ) {
						// Get existing user bookings
						$existing_bookings = get_user_meta( $user_to_update->ID, 'wp_travel_engine_user_bookings', true );
						$existing_bookings = is_array( $existing_bookings ) ? $existing_bookings : array();

						// Merge and deduplicate bookings
						$merged_bookings = array_unique( array_merge( $existing_bookings, $new_bookings ) );

						update_user_meta( $user_to_update->ID, 'wp_travel_engine_user_bookings', $merged_bookings );
					}
				}
			}
		}
	}

	/**
	 * Checks if customer exists
	 *
	 * @param string $email Customer email, matched exactly against the customer post title.
	 * @return int|false Customer post ID, or false when none matches.
	 * @since 6.9.0 Match the email exactly instead of a LIKE '%email%' substring, which let a suffix resolve a foreign customer (CWE-863).
	 */
	public static function is_exists( string $email ) {
		global $wpdb;

		if ( empty( $email ) ) {
			return false;
		}

		$prepared_statement = $wpdb->prepare( "SELECT `ID` FROM {$wpdb->posts} WHERE `post_title` = %s AND `post_type` = %s", sanitize_email( $email ), 'customer' );

		return $wpdb->get_row( $prepared_statement )->ID ?? false;
	}

	/**
	 * @param $meta_key
	 * @param $meta_value
	 *
	 * @return $this
	 * @since 6.4.0
	 */
	public function set_my_meta( $meta_key, $meta_value ): Customer {

		$this->my_data ??= ArrayUtility::make( $this->get_customer_meta() );

		$this->my_data->set( $meta_key, $meta_value );

		return parent::set_meta( 'wp_travel_engine_booking_setting', $this->my_data->value() );
	}

	/**
	 * Set customer details.
	 *
	 * @param array $customer_details
	 *
	 * @return void
	 * @since 6.4.0
	 */
	public function set_customer_details( array $customer_details ): void {
		foreach ( $customer_details as $key => $value ) {
			$this->set_my_meta( 'place_order.booking.' . $key, $value );
		}
	}

	/**
	 * Retrieves the user ID for a given email.
	 *
	 * @param string $email The email address to search for.
	 *
	 * @return int|false The user ID if found, false otherwise.
	 * @since 6.4.0
	 */
	public function get_user_id( string $email ) {
		$user = get_user_by( 'email', $email );

		return $user->ID ?? false;
	}

	/**
	 * @return array
	 * @since 6.5.2
	 */
	public function get_data(): array {
		return array(
			'id'         => $this->get_id(),
			'email'      => $this->get_customer_email(),
			'first_name' => $this->get_customer_fname(),
			'last_name'  => $this->get_customer_lname(),
			'address'    => $this->get_customer_address(),
			'city'       => $this->get_customer_city(),
			'state'      => $this->get_customer_state(),
			'country'    => $this->get_customer_country(),
			'postcode'   => $this->get_customer_postcode(),
			'phone'      => $this->get_customer_phone(),
		);
	}

	/**
	 * Logs the traveler into the account just created for them at checkout, for a limited time.
	 *
	 * Call only after maybe_register_as_user() returned true: that guarantees the email had no
	 * account before this request, so it cannot sign anyone into an existing account.
	 *
	 * @return void
	 * @since 6.9.0
	 */
	public function maybe_login(): void {
		if ( is_user_logged_in() || 'auto' !== wptravelengine_account_creation_mode() ) {
			return;
		}

		$user = get_user_by( 'email', sanitize_email( trim( $this->get_title() ) ) );

		if ( ! $user ) {
			return;
		}

		/**
		 * Filters how long, in seconds, a traveler stays logged in after checkout created their account.
		 *
		 * @param int      $duration Session length in seconds.
		 * @param \WP_User $user     The new account.
		 * @since 6.9.0
		 */
		$duration = (int) apply_filters( 'wptravelengine_checkout_auto_login_duration', 4 * HOUR_IN_SECONDS, $user );

		$expiration = static function () use ( $duration ) {
			return $duration;
		};

		add_filter( 'auth_cookie_expiration', $expiration, PHP_INT_MAX );
		wp_set_auth_cookie( $user->ID, true );
		remove_filter( 'auth_cookie_expiration', $expiration, PHP_INT_MAX );
	}
}
