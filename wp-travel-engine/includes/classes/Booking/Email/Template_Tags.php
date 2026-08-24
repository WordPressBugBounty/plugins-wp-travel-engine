<?php

/**
 * Email Tags.
 *
 * @since 5.5.3
 * @since 6.8.7 Extends EmailTags and resolves tags via build_callbacks() instead of eagerly building one large array.
 */
namespace WPTravelEngine\Booking\Email;

use WPTravelEngine\Builders\FormFields\BillingFormFields;
use WPTravelEngine\Builders\FormFields\EmergencyFormFields;
use WPTravelEngine\Builders\FormFields\TravellerFormFields;
use WPTravelEngine\Core\Controllers\Ajax\ResendPurchaseReceipt;
use WPTravelEngine\Core\Models\Settings\PluginSettings;
use WPTravelEngine\Helpers\Countries;
use WPTravelEngine\Abstracts\EmailTags;
use WPTravelEngine\Helpers\CartInfoParser;
use WPTravelEngine\Core\Models\Post\Booking;
use WPTravelEngine\Utilities\PaymentCalculator;

class Template_Tags extends EmailTags {

	/**
	 * @inheritDoc
	 */
	public const CALLBACKS_FILTER = 'wptravelengine_email_tags_callback';

	/**
	 * @inheritDoc
	 */
	public const METHOD_ALIASES = array(
		'get_name'                      => 'get_billing_first_name',
		'get_last_name'                 => 'get_billing_last_name',
		'get_fullname'                  => 'get_billing_fullname',
		'get_user_email'                => 'get_billing_email',
		'get_city'                      => 'get_billing_city',
		'get_country'                   => 'get_billing_country',
		'get_total'                     => 'get_total_amount',
		'get_check_payment_instruction' => 'get_check_payment_details',
		'get_trip_payment_details'      => 'get_trip_booking_payment',
		'get_traveler_count'            => 'get_no_of_travelers',
		'get_date'                      => 'get_current_date',
		'get_trip_booked_date'          => 'get_current_date',
		'get_total_cost'                => 'get_total_amount',
	);

	/**
	 * Called from booking details.
	 *
	 * @var bool
	 * @since 6.7.1
	 */
	public bool $called_from_booking_details = false;

	/**
	 * Called from payment details.
	 *
	 * @var bool
	 * @since 6.7.1
	 */
	public bool $called_from_payment_details = false;

	/**
	 * Raw `order_trips` meta for the booking — one entry per trip in the cart.
	 *
	 * @var array
	 * @since 6.8.7
	 */
	public array $order_trips;

	/**
	 * Global Settings.
	 *
	 * @var object
	 * @since 6.8.7
	 */
	public object $global_settings;

	/**
	 * Manual Email Trigger or not
	 *
	 * @var bool
	 * @since 6.8.7
	 */
	public bool $is_manual_trigger;

	/**
	 * Constructor.
	 *
	 * @since 6.8.0 Use get_post_meta() directly for traveller_details to bypass magic property caching.
	 * @since 6.8.7 Use wptravelengine_get_booking()/wptravelengine_get_payment() (Booking/Payment models) instead of get_post(); meta reads go through get_meta() accordingly.
	 *
	 * @param int $booking_id Booking post ID.
	 * @param int $payment_id Payment post ID.
	 * @throws \InvalidArgumentException If the booking or payment cannot be found.
	 * @return void
	 */
	public function __construct( $booking_id, $payment_id ) {
		$this->booking = wptravelengine_get_booking( $booking_id );

		if ( ! $this->booking ) {
			throw new \InvalidArgumentException( sprintf( 'Booking with ID %d not found.', $booking_id ) );
		}

		$this->payment = wptravelengine_get_payment( $payment_id );

		if ( ! $this->payment ) {
			throw new \InvalidArgumentException( sprintf( 'Payment with ID %d not found.', $payment_id ) );
		}

		$this->cart_info = new CartInfoParser( (array) $this->booking->get_cart_info() );

		$this->order_trips  = $this->booking->get_order_items();
		$this->billing_info = $this->booking->get_billing_info() ?: array();

		$this->trip = (object) ( array_values( $this->order_trips )[0] ?? array() );

		$this->global_settings = new PluginSettings();

		$this->called_from_booking_details = false;
		$this->called_from_payment_details = false;

		$this->is_manual_trigger = apply_filters( 'wptravelengine_email_template_is_manual_trigger', 'wte_resend_purchase_receipt' === ( $_REQUEST['action'] ?? '' ), $this->booking );

		parent::__construct();
	}

	public function get_billing_fullname() {
		return implode( ' ', array( $this->get_billing_first_name(), $this->get_billing_last_name() ) );
	}

	/**
	 * Get the booked trip's title.
	 *
	 * @since 6.8.7
	 */
	public function get_booked_trip_name(): string {
		return html_entity_decode( $this->trip->title ?: 'Untitled Trip', ENT_QUOTES, 'UTF-8' );
	}

	/**
	 * @since 6.8.7
	 */
	public function get_trip_start_date(): string {
		return wptravelengine_format_trip_datetime( $this->trip->datetime );
	}

	/**
	 * @since 6.8.7
	 */
	public function get_trip_end_date(): string {
		return wptravelengine_format_trip_datetime( $this->trip->end_datetime ?? 0 );
	}

	/**
	 * @since 6.8.7
	 */
	public function get_tprice(): string {
		return wptravelengine_the_price_with_decimal( $this->trip->cost ?? 0, false );
	}

	/**
	 * @since 6.8.7
	 */
	public function get_booking_id(): string {
		return sprintf( __( 'Booking #%1$s', 'wp-travel-engine' ), $this->booking->ID );
	}

	/**
	 * @since 6.8.7
	 */
	public function get_booking_url(): string {
		return admin_url() . 'post.php?post=' . $this->booking->ID . '&action=edit';
	}

	/**
	 * @since 6.8.7
	 */
	public function get_booking_trips_count(): int {
		return ! empty( $this->order_trips ) ? count( $this->order_trips ) : 1;
	}

	/**
	 * @since 6.8.7
	 */
	public function get_payment_id(): int {
		return (int) $this->payment->ID;
	}

	/**
	 * @since 6.8.7
	 */
	public function get_payment_link(): string {
		return $this->booking->get_due_payment_link();
	}

	/**
	 * Get the subtotal amount.
	 *
	 * @since 6.5.0
	 * @return string
	 * @since 6.8.7 Added return type for EmailTags contract.
	 */
	public function get_subtotal(): string {
		return wptravelengine_the_price_with_decimal( $this->cart_info->subtotal, false );
	}

	/**
	 * Get the total amount.
	 *
	 * @since 6.5.0
	 * @return string
	 */
	public function get_total_amount() {
		return wptravelengine_the_price_with_decimal( $this->cart_info->total ?? 0, false );
	}

	/**
	 * @since 6.8.7
	 */
	public function get_due(): string {
		return wptravelengine_the_price_with_decimal( max( 0, $this->booking->get_total_due_amount() ), false );
	}

	/**
	 * @since 6.8.7
	 */
	public function get_total_gateway_fee(): string {
		$payments_data = $this->booking->get_payments_data();
		return wptravelengine_the_price_with_decimal( $payments_data['totals']['gateway_fee'] ?? 0, false );
	}

	/**
	 * @since 6.8.7
	 */
	public function get_discount_name(): string {
		$discount = $this->get_first_discount();
		return $discount ? (string) ( $discount->name ?? '' ) : '';
	}

	/**
	 * Get trip code for the booked trip.
	 *
	 * @since 6.6.10
	 * @since 6.8.7 Added return type for EmailTags contract.
	 */
	public function get_trip_code(): string {
		$trip = wptravelengine_get_trip( $this->trip->ID );

		return is_null( $trip ) ? '' : $trip->get_trip_code();
	}

	/**
	 * Set default tags.
	 *
	 * @param array $tags The tags.
	 *
	 * @return $this
	 * @since 6.5.0
	 * @since 6.8.7 Delegates tag resolution to get_email_tags() (EmailTags contract) instead of the old eager array-building flow.
	 */
	public function set_tags( array $tags = array() ) {
		parent::set_tags( $this->get_email_tags() );
		parent::set_tags( $tags );
		return $this;
	}

	/**
	 * Gets Booking Payment method by Payment ID.
	 *
	 * @param int|null $payment_id Defaults to the current payment's ID.
	 * @return string
	 * @since 6.8.7 Made $payment_id optional (defaults to $this->payment->ID) and added return type, to satisfy EmailTags::get_payment_method() contract; name/behavior otherwise unchanged.
	 */
	public function get_payment_method( $payment_id = null ): string {
		$payment_id     = $payment_id ?? $this->payment->ID;
		$payment_method = get_post_meta( $payment_id, 'payment_gateway', true );

		return empty( $payment_method ) ? __( 'N/A', 'wp-travel-engine' ) : $payment_method;
	}

	/**
	 * @since 6.8.7
	 */
	public function get_discount_amount(): string {
		if ( ! $this->get_first_discount() ) {
			return '';
		}
		return wptravelengine_the_price( $this->cart_info->get_totals( 'total_coupon' ), false );
	}

	public function get_billing_first_name() {
		return $this->billing_info['fname'] ?? $this->billing_info['booking_first_name'] ?? '';
	}

	public function get_billing_last_name() {
		return $this->billing_info['lname'] ?? $this->billing_info['booking_last_name'] ?? '';
	}

	public function get_billing_email() {
		return $this->billing_info['email'] ?? $this->billing_info['booking_email'] ?? '';
	}

	/**
	 * @since 6.8.7 Added return type for EmailTags contract (name unchanged).
	 */
	public function get_billing_address(): string {
		return $this->billing_info['address'] ?? $this->billing_info['booking_address'] ?? '';
	}

	public function get_billing_city() {
		return $this->billing_info['city'] ?? $this->billing_info['booking_city'] ?? '';
	}

	/**
	 * Get the first discount entry from the cart, if any.
	 *
	 * @since 6.8.7
	 */
	private function get_first_discount(): ?object {
		$discounts = $this->cart_info->discounts;
		if ( empty( $discounts ) || ! is_array( $discounts ) ) {
			return null;
		}
		return (object) array_shift( $discounts );
	}

	/**
	 * @since 6.8.7
	 */
	public function get_discount_sign(): string {
		$discount = $this->get_first_discount();
		if ( ! $discount ) {
			return '';
		}
		return 'percentage' === $discount->type ? '%' : $this->payment->get_currency();
	}

	/**
	 * @since 6.8.7
	 */
	public function get_discount_value(): string {
		$discount = $this->get_first_discount();
		if ( ! $discount ) {
			return '';
		}
		return 'percentage' === $discount->type ? (string) $discount->value : $this->get_discount_amount();
	}

	/**
	 * Get the number of travelers.
	 *
	 * @return int
	 * @since 6.7.6
	 * @since 6.8.7 Rename Function name to get_no_of_travellers to get_no_of_travelers.
	 */
	public function get_no_of_travelers(): int {
		if ( ! empty( $this->trip->pax ) ) {
			return array_sum( $this->trip->pax );
		}

		$travelers_count = $this->cart_info->get_item()->travelers_count();
		if ( $travelers_count > 0 ) {
			return $travelers_count;
		}
		return 0;
	}

	/**
	 * Get the trip URL.
	 *
	 * @return string
	 * @since 6.7.6 Updated: only get url from this function.
	 * @since 6.8.7 Added return type for EmailTags contract.
	 */
	public function get_trip_url(): string {
		return esc_url( get_permalink( $this->trip->ID ?? 0 ) );
	}

	/**
	 * Get the current date.
	 *
	 * @since 6.5.0
	 * @return string
	 * @since 6.7.10 Updated booked date calculation.
	 */
	public function get_current_date() {
		$date_to_format = $this->booking->post_date_gmt ?: $this->booking->post_date;
		$timestamp      = strtotime( $date_to_format );

		return wp_date( get_option( 'date_format', 'Y-m-d' ) . ' ' . get_option( 'time_format', 'H:i:s' ), $timestamp );
	}

	/**
	 * Get the rendered traveler data template for the {traveler_data} deprecated tag.
	 *
	 * @since 6.8.7
	 */
	public function get_traveler_data(): string {
		$traveller_data = $this->booking->get_travelers();
		if ( empty( $traveller_data ) ) {
			return '';
		}
		$pno = isset( $this->trip->pax ) ? array_sum( $this->trip->pax ) : 0;

		return $this->get_traveller_template( 'traveller-data', $pno, $traveller_data );
	}

	public function get_billing_country() {
		$countries_list = Countries::list();
		if ( isset( $countries_list[ $this->billing_info['country'] ] ) ) {
			return $countries_list[ $this->billing_info['country'] ];
		}
		if ( isset( $this->billing_info['country'] ) ) {
			return $this->billing_info['country'];
		}

		return wte_array_get( $this->billing_info, 'booking_country', '' );
	}

	/**
	 * Get traveller email template.
	 */
	public function get_traveller_template( $type, $pno, $personal_options ) {
		ob_start();
		$args = array(
			'data'    => $personal_options,
			'numbers' => $pno,
		);

		// Email Content.
		wte_get_template( "emails/{$type}.php", $args );

		$template = ob_get_clean();

		return $template;
	}

	/**
	 * Get the paid amount.
	 *
	 * @since 6.5.0
	 * @return string
	 * @since 6.8.7 Added return type for EmailTags contract.
	 */
	public function get_paid_amount(): string {
		return wptravelengine_the_price_with_decimal( $this->booking->get_total_paid_amount(), false );
	}

	/**
	 * Get the trip extra fee.
	 * This function consists the extra fee..
	 *
	 * @param Booking|null $booking Defaults to the current booking.
	 * @return string
	 * @since 6.7.6
	 * @since 6.8.7 Made $booking optional (defaults to the current booking) and returns the pre-formatted price string, to satisfy EmailTags::get_trip_extra_fee() contract; name unchanged.
	 */
	public function get_trip_extra_fee( ?Booking $booking = null ): string {
		$booking           = $booking ?? $this->booking;
		$payment_data      = $booking->get_payments_data( false );
		$total_paid_amount = $booking->get_total_paid_amount();
		if ( $total_paid_amount === 0.0 ) {
			$fee = $payment_data['totals']['extra_charges'] ?? 0;
			return wptravelengine_the_price_with_decimal( $fee, false );
		}
		// Addition of extra fee for the trip addition of ( Tax, Booking Fee, Payment Gateway Fee ).
		$payment_calculator = PaymentCalculator::for( $booking->get_currency() );
		$payable            = $payment_calculator->subtract( $payment_data['totals']['payable'] ?? 0, $total_paid_amount );
		// Make sure payable is positive number because payment transaction fee can be added to paid amount directly which can be negative after sub.
		$payable   = (string) abs( floatval( $payable ) );
		$extra_fee = $payment_calculator->add( $payable, $payment_data['totals']['extra_charges'] ?? 0 );
		return wptravelengine_the_price_with_decimal( $extra_fee, false );
	}

	/**
	 * Get trip booking Payment details.
	 *
	 * @since 6.5.0
	 * @return string
	 * @updated 6.7.0
	 */
	public function get_trip_booking_payment() {

		$this->called_from_payment_details = true;

		// Get payment content based on cart version.
		$content = $this->cart_info->is_curr_cart_ver()
			? $this->render_tag_template( 'payment-details-v4.php', $this->get_v4_payment_args() )
			: $this->render_tag_template( 'payment-details-v3.php' );

		ob_start();
		do_action( 'wptravelengine_email_template_after_billing_details', (array) $this->booking->get_cart_info() );
		$content .= ob_get_clean();

		return $content;
	}

	/**
	 * Precompute every value payment-details-v4.php needs, so the template itself
	 * makes no Booking/Payment model calls (kept renderable from DummyTags too).
	 *
	 * @return array
	 * @since 6.8.7
	 */
	private function get_v4_payment_args(): array {
		$p_data  = $this->booking->get_payments_data( false );
		$_totals = $p_data['totals'] ?? array();

		$cart_info    = $this->cart_info;
		$payment_type = $cart_info->payment_type ?? '';
		if ( $this->is_manual_trigger ) {
			$initial_deposit = (float) ( $_totals['total_deposit'] ?? $cart_info->get_totals( 'partial_total' ) );
			$amounts         = array(
				'subtotal'        => (float) $_totals['subtotal'],
				'deposit'         => (float) $_totals['total_paid'],
				'due'             => (float) ( $_totals['due_exclusive'] ?? 0 ),
				'tax'             => (float) ( $_totals['tax']['value'] ?? 0 ),
				'total'           => (float) $_totals['total_exclusive'],
				'initial_deposit' => (float) ( $initial_deposit - $this->booking->get_refunded_amount() ),
				'remaining_total' => (float) max( $_totals['due_exclusive'] ?? 0, 0 ),
				'gateway_fee'     => (float) ( $_totals['gateway_fee'] ?? 0 ),
			);
			unset( $initial_deposit );

			$payment_type = $amounts['due'] > 0 ? 'partial' : 'full';
		} else {
			$amounts = array(
				'subtotal'        => (float) $cart_info->get_totals( 'subtotal' ),
				'deposit'         => (float) $this->payment->get_amount(),
				'due'             => (float) $_totals['due_exclusive'] ?? 0,
				'tax'             => (float) $cart_info->get_totals( 'total_tax' ),
				'total'           => (float) $cart_info->get_totals( 'total' ),
				'initial_deposit' => (float) $cart_info->get_totals( 'partial_total' ),
				'remaining_total' => (float) $cart_info->get_totals( 'due_total' ),
				'gateway_fee'     => (float) $this->payment->get_gateway_fee(),
			);
		}

		$deductible_items = array();
		foreach ( $cart_info->get_deductible_items() ?? array() as $line_item ) {
			$deductible_items[] = array(
				'label' => $line_item['label'] ?? '',
				'value' => $line_item['value'] && $line_item['value'] > 0 ? $line_item['value'] : $cart_info->get_totals( 'total_' . $line_item['name'] ) ?? 0,
			);
		}

		return array(
			'payment_type'                => $payment_type,
			'payment_gateway'             => $this->payment->get_payment_gateway(),
			'amounts'                     => $amounts,
			'_totals'                     => $_totals,
			'deductible_items'            => $deductible_items,
			'cart_info_array'             => (array) $this->booking->get_cart_info(),
			'payable_amount'              => $this->payment->get_payable_amount(),
			'tax_enable'                  => 'yes' === $this->global_settings->get( 'tax_enable' ),
			'is_inclusive_tax'            => 'inclusive' === $this->global_settings->get( 'tax_type_option' ),
			'excl_label'                  => $cart_info->exclusive_label,
			'called_from_booking_details' => $this->called_from_booking_details,
		);
	}

	/**
	 * This function returns label for line items of cart.
	 *
	 * @since 6.7.9
	 * @since 6.7.11 Changed visibility from public to protected.
	 */
	protected function get_line_item_label( $line_item ) {
		switch ( $line_item ) {
			case 'pricing_category':
				$label = '';
				break;
			case 'extra_service':
				$label = __( 'Extra Service(s):', 'wp-travel-engine' );
				break;
			case 'accommodation':
				$label = __( 'Accommodation(s):', 'wp-travel-engine' );
				break;
			case 'pickup_point':
				$label = __( 'Pickup Points:', 'wp-travel-engine' );
				break;
			case 'travel_insurance':
				$label = __( 'Travel Insurance:', 'wp-travel-engine' );
				break;
			default:
				$label = ucwords( str_replace( '_', ' ', $line_item ) ) . ':';
				break;
		}
		return apply_filters( 'wptravelengine_mail_line_item_label', $label, $line_item );
	}

	/**
	 * Build and register email template tags.
	 *
	 * @since 6.8.7 Reworked to resolve tags via build_callbacks() (EmailTags contract) instead of eagerly building one large array; billing fields are now injected in build_callbacks() itself (shared with DummyTags), so the wte_booking_mail_tags filter is the only thing layered on top.
	 *
	 * @param string $content Template body content.
	 * @param string $subject Template subject line.
	 * @return array<string, string>
	 */
	public function get_email_tags( string $content = '', string $subject = '' ): array {
		$email_tags = parent::get_email_tags( $content, $subject );

		return apply_filters( 'wte_booking_mail_tags', $email_tags, $this->payment->ID, $this->booking->ID, $this );
	}

	/**
	 * Get additional note.
	 *
	 * @return string
	 * @since 6.8.7 Added return type for EmailTags contract.
	 */
	public function get_additional_note(): string {
		$customer_note = $this->booking->get_customer_note();

		if ( empty( $customer_note ) ) {
			return '';
		}

		return $this->render_tag_template( 'additional-note.php', compact( 'customer_note' ) );
	}

	public function get_check_payment_details() {
		if ( ! $this->payment || 'check_payments' !== $this->payment->get_payment_gateway() ) {
			return '';
		}

		$instruction = $this->global_settings->get( 'check_payment.instruction', '' );

		return $this->render_tag_template( 'check-payment-instruction.php', compact( 'instruction' ) );
	}

	/**
	 * Get the Billing details.
	 *
	 * @return string
	 * @since 6.7.12 Updated billing details with other updated form fields.
	 * @since 6.8.7 Added return type for EmailTags contract.
	 */
	public function get_billing_details(): string {
		if ( empty( $this->billing_info ) ) {
			return '';
		}

		$billing_form_fields = new BillingFormFields();
		$fields              = $billing_form_fields->with_values( $this->billing_info );

		return $this->render_tag_template( 'billing-details.php', compact( 'fields' ) );
	}

	/**
	 * Get the Emergency details.
	 *
	 * @return string
	 * @since 6.7.12 Updated EmergencyFormFields Dynamic form fields get to display dynamic fields.
	 * @since 6.8.7 Added return type for EmailTags contract.
	 */
	public function get_emergency_details(): string {
		$emergency_details = $this->booking->get_emergency_contacts();

		if ( empty( $emergency_details ) ) {
			return '';
		}

		$contacts              = array();
		$emergency_form_fields = new EmergencyFormFields();
		foreach ( $emergency_details as $details ) {
			$contacts[] = $emergency_form_fields->with_values( $details, $this->booking );
		}

		return $this->render_tag_template( 'emergency-details.php', compact( 'contacts' ) );
	}

	/**
	 * Get the Traveler details.
	 *
	 * @return string
	 * @since rename function from get_traveller_details to get_traveler_details
	 */
	public function get_traveler_details(): string {
		$traveler_details = $this->booking->get_travelers();

		if ( empty( $traveler_details ) ) {
			return '';
		}

		$travelers            = array();
		$traveler_form_fields = new TravellerFormFields();
		foreach ( $traveler_details as $details ) {
			$travelers[] = $traveler_form_fields->with_values( $details, $this->booking );
		}

		return $this->render_tag_template( 'traveler-details.php', compact( 'travelers' ) );
	}

	/**
	 * @since 6.8.7 Precomputes accounts/instructions/labels so bank-details.php stays presentation-only.
	 */
	public function get_bank_details(): string {
		if ( 'direct_bank_transfer' !== $this->payment->get_payment_gateway() ) {
			return '';
		}

		$accounts     = $this->global_settings->get( 'bank_transfer.accounts', array() );
		$instructions = $this->global_settings->get( 'bank_transfer.instruction', '' );

		$labels = array(
			'account_name'   => __( 'Account Name', 'wp-travel-engine' ),
			'account_number' => __( 'Account Number', 'wp-travel-engine' ),
			'bank_name'      => __( 'Bank Name', 'wp-travel-engine' ),
			'sort_code'      => __( 'Sort Code', 'wp-travel-engine' ),
			'iban'           => __( 'IBAN', 'wp-travel-engine' ),
			'swift'          => __( 'BIC/Swift', 'wp-travel-engine' ),
		);

		return $this->render_tag_template( 'bank-details.php', compact( 'accounts', 'instructions', 'labels' ) );
	}

	/**
	 * Get trip booking details.
	 *
	 * @since 6.5.0
	 * @since 6.8.7 Added return type for EmailTags contract.
	 *
	 * @return string
	 */
	public function get_trip_booking_details(): string {
		$payment_gateway = $this->payment ? $this->payment->get_payment_gateway() : '';
		$customer_note   = $this->booking->get_customer_note();
		return $this->render_tag_template( 'trip-details.php', compact( 'payment_gateway', 'customer_note' ) );
	}

	/**
	 * Get trip booking Summary details.
	 *
	 * @since 6.5.0
	 * @since 6.7.11 Skip empty line item groups; added wptravelengine_email_manual_trigger_{type} action.
	 * @since 6.8.0 Escape trip date output; end date reads from $trip->end_datetime instead of wptravelengine_format_trip_end_datetime().
	 * @since 6.8.7 Added return type for EmailTags contract.
	 *
	 * @return string
	 */
	public function get_trip_booking_summary(): string {
		$cart_info  = (array) $this->booking->get_cart_info();
		$line_items = $cart_info['items'][0]['line_items'] ?? array();

		$pricing_category_items = $this->get_pricing_category_items();

		return $this->render_tag_template( 'booking-summary.php', compact( 'cart_info', 'line_items', 'pricing_category_items' ) );
	}

	/**
	 * Build one row per booked pricing category, from the trip's pax/pax_cost.
	 *
	 * @return array<int, array{label: string, quantity: int, price: float, total: float}>
	 * @since 6.8.7
	 */
	private function get_pricing_category_items(): array {
		static $pricing_categories = null;

		if ( null === $pricing_categories ) {
			$pricing_categories = get_terms(
				array(
					'taxonomy'   => 'trip-packages-categories',
					'hide_empty' => false,
					'orderby'    => 'term_id',
					'fields'     => 'id=>name',
				)
			);
		}

		$items = array();
		foreach ( (array) ( $this->trip->pax ?? array() ) as $pricing_category_id => $tcount ) {
			if ( ! isset( $this->trip->pax_cost[ $pricing_category_id ] ) || +$tcount < 1 ) {
				continue;
			}
			$total   = +$this->trip->pax_cost[ $pricing_category_id ];
			$items[] = array(
				'label'    => $pricing_categories[ $pricing_category_id ] ?? $pricing_category_id,
				'quantity' => $tcount,
				'price'    => $total / $tcount,
				'total'    => $total,
			);
		}

		return $items;
	}

	/**
	 * @since 6.8.7 Added return type for EmailTags contract.
	 */
	public function get_booking_details(): string {
		if ( $this->is_manual_trigger ) {
			return ResendPurchaseReceipt::get_booking_detail( $this->booking->ID );
		}
		return $this->render_tag_template( 'booking-details.php' );
	}
}
