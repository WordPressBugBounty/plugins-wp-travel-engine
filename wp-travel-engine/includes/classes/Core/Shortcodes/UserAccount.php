<?php
/**
 * ShortCode dashboard.
 *
 * @package    WPTravelEngine
 * @subpackage WPTravelEngine\Core\Shortcodes
 */

namespace WPTravelEngine\Core\Shortcodes;

use WPTravelEngine\Email\UserEmail;
use WPTravelEngine\Helpers\Countries;
use WPTravelEngine\Abstracts\Shortcode;
use WPTravelEngine\Core\Models\Settings\PluginSettings;
use WPTravelEngine\Builders\FormFields\BookingFormFields;

/**
 * Class UserAccount.
 *
 * Responsible for creating shortcodes for user account displaying and maintaining it.
 *
 * @since 6.0.0
 */
class UserAccount extends Shortcode {
	/**
	 * Shortcode tag.
	 *
	 * @var string
	 */
	const TAG = 'wp_travel_engine_dashboard';

	/**
	 * Dashboard menus. Keys double as route slugs.
	 *
	 * @return array Menus, keyed by route slug.
	 * @since 6.9.0 Made public; added `overview`, `icon`; renamed `address`/`account` keys.
	 */
	public static function dashboard_menus() {
		$dashboard_menus = array(
			'overview' => array(
				'menu_title' => __( 'Overview', 'wp-travel-engine' ),
				'menu_class' => 'lrf-overview',
				'icon'       => 'overview',
				'priority'   => 10,
			),
			'bookings' => array(
				'menu_title' => __( 'Booking', 'wp-travel-engine' ),
				'menu_class' => 'lrf-bookings',
				'icon'       => 'bookings',
				'priority'   => 20,
			),
			'billing'  => array(
				'menu_title' => __( 'Billing Info', 'wp-travel-engine' ),
				'menu_class' => 'lrf-address',
				'icon'       => 'billing',
				'priority'   => 30,
			),
			'profile'  => array(
				'menu_title' => __( 'Account Details', 'wp-travel-engine' ),
				'menu_class' => 'lrf-account',
				'icon'       => 'profile',
				'priority'   => 40,
			),
		);

		$dashboard_menus = apply_filters( 'wp_travel_engine_user_dashboard_menus', $dashboard_menus );

		// Legacy `address`/`account` filter keys still work: moved to the new key, which wins if both exist.
		$renamed_keys = array(
			'address' => 'billing',
			'account' => 'profile',
		);

		foreach ( $renamed_keys as $legacy => $current ) {
			if ( ! isset( $dashboard_menus[ $legacy ] ) ) {
				continue;
			}

			if ( ! isset( $dashboard_menus[ $current ] ) ) {
				$dashboard_menus[ $current ] = $dashboard_menus[ $legacy ];
			}

			unset( $dashboard_menus[ $legacy ] );
		}

		return $dashboard_menus;
	}

	/**
	 * Bookings Dashboard menus.
	 *
	 * @return array Menus.
	 * @since 6.9.0 Made public so the account REST routes can reuse the filtered menus.
	 */
	public static function bookings_dashboard_menus() {
		$bookings_dashboard_menus        = array(
			'active'  => array(
				'menu_title' => __( 'Upcoming Trips', 'wp-travel-engine' ),
				'menu_class' => 'wpte-active-bookings',
				'priority'   => 10,
			),
			'history' => array(
				'menu_title' => __( 'Booking History', 'wp-travel-engine' ),
				'menu_class' => 'wpte-history-bookings',
				'priority'   => 30,
			),
		);
		return $bookings_dashboard_menus = apply_filters( 'wp_travel_engine_user_dashboard_booking_menus', $bookings_dashboard_menus );
	}

	/**
	 * Form definitions the dashboard renders records with.
	 *
	 * @return array<string, array<string, array<string, mixed>>>
	 * @since 6.9.0
	 */
	protected static function form_fields(): array {
		return BookingFormFields::form_definitions();
	}

	/**
	 * Bootstrap data for the React dashboard mount point. Empty off the account page.
	 *
	 * @return array Empty when not on the account page.
	 * @since 6.9.0
	 */
	public static function get_dashboard_data(): array {
		if ( ! wp_travel_engine_is_account_page() || ! is_user_logged_in() ) {
			return array();
		}

		$dashboard_page_id = wp_travel_engine_get_dashboard_page_id();
		$dashboard_url     = wp_travel_engine_get_page_permalink_by_id( $dashboard_page_id );

		// Presentational keys only; `menu_content_cb` is a classic-dashboard concern the
		// `my/<slug>` REST routes do not resolve.
		$menus = array();
		foreach ( wp_travel_engine_sort_array_by_priority( self::dashboard_menus() ) as $key => $menu ) {

			$markup = '';

			if ( ! empty( $menu['render'] ) ) {
				ob_start();

				try {
					$template_args = array_merge(
						self::get_args(),
						array(
							'menu' => $menu,
							'key'  => $key,
						)
					);

					wte_get_template( $menu['render'], $template_args, '', $menu['render_path'] ?? '' );
					$markup = ob_get_clean();
				} catch ( \Throwable $e ) {
					ob_end_clean();
					$markup = '';
				}
			}

			$menus[] = array(
				'key'      => $key,
				'title'    => $menu['menu_title'] ?? '',
				'class'    => $menu['menu_class'] ?? '',
				'priority' => $menu['priority'] ?? 10,
				'icon'     => $menu['icon'] ?? '',
				'markup'   => $markup,
			);
		}

		$user    = wp_get_current_user();
		$billing = get_user_meta( $user->ID, 'wp_travel_engine_customer_billing_details', true );
		$billing = is_array( $billing ) ? $billing : array();

		return array(
			'root'           => esc_url_raw( rest_url( 'wptravelengine/v1/' ) ),
			'nonce'          => wp_create_nonce( 'wp_rest' ),
			'pageUrl'        => $dashboard_url,
			'user'           => array(
				'firstName' => ! empty( $billing['billing_first_name'] ) ? $billing['billing_first_name'] : $user->first_name,
				'lastName'  => ! empty( $billing['billing_last_name'] ) ? $billing['billing_last_name'] : $user->last_name,
				'email'     => ! empty( $billing['billing_email'] ) ? $billing['billing_email'] : $user->user_email,
				'username'  => $user->user_login,
			),
			'logoutUrl'      => html_entity_decode( wp_logout_url( $dashboard_url ), ENT_QUOTES, 'UTF-8' ),
			'countries'      => Countries::list(),
			'formFields'     => self::form_fields(),
			'bookingsCount'  => self::get_owned_bookings_count(),
			'canEditDetails' => self::travelers_may_edit(),
			'labels'         => wptravelengine_get_labels(),
			'menus'          => $menus,
			'dateFormat'     => self::date_format_data(),
		);
	}

	/**
	 * The site's date and time formats, with the locale's names to spell them in.
	 *
	 * @since 6.9.0
	 * @return array{date: string, time: string, months: string[], monthsShort: string[], weekdays: string[], weekdaysShort: string[], meridiem: array<string, string>}
	 */
	public static function date_format_data(): array {
		global $wp_locale;

		$months   = array_values( $wp_locale->month );
		$weekdays = array_values( $wp_locale->weekday );

		return array(
			'date'          => (string) get_option( 'date_format', 'F j, Y' ),
			'time'          => (string) get_option( 'time_format', 'g:i a' ),
			'months'        => $months,
			'monthsShort'   => array_map( array( $wp_locale, 'get_month_abbrev' ), $months ),
			'weekdays'      => $weekdays,
			'weekdaysShort' => array_map( array( $wp_locale, 'get_weekday_abbrev' ), $weekdays ),
			'meridiem'      => $wp_locale->meridiem,
		);
	}

	/**
	 * Whether a traveler may come back and change details they have already given.
	 *
	 * @return bool
	 * @since 6.9.0
	 */
	public static function travelers_may_edit(): bool {
		return wptravelengine_toggled( wptravelengine_settings()->get( 'allow_traveler_details_edit', false ) );
	}

	/**
	 * Whether traveler details are collected at all; off, the dashboard neither shows nor takes them.
	 *
	 * @return bool
	 * @since 6.9.0
	 */
	public static function collects_travelers(): bool {
		return wptravelengine_toggled( wptravelengine_settings()->get( 'display_travellers_info', 'yes' ) );
	}

	/**
	 * IDs of the bookings belonging to the current user, oldest first.
	 *
	 * Separate from the models so the sidebar's badge can count without building one Booking
	 * per ID on pages that never read the list.
	 *
	 * @return array<int> Booking post IDs, in the order they are stored.
	 * @since 6.9.0
	 */
	protected static function stored_booking_ids(): array {
		// Keyed by user so the cache, which outlives one REST dispatch, can't leak between users.
		static $cache = array();

		$user_id = get_current_user_id();

		if ( isset( $cache[ $user_id ] ) ) {
			return $cache[ $user_id ];
		}

		// Customer post meta only; legacy user-meta is not merged (it has leaked other customers' booking IDs).
		$customer = wptravelengine_get_customer( wp_get_current_user() );
		$ids      = $customer ? $customer->get_meta( 'wp_travel_engine_bookings' ) : array();
		$ids      = array_values( array_unique( array_map( 'intval', is_array( $ids ) ? $ids : array() ) ) );

		if ( ! empty( $ids ) ) {
			_prime_post_caches( $ids, false, false );
			update_meta_cache( 'post', $ids );
		}

		// The Booking model's own validity rule, applied without constructing one per ID.
		$cache[ $user_id ] = array_values(
			array_filter(
				$ids,
				function ( $id ) {
					return 'booking' === get_post_type( $id );
				}
			)
		);

		return $cache[ $user_id ];
	}

	/**
	 * Whether a booking belongs to the current user.
	 *
	 * @param int $booking_id Booking post ID.
	 * @return bool
	 * @since 6.9.0
	 */
	public static function owns_booking( int $booking_id ): bool {
		return is_user_logged_in() && in_array( $booking_id, self::stored_booking_ids(), true );
	}

	/**
	 * How many bookings the current user owns.
	 *
	 * @return int Booking count.
	 * @since 6.9.0
	 */
	public static function get_owned_bookings_count(): int {
		return count( self::stored_booking_ids() );
	}

	/**
	 * Bookings belonging to the current user, newest first.
	 *
	 * @return array
	 * @since 6.9.0 Built from stored_booking_ids(), which primes the post cache for legacy IDs too.
	 */
	public static function get_owned_bookings(): array {
		static $cache = array();

		$user_id = get_current_user_id();

		if ( isset( $cache[ $user_id ] ) ) {
			return $cache[ $user_id ];
		}

		$bookings = array();

		foreach ( self::stored_booking_ids() as $booking_id ) {
			$booking = wptravelengine_get_booking( $booking_id );

			if ( $booking ) {
				$bookings[] = $booking;
			}
		}

		$cache[ $user_id ] = array_reverse( $bookings );

		self::prime_booking_caches( $cache[ $user_id ] );

		return $cache[ $user_id ];
	}

	/**
	 * Warms the object cache for bookings and their trips, avoiding an O(bookings + trips)
	 * query fan-out (a 7-booking page previously cost 145 queries).
	 *
	 * @param array $bookings Booking models.
	 * @return void
	 * @since 6.9.0
	 */
	protected static function prime_booking_caches( array $bookings ): void {
		$booking_ids = array();

		foreach ( $bookings as $booking ) {
			$booking_ids[] = $booking->get_id();
		}

		if ( ! $booking_ids ) {
			return;
		}

		update_meta_cache( 'post', $booking_ids );

		$trip_ids = array();

		foreach ( $bookings as $booking ) {
			foreach ( wptravelengine_get_cart_info( $booking->ID )->get_items() as $item ) {
				$trip_id = (int) $item->get_trip_id();

				if ( $trip_id ) {
					$trip_ids[ $trip_id ] = $trip_id;
				}
			}
		}

		if ( ! $trip_ids ) {
			return;
		}

		// Meta included: the dashboard reads each trip's gallery from `wpte_gallery_id` meta.
		_prime_post_caches( array_values( $trip_ids ), false, true );
	}

	/**
	 * Booking IDs belonging to the current user.
	 *
	 * @return array Booking IDs, newest first.
	 * @since 6.9.0 Read from the stored IDs rather than from a Booking per ID.
	 */
	public static function get_owned_booking_ids(): array {
		return array_reverse( self::stored_booking_ids() );
	}

	/**
	 * Arguments for a tab template/callback, same shape the classic dashboard passed.
	 * `*_glance*` keys (misspelling included) are unread by core, kept only for add-ons.
	 *
	 * @return array Template args.
	 * @since 6.9.0
	 */
	public static function get_args(): array {
		$user_id     = get_current_user_id();
		$booking_ids = self::get_owned_booking_ids();

		return array(
			'current_user'             => wp_get_current_user(),
			'dashboard_menus'          => self::dashboard_menus(),
			'bookings_dashboard_menus' => self::bookings_dashboard_menus(),
			'bookings'                 => $booking_ids,
			'bookings_glance'          => array_slice( $booking_ids, 0, 5 ),
			'biling_glance_data'       => get_user_meta( $user_id, 'wp_travel_engine_customer_billing_details', true ),
		);
	}

	/**
	 * Classic dashboard tab, which the React dashboard has replaced.
	 *
	 * @param array $args Unused.
	 * @return void
	 * @deprecated 6.9.0 No longer called by WP Travel Engine. It will be removed in a future release.
	 */
	public static function dashboard_menu_bookings_tab( $args ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		_deprecated_function( __METHOD__, '6.9.0' );
	}

	/**
	 * Classic dashboard tab, which the React dashboard has replaced.
	 *
	 * @param array $args Unused.
	 * @return void
	 * @deprecated 6.9.0 No longer called by WP Travel Engine. It will be removed in a future release.
	 */
	public static function dashboard_menu_address_tab( $args ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		_deprecated_function( __METHOD__, '6.9.0' );
	}

	/**
	 * Classic dashboard tab, which the React dashboard has replaced.
	 *
	 * @param array $args Unused.
	 * @return void
	 * @deprecated 6.9.0 No longer called by WP Travel Engine. It will be removed in a future release.
	 */
	public static function dashboard_menu_account_tab( $args ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		_deprecated_function( __METHOD__, '6.9.0' );
	}

	/**
	 * Classic dashboard tab, which the React dashboard has replaced.
	 *
	 * @param array $args Unused.
	 * @return void
	 * @deprecated 6.9.0 No longer called by WP Travel Engine. It will be removed in a future release.
	 */
	public static function bookings_menu_active_tab( $args ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		_deprecated_function( __METHOD__, '6.9.0' );
	}

	/**
	 * Classic dashboard tab, which the React dashboard has replaced.
	 *
	 * @param array $args Unused.
	 * @return void
	 * @deprecated 6.9.0 No longer called by WP Travel Engine. It will be removed in a future release.
	 */
	public static function bookings_menu_history_tab( $args ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		_deprecated_function( __METHOD__, '6.9.0' );
	}

	/**
	 * Retrieves the UserAccount shortcode output.
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string The generated HTML output.
	 * @since 6.9.0 Logged-in branch now prints the React dashboard's mount point instead of
	 *             account/content-dashboard.php.
	 */
	public function output( $atts ): string {

		/**
		 * Remove sidebar from user account page
		 */
		$themes = array(
			'travel-monster',
			'travel-agency',
			'travel-agency-pro',
			'travel-booking',
			'travel-booking-pro',
			'travel-muni',
			'travel-muni-pro',
		);

		if ( in_array( get_template(), $themes ) ) {
			add_filter( str_replace( '-', '_', get_template() ) . '_display_page_sidebar', '__return_false' );
		}

		ob_start();

		if ( ! is_user_logged_in() ) {
			// phpcs:disable
			// After password reset, add confirmation message.
			if ( ! empty( $_GET['password-reset'] ) ) {
				WTE()->notices->add( __( 'Your Password has been updated successfully. Please Log in to continue.', 'wp-travel-engine' ), 'success' );
			}
			if ( isset( $_GET['action'] ) && 'lost-pass' == $_GET['action'] ) {
				self::lost_password();
			} else {
				// Get user login.
				wte_get_template( 'account/form-login.php' );
			}
		} else {
			$localize = static function () {
				wp_localize_script( 'my-account', 'wptravelengineDashboardData', self::get_dashboard_data() );
			};

			if ( wp_script_is( 'my-account', 'registered' ) ) {
				$localize();
			} else {
				add_action( 'wp_enqueue_scripts', $localize, 20 );
			}

			echo '<div id="wpte-traveler-dashboard" class="wpte-tdash loading">
				<div class="wpte-tdash__shell"></div>
				<div class="wpte-view"></div>
				</div>';
		}

		return ob_get_clean();
	}

	/**
	 * Lost password page handling.
	 */
	public static function lost_password() {
		// phpcs:disable
		/**
		 * After sending the reset link, don't show the form again.
		 */
		if ( ! empty( $_GET['reset-link-sent'] ) ) {
			wte_get_template( 'account/lostpassword-confirm.php' );
			return;
			/**
			 * Process reset key / login from email confirmation link
			*/
		} elseif ( ! empty( $_GET['show-reset-form'] ) ) {
			if ( isset( $_COOKIE[ 'wp-resetpass-' . COOKIEHASH ] ) && 0 < strpos( $_COOKIE[ 'wp-resetpass-' . COOKIEHASH ], ':' ) ) {
				list( $rp_login, $rp_key ) = array_map( 'wp_travel_engine_clean_vars', explode( ':', wp_unslash( $_COOKIE[ 'wp-resetpass-' . COOKIEHASH ] ), 2 ) );
				$user = self::check_password_reset_key( $rp_key, $rp_login );

				// reset key / login is correct, display reset password form with hidden key / login values
				if ( is_object( $user ) ) {

					wte_get_template( 'account/form-reset-password.php', array(
						'key'   => $rp_key,
						'login' => $rp_login,
					) );

					return;
				}
			}
		}

		// Show lost password form by default.
		wte_get_template( 'account/form-lostpassword.php' );
		// phpcs:enable
	}

	/**
	 * Retrieves a user row based on password reset key and login.
	 *
	 * @uses $wpdb WordPress Database object
	 *
	 * @param string $key Hash to validate sending user's password
	 * @param string $login The user login
	 *
	 * @return WP_User|bool User's database row on success, false for invalid keys
	 */
	public static function check_password_reset_key( $key, $login ) {
		// Check for the password reset key.
		// Get user data or an error message in case of invalid or expired key.
		$user = check_password_reset_key( $key, $login );

		if ( is_wp_error( $user ) ) {
			WTE()->notices->add( __( 'Error:', 'wp-travel-engine' ) . __( 'This key is invalid or has already been used. Please reset your password again if needed.', 'wp-travel-engine' ), 'error' );
			return false;
		}

		return $user;
	}

	/**
	 * Handles sending password retrieval email to customer.
	 *
	 * Based on retrieve_password() in core wp-login.php.
	 *
	 * @uses $wpdb WordPress Database object
	 * @return bool True: when finish. False: on error
	 */
	public static function retrieve_password() {
		$login = trim( sanitize_text_field( wp_unslash( $_POST['user_login'] ) ) ); // phpcs:ignore

		if ( empty( $login ) ) {

			WTE()->notices->add( __( 'Error:', 'wp-travel-engine' ) . __( 'Enter an email or username.', 'wp-travel-engine' ), 'error' );

			return false;

		} else {
			// Check on username first, as customers can use emails as usernames.
			$user_data = get_user_by( 'login', $login );
		}

		// If no user found, check if it login is email and lookup user based on email.
		if ( ! $user_data && is_email( $login ) && apply_filters( 'wp_travel_engine_get_username_from_email', true ) ) {
			$user_data = get_user_by( 'email', $login );
		}

		$errors = new \WP_Error();

		do_action( 'wptravelengine_lostpassword_post', $errors );

		if ( $errors->get_error_code() ) {

			WTE()->notices->add( __( 'Error:', 'wp-travel-engine' ) . $errors->get_error_message(), 'error' );

			return false;
		}

		if ( ! $user_data ) {

			WTE()->notices->add( __( 'Error:', 'wp-travel-engine' ) . __( 'Invalid username or email.', 'wp-travel-engine' ), 'error' );

			return false;
		}

		if ( is_multisite() && ! is_user_member_of_blog( $user_data->ID, get_current_blog_id() ) ) {
			WTE()->notices->add( __( 'Error:', 'wp-travel-engine' ) . __( 'Invalid username or email.', 'wp-travel-engine' ), 'error' );

			return false;
		}

		// redefining user_login ensures we return the right case in the email.
		$user_login = $user_data->user_login;

		do_action( 'wptravelengine_retrieve_password', $user_login );

		$allow = apply_filters( 'allow_password_reset', true, $user_data->ID );

		if ( ! $allow ) {

			WTE()->notices->add( __( 'Error:', 'wp-travel-engine' ) . __( 'Password reset is not allowed for this user.', 'wp-travel-engine' ), 'error' );

			return false;

		} elseif ( is_wp_error( $allow ) ) {

			WTE()->notices->add( __( 'Error:', 'wp-travel-engine' ) . $allow->get_error_message(), 'error' );

			return false;
		}

		$plugin_settings = new PluginSettings();

		$forgot_password_settings = $plugin_settings->get( 'customer_email_notify_tabs.forgot_password' );
		if ( wptravelengine_toggled( $forgot_password_settings['enabled'] ) ) {
			// Get password reset key (function introduced in WordPress 4.4).
			$key = get_password_reset_key( $user_data );

			if ( $user_login && $key ) {
				$email = new UserEmail( $user_data->ID );
				$email->set( 'to', $user_data->user_email );
				$email->set( 'my_subject', $forgot_password_settings['subject'] );
				$email->set( 'content', $forgot_password_settings['content'] );
				$email->send();
			}
		}

		return true;
	}

	/**
	 * Handles resetting the user's password.
	 *
	 * @param object $user The user
	 * @param string $new_pass New password for the user in plaintext
	 */
	public static function reset_password( $user, $new_pass ) {
		do_action( 'password_reset', $user, $new_pass );

		wp_set_password( $new_pass, $user->ID );
		self::set_reset_password_cookie();

		wp_password_change_notification( $user );
	}

	/**
	 * Set or unset the cookie.
	 *
	 * @param string $value
	 */
	public static function set_reset_password_cookie( $value = '' ) {
		$rp_cookie = 'wp-resetpass-' . \COOKIEHASH;
		$rp_path   = current( explode( '?', wte_clean( wp_unslash( $_SERVER['REQUEST_URI'] ) ) ) ); // phpcs:ignore

		if ( $value ) {
			setcookie( $rp_cookie, $value, 0, $rp_path, \COOKIE_DOMAIN, is_ssl(), true );
		} else {
			setcookie( $rp_cookie, ' ', time() - \YEAR_IN_SECONDS, $rp_path, \COOKIE_DOMAIN, is_ssl(), true );
		}
	}
}
