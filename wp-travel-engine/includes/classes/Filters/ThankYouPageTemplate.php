<?php
/**
 * Thank You Page Template Filters.
 *
 * @since 6.3.3
 */

namespace WPTravelEngine\Filters;

use WPTravelEngine\Builders\FormFields\TravellerFormFields;
use WPTravelEngine\Builders\FormFields\BillingFormFields;
use WPTravelEngine\Builders\FormFields\EmergencyFormFields;
use WPTravelEngine\Abstracts\CartItem;
use WPTravelEngine\Core\Cart\Cart;
use WPTravelEngine\Core\Models\Post\Booking;
use WPTravelEngine\Core\Coupons;
use WPTravelEngine\Core\Models\Post\Payment;
use WPTravelEngine\Core\Models\Post\Trip;
use WPTravelEngine\Pages\Checkout;
use WPTravelEngine\PaymentGateways\CheckPayment;
use WPTravelEngine\PaymentGateways\DirectBankTransfer;
use WPTravelEngine\PaymentGateways\PaymentGateways;
use WPTravelEngine\Core\Cart\Adjustments\GatewayFee;

/**
 * Thank You Page Template Filters.
 *
 * @since 6.3.3
 */
class ThankYouPageTemplate extends CheckoutPageTemplate {

	public Booking $booking;

	public Payment $payment;

	/**
	 * @var ?Cart
	 */
	public ?Cart $cart = null;

	/**
	 * Constructor.
	 *
	 * @param Booking $booking
	 * @param Payment $payment
	 */
	public function __construct( Booking $booking, Payment $payment ) {
		$this->booking = $booking;
		$this->payment = $payment;

		$this->set_cart();

		add_action(
			'shutdown',
			function () {
				$this->cart->clear();
			}
		);
	}

	/**
	 * @return void
	 * @since 6.9.0 Prints payment details after booking details; drops the tour-details part hook.
	 */
	public function hooks() {
		add_action( 'wptravelengine_thankyou_before_content', array( $this, 'page_header' ) );
		add_action( 'wptravelengine_thankyou_content', array( $this, 'page_content' ) );
		add_action( 'wptravelengine_thankyou_booking_details', array( $this, 'booking_details' ) );
		add_action( 'wptravelengine_thankyou_after_booking_details', array( $this, 'print_payment_details' ), 9 );
		add_action( 'wptravelengine_thankyou_after_booking_details', array( $this, 'after_booking_details' ) );
		add_action( 'wptravelengine_thankyou_cart_summary', array( $this, 'cart_summary' ) );
		add_action( 'thankyou_template_parts_cart-summary', array( $this, 'cart_summary_partial' ) );
		add_action( 'wptravelengine_thankyou_booking_details_direct_bank_transfer', array( $this, 'print_bank_details' ) );
		add_action( 'wptravelengine_thankyou_booking_details_check_payments', array( $this, 'print_check_instruction' ) );
	}

	/**
	 * Print Check Instruction.
	 *
	 * @since 6.3.3
	 */
	public function print_check_instruction( $payment_id ) {
		$payment = wptravelengine_get_payment( $payment_id );
		if ( $payment ) {
			$check_payment = new CheckPayment();
			$check_payment->print_instruction( $payment_id );
		}
	}

	/**
	 * After Booking Details.
	 *
	 * @since 6.3.3
	 */
	public function after_booking_details() {
		do_action( "wptravelengine_thankyou_booking_details_{$this->payment->get_payment_gateway()}", $this->payment->get_id() );
	}

	/**
	 * Print Bank Details.
	 *
	 * @since 6.3.3
	 */
	public function print_bank_details( $payment_id ) {
		$payment = wptravelengine_get_payment( $payment_id );
		if ( $payment ) {
			$direct_bank_transfer = new DirectBankTransfer();

			$direct_bank_transfer->print_instruction( $payment_id );
		}
	}

	/**
	 * Print Payment Details.
	 *
	 * @since 6.3.3
	 * @since 6.9.0 Hidden for Book Now Pay Later; shows transaction ID, date and gateway instead of status.
	 */
	public function print_payment_details() {

		// Hidden for Book Now Pay Later — no payment has actually been taken yet.
		if ( 'booking_only' === $this->payment->get_payment_gateway() ) {
			return;
		}

		$remarks        = __( 'Your booking order has been placed. Your booking will be confirmed after payment confirmation/settlement.', 'wp-travel-engine' );
		$payment_amount = $this->cart->get_total_payable_amount() ?: $this->payment->get_payable_amount();

		// Falls back to the payment post's date, same as ViewBooking.
		$transaction_id   = $this->payment->get_transaction_id();
		$transaction_date = $this->payment->get_transaction_date();
		$transaction_date = '' !== $transaction_date ? $transaction_date : (string) $this->payment->post->post_date_gmt;
		$payment_gateway  = $this->get_payment_gateway_label();

		wptravelengine_get_template(
			'thank-you/content-payment-details.php',
			compact(
				'payment_amount',
				'transaction_id',
				'transaction_date',
				'payment_gateway',
				'remarks'
			)
		);
	}

	/**
	 * Resolve a human-readable label for the payment's gateway.
	 *
	 * Falls back to the stored gateway slug when a registered gateway
	 * object with a label cannot be resolved.
	 *
	 * @return string
	 * @since 6.9.0
	 */
	public function get_payment_gateway_label(): string {
		$gateway_slug = $this->payment->get_payment_gateway();

		if ( '' === $gateway_slug ) {
			return '';
		}

		$gateway = PaymentGateways::instance()->get_payment_gateway( $gateway_slug );
		$label   = $gateway ? $gateway->get_label() : '';

		return '' !== $label ? $label : $gateway_slug;
	}

	/**
	 * Payment status badge label for the confirmation page (Fully Paid / Deposit Paid / Payment Pending).
	 *
	 * @return string
	 * @since 6.9.0
	 */
	public function get_confirmation_payment_status_label(): string {
		if ( 'booking_only' === $this->payment->get_payment_gateway() ) {
			return __( 'Payment Pending', 'wp-travel-engine' );
		}

		return wptravelengine_get_labels( 'booking_payment' )[ $this->booking->get_payment_status() ] ?? $this->booking->get_payment_status_label();
	}

	/**
	 * @return Cart
	 */
	public function get_cart(): ?Cart {
		return $this->cart;
	}

	/**
	 * Sets the cart from the booking's cart info.
	 *
	 * @return void
	 * @since 6.9.0 Sets the initial deposit for a due payment.
	 */
	protected function set_cart() {
		if ( ! $this->cart ) {
			global $wte_cart;

			$this->cart  = clone $wte_cart;
			$cart_info   = $this->booking->get_cart_info();
			$gateway_fee = $this->payment->get_gateway_fee();

			if ( $gateway_fee > 0.00 ) {
				$cart_info['totals']['payable_now']       = $this->payment->get_amount();
				$cart_info['totals']['total_gateway_fee'] = $gateway_fee;

				$gateway_fee_obj = new GatewayFee( $this->cart );
				$this->cart->add_fee( $gateway_fee_obj );
			}

			$this->cart->set_cart_key( wptravelengine_generate_key( time() ) );

			$this->cart->version = $this->booking->get_cart_version();

			$this->cart->load( $cart_info );

			// Totals are loaded, not recalculated; a due payment's deposit is what was paid before it.
			$this->cart->initial_deposit = null;
			if ( 'due' === $this->cart->get_payment_type() ) {
				$this->cart->initial_deposit = (string) $this->booking->get_deposit_till_now( $this->payment->get_id() );
			}
		}

		return $this->cart;
	}

	/**
	 * @return mixed|null
	 * @since 6.3.5
	 * @since 6.9.0 Takes the trip title and end date from the booking.
	 */
	public function get_tour_details() {
		$cart_items = $this->cart->getItems();

		$item_details = array();
		$end_datetime = $this->booking->get_end_datetime();

		foreach ( $cart_items as $cart_item ) {
			/** @var array $cart_item */
			$cart_id         = $cart_item['id'] ?? null;
			$trip_start_date = ! empty( $cart_item['trip_time'] ) ? $cart_item['trip_time'] : $cart_item['trip_date'];
			if ( ! empty( $end_datetime ) ) {
				$trip_end_date = wptravelengine_format_trip_datetime( $end_datetime );
			} else {
				// Legacy fallback for bookings with neither order_trips nor legacy end-date meta set.
				$trip_end_date = wptravelengine_format_trip_end_datetime( $trip_start_date, new Trip( $cart_item['trip_id'] ) );
			}
			$package_name = $cart_item['package_name'] ?? '';
			if ( empty( $package_name ) && ! empty( $cart_item['price_key'] ) ) {
				$package_name = get_the_title( $cart_item['price_key'] );
			}
			$travelers_count = isset( $cart_item['travelers_count'] ) && $cart_item['travelers_count'] > 0 ? $cart_item['travelers_count'] : array_sum( $cart_item['pax'] ?? array() );

			// if ( ! empty( $cart_item['trip_time_range'] ) ) {
			// $trip_end_date = wptravelengine_format_trip_datetime( $cart_item['trip_time_range'][1] ?? '' );
			// }

			$trip_id        = $cart_item['trip_id'];
			$is_custom_trip = wptravelengine_toggled( get_post_meta( $trip_id, 'is_created_from_booking', true ) );
			$trip_obj       = wptravelengine_get_trip( $trip_id );

			$title     = $this->booking->get_trip_title( $cart_id );
			$link      = $is_custom_trip ? '' : get_the_permalink( $trip_id );
			$trip_code = null !== $trip_obj ? $trip_obj->get_trip_code() : '';

			$item = array(
				sprintf(
					'<tr><td colspan="2">%s</td></tr>',
					sprintf(
						'<a %sclass="wpte-checkout__trip-name">%s</a>',
						$link ? 'href="' . esc_url( $link ) . '" ' : '',
						esc_html( $title )
					)
				),
				sprintf( '<tr><td>%s</td><td><strong>%s</strong></td></tr>', __( 'Booking ID:', 'wp-travel-engine' ), $this->booking->get_id() ),
				sprintf( '<tr><td>%s</td><td><strong>%s</strong></td></tr>', __( 'Package:', 'wp-travel-engine' ), $package_name ),
				sprintf( '<tr><td>%s</td><td><strong>%s</strong></td></tr>', __( 'Trip Code:', 'wp-travel-engine' ), $trip_code ),
				sprintf( '<tr><td>%s</td><td><strong>%s</strong></td></tr>', __( 'Starts On:', 'wp-travel-engine' ), wptravelengine_format_trip_datetime( $trip_start_date ) ),
				sprintf( '<tr><td>%s</td><td><strong>%s</strong></td></tr>', __( 'Ends On:', 'wp-travel-engine' ), $trip_end_date ),
				sprintf( '<tr><td>%s</td><td><strong>%s</strong></td></tr>', __( 'No. of Travelers:', 'wp-travel-engine' ), $travelers_count ),
			);

			$item_details[] = apply_filters( 'wptravelengine_checkout_page_item_' . __FUNCTION__, $item, $trip_obj, $cart_item );
		}

		return apply_filters( 'wptravelengine_checkout_page_' . __FUNCTION__, $item_details, $cart_items, $this );
	}

	/**
	 * Grouped add-on line items for the main-column trip card (accommodation, extras, insurance…).
	 *
	 * Traveler pricing categories are excluded — the travelers accordion already shows them.
	 *
	 * @return array<int, array{caption:string, items:array<int, array>}>
	 * @since 6.9.0
	 */
	public function get_trip_line_item_groups(): array {
		$groups   = array();
		$captions = wptravelengine_get_labels( 'line_item_group' );

		foreach ( $this->cart->getItems( true ) as $cart_item ) {
			if ( ! method_exists( $cart_item, 'get_additional_line_items' ) ) {
				continue;
			}

			foreach ( $cart_item->get_additional_line_items() as $item_type => $items ) {
				if ( 'pricing_category' === $item_type ) {
					continue;
				}

				$line_items = array();
				foreach ( (array) $items as $item ) {
					$candidates = $item instanceof CartItem ? array( $item ) : (array) $item;
					foreach ( $candidates as $line_item ) {
						if ( ! $line_item instanceof CartItem ) {
							continue;
						}
						$line_items[] = array(
							'name'  => $line_item->label,
							'qty'   => $line_item->quantity,
							'price' => $line_item->price,
							'total' => $line_item->get_subtotal(),
						);
					}
				}

				if ( empty( $line_items ) ) {
					continue;
				}

				$groups[] = array(
					'caption' => $captions[ $item_type ] ?? ucwords( str_replace( '_', ' ', $item_type ) ),
					'items'   => $line_items,
				);
			}
		}

		return apply_filters( 'wptravelengine_thankyou_trip_line_item_groups', $groups, $this );
	}

	/**
	 * Tour Details.
	 *
	 * @since 6.3.3
	 */
	public function tour_details() {
		$tour_details = $this->get_tour_details();
		wptravelengine_get_template(
			'template-checkout/content-tour-details.php',
			array_merge(
				compact( 'tour_details' ),
				array(
					'content_only' => true,
				)
			)
		);
	}

	/**
	 * Print the Cart Summary.
	 *
	 * @return void
	 * @since 6.4.0
	 * @since 6.9.0 Reads cart info through wptravelengine_get_cart_info(); scopes coupons to cart trips.
	 */
	public function print_cart_summary( $args ) {
		$template_instance                    = new Checkout( $this->cart );
		$template_instance->is_checkout_page  = false;
		$template_instance->payment_completed = $this->payment->is_completed();

		$cart_info = wptravelengine_get_cart_info( $this->booking->ID );

		$cart_line_items = $template_instance->get_cart_line_items();

		$deposit_amount = $cart_info->get_totals( 'partial_total' );
		$due_amount     = $cart_info->get_totals( 'due_total' );
		// Check if payment is due or partial payment.
		$is_payment_due = $this->cart->get_booking_ref();
		if ( $is_payment_due ) {
			$deposit_amount = $this->booking->get_total_paid_amount();
			$due_amount     = $this->booking->get_total_due_amount();
		}
		$is_partial_payment = in_array(
			$template_instance->cart->get_payment_type(),
			array(
				'partial',
				'due',
				'remaining_payment',
			),
			true
		);

		$show_coupon_form = wptravelengine_settings()->get( 'show_discount' ) === 'yes' && Coupons::is_coupon_available( $this->cart->get_cart_trip_ids() ) && 'due' !== $this->cart->get_payment_type() ? 'show' : 'hide';

		$coupons = array();

		foreach ( $this->cart->get_deductible_items() as $coupon_item ) {
			if ( 'coupon' !== $coupon_item->name ) {
				continue;
			}
			$coupons[] = array(
				'label'  => $coupon_item->label,
				'amount' => $this->cart->get_totals()['total_coupon'] ?? 0,
			);
		}

		$args = array_merge(
			compact( 'cart_line_items', 'deposit_amount', 'due_amount', 'is_partial_payment', 'coupons' ),
			array(
				'show_coupon_form'  => $show_coupon_form === 'show',
				'_wte_cart'         => $this->cart,
				'template_instance' => $template_instance,
			),
			$args
		);

		wptravelengine_get_template(
			'template-checkout/content-cart-summary.php',
			$args
		);
	}

	/**
	 * Cart Summary Partial.
	 *
	 * @since 6.3.3
	 */
	public function cart_summary_partial() {
		$this->print_cart_summary(
			array(
				'show_coupon_form' => false,
				'content_only'     => true,
				'show_title'       => true,
			)
		);
	}

	/**
	 * Page Header.
	 *
	 * @since 6.3.3
	 * @since 6.9.0 New message; passes booking reference, billing email and account-created flag.
	 */
	public function page_header() {

		if ( ! $thankyou_message = wptravelengine_settings()->get( 'confirmation_msg', false ) ) {
			$thankyou_message = __( "Your booking confirmation has been sent to {email}. Please check your spam folder if it doesn't arrive within a few minutes.", 'wp-travel-engine' );
		}

		$booking_reference = $this->booking->ID;
		$billing_email     = $this->booking->get_billing_email();
		$account_created   = wptravelengine_toggled( $this->booking->get_meta( 'wptravelengine_account_created' ) );

		wptravelengine_get_template(
			'thank-you/content-page-header.php',
			compact( 'thankyou_message', 'booking_reference', 'billing_email', 'account_created' )
		);
	}

	/**
	 * Page Content.
	 *
	 * @since 6.3.3
	 */
	public function page_content() {
		wptravelengine_get_template( 'thank-you/content-thank-you.php' );
	}

	/**
	 * Booking Details.
	 *
	 * @since 6.3.3
	 * @since 6.9.0 Shows a start time only when the trip date carries one.
	 */
	public function booking_details() {

		$order_trips        = $this->booking->get_meta( 'order_trips' );
		$order_trips        = is_array( $order_trips ) ? $order_trips : array();
		$additional_note    = $this->booking->get_meta( 'wptravelengine_additional_note' );
		$_traveller_details = $this->booking->get_travelers();
		$_booking_details   = $this->booking->get_meta( 'wptravelengine_billing_details' );
		$_emergency_details = $this->booking->get_meta( 'wptravelengine_emergency_details' );

		$order_trip = reset( $order_trips );
		$order_trip = is_array( $order_trip ) ? $order_trip : array();
		$cart_id    = key( $order_trips );

		$trip_id        = $order_trip['ID'] ?? $this->booking->get_trip_id();
		$is_custom_trip = wptravelengine_toggled( get_post_meta( $trip_id, 'is_created_from_booking', true ) );
		$trip_obj       = wptravelengine_get_trip( $trip_id );

		$start_datetime = $order_trip['datetime'] ?? '';
		$end_datetime   = $this->booking->get_end_datetime();
		if ( empty( $end_datetime ) && null !== $trip_obj ) {
			$end_datetime = wptravelengine_format_trip_end_datetime( $start_datetime, $trip_obj, 'Y-m-d H:i:s' );
		}

		$wpte_same_day = ! empty( $start_datetime ) && ! empty( $end_datetime )
			&& wp_date( 'Y-m-d', strtotime( $start_datetime ), new \DateTimeZone( 'UTC' ) ) === wp_date( 'Y-m-d', strtotime( $end_datetime ), new \DateTimeZone( 'UTC' ) );

		$date_format     = get_option( 'date_format', 'j M, Y' );
		$trip_start_date = wptravelengine_format_trip_datetime( $start_datetime, $date_format );
		$trip_end_date   = $wpte_same_day ? '' : wptravelengine_format_trip_datetime( $end_datetime, $date_format );

		// Region D1 trip-head data.
		$trip_title          = $this->booking->get_trip_title( $cart_id );
		$trip_permalink      = $is_custom_trip ? '' : get_the_permalink( $trip_id );
		$trip_reference      = $this->booking->get_id();
		$trip_code           = null !== $trip_obj ? $trip_obj->get_trip_code() : '';
		$payment_status      = $this->get_confirmation_payment_status_label();
		$is_completed        = $this->payment->is_completed();
		$trip_duration_label = null !== $trip_obj ? implode( ' / ', wptravelengine_get_trip_duration_arr( $trip_obj ) ) : '';

		$trip_start_time = ! empty( $order_trip['has_time'] ) && false !== strpos( $start_datetime, ':' ) ? wp_date( get_option( 'time_format', 'g:i a' ), strtotime( $start_datetime ), new \DateTimeZone( 'UTC' ) ) : '';

		// Package name from the booked cart item (falls back to the price-key post title).
		$cart_items   = $this->cart->getItems();
		$first_item   = is_array( $cart_items ) ? reset( $cart_items ) : array();
		$package_name = $first_item['package_name'] ?? '';
		if ( '' === $package_name && ! empty( $first_item['price_key'] ) ) {
			$package_name = get_the_title( $first_item['price_key'] );
		}

		// Add-on line-item groups (accommodation / extras / insurance) for the trip card.
		$line_item_groups = $this->get_trip_line_item_groups();

		$traveller_details     = array();
		$traveler_categories   = array();
		$traveller_form_fields = new TravellerFormFields();
		if ( is_array( $_traveller_details ) ) {
			foreach ( $_traveller_details as $index => $traveller ) {
				$traveller_details[]           = $traveller_form_fields->with_values( $traveller, $this->booking );
				$traveler_categories[ $index ] = $traveller['cat_label'] ?? '';
			}
		}

		$booking_details = array();
		if ( is_array( $_booking_details ) && ! empty( $_booking_details ) ) {
			$booking_form_fields = new BillingFormFields();
			$booking_details[]   = $booking_form_fields->with_values( $_booking_details );
		}

		$emergency_details = array();
		if ( is_array( $_emergency_details ) && ! empty( $_emergency_details ) ) {
			$emergency_form_fields = new EmergencyFormFields();

			if ( isset( $_emergency_details[0] ) && is_array( $_emergency_details[0] ) ) {
				foreach ( $_emergency_details as $emergency_contact ) {
					if ( is_array( $emergency_contact ) && ! empty( $emergency_contact ) ) {
						$emergency_details[] = $emergency_form_fields->with_values( $emergency_contact );
					}
				}
			} else {
				$emergency_details[] = $emergency_form_fields->with_values( $_emergency_details );
			}
		}

		wptravelengine_get_template(
			'thank-you/content-booking-details.php',
			compact(
				'trip_start_date',
				'trip_end_date',
				'trip_title',
				'trip_permalink',
				'trip_reference',
				'trip_code',
				'trip_duration_label',
				'trip_start_time',
				'package_name',
				'payment_status',
				'is_completed',
				'additional_note',
				'traveller_details',
				'traveler_categories',
				'booking_details',
				'emergency_details',
				'line_item_groups'
			)
		);
	}

	/**
	 * Cart Summary.
	 *
	 * @since 6.3.3
	 * @since 6.9.0 Passes the payment status label and completion flag to the template.
	 */
	public function cart_summary() {
		wptravelengine_get_template(
			'thank-you/content-cart-summary.php',
			array(
				'payment_status' => $this->get_confirmation_payment_status_label(),
				'is_completed'   => $this->payment->is_completed(),
			)
		);
	}
}
