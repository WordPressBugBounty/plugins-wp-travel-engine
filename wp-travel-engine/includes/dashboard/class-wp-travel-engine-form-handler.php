<?php
// Form handling for Dashboard.
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

use WPTravelEngine\Core\Models\User\BillingDetails;
use WPTravelEngine\Core\Models\Post\Customer;
use WPTravelEngine\Core\Shortcodes\UserAccount;

/**
 * Handle frontend forms.
 *
 * @class       Wp_Travel_Engine_Form_Handler
 * @version     1.3.3
 * @category    Class
 */
class Wp_Travel_Engine_Form_Handler {

	/**
	 * Customer meta key for lead traveller (index 0).
	 *
	 * @var string
	 */
	const LEAD_TRAVELLER_META_KEY = 'wptravelengine_traveller_details.0';

	/**
	 * Hook in methods.
	 */
	public static function init() {
		add_action( 'template_redirect', array( __CLASS__, 'redirect_reset_password_link' ) );
		add_action( 'template_redirect', array( __CLASS__, 'save_account_details' ) );
		add_action( 'template_redirect', array( __CLASS__, 'update_user_billing_data' ) );
		add_action( 'wp_loaded', array( __CLASS__, 'process_login' ), 20 );
		add_action( 'wp_loaded', array( __CLASS__, 'process_registration' ), 20 );
		add_action( 'wp_loaded', array( __CLASS__, 'process_lost_password' ), 20 );
		add_action( 'wp_loaded', array( __CLASS__, 'process_reset_password' ), 20 );
	}

	/**
	 * Process the login form.
	 */
	public static function process_login() {

		if ( isset( $_POST['_wpnonce'] ) ) {
			$nonce_value = sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) );
		}

		if ( isset( $_POST['wp-travel-engine-login-nonce'] ) ) {
			$nonce_value = sanitize_text_field( wp_unslash( $_POST['wp-travel-engine-login-nonce'] ) );
		}

		if ( ! isset( $nonce_value ) ) {
			return;
		}

		if ( ! empty( $_POST['login'] ) && wp_verify_nonce( $nonce_value, 'wp-travel-engine-login' ) ) {

			try {
				$creds = array();
				if ( isset( $_POST['username'] ) ) {
					$creds['user_login'] = trim( sanitize_text_field( wp_unslash( $_POST['username'] ) ) );
				}
				if ( isset( $_POST['password'] ) ) {
					$creds['user_password'] = trim( sanitize_text_field( wp_unslash( $_POST['password'] ) ) );
				}
				if ( isset( $_POST['rememberme'] ) ) {
					$creds['remember'] = trim( sanitize_text_field( wp_unslash( $_POST['rememberme'] ) ) );
				}

				$validation_error = new WP_Error();
				$validation_error = apply_filters( 'wp_travel_engine_process_login_errors', $validation_error, $creds['user_login'], $creds['user_password'] );

				if ( $validation_error->get_error_code() ) {
					throw new Exception( __( 'Error:', 'wp-travel-engine' ) . $validation_error->get_error_message() );
				}

				if ( empty( $creds['user_login'] ) ) {
					throw new Exception( __( 'Error:', 'wp-travel-engine' ) . __( 'Username is required.', 'wp-travel-engine' ) );
				}

				// On multisite, ensure user exists on current site, if not add them before allowing login.
				if ( is_multisite() ) {
					$user_data = get_user_by( is_email( $creds['user_login'] ) ? 'email' : 'login', $creds['user_login'] );

					if ( $user_data && ! is_user_member_of_blog( $user_data->ID, get_current_blog_id() ) ) {
						add_user_to_blog( get_current_blog_id(), $user_data->ID, 'wp-travel-engine-customer' );
					}
				}

				// Perform the login.
				$user = wp_signon( apply_filters( 'wp_travel_login_credentials', $creds ), is_ssl() );

				if ( is_wp_error( $user ) ) {
					$message = $user->get_error_message();
					$message = str_replace( '<strong>' . esc_html( $creds['user_login'] ) . '</strong>', '<strong>' . esc_html( $creds['user_login'] ) . '</strong>', $message );
					throw new Exception( $message );
				} else {

					if ( ! empty( $_POST['redirect'] ) ) {
						$redirect = wp_sanitize_redirect( wp_unslash( $_POST['redirect'] ) );
					} elseif ( wp_travel_engine_get_raw_referer() ) {
						$redirect = wp_travel_engine_get_raw_referer();
					} else {
						$redirect = wp_travel_engine_get_page_permalink_by_id( wp_travel_engine_get_dashboard_page_id() );
					}

					wp_redirect( wp_validate_redirect( apply_filters( 'wp_travel_engine_login_redirect', remove_query_arg( 'wp_travel_engine_error', $redirect ), $user ), wp_travel_engine_get_page_permalink_by_id( wp_travel_engine_get_dashboard_page_id() ) ) );

					exit;
				}
			} catch ( Exception $e ) {

				WTE()->notices->add( apply_filters( 'wp_travel_engine_login_errors', __( 'Error :  Invalid Username or Password', 'wp-travel-engine' ) ), 'error' );

				wp_safe_redirect( remove_query_arg( 'password-reset', wp_travel_engine_get_page_permalink_by_id( wp_travel_engine_get_dashboard_page_id() ) ) );

				exit;
			}
		} elseif ( isset( $_POST['username'] ) && empty( $_POST['username'] ) && wp_verify_nonce( $nonce_value, 'wp-travel-engine-login' ) ) {

			WTE()->notices->add( apply_filters( 'wp_travel_engine_login_errors', __( 'Error :  Username can not be empty', 'wp-travel-engine' ) ), 'error' );

			wp_safe_redirect( remove_query_arg( 'password-reset', wp_travel_engine_get_page_permalink_by_id( wp_travel_engine_get_dashboard_page_id() ) ) );

			exit;

		}
	}

	/**
	 * Process the registration form.
	 */
	public static function process_registration() {

		if ( isset( $_POST['_wpnonce'] ) ) {
			$nonce_value = sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) );
		}

		if ( isset( $_POST['wp-travel-engine-register-nonce'] ) ) {
			$nonce_value = sanitize_text_field( wp_unslash( $_POST['wp-travel-engine-register-nonce'] ) );
		}

		if ( ! empty( $_POST['register'] ) && wp_verify_nonce( $nonce_value, 'wp-travel-engine-register' ) ) {

			if ( isset( $_POST['username'] ) ) {
				$username = sanitize_text_field( wp_unslash( $_POST['username'] ) );
			}
			if ( isset( $_POST['password'] ) ) {
				$password = sanitize_text_field( wp_unslash( $_POST['password'] ) );
			}
			if ( isset( $_POST['email'] ) ) {
				$email = sanitize_email( wp_unslash( $_POST['email'] ) );
			}

			try {
				$validation_error = new WP_Error();
				$validation_error = apply_filters( 'wp_travel_process_registration_errors', $validation_error, $username, $password, $email );

				if ( $validation_error->get_error_code() ) {
					throw new Exception( $validation_error->get_error_message() );
				}

				$new_customer = wp_travel_engine_create_new_customer( sanitize_email( $email ), $username, $password );

				if ( is_wp_error( $new_customer ) ) {
					throw new Exception( $new_customer->get_error_message() );
				}

				if ( apply_filters( 'wp_travel_registration_auth_new_customer', true, $new_customer ) ) {
					wp_travel_engine_set_customer_auth_cookie( $new_customer );
				}

				// Create customer post if it doesn't exist and set lead traveller from user.
				$customer_id = Customer::is_exists( $email );
				if ( ! $customer_id ) {
					$user = get_user_by( 'id', $new_customer );
					if ( $user instanceof \WP_User ) {
						try {
							$customer_model   = self::get_or_create_customer( $email );
							$customer_details = array(
								'email' => $email,
								'fname' => $user->first_name,
								'lname' => $user->last_name,
							);
							$customer_model->set_customer_details( $customer_details );
							self::sync_lead_traveller( $customer_model, $customer_details );
							$customer_id = $customer_model->get_id();
						} catch ( \Exception $e ) {
							if ( function_exists( 'error_log' ) ) {
								error_log( sprintf( 'WP Travel Engine: Customer creation/sync failed during registration for user %d: %s', (int) $new_customer, $e->getMessage() ) );
							}
						}
					} elseif ( function_exists( 'error_log' ) ) {
							error_log( sprintf( 'WP Travel Engine: Failed to retrieve user %d during registration; customer record not created.', (int) $new_customer ) );
					}
				}

				// Update user meta if bookings have been made before registration.
				if ( $customer_id ) {
					$customer_bookings = get_post_meta( $customer_id, 'wp_travel_engine_bookings', true );
					if ( $customer_bookings ) {
						update_user_meta( $new_customer, 'wp_travel_engine_user_bookings', $customer_bookings );
					}
				}

				if ( ! empty( $_POST['redirect'] ) ) {
					$redirect = wp_sanitize_redirect( wp_unslash( $_POST['redirect'] ) );
				} elseif ( wp_travel_engine_get_raw_referer() ) {
					$redirect = wp_travel_engine_get_raw_referer();
				} else {
					$redirect = wp_travel_engine_get_page_permalink_by_id( wp_travel_engine_get_dashboard_page_id() );
				}

				wp_redirect( wp_validate_redirect( apply_filters( 'wp_travel_register_redirect', remove_query_arg( 'wp_travel_error', $redirect ), $username ), wp_travel_engine_get_page_permalink_by_id( wp_travel_engine_get_dashboard_page_id() ) ) );
				exit;

			} catch ( Exception $e ) {
				WTE()->notices->add( __( 'Error:', 'wp-travel-engine' ) . $e->getMessage(), 'error' );
			}
		}
	}

	/**
	 * Handle lost password form.
	 */
	public static function process_lost_password() {
		if ( isset( $_POST['_wpnonce'] ) ) {
			if ( wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), 'wp_travel_engine_lost_password' ) && isset( $_POST['wp_travel_engine_reset_password'] ) && isset( $_POST['user_login'] ) ) {
				$success = UserAccount::retrieve_password();

				// If successful, redirect to my account with query arg set.
				if ( $success ) {
					wp_redirect( add_query_arg( 'reset-link-sent', 'true', wp_travel_engine_lostpassword_url() ) );
					exit;
				}
			}
		}
	}

	/**
	 * Handle reset password form.
	 */
	public static function process_reset_password() {
		$posted_fields = array(
			'wp_travel_engine_reset_password',
			'password_1',
			'password_2',
			'reset_key',
			'reset_login',
			'_wpnonce',
		);

		foreach ( $posted_fields as $field ) {
			if ( ! isset( $_POST[ $field ] ) ) {
				return;
			}
			$posted_fields[ $field ] = sanitize_text_field( wp_unslash( $_POST[ $field ] ) );
		}

		if ( ! wp_verify_nonce( $posted_fields['_wpnonce'], 'wp_travel_engine_reset_password_nonce' ) ) {
			return;
		}

		$user = UserAccount::check_password_reset_key( $posted_fields['reset_key'], $posted_fields['reset_login'] );

		if ( $user instanceof WP_User ) {
			if ( empty( $posted_fields['password_1'] ) ) {
				WTE()->notices->add( __( 'Error :  Please enter your password.', 'wp-travel-engine' ), 'error' );
			}

			if ( $posted_fields['password_1'] !== $posted_fields['password_2'] ) {
				WTE()->notices->add( __( 'Error :  Passwords do not match', 'wp-travel-engine' ), 'error' );
			}

			$errors = new WP_Error();

			do_action( 'validate_password_reset', $errors, $user );

			wp_travel_engine_add_wp_error_notices( $errors );

			if ( 0 === wp_travel_engine_get_notice_count( 'error' ) ) {
				UserAccount::reset_password( $user, $posted_fields['password_1'] );

				do_action( 'wp_travel_customer_reset_password', $user );

				wp_redirect( add_query_arg( 'password-reset', 'true', wp_travel_engine_get_page_permalink_by_id( wp_travel_engine_get_dashboard_page_id() ) ) );
				exit;
			}
		}
	}

	/**
	 * Remove key and login from query string, set cookie, and redirect to account page to show the form.
	 */
	public static function redirect_reset_password_link() {

		if ( wp_travel_engine_is_account_page() && ! empty( $_GET['key'] ) && ! empty( $_GET['login'] ) ) {

			$value = sprintf( '%s:%s', sanitize_text_field( wp_unslash( $_GET['login'] ) ), sanitize_text_field( wp_unslash( $_GET['key'] ) ) );

			UserAccount::set_reset_password_cookie( $value );

			wp_safe_redirect( add_query_arg( 'show-reset-form', 'true', wp_travel_engine_lostpassword_url() ) );
			exit;
		}
	}

	/**
	 * Update User Billing Data.
	 *
	 * @since 6.9.0 Validation and persistence extracted to persist_billing_details() so the
	 *              account REST route can reuse them; this method now only handles the request.
	 */
	public static function update_user_billing_data() {

		if ( 'POST' !== strtoupper( $_SERVER['REQUEST_METHOD'] ) ) {
			return;
		}

		if ( empty( $_POST['action'] ) || 'wp_travel_engine_save_user_meta_billing_address' !== $_POST['action'] || empty( $_POST['wp_billing_address_security'] ) || ! wp_verify_nonce( $_POST['wp_billing_address_security'], 'wp_travel_engine_save_user_meta_billing_address' ) ) {
			return;
		}

		nocache_headers();

		$user_id = get_current_user_id();

		if ( $user_id <= 0 ) {
			return;
		}

		$result = self::persist_billing_details( $user_id, wp_unslash( $_POST ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified above.

		if ( is_wp_error( $result ) ) {
			wp_travel_engine_add_wp_error_notices( $result );

			return;
		}

		WTE()->notices->add( __( 'Billing Details Updated Successfully', 'wp-travel-engine' ), 'success' );

		wp_safe_redirect( wp_travel_engine_get_page_permalink_by_id( wp_travel_engine_get_dashboard_page_id() ) );
		exit;
	}

	/**
	 * Replaces every locked billing field in a submission with the value already stored.
	 *
	 * Overwrites rather than rejects: a locked field posted unchanged must not fail the save,
	 * and one posted edited must not land.
	 *
	 * @param int   $user_id User being saved.
	 * @param array $data    Submitted values.
	 *
	 * @return array Values with locked fields forced back to what is stored.
	 * @since 6.9.0
	 */
	protected static function apply_locked_billing_fields( int $user_id, array $data ): array {
		$locked = BillingDetails::locked_params();

		if ( ! $locked ) {
			return $data;
		}

		$stored = get_user_meta( $user_id, 'wp_travel_engine_customer_billing_details', true );
		$stored = is_array( $stored ) ? $stored : array();

		foreach ( $locked as $field => $meta_key ) {
			$data[ $field ] = (string) ( $stored[ $meta_key ] ?? '' );
		}

		return $data;
	}

	/**
	 * Validates and persists a user's billing details.
	 *
	 * Shared by the classic dashboard form and the `my/billing` REST route. Authorising
	 * $user_id is the caller's job.
	 *
	 * @param int   $user_id User ID.
	 * @param array $data    Unslashed values keyed by form field name, e.g. `customer_billing_address`.
	 *
	 * @return true|WP_Error True on success, or one message per missing required field.
	 * @since 6.9.0
	 * @since 6.9.0 Billing name and email are stored on the billing record itself; locked
	 *              fields are forced to their stored values before anything is written; the
	 *              saved name and email are written through to the WordPress user.
	 */
	public static function persist_billing_details( int $user_id, array $data ) {

		$data = self::apply_locked_billing_fields( $user_id, $data );

		$value = fn ( string $key ) => ! empty( $data[ $key ] ) ? wp_travel_engine_clean_vars( $data[ $key ] ) : '';

		$data_array = array();

		// Custom checkout questions keep their answer in the same record, under their own key.
		// Written first so the fixed slots below always win if a key ever collided.
		foreach ( BillingDetails::custom_params() as $param => $meta_key ) {
			$data_array[ $meta_key ] = $value( $param );
		}

		$locked = BillingDetails::locked_params();

		foreach ( BillingDetails::params() as $param => $meta_key ) {
			// A locked slot is not written at all, rather than written back as whatever is
			// stored: re-writing it puts an empty value over a slot the traveler cannot fill,
			// and `put()` merges over what is there, so leaving it out preserves it.
			if ( isset( $locked[ $param ] ) ) {
				continue;
			}

			$data_array[ $meta_key ] = $value( $param );
		}

		$billing_email = $data_array['billing_email'] ?? '';

		$errors = new WP_Error();

		foreach ( BillingDetails::required_params() as $param => $label ) {
			if ( ! empty( $data[ $param ] ) ) {
				continue;
			}

			/* translators: %s: Name of the required field. */
			$errors->add( 'wptravelengine_required_field', sprintf( __( 'Error :   %s is a required field.', 'wp-travel-engine' ), esc_html( $label ) ) );
		}

		// The billing email is who invoices go to, not who signs in, so it is validated as an
		// address but never checked for uniqueness against other accounts.
		if ( '' !== $billing_email && ! is_email( $billing_email ) ) {
			$errors->add( 'wptravelengine_invalid_email', __( 'Please Provide a valid email address', 'wp-travel-engine' ) );
		}

		if ( $errors->has_errors() ) {
			return $errors;
		}

		if ( array_key_exists( 'billing_email', $data_array ) ) {
			$data_array['billing_email'] = '' !== $billing_email ? sanitize_email( $billing_email ) : '';
		}

		$record = wptravelengine_get_user( $user_id );

		if ( ! $record ) {
			return new WP_Error( 'wptravelengine_invalid_user', __( 'Could not find the account to save against.', 'wp-travel-engine' ) );
		}

		$record->put( $data_array );

		// Read back rather than reusing what was written: a locked slot was left out of the
		// write, and the syncs below still need the value the record actually holds.
		$stored = array();

		foreach ( BillingDetails::params() as $meta_key ) {
			$stored[ $meta_key ] = $record->get( $meta_key );
		}

		$synced = self::sync_account_from_billing( $user_id, $stored );

		if ( is_wp_error( $synced ) ) {
			return $synced;
		}

		// Sync billing data to customer post lead traveller.
		$current_user   = get_user_by( 'id', $user_id );
		$customer_model = self::get_or_create_customer( $current_user->user_email );
		self::sync_lead_traveller(
			$customer_model,
			array(
				'address'  => $stored['billing_address'] ?? '',
				'city'     => $stored['billing_city'] ?? '',
				'state'    => $stored['billing_state'] ?? '',
				'postcode' => $stored['billing_zip_code'] ?? '',
				'country'  => $stored['billing_country'] ?? '',
				'phone'    => $stored['billing_phone'] ?? '',
				'company'  => $stored['billing_company'] ?? '',
				'fname'    => $stored['billing_first_name'] ?? '',
				'lname'    => $stored['billing_last_name'] ?? '',
				'email'    => $stored['billing_email'] ?? '',
			)
		);

		do_action( 'wp_travel_engine_save_billing_details', $user_id );

		return true;
	}

	/**
	 * Save the password/account details and redirect back to the my account page.
	 *
	 * @since 6.9.0 Validation and persistence extracted to persist_account_details() and the
	 *              profile-photo handling to persist_profile_image(), so the account REST route
	 *              can reuse them; this method now only handles the request.
	 */
	public static function save_account_details() {
		if ( 'POST' !== strtoupper( $_SERVER['REQUEST_METHOD'] ) ) {
			return;
		}

		if ( empty( $_POST['action'] ) || 'wp_travel_engine_save_account_details' !== $_POST['action'] || empty( $_POST['wp_account_details_security'] ) || ! wp_verify_nonce( $_POST['wp_account_details_security'], 'wp_travel_engine_save_account_details' ) ) {
			return;
		}

		nocache_headers();

		$user_id = get_current_user_id();

		if ( $user_id <= 0 ) {
			return;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Nonce verified above.
		$posted = wp_unslash( $_POST );
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		// A failed photo upload aborts the whole save, as it did before this was extracted.
		$image_result = self::persist_profile_image( $user_id, $posted );

		if ( is_wp_error( $image_result ) ) {
			wp_travel_engine_add_wp_error_notices( $image_result );

			return;
		}

		$result = self::persist_account_details( $user_id, $posted );

		if ( is_wp_error( $result ) ) {
			wp_travel_engine_add_wp_error_notices( $result );

			return;
		}

		WTE()->notices->add( __( 'Account Details Updated Successfully', 'wp-travel-engine' ), 'success' );

		wp_safe_redirect( wp_travel_engine_get_page_permalink_by_id( wp_travel_engine_get_dashboard_page_id() ) );
		exit;
	}

	/**
	 * Writes the billing name and email through to the WordPress user.
	 *
	 * Billing is the one place a traveler's details are edited, so the account follows it.
	 * Empty values are skipped — propagating them would blank the account's name or email.
	 *
	 * @param int   $user_id User ID.
	 * @param array $billing Billing values as just saved, keyed by meta key.
	 *
	 * @return true|WP_Error True on success, or an error if the email cannot be taken.
	 * @since 6.9.0
	 */
	protected static function sync_account_from_billing( int $user_id, array $billing ) {
		$current = get_user_by( 'id', $user_id );

		if ( ! $current ) {
			return new WP_Error( 'wptravelengine_invalid_user', __( 'Invalid user.', 'wp-travel-engine' ) );
		}

		$fields = array( 'ID' => $user_id );

		foreach ( array(
			'first_name' => 'billing_first_name',
			'last_name'  => 'billing_last_name',
		) as $property => $meta_key ) {
			$value = (string) ( $billing[ $meta_key ] ?? '' );

			if ( '' !== $value && $value !== $current->$property ) {
				$fields[ $property ] = $value;
			}
		}

		$email = (string) ( $billing['billing_email'] ?? '' );

		if ( '' !== $email && $email !== $current->user_email ) {
			if ( ! is_email( $email ) ) {
				return new WP_Error( 'wptravelengine_invalid_email', __( 'Please Provide a valid email address', 'wp-travel-engine' ) );
			}

			if ( email_exists( $email ) ) {
				return new WP_Error( 'wptravelengine_email_exists', __( 'The email address is already registered', 'wp-travel-engine' ) );
			}

			$fields['user_email'] = $email;
		}

		// Nothing but the ID means nothing changed; wp_update_user() would still fire its
		// hooks and, for an email, notify the user about a change that did not happen.
		if ( count( $fields ) < 2 ) {
			return true;
		}

		$result = wp_update_user( $fields );

		return is_wp_error( $result ) ? $result : true;
	}

	/**
	 * Account fields a traveler cannot change about themselves.
	 *
	 * Enforced in persist_account_details(), which is the only way either the dashboard or
	 * the classic form writes these — so a submitted value is ignored no matter how the
	 * request was built. The dashboard renders them read-only to match, but that is
	 * presentation; this is the rule.
	 *
	 * @return array Field names, keyed by the user property each one writes.
	 * @since 6.9.0
	 */
	public static function locked_account_fields(): array {
		/**
		 * Filters the account fields a traveler cannot change.
		 *
		 * Keys are form field names, values the `WP_User` property each reads its current
		 * value from. Return an empty array to let travelers edit their own name and email.
		 *
		 * @param array $fields Locked fields.
		 * @since 6.9.0
		 */
		return (array) apply_filters(
			'wptravelengine_locked_account_fields',
			array(
				'account_first_name' => 'first_name',
				'account_last_name'  => 'last_name',
				'account_email'      => 'user_email',
			)
		);
	}

	/**
	 * Replaces every locked field in a submission with the value already on the account.
	 *
	 * Overwrites rather than rejects: a locked field that arrives unchanged — which is what
	 * both forms post — must not fail the save, and one that arrives edited must not take
	 * effect. Required-field validation still sees a value, so nothing downstream changes.
	 *
	 * @param WP_User $user User being saved.
	 * @param array   $data Submitted values.
	 *
	 * @return array Values with locked fields forced back to what is stored.
	 * @since 6.9.0
	 */
	protected static function apply_locked_account_fields( $user, array $data ): array {
		foreach ( self::locked_account_fields() as $field => $property ) {
			$data[ $field ] = (string) ( $user->$property ?? '' );
		}

		return $data;
	}

	/**
	 * Validates and persists a user's account details (name, email, password).
	 *
	 * Shared by the classic dashboard form and the `account/profile` REST route. Profile
	 * photos are handled separately by persist_profile_image(). Authorising $user_id is the
	 * caller's job.
	 *
	 * @param int   $user_id User ID.
	 * @param array $data    Unslashed values keyed by form field name, e.g. `account_first_name`.
	 *
	 * @return true|WP_Error True on success, or one message per validation failure.
	 * @since 6.9.0
	 * @since 6.9.0 Locked fields are forced to their stored values before anything is written.
	 */
	public static function persist_account_details( int $user_id, array $data ) {

		$current_user = get_user_by( 'id', $user_id );

		if ( ! $current_user ) {
			return new WP_Error( 'wptravelengine_invalid_user', __( 'Invalid user.', 'wp-travel-engine' ) );
		}

		$data = self::apply_locked_account_fields( $current_user, $data );

		$account_first_name = ! empty( $data['account_first_name'] ) ? wp_travel_engine_clean_vars( $data['account_first_name'] ) : '';
		$account_last_name  = ! empty( $data['account_last_name'] ) ? wp_travel_engine_clean_vars( $data['account_last_name'] ) : '';
		$account_email      = ! empty( $data['account_email'] ) ? wp_travel_engine_clean_vars( $data['account_email'] ) : '';
		$pass_cur           = ! empty( $data['password_current'] ) ? wte_clean( $data['password_current'] ) : '';
		$pass1              = ! empty( $data['password_1'] ) ? wte_clean( $data['password_1'] ) : '';
		$pass2              = ! empty( $data['password_2'] ) ? wte_clean( $data['password_2'] ) : '';
		$save_pass          = true;

		$user             = new stdClass();
		$user->ID         = $user_id;
		$user->first_name = $account_first_name;
		$user->last_name  = $account_last_name;

		// Prevent emails being displayed, or leave alone.
		$user->display_name = is_email( $current_user->display_name ) ? $user->first_name : $current_user->display_name;

		$errors = new WP_Error();

		// Handle required fields.
		$required_fields = apply_filters(
			'wp_travel_engine_save_account_details_required_fields',
			array(
				'account_first_name' => __( 'First name', 'wp-travel-engine' ),
				'account_last_name'  => __( 'Last name', 'wp-travel-engine' ),
				'account_email'      => __( 'Email address', 'wp-travel-engine' ),
			)
		);

		foreach ( $required_fields as $field_key => $field_name ) {
			if ( empty( $data[ $field_key ] ) ) {
				/* translators: %s: Name of the required field. */
				$errors->add( 'wptravelengine_required_field', sprintf( __( 'Error :   %s is a required field.', 'wp-travel-engine' ), esc_html( $field_name ) ) );
			}
		}

		if ( $account_email ) {
			$account_email = sanitize_email( $account_email );
			if ( ! is_email( $account_email ) ) {
				$errors->add( 'wptravelengine_invalid_email', __( 'Please Provide a valid email address', 'wp-travel-engine' ) );
			} elseif ( email_exists( $account_email ) && $account_email !== $current_user->user_email ) {
				$errors->add( 'wptravelengine_email_exists', __( 'The email address is already registered', 'wp-travel-engine' ) );
			}
			$user->user_email = $account_email;
		}

		if ( ! empty( $pass_cur ) && empty( $pass1 ) && empty( $pass2 ) ) {
			$errors->add( 'wptravelengine_password_empty', __( 'Please Fill Out All Password Fields.', 'wp-travel-engine' ) );
			$save_pass = false;
		} elseif ( ! empty( $pass1 ) && empty( $pass_cur ) ) {
			$errors->add( 'wptravelengine_password_current_missing', __( 'Please Enter Your Current Password', 'wp-travel-engine' ) );
			$save_pass = false;
		} elseif ( ! empty( $pass1 ) && empty( $pass2 ) ) {
			$errors->add( 'wptravelengine_password_confirm_missing', __( 'Please re-enter your password', 'wp-travel-engine' ) );
			$save_pass = false;
		} elseif ( ( ! empty( $pass1 ) || ! empty( $pass2 ) ) && $pass1 !== $pass2 ) {
			$errors->add( 'wptravelengine_password_mismatch', __( 'New Passwords do not match', 'wp-travel-engine' ) );
			$save_pass = false;
		} elseif ( ! empty( $pass1 ) && ! wp_check_password( $pass_cur, $current_user->user_pass, $current_user->ID ) ) {
			$errors->add( 'wptravelengine_password_incorrect', __( 'Your current password is incorrect', 'wp-travel-engine' ) );
			$save_pass = false;
		}

		if ( $pass1 && $save_pass ) {
			$user->user_pass = $pass1;
		}

		// Allow plugins to return their own errors.
		do_action_ref_array( 'wp_travel_engine_save_account_details_errors', array( &$errors, &$user ) );

		if ( $errors->has_errors() ) {
			return $errors;
		}

		wp_update_user( $user );

		// Sync account name/email to customer post and lead traveller.
		$customer_email   = ! empty( $account_email ) ? $account_email : $current_user->user_email;
		$customer_model   = self::get_or_create_customer( $customer_email );
		$customer_details = array(
			'fname' => $account_first_name,
			'lname' => $account_last_name,
			'email' => $customer_email,
		);
		$customer_model->set_customer_details( $customer_details );
		$customer_model->save();
		self::sync_lead_traveller( $customer_model, $customer_details );

		do_action( 'wp_travel_engine_save_account_details', $user->ID );

		return true;
	}

	/**
	 * Attaches, replaces or clears a user's dashboard profile photo.
	 *
	 * A missing `user_profile_image` value means "no photo", which clears any existing
	 * attachment — matching the classic form, where an empty field removes the photo.
	 *
	 * @param int   $user_id User ID.
	 * @param array $data    Unslashed values keyed by form field name.
	 *
	 * @return true|WP_Error True on success, WP_Error when the attachment could not be created.
	 * @since 6.9.0
	 */
	public static function persist_profile_image( int $user_id, array $data ) {

		$users_meta = get_user_meta( $user_id, 'wte_users_meta', true );

		if ( ! is_array( $users_meta ) || empty( $users_meta ) ) {
			$users_meta = array();
		}

		$existing_id = isset( $users_meta['user_profile_image_id'] ) && is_numeric( $users_meta['user_profile_image_id'] )
			? $users_meta['user_profile_image_id']
			: false;

		$image_file = ! empty( $data['user_profile_image'] ) ? sanitize_text_field( wp_normalize_path( $data['user_profile_image'] ) ) : false;
		$image_url  = ! empty( $data['user_profile_image_url'] ) ? esc_url_raw( $data['user_profile_image_url'] ) : false;

		if ( $image_file && $image_url && 'custom-image' !== $image_file ) {

			if ( $existing_id ) {
				wp_delete_attachment( $existing_id );
			}

			$attached_img_id = self::set_user_profile_image( $image_file, $user_id );

			if ( ! $attached_img_id ) {
				return new WP_Error( 'wptravelengine_profile_image_failed', __( 'There was an issue updating your profile photo.', 'wp-travel-engine' ) );
			}

			$users_meta['user_profile_image_id'] = absint( $attached_img_id );
			update_user_meta( $user_id, 'wte_users_meta', $users_meta );

			return true;
		}

		if ( ! $image_file ) {
			if ( $existing_id ) {
				wp_delete_attachment( $existing_id );
			}

			$users_meta['user_profile_image_id'] = false;
			update_user_meta( $user_id, 'wte_users_meta', $users_meta );
		}

		return true;
	}

	/**
	 * Set user dashboard profile image
	 *
	 * @param boolean|string     $image_url
	 * @param boolean|string|int $user_id
	 *
	 * @return mixed
	 * @since 6.8.1 Added tmp-dir source validation to reject arbitrary uploads-dir paths before rename.
	 */
	public static function set_user_profile_image( $image_url = false, $user_id = false ) {

		if ( ! $image_url || ! $user_id ) {
			return false;
		}

		$users_meta = get_user_meta( $user_id, 'wte_users_meta' );

		if ( ! is_array( $users_meta ) ) :
			$users_meta = array();
		endif;

		if ( ! function_exists( 'wp_get_current_user' ) ) {
			require_once ABSPATH . 'wp-includes/pluggable.php';
		}

		$uploads   = wp_get_upload_dir();
		$base_real = ( $uploads['basedir'] ?? false ) ? realpath( $uploads['basedir'] ) : false;
		if ( ! $base_real ) {
			return false;
		}

		$wte_image_dir = trailingslashit( $uploads['basedir'] ) . 'wp-travel-engine/images/users';
		$img_filetype  = wp_check_filetype( $image_url, null );

		if ( ! ( $img_filetype['ext'] ?? true ) || ! ( $img_filetype['type'] ?? true ) ) {
			return false;
		}

		$img_file_ext = $img_filetype['ext'] ?? '.jpg';

		$img_file_name = $wte_image_dir . '/wte_users_' . $user_id . '.' . $img_file_ext;

		if ( wp_mkdir_p( $wte_image_dir ) ) :

			if ( file_exists( $img_file_name ) ) :
				wp_delete_file( $img_file_name );
			endif;

			if ( file_exists( $image_url ) ) :
				$src_real = realpath( $image_url );
				if ( $src_real && is_file( $src_real ) && strpos( wp_normalize_path( $src_real ), wp_normalize_path( $base_real ) ) === 0 ) {
					// Only files inside the WP Travel Engine tmp dir are valid sources.
					$wptravelengine_tmp_path = realpath( trailingslashit( $uploads['basedir'] ) . 'wp-travel-engine/tmp' );
					$src_normalized          = wp_normalize_path( $src_real );
					$tmp_normalized          = trailingslashit( wp_normalize_path( $wptravelengine_tmp_path ) );
					$is_from_tmp_dir         = $wptravelengine_tmp_path && strpos( $src_normalized, $tmp_normalized ) === 0;
					if ( $is_from_tmp_dir ) {
						if ( ! @rename( $src_real, $img_file_name ) ) {
							return false;
						}
					}
				}
			endif;

		endif;

		if ( ! file_exists( $img_file_name ) ) {
			return false;
		}

		$attachment = array(
			'post_mime_type' => $img_filetype['type'],
			'post_title'     => sanitize_file_name( 'wte_users_' . $user_id . '.' . $img_file_ext ),
			'post_content'   => '',
			'post_status'    => 'inherit',
		);

		$attached_img_id = wp_insert_attachment( $attachment, $img_file_name );

		if ( defined( 'ABSPATH' ) && file_exists( ABSPATH . 'wp-admin/includes/image.php' ) ) :

			require_once ABSPATH . 'wp-admin/includes/image.php';

			$attach_data                         = wp_generate_attachment_metadata( $attached_img_id, $img_file_name );
			$update_attachment                   = wp_update_attachment_metadata( $attached_img_id, $attach_data );
			$users_meta['user_profile_image_id'] = $attached_img_id;

			return $attached_img_id;

		endif;
	}

	/**
	 * Returns customer by email, or creates a new customer post.
	 *
	 * @param string $email Customer email (post_title).
	 * @return Customer
	 * @since 6.7.6
	 */
	private static function get_or_create_customer( string $email ) {
		$customer_id = Customer::is_exists( $email );
		if ( $customer_id ) {
			return new Customer( $customer_id );
		}
		try {
			return Customer::create_post(
				array(
					'post_status' => 'publish',
					'post_type'   => 'customer',
					'post_title'  => $email,
				)
			);
		} catch ( \Exception $e ) {
			// Another process may have created the customer (race); return existing if so.
			$customer_id = Customer::is_exists( $email );
			if ( $customer_id ) {
				return new Customer( $customer_id );
			}
			throw $e;
		}
	}

	/**
	 * Merges new lead traveller data with existing and saves to customer meta.
	 *
	 * @param Customer $customer Customer model.
	 * @param array    $lead_data Lead traveller fields (e.g. fname, lname, email, address, ...).
	 * @return void
	 * @since 6.7.6
	 */
	private static function sync_lead_traveller( Customer $customer, array $lead_data ) {
		$existing   = $customer->get_customer_meta();
		$lead_index = isset( $existing['wptravelengine_traveller_details'][0] ) && is_array( $existing['wptravelengine_traveller_details'][0] )
			? $existing['wptravelengine_traveller_details'][0]
			: array();

		// Sanitize and filter: skip empty values, sanitize by field type before saving to customer meta.
		$sanitized_lead_data = array();
		foreach ( $lead_data as $key => $value ) {
			if ( '' === $value || null === $value ) {
				continue;
			}
			if ( 'email' === $key ) {
				$sanitized_lead_data[ $key ] = sanitize_email( $value );
			} else {
				$sanitized_lead_data[ $key ] = sanitize_text_field( $value );
			}
		}

		$customer->set_my_meta( self::LEAD_TRAVELLER_META_KEY, array_merge( $lead_index, $sanitized_lead_data ) );
		$customer->save();
	}
}

// Run the show.
Wp_Travel_Engine_Form_Handler::init();
