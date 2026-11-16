<?php
/**
 * Booking admin view renderer.
 * Handles meta box rendering for the booking post edit screen.
 * Refined and migrated methods from WPTravelEngine\Core\PostTypes\Booking class.
 *
 * @package WPTravelEngine/Core/Booking
 * @since 6.8.0
 */

namespace WPTravelEngine\Core\Booking;

use WPTravelEngine\Core\Tax;
use WPTravelEngine\Core\Cart\Cart;
use WPTravelEngine\Traits\Singleton;
use WPTravelEngine\Helpers\Functions;
use WPTravelEngine\Helpers\BookedItem;
use WPTravelEngine\Utilities\ArrayUtility;
use WPTravelEngine\Core\PostTypes\Booking;
use WPTravelEngine\Helpers\CartInfoParser;
use WPTravelEngine\Core\Models\Post\Payment;
use WPTravelEngine\Utilities\PaymentCalculator;
use WPTravelEngine\Core\Models\Post\TripPackage;
use WPTravelEngine\Builders\FormFields\BookingFormFields;
use WPTravelEngine\Core\Models\Post\Booking as BookingModel;
use WPTravelEngine\Builders\FormFields\BillingEditFormFields;
use WPTravelEngine\Builders\FormFields\PaymentEditFormFields;
use WPTravelEngine\Core\Controllers\Ajax\SendPaymentReminder;
use WPTravelEngine\Builders\FormFields\EmergencyEditFormFields;
use WPTravelEngine\Builders\FormFields\OrderTripEditFormFields;
use WPTravelEngine\Builders\FormFields\TravellerEditFormFields;
use WPTravelEngine\Core\Booking\LineItems\Manager as LineItemsManager;

/**
 * Renders the booking admin meta box for view and edit actions.
 *
 * @since 6.8.0
 */
class ViewBooking {

	use Singleton;

	/**
	 * Booking instance.
	 *
	 * @var ?BookingModel $booking
	 */
	protected $booking = null;

	/**
	 * Is new booking.
	 *
	 * @var bool $is_new_booking
	 */
	protected bool $is_new_booking = false;

	/**
	 * Constructor.
	 *
	 * Built through `instance()`, which caches the view for the rest of the request.
	 *
	 * @param int $booking_id Booking post ID.
	 * @since 6.8.0
	 * @since 6.9.0 Constructed through the Singleton trait.
	 */
	protected function __construct( int $booking_id ) {
		$this->booking        = wptravelengine_get_booking( $booking_id, true );
		$this->is_new_booking = $this->booking && ! $this->booking->has_meta( 'order_trips' );
	}

	/**
	 * Register Hooks.
	 *
	 * @since 6.8.0
	 * @since 6.9.0 Notice removal moved to AdminNoticeSuppressor; this notice re-registers after it.
	 */
	public static function register_hooks(): void {
		add_action(
			'current_screen',
			static function ( \WP_Screen $screen ): void {
				if ( Booking::POST_TYPE !== $screen->id ) {
					return;
				}
				add_filter( 'screen_options_show_screen', '__return_false' );
			}
		);

		// Registered after the suppressor has cleared the screen, or it would be removed too.
		add_action(
			'wptravelengine_admin_notices_suppressed',
			static function ( \WP_Screen $screen ): void {
				if ( Booking::POST_TYPE === $screen->id ) {
					add_action( 'admin_notices', array( __CLASS__, 'dashboard_notice' ) );
				}
			}
		);

		add_action(
			'edit_form_top',
			static function ( \WP_Post $post ): void {
				if ( Booking::POST_TYPE === $post->post_type ) {
					$booking_view = self::instance( (int) $post->ID );

					if ( $booking_view->should_render_app() ) {
						$booking_view->render_app();
					}
				}
			},
			5
		);
	}

	/**
	 * Prints an info notice on the booking edit screen warning about manual edits.
	 *
	 * @return void
	 * @since 6.4.0
	 * @since 6.8.0 Migrated from Plugin class.
	 */
	public static function dashboard_notice(): void {
		$screen = get_current_screen();
		if ( 'booking' !== $screen->id || 'edit' !== ( $_GET['wptravelengine_action'] ?? '' ) ) {
			return;
		}

		printf(
			'<div class="%1$s"><p><strong>%2$s</strong></p></div>',
			esc_attr( 'notice notice-info is-dismissible' ),
			esc_html__( 'Notice: Please be aware that you are responsible for any mistakes, payment issues, or customer concerns that may arise when editing the booking summary. Double-check your changes, update payment settings, and contact support if you need assistance.', 'wp-travel-engine' )
		);
	}

	/**
	 * Entry point called from the meta box callback; renders the legacy booking screen.
	 *
	 * @return void
	 * @since 6.8.0
	 * @since 6.9.0 Renders only the legacy screen, for a pre-4.0 cart; the React app edits the rest.
	 */
	public function process_markup(): void {
		if ( null === $this->booking ) {
			echo esc_html__( 'Booking not found.', 'wp-travel-engine' );
			return;
		}

		// The non-app booking/ templates are kept on disk for overrides, but nothing renders them now.
		if ( $this->should_render_app() || $this->is_new_booking || $this->booking->is_curr_cart() ) {
			return;
		}

		wp_enqueue_script( 'wptravelengine-booking-legacy-edit' );

		wptravelengine_get_admin_template( 'booking/legacy/index.php', $this->get_template_args() );
	}

	/**
	 * Whether this screen should hand off to the React booking edit app.
	 *
	 * The app only edits an existing booking on the current cart version; the create
	 * flow and pre-4.0 carts stay on the legacy templates.
	 *
	 * @return bool
	 * @since 6.9.0
	 */
	public function should_render_app(): bool {
		global $current_screen;

		if ( $this->is_new_booking ) {
			return false;
		}

		if ( $current_screen && 'add' === $current_screen->action ) {
			return false;
		}

		return $this->booking->is_curr_cart();
	}

	/**
	 * Prints the React booking edit app's page body.
	 *
	 * Called from `PostTypes\Booking::render_booking_edit_app()` between core's admin header
	 * and footer, so this owns the `.wrap` that the classic editor would have printed.
	 *
	 * @return void
	 * @since 6.9.0
	 */
	public function render_app(): void {
		printf(
			'<div class="wrap"><h1 class="screen-reader-text">%1$s</h1><div id="wptravelengine-booking-edit-app" class="wptravelengine-booking-edit-app"></div></div>',
			esc_html( get_the_title( $this->booking->ID ) )
		);
	}

	/**
	 * Fee descriptors from the booking's payment form fields, by slug — the fallback when the cart
	 * charged no fee. Skips non-fees, slugs in `$existing`, and (unless `$with_tax`) the tax field.
	 *
	 * @param BookingModel         $booking  Booking to read the form fields of.
	 * @param array<string, mixed> $existing Fees already gathered, by slug — not re-added.
	 * @param bool                 $with_tax Keep the tax field; off by default as callers usually synthesize tax.
	 * @return array<string, array<string, mixed>> Fee descriptors by slug.
	 * @since 6.9.0
	 */
	public static function form_field_fees( BookingModel $booking, array $existing = array(), bool $with_tax = false ): array {
		$fees = array();

		foreach ( BookingFormFields::for( $booking )->payments() as $payment_data ) {
			foreach ( $payment_data as $key => $values ) {
				$behaviours = $values['behaviours'] ?? array();

				if ( 'fee' !== ( $behaviours['type'] ?? '' ) || isset( $existing[ $key ] ) || ( ! $with_tax && 'tax' === $key ) ) {
					continue;
				}

				$fees[ $key ] = array(
					'name'            => $key,
					'label'           => wptravelengine_decode_entity( $values['field_label'] ?? '' ),
					'description'     => wptravelengine_decode_entity( (string) ( $values['description'] ?? '' ) ),
					'adjustment_type' => (string) ( $behaviours['adjustment_type'] ?? '' ),
					'apply_tax'       => (bool) ( $behaviours['apply_tax'] ?? false ),
					'apply_upfront'   => (bool) ( $behaviours['apply_upfront'] ?? false ),
					'_class_name'     => (string) ( $behaviours['class_name'] ?? '' ),
					'percentage'      => (float) ( $behaviours['percentage'] ?? 0 ),
				);
			}
		}

		return $fees;
	}

	/**
	 * Fees an administrator can record against a payment on this booking.
	 *
	 * @param int $booking_id Booking being edited.
	 * @return array<int, array<string, mixed>> Fee descriptors, in the order they are declared.
	 * @since 6.9.0
	 */
	public static function get_available_fees( int $booking_id ): array {
		$booking = wptravelengine_get_booking( $booking_id, true );

		if ( ! $booking ) {
			return array();
		}

		$available_fees = array();
		$cart_fees      = $booking->cart_info->get_fees();

		foreach ( $cart_fees as $fee ) {
			$name = (string) ( $fee['name'] ?? '' );

			if ( '' === $name || isset( $available_fees[ $name ] ) ) {
				continue;
			}

			$available_fees[ $name ] = array(
				'name'            => $name,
				'label'           => wptravelengine_decode_entity( (string) ( $fee['label'] ?? '' ) ),
				'description'     => wptravelengine_decode_entity( (string) ( $fee['description'] ?? '' ) ),
				'adjustment_type' => (string) ( $fee['adjustment_type'] ?? '' ),
				'apply_tax'       => (bool) ( $fee['apply_tax'] ?? false ),
				'apply_upfront'   => (bool) ( $fee['apply_upfront'] ?? false ),
				'_class_name'     => (string) ( $fee['_class_name'] ?? '' ),
				'percentage'      => (float) ( $fee['percentage'] ?? 0 ),
			);
		}

		$all_booking_only = array_reduce(
			$booking->get_payments(),
			static fn( bool $carry, $payment ): bool => $carry && 'booking_only' === $payment->get_payment_gateway(),
			true
		);

		if ( $all_booking_only ) {
			$available_fees += self::form_field_fees( $booking, $available_fees );
		}

		$tax = Tax::for_booking( $booking->ID );

		// Inclusive tax carries no fee line, so `name`/`percentage` seed the entry the UI looks up by name.
		if ( $tax->is_taxable() || isset( $available_fees['tax'] ) ) {
			$available_fees['tax']['name']         = 'tax';
			$available_fees['tax']['percentage']   = $available_fees['tax']['percentage'] ?? $tax->get_tax_percentage();
			$available_fees['tax']['is_inclusive'] = $tax->is_inclusive();
		}

		return array_values( $available_fees );
	}

	/**
	 * Coupons an administrator can discount a booking with.
	 *
	 * Only what the dialog needs to prefill its fields; whether a coupon still applies to this
	 * booking is settled when it is written, not here.
	 *
	 * @return array<int, array<string, mixed>> Code, label and terms, in publish order.
	 * @since 6.9.0
	 */
	protected static function get_coupons(): array {
		$coupons = array();

		$posts = get_posts(
			array(
				'post_type'   => 'wte-coupon',
				'post_status' => 'publish',
				'numberposts' => -1,
			)
		);

		foreach ( $posts as $post ) {
			$meta = ArrayUtility::to_array( get_post_meta( $post->ID, 'wp_travel_engine_coupon_metas', true ) );
			$code = (string) get_post_meta( $post->ID, 'wp_travel_engine_coupon_code', true );

			if ( '' === $code ) {
				continue;
			}

			$coupons[] = array(
				'code'            => $code,
				'label'           => $post->post_title,
				'adjustment_type' => 'percentage' === ( $meta['general']['coupon_type'] ?? '' ) ? 'percentage' : 'fixed',
				'value'           => (float) ( $meta['general']['coupon_value'] ?? 0 ),
			);
		}

		return $coupons;
	}

	/**
	 * Methods an administrator can record a payment against.
	 *
	 * @return array<int, array<string, string>> Value/label pairs, in registration order.
	 * @since 6.9.0
	 */
	protected static function get_payment_methods(): array {
		$methods = array();

		foreach ( wp_travel_engine_get_available_payment_gateways() as $slug => $gateway ) {
			$methods[] = array(
				'value' => (string) $slug,
				'label' => wptravelengine_decode_entity( $gateway['public_label'] ?? $gateway['label'] ?? wptravelengine_humanize_key( $slug ) ),
			);
		}

		return apply_filters( 'wptravelengine_booking_payment_methods', $methods );
	}

	/**
	 * Prepares template arguments for Booking Page.
	 *
	 * @param string $mode
	 * @return array
	 * @since 6.4.0
	 * @since 6.8.0 Moved from PostTypes\Booking; uses class property instead of parameter.
	 * @since 6.9.0 Reads cart info through wptravelengine_get_cart_info().
	 */
	protected function get_template_args( string $mode = 'view' ): array {
		$cart_info = $this->booking->get_cart_info() ?? array();

		if ( empty( $cart_info ) ) {
			$cart_info['version'] = Cart::CURRENT_VERSION;
			$this->booking->sync_metas( array( 'cart_info' => $cart_info ) );
		}

		$cart_info['items'][0]['package_name'] ??= $this->booking->get_order_items()[0]['package_name'] ?? '';

		$cart_info = wptravelengine_get_cart_info( $this->booking->ID, $cart_info );

		$mode = 'view' === $mode ? 'readonly' : 'edit';

		/** @since 6.7.1 filter: wptravelengine_booking_form_field_modes( array $field_modes, string $mode, BookingModel $booking ) */
		$field_modes = apply_filters(
			'wptravelengine_booking_form_field_modes',
			array_fill_keys( array( 'template_mode', 'order_trip', 'travellers', 'emergency_contacts', 'billing', 'payments', 'admin_notes' ), $mode ),
			$mode,
			$this->booking
		);

		$currency_code = $cart_info->get_currency();
		$payment_data  = $cart_info->is_curr_cart_ver( '>=' ) ? $this->booking->get_payments_data( false )['payments'] ?? array() : array();

		return array(
			'booking'                        => $this->booking,
			'cart_info'                      => $cart_info,
			'template_mode'                  => $field_modes['template_mode'],
			'order_trip_form_fields'         => $this->get_order_trip_form_fields( $field_modes['order_trip'], $cart_info->get_item() ),
			'travellers_form_fields'         => $this->get_travellers_form_fields( $field_modes['travellers'] ),
			'emergency_contacts_form_fields' => $this->get_emergency_contacts_form_fields( $field_modes['emergency_contacts'] ),
			'billing_edit_form_fields'       => $this->get_billing_edit_form_fields( $field_modes['billing'] ),
			'admin_notes_edit_form_fields'   => $field_modes['admin_notes'] ?? 'readonly',
			'payments_edit_form_fields'      => $this->get_payments_edit_form_fields( $field_modes['payments'], $payment_data ),
			'pricing_arguments'              => compact( 'currency_code' ),
			'calculator'                     => PaymentCalculator::for( $currency_code ),
		);
	}

	/**
	 * Builds the order trip form fields object.
	 *
	 * @param string     $mode
	 * @param BookedItem $order_trip
	 *
	 * @return OrderTripEditFormFields
	 * @since 6.8.0
	 * @since 6.9.0 Takes the booked date from post_date_gmt.
	 */
	private function get_order_trip_form_fields( string $mode, BookedItem $order_trip ): OrderTripEditFormFields {

		$split_datetime_value = fn( $v ) => array(
			'date' => ( $e = explode( 'T', $v ) )[0],
			'time' => $e[1] ?? '',
		);

		$order_trips  = $this->booking->get_meta( 'order_trips' );
		$start_parts  = $split_datetime_value( (string) ( $this->booking->get_meta( 'trip_datetime' ) ?: $order_trip->get_trip_date() ) );
		$end_parts    = $split_datetime_value( (string) ( $this->booking->get_meta( 'end_datetime' ) ?: ( ( $order_trips[ $order_trip->get_id() ]['end_datetime'] ?? '' ) ?: $order_trip->get_end_date() ) ) );
		$package_name = $order_trip->get_package_name();
		$package_id   = $order_trip->get_trip_package_id();

		$defaults = array(
			'id'                  => $order_trip->get_trip_id(),
			'booked_date'         => $this->booking->post->post_date_gmt ?? '',
			'start_date'          => $start_parts['date'],
			'start_time'          => $start_parts['time'],
			'end_date'            => $end_parts['date'],
			'end_time'            => $end_parts['time'],
			'trip_code'           => $order_trip->get_trip_code() ?: ( $order_trip->get_trip_id() ? 'WTE-' . $order_trip->get_trip_id() : '' ),
			'number_of_travelers' => $order_trip->travelers_count(),
			'package_id'          => $package_id,
			'package_name'        => $package_name,
			'is_new_booking'      => $this->is_new_booking,
		);

		return new OrderTripEditFormFields( $defaults, $mode );
	}

	/**
	 * Builds traveller form field objects mapped to their pricing categories.
	 *
	 * @param string $mode
	 * @return TravellerEditFormFields[]
	 * @since 6.8.0
	 */
	private function get_travellers_form_fields( string $mode ): array {
		$travellers = $this->booking->get_travelers();

		if ( empty( $travellers ) ) {
			return $travellers;
		}

		$result = array();
		foreach ( $travellers as $index => $traveller ) {
			$traveller['index'] = $index;
			$result[]           = new TravellerEditFormFields( $traveller, $mode, $this->booking );
		}

		return $result;
	}

	/**
	 * Builds emergency contact form field objects.
	 *
	 * @param string $mode
	 * @return EmergencyEditFormFields[]
	 * @since 6.8.0
	 */
	private function get_emergency_contacts_form_fields( string $mode ): array {
		$contacts = $this->booking->get_emergency_contacts();

		$result = array();
		foreach ( $contacts as $index => $contact ) {
			$contact['index'] = $index;
			$result[]         = new EmergencyEditFormFields( $contact, $mode, $this->booking );
		}

		return $result;
	}

	/**
	 * Builds the billing edit form fields object.
	 *
	 * @param string $mode
	 * @return BillingEditFormFields
	 * @since 6.8.0
	 */
	private function get_billing_edit_form_fields( string $mode ): BillingEditFormFields {
		return new BillingEditFormFields( $this->booking->get_billing_info(), $mode );
	}

	/**
	 * Builds payment edit form field objects for all payments on the booking.
	 *
	 * @param string $mode
	 * @param array  $payment_data
	 * @return PaymentEditFormFields[]
	 * @since 6.8.0
	 * @since 6.9.0 Falls back to the payment post_date_gmt for the date.
	 */
	private function get_payments_edit_form_fields( string $mode, array $payment_data ): array {

		$payment_status = array(
			'success' => wptravelengine_success_payment_status(),
			'pending' => wptravelengine_pending_payment_status(),
			'failed'  => wptravelengine_failed_payment_status(),
		);

		$result   = array();
		$payments = $this->booking->get_payments();

		foreach ( $payments as $payment ) :
			if ( ! $payment instanceof Payment ) {
				continue;
			}

			$p_id          = $payment->get_id();
			$_payment_data = $payment_data[ $p_id ] ?? array();

			$status = $payment->get_payment_status();

			if ( isset( $payment_status['success'][ $status ] ) ) {
				$status = 'completed';
			} elseif ( isset( $payment_status['pending'][ $status ] ) ) {
				$status = 'pending';
			} elseif ( isset( $payment_status['failed'][ $status ] ) ) {
				$status = 'failed';
			}

			$labels   = array();
			$defaults = array(
				'id'               => $p_id,
				'status'           => $status,
				'gateway'          => $payment->get_payment_gateway(),
				'deposit'          => $_payment_data['deposit'] ?? 0,
				'amount'           => $payment->get_amount(),
				'date'             => $payment->get_transaction_date() ?: ( $payment->post->post_date_gmt ?? '' ),
				'currency'         => $payment->get_payable_currency(),
				'transaction_id'   => $payment->get_transaction_id(),
				'gateway_response' => $this->format_gateway_response( $payment->get_gateway_response() ),
				'payment_source'   => $payment->get_payment_source(),
				'gateway_fee'      => $payment->get_gateway_fee(),
				'payable'          => $payment->get_payable_amount(),
				'refunded'         => ( $_payment_data['refunded_amt'] ?? 0.00 ),
			);

			foreach ( $this->booking->get_fees() as $value ) {
				$slug = $value['name'];
				if ( isset( $_payment_data[ $slug ] ) ) {
					$labels[ $slug ]   = $value['label'];
					$defaults[ $slug ] = $_payment_data[ $slug ];
				}
			}

			$defaults = apply_filters( 'wptravelengine_payment_edit_form_fields', $defaults, $payment );

			$result[] = new PaymentEditFormFields( $defaults, $mode, $labels );
		endforeach;

		return $result;
	}

	/**
	 * Serializes a payment gateway response value to a display string.
	 *
	 * @param mixed $gateway_response
	 * @return string
	 * @since 6.8.0
	 */
	private function format_gateway_response( $gateway_response ): string {
		if ( empty( $gateway_response ) ) {
			return '';
		}
		if ( \is_array( $gateway_response ) || \is_object( $gateway_response ) ) {
			return wp_json_encode( $gateway_response, JSON_PRETTY_PRINT );
		}
		return $gateway_response;
	}

	/**
	 * Assembles a booking in the shape `get_item_schema()` declares.
	 *
	 * @param BookingModel $booking    Booking to describe.
	 * @param bool         $with_files Resolve file fields to the files they hold, in place of IDs; costly, so only for one booking at a time.
	 * @return array<string, mixed>
	 * @since 6.9.0
	 */
	public static function prepare_booking_data( BookingModel $booking, bool $with_files = false ): array {
		/** @var CartInfoParser $parser */
		$parser   = $booking->cart_info;
		$trips    = self::prepare_booked_trips( $booking );
		$billing  = ArrayUtility::to_array( $booking->get_billing_info() );
		$contacts = ArrayUtility::to_array( $booking->get_emergency_contacts()[0] ?? array() );

		if ( $with_files ) {
			$fields   = BookingFormFields::for( $booking, true );
			$billing  = $fields->with_files( $billing, 'billing' );
			$contacts = $fields->with_files( $contacts, 'emergency' );

			foreach ( $trips as &$trip ) {
				$trip['travelers'] = array_map( static fn ( $traveler ) => $fields->with_files( (array) $traveler, 'travelers' ), (array) $trip['travelers'] );
			}
			unset( $trip );
		}

		$migrated_from = absint( $booking->get_meta( '_migrated_from' ) );

		$data = array(
			'id'                 => $booking->ID,
			'booking_status'     => $booking->get_booking_status(),
			'prev_status'        => (string) $booking->get_meta( '_prev_booking_status' ),
			'booked_date'        => (string) $booking->post->post_date_gmt,
			'currency'           => $parser->get_currency(),
			'total_travelers'    => $parser->total_travelers_count(),
			'total_amount'       => (float) $parser->get_totals( 'total' ),
			'total_paid_amount'  => $booking->get_total_paid_amount(),
			'total_due_amount'   => $booking->get_total_due_amount(),
			'collected_amount'   => $booking->get_collected_amount(),
			'refunded_amount'    => $booking->get_refunded_amount(),
			'refunds'            => $booking->get_refunds(),
			'booked_trips'       => $trips,
			'discounts'          => ArrayUtility::rename_each(
				ArrayUtility::transform_each(
					$parser->get_deductible_items(
						array( 'name', 'label', 'description', 'type', 'adjustment_type', 'percentage', 'value', 'is_manual' )
					),
					array(
						'label'       => 'wptravelengine_decode_entity',
						'description' => 'wptravelengine_decode_entity',
						'is_manual'   => 'wptravelengine_toggled',
					)
				),
				array( 'adjustment_type' => 'type' )
			),
			'billing'            => $billing,
			'emergency_contacts' => $contacts,
			'payments'           => self::prepare_payments( $booking ),
			'notes'              => $booking->get_my_notes(),
			'activities'         => self::prepare_activities( $booking ),
			'traveler_requests'  => self::prepare_traveler_requests( $booking ),
			'customer'           => self::prepare_customer( $booking ),
			'payment_link'       => $booking->get_due_payment_link(),
			'wc_order_edit_link' => self::wc_order_edit_link( $booking ),
			'state_badges'       => Booking::get_state_badges( $booking->post ),
			'original_booking'   => $migrated_from ? (string) get_edit_post_link( $migrated_from, 'raw' ) : '',
			'is_manual'          => $booking->is_manual(),
		);

		return $data;
	}

	/**
	 * Describes each trip in the cart.
	 *
	 * @param BookingModel $booking Booking being described.
	 *
	 * @return array<int, array<string, mixed>>
	 * @since 6.9.0
	 */
	protected static function prepare_booked_trips( BookingModel $booking ): array {
		/** @var CartInfoParser $parser */
		$trips  = array();
		$parser = $booking->cart_info;

		// What each trip was called when booked, for one deleted since.
		$ordered = array_column( ArrayUtility::to_array( $booking->get_meta( 'order_trips' ) ), 'title', 'ID' );

		// The quote link checkout offered in place of plans; booking-wide, so it rides on the first trip.
		$insurance = ArrayUtility::to_array( $booking->get_meta( 'wptravelengine_travel_insurance' ) );
		$affiliate = esc_url_raw( (string) ( $insurance['travel_insurance_affiliate_link'] ?? '' ) );

		/** @var BookedItem $item */
		foreach ( $parser->get_items() as $index => $item ) {
			$line_items = $item->get_line_items();
			$categories = $line_items['pricing_category'] ?? array();

			unset( $line_items['pricing_category'] );

			if ( $categories ) {
				$line_items = array_merge( array( 'price_category' => $categories ), $line_items );
			}

			$trip       = $item->get_trip();
			$trip_id    = (int) $item->get_trip_id();
			$package_id = (int) $item->get_trip_package_id();

			$title = (string) $item->get_trip_title();

			if ( '' === $title ) {
				$title = (string) ( $ordered[ $trip_id ] ?? '' );
			}

			if ( '' === $title && $trip_id ) {
				/* translators: %d: ID of a trip that no longer exists. */
				$title = sprintf( __( 'Trip #%d', 'wp-travel-engine' ), $trip_id );
			}

			$p_label = (string) $item->get_package_name();

			if ( '' === $p_label && $package_id ) {
				$p_label = (string) get_the_title( $package_id );
			}

			if ( '' === $p_label && $package_id ) {
				/* translators: %d: ID of a trip package that no longer exists. */
				$p_label = sprintf( __( 'Package #%d', 'wp-travel-engine' ), $package_id );
			}

			$_line_items = array();
			$travelers   = $booking->get_travelers();

			foreach ( $line_items as $id => $rows ) {
				if ( ! is_array( $rows ) || empty( $rows ) ) {
					continue;
				}

				$items   = array();
				$handler = LineItemsManager::get( 'price_category' === $id ? 'pricing_category' : $id );
				foreach ( $handler->normalize( array_map( array( ArrayUtility::class, 'to_array' ), $rows ) ) as $row ) {
					$items[] = $handler->to_resource( $row );

					$at = $row['traveller_id'] ?? null;
					if ( 'pickup_point' === $id && null !== $at && isset( $travelers[ (int) $at ] ) ) {
						$travelers[ $at ]['pickups'] = array(
							'label'     => wptravelengine_decode_entity( (string) ( $travelers[ $at ]['pickup_point'] ?? '' ) ?: (string) ( $row['label'] ?? '' ) ),
							'price'     => (float) ( $row['price'] ?? 0 ),
							'is_manual' => wptravelengine_toggled( $row['is_manual'] ?? false ),
						);
					}
				}

				$_line_items[] = compact( 'id', 'items' );
			}

			$status = $trip_id ? get_post_status( $trip_id ) : false;

			$trip = array(
				'key'             => $index,
				'id'              => $trip_id,
				'exists'          => false !== $status && 'trash' !== $status,
				'title'           => wptravelengine_decode_entity( $title ),
				'url'             => $trip ? $trip->get_permalink() : '',
				'image'           => $trip ? $trip->get_thumbnail()['src'] : '',
				'trip_code'       => $item->get_trip_code(),
				'start_datetime'  => $item->get_start_date(),
				'end_datetime'    => $item->get_end_date(),
				'duration_label'  => $booking->trip_duration_label( $trip_id ),
				'travelers_count' => $item->travelers_count(),
				'package'         => array(
					'id'    => $package_id,
					'label' => wptravelengine_decode_entity( $p_label ),
				),
				'line_items'      => $_line_items,
				'travelers'       => $travelers,
			);

			if ( '' !== $affiliate && empty( $trips ) ) {
				$trip['insurance_affiliate'] = array(
					'link'  => $affiliate,
					'label' => wptravelengine_decode_entity( (string) ( $insurance['travel_insurance_affiliate_label'] ?? '' ) ),
				);
			}

			$availability = self::seat_state( $item, null, $booking->ID );

			if ( null !== $availability ) {
				$trip['availability'] = $availability;
			}

			$trips[] = $trip;
		}

		return $trips;
	}

	/**
	 * Seat availability for a booked item on a date.
	 *
	 * Capacity is read unclamped and other bookings' pax subtracted, so the booking never
	 * counts against itself and an oversold date stays visible. The recurrence check runs
	 * only for a candidate date: it drops past departures and anything inside the cut-off.
	 *
	 * @param BookedItem  $item       Booked item to price seats for.
	 * @param string|null $date       Y-m-d candidate date. Null uses the booked date.
	 * @param int         $booking_id Booking to exclude from the booked total.
	 * @return array<string, mixed>|null Null when it cannot be determined.
	 * @since 6.9.0
	 */
	public static function seat_state( BookedItem $item, ?string $date, int $booking_id ): ?array {
		$trip_id    = (int) $item->get_trip_id();
		$package_id = (int) $item->get_trip_package_id();
		$booked     = substr( (string) $item->get_start_date(), 0, 10 );
		$date       = $date ?? $booked;

		if ( ! $trip_id || ! $package_id || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
			return null;
		}

		$trip    = wptravelengine_get_trip( $trip_id, true );
		$package = $trip ? $trip->get_package( $package_id ) : null;

		if ( ! $package ) {
			return null;
		}

		$capacity = $package->get_cat_max_cap( null );

		if ( ! is_numeric( $capacity ) ) {
			$capacity = wptravelengine_normalize_numeric_val( $trip->get_maximum_participants() );
		}

		// Nothing departs that day, so none of the package's seats are open on it.
		if ( $date !== $booked && ! self::is_valid_departure( $package, $date ) ) {
			return array(
				'date'         => $date,
				'status'       => 'invalid_date',
				'is_unlimited' => false,
				'capacity'     => is_numeric( $capacity ) ? (int) $capacity : null,
				'seats_left'   => 0,
				'booked_pax'   => $item->travelers_count(),
			);
		}

		if ( ! is_numeric( $capacity ) ) {
			return array(
				'date'         => $date,
				'status'       => 'unlimited',
				'is_unlimited' => true,
				'capacity'     => null,
				'seats_left'   => null,
				'booked_pax'   => $item->travelers_count(),
			);
		}

		$others = ( new Inventory( Inventory::get_original_trip_id( $trip_id ) ) )
			->pax_on_date( $package_id, $date, $booking_id );

		$seats_left = (int) $capacity - $others;

		if ( $date < wp_date( 'Y-m-d' ) ) {
			$status = 'past';
		} elseif ( $seats_left > 0 ) {
			$status = 'available';
		} else {
			$status = $seats_left < 0 ? 'oversold' : 'full';
		}

		return array(
			'date'         => $date,
			'status'       => $status,
			'is_unlimited' => false,
			'capacity'     => (int) $capacity,
			'seats_left'   => $seats_left,
			'booked_pax'   => $item->travelers_count(),
		);
	}

	/**
	 * Whether a candidate date is a departure for this package.
	 *
	 * `version` must be `v3`, or the parser omits seats and every date reads as uncapped.
	 *
	 * @param TripPackage $package Package the booking sits on.
	 * @param string      $date    Y-m-d candidate date.
	 * @return bool
	 * @since 6.9.0
	 */
	protected static function is_valid_departure( TripPackage $package, string $date ): bool {
		$dates = $package->get_package_dates(
			array(
				'from'    => $date,
				'to'      => $date,
				'version' => 'v3',
			)
		);

		return isset( $dates[ $date ] );
	}

	/**
	 * Edit link for the WooCommerce order this booking was paid through, if any.
	 *
	 * Falls back to the `_wte_wc_order_id` meta key for add-on versions older than 1.5.0,
	 * which never hook the filter.
	 *
	 * @param BookingModel $booking Booking being described.
	 * @return string Admin edit URL for the WC order, or '' when none is linked.
	 * @since 6.9.0
	 */
	protected static function wc_order_edit_link( BookingModel $booking ): string {
		$link = (string) apply_filters( 'wptravelengine_booking_wc_order_edit_link', '', $booking );

		if ( '' === $link && function_exists( 'wc_get_order' ) ) {
			$order_id = get_post_meta( $booking->ID, '_wte_wc_order_id', true );
			$order    = $order_id ? wc_get_order( $order_id ) : false;
			$link     = $order ? $order->get_edit_order_url() : '';
		}

		return $link;
	}

	/**
	 * Describes each payment against the booking.
	 *
	 * @param BookingModel $booking Booking being described.
	 * @return array<int, array<string, mixed>>
	 * @since 6.9.0
	 */
	protected static function prepare_payments( BookingModel $booking ): array {
		$payments = array_filter(
			$booking->get_payments(),
			static function ( $payment ): bool {
				return $payment instanceof Payment;
			}
		);

		$fees = $payments ? array_column( self::get_available_fees( $booking->ID ), 'name' ) : array();

		return array_values(
			array_map(
				static function ( Payment $payment ) use ( $booking, $fees ): array {
					$status          = $payment->get_payment_status();
					$type            = wptravelengine_payment_status_type( $status );
					$request_deposit = $payment->get_request_deposit();
					$totals          = (array) $payment->get_cart_totals();

					$breakdown = array( 'deposit' => $payment->get_deposit() );

					foreach ( $booking->get_price_breakdown( $payment->ID ) as $slug => $fee ) {
						$breakdown[ $slug ] = $fee['amount'];
					}

					// A fee the booking's cart no longer lists, e.g. a booking fee, still counts on the payments that charged it.
					foreach ( $fees as $slug ) {
						if ( ! isset( $breakdown[ $slug ] ) && isset( $totals[ 'total_' . $slug ] ) ) {
							$breakdown[ $slug ] = (float) $totals[ 'total_' . $slug ];
						}
					}

					$gateway_fee = (float) $payment->get_gateway_fee();
					if ( $gateway_fee > 0 && isset( $totals['deposit'] ) ) {
						$breakdown['gateway_fee'] = $gateway_fee;
					}

					$payment_link = $booking->get_total_due_amount() < $payment->get_deposit() ? '' : $payment->get_payment_link();

					return array(
						'id'                   => $payment->ID,
						'label'                => self::gateway_label( $payment->get_payment_gateway() ),
						'gateway'              => $payment->get_payment_gateway(),
						'date'                 => $payment->get_transaction_date() ?: (string) $payment->post->post_date_gmt,
						'source'               => $payment->get_payment_source(),
						'source_admin_updated' => (bool) $payment->get_meta( 'is_checkout_payment_edited' ),
						'payment_link'         => $payment_link,
						'status'               => $type ?: $status,
						'transaction_id'       => $payment->get_transaction_id(),
						'currency'             => $payment->get_payable_currency(),
						'paid_amount'          => $payment->get_amount(),
						'payable_amount'       => $payment->is_completed() ? 0 : $payment->get_payable_amount(),
						'refunded'             => $payment->get_refunded_amount(),
						'remark'               => $payment->get_remark(),
						'breakdown'            => $breakdown,
						'is_payment_request'   => $payment->is_payment_request(),
						'deposit_type'         => $request_deposit['type'],
						'deposit_value'        => $request_deposit['value'],
					);
				},
				$payments
			)
		);
	}

	/**
	 * Gateway display label, falling back to a humanized slug.
	 *
	 * @param string $gateway Gateway slug.
	 * @return string
	 * @since 6.9.0
	 */
	protected static function gateway_label( string $gateway ): string {
		if ( '' === $gateway ) {
			return '';
		}

		$gateways = wp_travel_engine_get_available_payment_gateways();
		$labels   = $gateways[ $gateway ] ?? array();
		$label    = (string) ( $labels['public_label'] ?? $labels['label'] ?? '' );

		return '' !== $label ? wptravelengine_decode_entity( $label ) : wptravelengine_humanize_key( $gateway );
	}

	/**
	 * What has happened to the booking.
	 *
	 * Seeded with what SaveBooking recorded on the booking, which the filter may add to or
	 * replace. Entries are normalized to the schema shape, with unknown keys passed through
	 * for whatever added them.
	 *
	 * @param BookingModel $booking Booking being described.
	 * @return array<int, array<string, mixed>>
	 * @since 6.9.0
	 */
	protected static function prepare_activities( BookingModel $booking ): array {
		$recorded = $booking->get_activities();

		$activities = apply_filters( 'wptravelengine_booking_activities', $recorded, $booking );

		if ( ! is_array( $activities ) ) {
			return array();
		}

		$categories = array( 'payments', 'documents', 'traveler', 'addons', 'messages' );

		return array_values(
			array_map(
				function ( $entry ) use ( $categories ): array {
					$entry    = (array) $entry;
					$category = sanitize_key( $entry['category'] ?? '' );

					return array_merge(
						$entry,
						array(
							'id'       => $entry['id'] ?? '',
							'type'     => sanitize_key( $entry['type'] ?? '' ),
							'category' => \in_array( $category, $categories, true ) ? $category : '',
							'actor'    => 'traveler' === ( $entry['actor'] ?? '' ) ? 'traveler' : 'admin',
							'who'      => (string) ( $entry['who'] ?? '' ),
							'date'     => (string) ( $entry['date'] ?? '' ),
							'text'     => (string) ( $entry['text'] ?? '' ),
						)
					);
				},
				$activities
			)
		);
	}

	/**
	 * Changes the traveler has asked for.
	 *
	 * Core stores no such requests, so the list starts empty and is whatever the filter
	 * supplies. Each entry is normalized to the schema shape, with unknown keys passed
	 * through for whatever added them.
	 *
	 * @param BookingModel $booking Booking being described.
	 * @return array<int, array<string, mixed>>
	 * @since 6.9.0
	 */
	protected static function prepare_traveler_requests( BookingModel $booking ): array {

		$requests = apply_filters( 'wptravelengine_booking_traveler_requests', array(), $booking );

		if ( ! is_array( $requests ) ) {
			return array();
		}

		return array_values(
			array_map(
				function ( $request ): array {
					$request = (array) $request;

					return array_merge(
						$request,
						array(
							'id'         => $request['id'] ?? '',
							'type'       => sanitize_key( $request['type'] ?? '' ),
							'title'      => (string) ( $request['title'] ?? '' ),
							'date'       => (string) ( $request['date'] ?? '' ),
							'meta'       => (string) ( $request['meta'] ?? '' ),
							'summary'    => (string) ( $request['summary'] ?? '' ),
							'notes'      => (string) ( $request['notes'] ?? '' ),
							'admin_note' => (string) ( $request['admin_note'] ?? '' ),
							'status'     => 'handled' === ( $request['status'] ?? '' ) ? 'handled' : 'pending',
						)
					);
				},
				$requests
			)
		);
	}

	/**
	 * Customer the booking belongs to.
	 *
	 * @param BookingModel $booking Booking being described.
	 * @return array<string, mixed>
	 * @since 6.9.0
	 */
	protected static function prepare_customer( BookingModel $booking ): array {
		$customer = (array) $booking->get_customer();

		$name = trim( ( $customer['first_name'] ?? '' ) . ' ' . ( $customer['last_name'] ?? '' ) );

		if ( '' === $name ) {
			$name = (string) ( $customer['name'] ?? '' );
		}

		$email = (string) ( $customer['email'] ?? '' );

		return array(
			'id'     => (int) ( $customer['id'] ?? 0 ),
			'name'   => $name,
			'email'  => $email,
			'avatar' => '' !== $email ? (string) get_avatar_url( $email, array( 'size' => 112 ) ) : '',
		);
	}

	/**
	 * Bootstrap data handed to the React booking edit app.
	 *
	 * @param int $booking_id Booking post ID.
	 *
	 * @return array<string, mixed> Empty when the booking cannot be read.
	 * @since 6.9.0
	 */
	public static function get_edit_data( int $booking_id ): array {
		$booking = wptravelengine_get_booking( $booking_id, true );

		if ( ! $booking ) {
			return array();
		}

		$fields        = BookingFormFields::for( $booking, true );
		$currency_code = (string) $booking->get_currency();

		$data = array(
			'bookingId'       => $booking->ID,
			'links'           => array(
				'rest' => '/wptravelengine/v2/bookings/',
				'list' => admin_url( 'edit.php?post_type=booking' ),
			),
			'countries'       => Functions::get_countries(),
			'currencyCode'    => $currency_code,
			'currencySymbol'  => Functions::currency_symbol_by_code( $currency_code ),
			'labels'          => wptravelengine_get_labels(),
			'billing'         => $fields->billing( true ),
			'contacts'        => $fields->emergency_contacts( true ),
			'askEmergency'    => wptravelengine_toggled( wptravelengine_settings()->get( 'display_travellers_info', 'yes' ) ) && wptravelengine_toggled( wptravelengine_settings()->get( 'display_emergency_contact', 'yes' ) ),
			'travelers'       => $fields->travelers( true ),
			'pickupPoints'    => self::get_pickup_points( $booking ),
			'categoryOptions' => self::get_category_options( $booking ),
			'fields'          => $fields->field_definitions(),
			'paymentMethods'  => self::get_payment_methods(),
			'paymentFees'     => static::get_available_fees( $booking_id ),
			'exclusiveLabel'  => wptravelengine_decode_entity( $booking->cart_info->exclusive_label ),
			'coupons'         => self::get_coupons(),
			'reminderBody'    => SendPaymentReminder::template( $booking ),
			'activePlugins'   => array(
				'pricing_category' => true,
				'accommodation'    => wptravelengine_is_addon_active( 'accommodation' ),
				'extra_service'    => wptravelengine_is_addon_active( 'extra_service' ),
				'pickup_point'     => wptravelengine_is_addon_active( 'pickup_point' ),
				'travel_insurance' => wptravelengine_is_addon_active( 'travel_insurance' ),
				'zapier'           => wptravelengine_is_addon_active( 'zapier' ),
			),
			'commissions'     => apply_filters( 'wptravelengine_booking_commissions', array(), $booking ),
			'ajax'            => array(
				'url'    => admin_url( 'admin-ajax.php' ),
				'nonces' => array(
					'purchase_receipt' => wp_create_nonce( 'wte_resend_purchase_receipt' ),
					'payment_reminder' => wp_create_nonce( 'wte_send_payment_reminder' ),
					'button_action'    => wp_create_nonce( 'wte_admin_button_action_nonce' ),
				),
			),
			'legacyHooks'     => self::get_legacy_hooks( $booking ),
		);

		/**
		 * Filters the data the React booking edit app boots with. Add-ons add theirs under their own key.
		 *
		 * @param array<string, mixed> $data    Bootstrap data.
		 * @param BookingModel         $booking Booking.
		 * @since 6.9.0
		 */
		return apply_filters( 'wptravelengine_booking_edit_data', $data, $booking );
	}

	/**
	 * Markup printed on the PHP booking screen's sidebar action, captured for the edit app.
	 *
	 * Read-only bridge: inputs in it are not saved and scripts in it do not run. Only callbacks
	 * hooked after priority 13 are captured. Add-ons should move to
	 * `wptravelengine_booking_edit_data` and the `wptravelengine.booking_edit.*` JS filters.
	 *
	 * @param BookingModel $booking Booking.
	 * @return array<string, string> HTML by slot; empty when nothing is printed.
	 * @since 6.9.0
	 */
	private static function get_legacy_hooks( BookingModel $booking ): array {
		global $wp_filter;

		$hook = 'wptravelengine_booking_details_sidebar';

		if ( ! isset( $wp_filter[ $hook ] ) ) {
			return array();
		}

		ob_start();
		foreach ( $wp_filter[ $hook ]->callbacks as $priority => $callbacks ) {
			if ( $priority <= 13 ) {
				continue;
			}
			foreach ( $callbacks as $callback ) {
				call_user_func_array( $callback['function'], array_slice( array( $booking ), 0, (int) $callback['accepted_args'] ) );
			}
		}
		$output = ob_get_clean();

		return '' !== trim( $output ) ? array( 'sidebar' => $output ) : array();
	}

	/**
	 * The pickup points each booked trip offers, for the traveler editor to choose from.
	 *
	 * Read off the trip rather than the booking: a booking records which point a traveler was
	 * collected from, not the list it was picked out of, and the operator is picking again.
	 *
	 * @param BookingModel $booking Booking being edited.
	 * @return array<int, array<int, array<string, mixed>>> Points keyed by trip ID; a trip
	 *                                                      offering none is left out.
	 * @since 6.9.0
	 */
	public static function get_pickup_points( BookingModel $booking ): array {
		$points = array();

		foreach ( $booking->booked_trip_ids() as $trip_id ) {
			$stored = get_post_meta( (int) $trip_id, 'wptravelengine_pickup_points', true );

			if ( ! is_array( $stored ) || ! $stored ) {
				continue;
			}

			$offered = array();

			foreach ( $stored as $point ) {
				$id = (string) ( $point['id'] ?? '' );

				if ( '' === $id ) {
					continue;
				}

				// A point that costs nothing says so either by its type or by an empty price.
				$is_free = 'free' === ( $point['pickup_type'] ?? '' ) || '' === ( $point['price'] ?? '' );

				$offered[] = array(
					'id'       => $id,
					'location' => wptravelengine_decode_entity( (string) ( $point['location'] ?? '' ) ),
					'price'    => $is_free ? 0.0 : (float) $point['price'],
				);
			}

			if ( $offered ) {
				$points[ (int) $trip_id ] = $offered;
			}
		}

		return $points;
	}

	/**
	 * The traveler categories each booked trip can be priced under, for the traveler editor's select.
	 *
	 * The booked package's categories, plus any the cart still holds that the package no longer offers,
	 * marked `deleted`. Each carries the rate a traveler adds: what the cart charges for it, or the
	 * package's current price for a category the booking holds no one under.
	 *
	 * @param BookingModel $booking Booking being edited.
	 * @return array<int, array<int, array{value: string, label: string, deleted: bool, price: float}>> Options keyed by trip ID.
	 * @since 6.9.0
	 */
	protected static function get_category_options( BookingModel $booking ): array {
		$options = array();

		foreach ( (array) $booking->get_cart_info( 'items' ) as $item ) {
			$item       = ArrayUtility::to_array( $item );
			$trip_id    = (int) ( $item['trip_id'] ?? 0 );
			$package_id = (int) ( $item['price_key'] ?? 0 );
			$labels     = array();
			$prices     = array();

			$trip    = $trip_id ? wptravelengine_get_trip( $trip_id ) : null;
			$package = $trip ? $trip->get_package( $package_id ) : null;

			// A trip made from a manual booking keeps its package in `__manual_packages`, not `packages_ids`.
			if ( ! $package && $trip && 'trip-packages' === get_post_type( $package_id ) ) {
				$package = new TripPackage( $package_id, $trip );
			}

			$packages = array();

			if ( $package ) {
				$packages[] = $package;
			} elseif ( $trip ) {
				foreach ( wp_parse_id_list( (array) get_post_meta( $trip_id, 'packages_ids', true ) ) as $pid ) {
					$current = $trip->get_package( $pid );

					if ( $current ) {
						$packages[] = $current;
					}
				}
			}

			foreach ( $packages as $pkg ) {
				foreach ( $pkg->get_traveler_categories() as $category ) {
					$labels[ (int) $category->id ] = (string) $category->label;
					$prices[ (int) $category->id ] = (float) $category->get_actual_price();
				}
			}

			$offered = $labels;

			foreach ( (array) ( $item['line_items']['pricing_category'] ?? array() ) as $row ) {
				$row = ArrayUtility::to_array( $row );
				$id  = (int) ( $row['id'] ?? 0 );

				if ( ! $id ) {
					continue;
				}

				if ( ! isset( $labels[ $id ] ) ) {
					$labels[ $id ] = (string) ( $row['label'] ?? "Category - {$id}" );
				}

				// The booking's own rate wins: it is what the cart prices this category at.
				$prices[ $id ] = (float) ( $row['price'] ?? $prices[ $id ] ?? 0 );
			}

			foreach ( $labels as $id => $label ) {
				$deleted = ! isset( $offered[ $id ] );

				$options[ $trip_id ][] = array(
					'value'   => (string) $id,
					'label'   => $deleted
						/* translators: %s: name of a traveler category the trip no longer offers. */
						? sprintf( __( '%s (Deleted)', 'wp-travel-engine' ), wptravelengine_decode_entity( $label ) )
						: wptravelengine_decode_entity( $label ),
					'deleted' => $deleted,
					'price'   => $prices[ $id ] ?? 0.0,
				);
			}
		}

		return $options;
	}
}
