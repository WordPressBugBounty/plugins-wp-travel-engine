<?php
/**
 * Booking read accessors.
 *
 * @package WPTravelEngine/Core/Models
 * @since 6.9.0
 */

namespace WPTravelEngine\Core\Models\Post;

use WPTravelEngine\Builders\FormFields;
use WPTravelEngine\Core\Booking\Inventory;
use WPTravelEngine\Helpers\CartInfoParser;
use WPTravelEngine\Utilities\ArrayUtility;
use WPTravelEngine\Utilities\PaymentCalculator;

/**
 * Booking read accessors, used by `Booking`.
 *
 * `ACTIVITY_META_KEY` lives on `Booking`: PHP 7.4 traits cannot declare constants.
 *
 * @since 6.9.0
 */
trait BookingAttributes {

	/**
	 * The form as it stood when the booking was made, read once.
	 *
	 * @var array<string, array<string, array<string, mixed>>>|null
	 * @since 6.9.0
	 */
	protected ?array $fields_snapshot = null;

	/**
	 * The booking's cart, parsed on first read.
	 *
	 * @var CartInfoParser|null
	 * @since 6.9.0
	 */
	protected ?CartInfoParser $parsed_cart = null;

	/**
	 * @var null|Payment[] $payments Payments made for this booking.
	 */
	protected ?array $payments = null;

	/**
	 * Trip models for this booking's trips, keyed by trip ID.
	 *
	 * @var array
	 * @since 6.9.0
	 */
	protected array $trips = array();

	/**
	 * Payments data.
	 *
	 * @var array
	 * @since 6.7.0
	 */
	public array $payments_data = array();

	/**
	 * Serves `$booking->cart_info` as the parser, built on first read.
	 *
	 * @param string $name Property name being read.
	 * @return mixed
	 * @since 6.9.0
	 */
	public function __get( string $name ) {
		if ( 'cart_info' === $name ) {
			if ( null === $this->parsed_cart ) {
				$this->parsed_cart = wptravelengine_get_cart_info( $this->ID, (array) $this->get_cart_info() );
			}

			return $this->parsed_cart;
		}

		return parent::__get( $name );
	}

	/**
	 * Retrieves booking status.
	 *
	 * @return string Booking status
	 */
	public function get_booking_status(): string {
		$status = $this->get_meta( 'wp_travel_engine_booking_status' );

		return ! $status ? $this->post->post_status : $status;
	}

	/**
	 * Retrieves booking status label.
	 *
	 * @return string Booking status label.
	 * @since 6.8.0
	 */
	public function get_booking_status_label(): string {
		$status     = strtolower( $this->get_booking_status() );
		$all_status = wp_travel_engine_get_booking_status();
		return $all_status[ $status ]['text'] ?? __( 'Pending', 'wp-travel-engine' );
	}

	/**
	 * Retrieves order trip.
	 *
	 * @return object|null Order trip.
	 */
	public function get_order_trip() {
		$order_trips = $this->get_meta( 'order_trips' ) ?? array();

		if ( ! is_array( $order_trips ) || empty( $order_trips ) ) {
			return null;
		}

		$order_trip_object = new \stdClass();

		$order_trip_object->cart_id = key( $order_trips );

		foreach ( current( $order_trips ) as $key => $value ) {
			$order_trip_object->$key = $value;
		}

		return $order_trip_object;
	}

	/**
	 * Get Booked Trip ID.
	 *
	 * @return int Booked Trip ID
	 */
	public function get_trip_id() {
		$order_trips = $this->get_order_items();

		return $order_trips['trip_id'] ?? $order_trips[0]['ID'] ?? $this->get_cart_info( 'items' )[0]['trip_id'] ?? 0;
	}

	/**
	 * Check if booked trip exists.
	 *
	 * @since 6.8.6
	 *
	 * @return bool
	 */
	public function trip_exists(): bool {
		return get_post_type( $this->get_trip_id() ) === 'trip';
	}

	/**
	 * Get Booked Trip Title.
	 *
	 * @return string Booked Trip Title
	 * @since 6.8.6 Get Trip title from order_items as first priority.
	 */
	public function get_trip_title() {
		$order_trips = $this->get_order_items();

		return $order_trips['title'] ?? $order_trips[0]['title'] ?? get_the_title( $this->get_trip_id() ) ?? '';
	}

	/**
	 * Get Trip Cost.
	 *
	 * @return float Trip Cost
	 */
	public function get_trip_cost() {
		$order_trips = $this->get_order_items();

		return $order_trips['cost'] ?? 0;
	}

	/**
	 * Get Trip Partial Cost.
	 *
	 * @return float Trip Partial Cost
	 */
	public function get_partial_cost() {
		$order_trips = $this->get_order_items();

		return $order_trips['partial_cost'] ?? 0;
	}

	/**
	 * Get Trip DateTime.
	 *
	 * @return string Trip DateTime
	 */
	public function get_trip_datetime() {
		$order_trips = $this->get_order_items();

		return $order_trips['datetime'] ?? $order_trips[0]['datetime'] ?? gmdate( 'Y-m-d' );
	}

	/**
	 * Get Trip Pax.
	 *
	 * @return array Trip Pax
	 */
	public function get_trip_pax() {
		$order_trips = $this->get_order_items();

		return $order_trips['pax'] ?? $order_trips[0]['pax'] ?? array();
	}

	/**
	 * Get Trip Pax Cost.
	 *
	 * @return array Trip Pax Cost
	 */
	public function get_trip_pax_cost() {
		$order_trips = $this->get_order_items();

		return $order_trips['pax_cost'] ?? array();
	}

	/**
	 * Get Trip Extras.
	 *
	 * @return array Trip Extras
	 */
	public function get_trip_extras() {
		$order_trips = $this->get_order_items();

		return $order_trips['trip_extras'] ?? array();
	}

	/**
	 * Get Trip Package name.
	 *
	 * @return string Trip Package name
	 */
	public function get_trip_package_name() {
		$order_trips = $this->get_order_items();

		if ( is_array( $order_trips ) && ! empty( $order_trips ) ) {
			foreach ( $order_trips as $order_trip ) {
				if ( isset( $order_trip['package_name'] ) && ! empty( $order_trip['package_name'] ) ) {
					return $order_trip['package_name'];
				}
			}
		}

		return '';
	}

	/**
	 * Get Trip has time.
	 *
	 * @return bool Trip has time
	 */
	public function get_trip_has_time() {
		$order_trips = $this->get_order_items();

		return $order_trips['has_time'] ?? false;
	}

	/**
	 * Retrieves due amount.
	 *
	 * @return float Due Amount.
	 */
	public function get_due_amount(): float {
		$amount = floatval( $this->get_meta( 'due_amount' ) ?: 0 );

		return (float) number_format( $amount, 2, '.', '' );
	}

	/**
	 * Retrieves paid amount.
	 *
	 * @return float Paid Amount
	 */
	public function get_paid_amount(): float {
		$amount = $this->get_meta( 'paid_amount' ) ?? 0;

		return (float) number_format( ! $amount ? 0 : $amount, 2, '.', '' );
	}

	/**
	 * Retrieves booking cart info.
	 *
	 * @return mixed Booking cart info
	 */
	public function get_cart_info( $key = null ) {
		$cart_info = $this->get_meta( 'cart_info' ) ?? array();

		if ( ! is_null( $key ) ) {
			return $cart_info[ $key ] ?? null;
		}

		if ( (bool) $cart_info ) {
			if ( ! isset( $cart_info['items'] ) ) {
				$cart_info['items'] = $this->get_order_items();
			}
		}

		return ! $cart_info ? array() : $cart_info;
	}

	/**
	 * Retrieves booking cart info - Currency.
	 *
	 * @return string Currency
	 */
	public function get_currency() {
		$cart_info = $this->get_cart_info();

		return $cart_info['currency'] ?? '';
	}

	/**
	 * Retrieves booking cart info - Subtotal.
	 *
	 * @return float Subtotal
	 */
	public function get_subtotal() {
		$cart_info = $this->get_cart_info();

		return $cart_info['totals']['subtotal'] ?? 0;
	}

	/**
	 * Retrieves booking cart info - Total.
	 *
	 * @return float Total
	 */
	public function get_total(): float {
		$cart_info = $this->get_cart_info();

		return (float) ( $cart_info['totals']['total'] ?? 0 );
	}

	/**
	 * Retrieves booking cart info - Cart Partial.
	 *
	 * @return float Cart Partial
	 */
	public function get_cart_partial(): float {
		$cart_info = $this->get_cart_info();

		return (float) ( $cart_info['totals']['partial_total'] ?? 0 );
	}

	/**
	 * Retrieves booking cart info - Discounts.
	 *
	 * @return array Discounts
	 */
	public function get_discounts() {
		$cart_info = $this->get_cart_info();

		return $cart_info['discounts'] ?? array();
	}

	/**
	 * Retrieves booking cart info - Tax Amount.
	 *
	 * @return float Tax Amount
	 */
	public function get_tax_amount() {
		$cart_info = $this->get_cart_info();

		return $cart_info['tax_amount'] ?? 0;
	}

	/**
	 * Retrieves payment details. Reads a `payments` write still queued under `with_delayed_save()`.
	 *
	 * @return array payment details
	 */
	public function get_payment_detail() {
		return $this->data['__changes']['payments'] ?? $this->get_meta( 'payments' ) ?? array();
	}

	/**
	 * Retrives Payment Details - Payment Status.
	 *
	 * @return string Payment Status
	 */
	public function get_payment_status() {
		return $this->get_meta( 'wp_travel_engine_booking_payment_status' );
	}

	/**
	 * Retrives Payment Details - Payment Gateway.
	 *
	 * @return string Payment Gateway
	 */
	public function get_payment_gateway() {
		return $this->get_meta( 'wp_travel_engine_booking_payment_gateway' );
	}

	/**
	 * Retrives Payment Details - Payment Method.
	 *
	 * @return string Payment Method
	 */
	public function get_payment_method() {
		return $this->get_meta( 'wp_travel_engine_booking_payment_method' );
	}

	/**
	 * Retries Billing Info Data.
	 *
	 * @return string|array Billing Info Data
	 */
	public function get_billing_info( ?string $key = null ) {
		if ( $this->has_meta( 'wptravelengine_billing_details' ) ) {
			$billing_info = $this->get_meta( 'wptravelengine_billing_details' );
		} else {
			$billing_info = $this->get_meta( 'billing_info' ) ?? array();
		}

		if ( ! is_null( $key ) ) {
			return $billing_info[ $key ] ?? '';
		}

		return ! $billing_info ? array() : $billing_info;
	}

	/**
	 * Get Billing Info - First Name.
	 *
	 * @return string First Name
	 */
	public function get_billing_fname(): string {
		return $this->get_billing_info( 'fname' );
	}

	/**
	 * Get Billing Info - Last Name.
	 *
	 * @return string Last Name
	 */
	public function get_billing_lname(): string {
		return $this->get_billing_info( 'lname' );
	}

	/**
	 * Get Billing Info - Email.
	 *
	 * @return string Email
	 */
	public function get_billing_email(): string {
		return $this->get_billing_info( 'email' );
	}

	/**
	 * Get Billing Info - Address.
	 *
	 * @return string Address
	 */
	public function get_billing_address(): string {
		return $this->get_billing_info( 'address' );
	}

	/**
	 * Get Billing Info - City.
	 *
	 * @return string City
	 */
	public function get_billing_city(): string {
		return $this->get_billing_info( 'city' );
	}

	/**
	 * Get Billing Info - Country.
	 *
	 * @return string Country.
	 */
	public function get_billing_country(): string {
		return $this->get_billing_info( 'country' );
	}

	/**
	 * Get Order Items.
	 *
	 * @return array
	 */
	public function get_order_items(): array {
		$order_trips = $this->get_meta( 'order_trips' );

		return is_array( $order_trips ) ? array_values( $order_trips ) : array();
	}

	/**
	 * Get Payments Object.
	 *
	 * @return Payment[]
	 * @since 6.9.0 Reads IDs via `get_payment_detail()`; none reports none, not the last list read.
	 */
	public function get_payments(): array {
		$this->payments = array_map(
			function ( $payment ) {
				return wptravelengine_get_payment( $payment );
			},
			(array) $this->get_payment_detail()
		);

		return array_filter( $this->payments );
	}

	/**
	 * Retrieves Additional Fields Data.
	 *
	 * @return array Additional Fields Data.
	 */
	public function get_additional_fields(): array {
		return $this->get_meta( 'additional_fields' ) ?? array();
	}

	/**
	 * Retrieves Traveler Info Data - Travelers.
	 *
	 * @return array Travelers
	 * @since 6.4.0 Retrieves travelers info from wptravelengine_travelers_details and in particular format.
	 * @since 6.8.3 Adds `cat_id`/`cat_label` per traveler from cart_info's subtotal_reservations.
	 * @since 6.9.0 Every traveler the cart priced is returned; one not yet given carries only its category.
	 */
	public function get_travelers(): array {
		$travelers = ArrayUtility::to_array( $this->get_meta( 'wptravelengine_travelers_details' ) );
		$items     = $this->get_cart_info( 'items' ) ?? array();

		$item = $items[0] ?? array();

		$pc_travelers     = $item['subtotal_reservations']['travelers'] ?? array();
		$price_categories = $item['line_items']['pricing_category'] ?? array();
		$traveler_count   = array_sum( ArrayUtility::to_array( $item['travelers'] ?? $item['pax'] ?? array() ) );

		$i = 0;
		$k = 0;
		foreach ( $pc_travelers as $price_category ) {
			for ( $j = 0; $j < $price_category['quantity']; $j++ ) {
				if ( $k >= $traveler_count ) {
					break 2;
				}
				$id = $travelers[ $k ]['cat_id'] ?? $price_category['id'];

				$travelers[ $k ]['cat_id']      = $id;
				$travelers[ $k ]['cat_label'] ??= $item['category_info'][ $id ]['label'] ?? $price_categories[ $i ]['label'] ?? "Category - {$id}";
				++$k;
			}
			++$i;
		}

		if ( $travelers ) {
			return $travelers;
		}

		// Check for legacy format.
		$traveler_info = $this->get_meta( 'wp_travel_engine_placeorder_setting' );

		if ( ! empty( $traveler_info['place_order']['travelers'] ) ) {
			return ArrayUtility::normalize( $traveler_info['place_order']['travelers'], 'fname' );
		}

		return array();
	}

	/**
	 * Retrieves Traveler Info Data - Emergency Contact Details.
	 *
	 * @return array Emergency Contact Details
	 */
	public function get_emergency_contacts(): array {
		if ( $this->has_meta( 'wptravelengine_emergency_details' ) ) {
			$emergency_contacts = $this->get_meta( 'wptravelengine_emergency_details' );
			if ( empty( $emergency_contacts ) ) {
				return array();
			}
			if ( ! isset( $emergency_contacts[0] ) ) {
				$emergency_contacts = array( $emergency_contacts );
			}

			return $emergency_contacts;
		}

		// Check for legacy format first.
		$emergency_info = $this->get_meta( 'wp_travel_engine_placeorder_setting' );

		if ( ! empty( $emergency_info['place_order']['relation'] ) ) {
			return ArrayUtility::normalize( $emergency_info['place_order']['relation'] );
		}

		return array();
	}

	/**
	 * Last Payment.
	 *
	 * @return  false|Payment
	 */
	public function get_last_payment() {
		$payments = $this->get_payments();

		return end( $payments );
	}

	/**
	 * Whether any balance is still owed, judged at the currency's scale.
	 *
	 * @return bool
	 * @since 6.4.0
	 * @since 6.9.0 Any positive balance counts; it had regressed to more than 1.
	 */
	public function has_due_payment(): bool {
		$paid_amount = 0;
		$payments    = $this->get_payment_detail();
		$due_amount  = $this->get_total_due_amount();

		$calc = PaymentCalculator::for( $this->get_currency() );

		if ( $due_amount && is_numeric( $due_amount ) ) {
			return $calc->is_positive( (string) $due_amount );
		}

		if ( is_array( $payments ) && count( $payments ) > 0 ) {
			foreach ( $payments as $payment ) {
				$payment      = Payment::make( $payment );
				$paid_amount += $payment->get_amount();
			}
		}
		$due_amount = $this->get_total() - $paid_amount;

		return $calc->is_positive( (string) $due_amount );
	}

	/**
	 * Get Total Paid Amount.
	 *
	 * @return float
	 * @since 6.4.0
	 * @since 6.7.8 Support for old cart version where total paid amount is not stored.
	 */
	public function get_total_paid_amount(): float {
		if ( $this->is_curr_cart() ) {
			$paid_amount = $this->get_meta( 'total_paid_amount' );
			// Check if the value exists and is numeric, even if it's 0
			if ( $paid_amount !== null && $paid_amount !== false && is_numeric( $paid_amount ) ) {
				return (float) $paid_amount;
			}
		}
		$payments    = get_post_meta( $this->ID, 'payments', true );
		$paid_amount = 0;
		if ( is_array( $payments ) && count( $payments ) > 0 ) {
			foreach ( $payments as $p_id ) {
				$payment = wptravelengine_get_payment( $p_id );
				if ( $payment && $payment->is_completed() ) {
					$paid_amount += $payment->get_amount();
				}
			}
		}

		return $paid_amount;
	}

	/**
	 * Paid amount net of extra charges; legacy carts fall back to `total_tax`.
	 *
	 * @return float
	 * @since 6.8.6
	 */
	public function get_revenue(): float {
		$calculator          = PaymentCalculator::for( $this->get_currency() );
		$is_curr_cart        = $this->is_curr_cart();
		$total_extra_charges = '0.00';

		foreach ( $this->get_payments() as $payment ) {
			if ( ! $payment->is_completed() || $payment->is_refunded() ) {
				continue;
			}

			$cart_totals   = $payment->get_cart_totals();
			$extra_charges = $cart_totals['total_extra_charges'] ?? null;

			if ( null === $extra_charges && ! $is_curr_cart ) {
				// Legacy carts baked fees into tax; use it as the closest proxy.

				$totals = $this->get_cart_info( 'totals' );

				foreach ( $this->get_fees() as $fee ) {
					$key                 = 'total_' . ( $fee['name'] ?? '' );
					$total_extra_charges = $calculator->add( $total_extra_charges, (string) ( $totals[ $key ] ?? '0.00' ) );
				}

				break;
			} elseif ( is_numeric( $extra_charges ) ) {
				$total_extra_charges = $calculator->add( $total_extra_charges, (string) $extra_charges );
			}
		}

		return (float) $calculator->subtract( (string) $this->get_total_paid_amount(), $total_extra_charges );
	}

	/**
	 * Get Total Due Amount.
	 *
	 * @return float
	 * @since 6.4.0
	 */
	public function get_total_due_amount(): float {
		$due_amount = $this->get_meta( 'total_due_amount' );
		if ( $due_amount !== null && $due_amount !== false && is_numeric( $due_amount ) ) {
			return (float) $due_amount;
		}
		$paid_amount = $this->get_total_paid_amount();

		return $this->get_total() - $paid_amount;
	}

	/**
	 * Get customer note.
	 *
	 * @return string Additional Details
	 */
	public function get_customer_note(): string {
		return $this->get_meta( 'wptravelengine_additional_note' ) ?? '';
	}

	/**
	 * Get Admin Notes.
	 *
	 * @return string Admin Notes
	 * @since 6.9.0 Joins the pre-list note and the internal notes, in that order.
	 */
	public function get_admin_note(): string {
		return implode( "\n\n", array_filter( wp_list_pluck( $this->get_my_notes( 'admin' ), 'content' ) ) );
	}

	/**
	 * Every note on the booking, the customer's first and the internal ones after it.
	 *
	 * @param string|null $by Limit to one side, `admin` or `customer`; null for every note.
	 * @return array<int, array<string, string>> Entries of `id`, `by`, `content`, `author`, `date`.
	 * @since 6.9.0
	 */
	public function get_my_notes( ?string $by = null ): array {
		$notes = array();

		$customer_note = $this->get_customer_note();

		if ( '' !== $customer_note ) {
			$notes[] = array(
				'id'      => 'customer',
				'by'      => 'customer',
				'content' => $customer_note,
				'author'  => '',
				'date'    => (string) $this->post->post_date_gmt,
			);
		}

		$admin_notes = $this->get_meta( 'wptravelengine_internal_notes' );
		$admin_notes = is_array( $admin_notes ) ? $admin_notes : array();
		$legacy_note = (string) ( $this->get_meta( 'wptravelengine_admin_notes' ) ?? '' );

		if ( '' !== $legacy_note ) {
			// Written before notes became a list, so it has no id of its own to carry.
			array_unshift(
				$admin_notes,
				array(
					'id'      => 'legacy',
					'content' => $legacy_note,
					'date'    => (string) $this->post->post_modified_gmt,
				)
			);
		}

		foreach ( $admin_notes as $note ) {
			$note = (array) $note;

			$notes[] = array(
				'id'      => (string) ( $note['id'] ?? '' ),
				'by'      => 'admin',
				'content' => (string) ( $note['content'] ?? '' ),
				'author'  => (string) ( $note['author'] ?? '' ),
				'date'    => (string) ( $note['date'] ?? '' ),
			);
		}

		if ( null === $by ) {
			return $notes;
		}

		return array_values(
			array_filter(
				$notes,
				static function ( array $note ) use ( $by ): bool {
					return $by === $note['by'];
				}
			)
		);
	}

	/**
	 * @return object
	 */
	public function get_customer(): object {
		$email_address = $this->get_billing_email();
		$_customer     = new \stdClass();

		if ( $customer_id = Customer::is_exists( $email_address ) ) {
			$customer  = new Customer( $customer_id );
			$_customer = $customer->get_data();
		} else {
			$_customer->id         = 0;
			$_customer->first_name = '';
			$_customer->last_name  = '';
			$_customer->email      = $email_address;
			$_customer->phone      = $this->get_billing_info( 'phone' );
		}

		return (object) $_customer;
	}

	/**
	 * @param bool $basic When true, omits customer, payments, and each trip's permalink.
	 * @return array
	 * @since 6.5.2
	 * @since 6.9.0 Added $basic param.
	 */
	public function get_data( bool $basic = false ): array {

		$order_items = $this->get_order_items();
		$cart_items  = $this->cart_info->get_items();

		$booked_trips = array_map(
			function ( $item, $index ) use ( $order_items ) {
				$order_item = $order_items[ $index ] ?? array();

				if ( (int) ( $order_item['ID'] ?? 0 ) !== (int) $item->get_trip_id() ) {
					$order_item = array();
				}

				$title = $item->get_trip_title();

				return array(
					'id'                   => $item->get_trip_id(),
					'title'                => '' !== (string) $title ? $title : (string) ( $order_item['title'] ?? '' ),
					'url'                  => get_permalink( $item->get_trip_id() ),
					'trip_start_date'      => $item->get_trip_date(),
					'trip_end_date'        => $item->get_end_date(),
					'number_of_travellers' => $item->travelers_count(),
					'line_items'           => array_map(
						function ( $line_items ) {
							return array_map(
								function ( $_line_item ) {
									return array(
										'label'    => $_line_item['label'] ?? '',
										'quantity' => $_line_item['quantity'] ?? 0,
										'price'    => $_line_item['price'] ?? 0,
										'total'    => $_line_item['total'] ?? 0,
									);
								},
								$line_items
							);
						},
						$item->get_line_items()
					),
				);
			},
			$cart_items,
			array_keys( $cart_items )
		);

		$data = array(
			'id'                => $this->ID,
			'booking_status'    => $this->get_booking_status(),
			'booked_date'       => $this->post->post_date_gmt,
			'total_amount'      => $this->cart_info->get_totals( 'total' ),
			'paid_amount'       => $this->get_paid_amount(),
			'due_amount'        => $this->get_due_amount(),
			'total_paid_amount' => $this->get_total_paid_amount(),
			'total_due_amount'  => $this->get_total_due_amount(),
			'currency'          => $this->cart_info->get_currency(),
			'booked_trips'      => $booked_trips,
			'deductible_items'  => array_values(
				array_map(
					function ( $item ) {
						$name  = (string) ( $item['name'] ?? '' );
						$label = (string) ( $item['label'] ?? '' );

						return array(
							'name'   => $name,
							'label'  => '' !== $label ? $label : wptravelengine_humanize_key( $name ),
							'amount' => (float) ( $item['value'] ?? 0 ),
						);
					},
					$this->cart_info->get_deductible_items()
				)
			),
		);

		if ( ! $basic ) {
			$data['customer'] = $this->get_customer();
			$data['payments'] = array_map(
				function ( $payment ) {
					$status = $payment->get_payment_status();

					return array(
						'id'                    => $payment->ID,
						'amount'                => $payment->get_amount(),
						'date'                  => $payment->get_transaction_date(),
						'status'                => $status,
						'status_label'          => wptravelengine_payment_status( $status ),
						'status_type'           => wptravelengine_payment_status_type( $status ),
						'payment_gateway'       => $payment->get_payment_gateway(),
						'payment_gateway_label' => $this->gateway_label( $payment->get_payment_gateway() ),
					);
				},
				$this->get_payments()
			);
		}

		return $data;
	}

	/**
	 * Compare current cart version with the given version.
	 *
	 * @param string $op Operator to compare. Default is '=='.
	 * @param string $version Version to compare with. Default is '4.0'.
	 * @return bool
	 * @since 6.7.0
	 */
	public function is_curr_cart( string $op = '==', string $version = '4.0' ): bool {
		global $current_screen;
		if ( $current_screen && $current_screen->id === 'booking' && $current_screen->action === 'add' && $current_screen->base === 'post' ) {
			return true;
		}
		return version_compare( $this->get_cart_version(), $version, $op );
	}

	/**
	 * Retrieves booking cart info - Version.
	 *
	 * @return string Version
	 * @since 6.7.0
	 */
	public function get_cart_version(): string {
		return (string) ( $this->get_meta( 'cart_version' ) ?: ( $this->get_cart_info( 'version' ) ?? '3.0' ) );
	}

	/**
	 * Checks whether booking migrated from legacy booking post.
	 *
	 * @return bool
	 * @since 6.8.6
	 */
	public function is_migrated(): bool {
		return (bool) absint( $this->get_meta( '_migrated_to' ) );
	}

	/**
	 * Get fees data.
	 *
	 * @return array Fees data.
	 * @since 6.7.0
	 */
	public function get_fees(): array {
		return $this->get_cart_info( 'fees' ) ?? array();
	}

	/**
	 * What one payment's fees came to, named by the cart that charged them.
	 *
	 * @param int $payment_id The payment to break down.
	 * @return array<string, array<string, mixed>> Fee slug to its label and amount, empty when
	 *                                             the booking holds no such payment.
	 * @since 6.9.0
	 */
	public function get_price_breakdown( int $payment_id ): array {
		$payment = $this->get_payment_by_id( $payment_id );

		if ( ! ( $payment instanceof Payment ) ) {
			return array();
		}

		$totals    = (array) $payment->get_cart_totals();
		$breakdown = array();

		foreach ( $this->cart_info->get_fees() as $fee ) {
			$name = (string) ( $fee['name'] ?? '' );

			if ( '' === $name ) {
				continue;
			}

			$label = (string) ( $fee['label'] ?? '' );

			$breakdown[ $name ] = array(
				'label'  => '' !== $label ? $label : wptravelengine_humanize_key( $name ),
				'amount' => (float) ( $totals[ 'total_' . $name ] ?? 0 ),
			);
		}

		return $breakdown;
	}

	/**
	 * Get Payment by ID.
	 *
	 * @param int $payment_id The payment ID.
	 *
	 * @return ?Payment
	 * @since 6.7.0
	 */
	public function get_payment_by_id( int $payment_id ) {
		foreach ( $this->get_payments() as $payment ) {
			if ( $payment->ID === $payment_id ) {
				return $payment;
			}
		}
		return null;
	}

	/**
	 * Get fees by type.
	 *
	 * @return array
	 * @since 6.7.0
	 */
	public function get_fee_types(): array {
		$fees         = $this->get_fees();
		$fees_by_type = array();

		foreach ( $fees as $fee ) {
			$apply_tax = $fee['apply_tax'] ?? true;
			if ( 'tax' === $fee['name'] ) {
				$fees_by_type['tax'] = $fee;
			} elseif ( $apply_tax ) {
				$fees_by_type['tax_inclusive'][] = $fee;
			} else {
				$fees_by_type['tax_exclusive'][] = $fee;
			}
		}

		return $fees_by_type;
	}

	/**
	 * Get payments data.
	 *
	 * @param bool $success Whether to include successful payments only. Default true.
	 *
	 * @return array Payments data.
	 * {
	 *  'totals' => array<mixed>,
	 *  'payments' => array<mixed>,
	 * }
	 * @since 6.7.0
	 * @since 6.7.1 Skips calculation for failed payments.
	 * @since 6.8.6 Adds the payment status slug to each payment entry.
	 * @since 6.8.6 Nets total_paid/total_deposit against the booking's refunded amount instead of excluding refunded payments entirely.
	 */
	public function get_payments_data( bool $success = true ): array {

		$key = $success ? 'success' : 'not_success';

		if ( isset( $this->payments_data[ $key ] ) ) {
			return $this->payments_data[ $key ];
		}

		$totals    = array(
			'total_deposit'   => '0',
			'total_paid'      => '0',
			'total_exclusive' => '0',
			'total_discount'  => '0',
			'total_refund'    => '0',
			'due_exclusive'   => '0',
			'payable'         => '0',
			'extra_charges'   => '0',
			'gateway_fee'     => '0',
		);
		$payments  = array();
		$fee_types = $this->get_fee_types();

		$calc = PaymentCalculator::for( $this->get_currency() );

		$discounts = $this->get_discounts();

		foreach ( $this->get_payments() as $payment ) {
			$cart_totals = $payment->get_cart_totals();

			$is_refunded = $payment->is_refunded();
			if ( ! $is_refunded && ( ! $cart_totals || $payment->is_failed() || ( $success && ! $payment->is_completed() ) ) ) {
				continue;
			}

			$p_id         = $payment->ID;
			$refunded_amt = $payment->get_refunded_amount();

			$payments[ $p_id ]['total']         = (string) $payment->get_amount();
			$payments[ $p_id ]['deposit']       = (string) ( $cart_totals['deposit'] ?? '0.00' );
			$payments[ $p_id ]['payable']       = (string) ( $cart_totals['payable_now'] ?? '0.00' );
			$payments[ $p_id ]['extra_charges'] = (string) ( $cart_totals['total_extra_charges'] ?? '0.00' );
			$payments[ $p_id ]['gateway_fee']   = (string) ( $payment->get_gateway_fee() );
			$payments[ $p_id ]['is_refunded']   = $is_refunded;
			$payments[ $p_id ]['refunded_amt']  = $refunded_amt;
			$payments[ $p_id ]['status']        = $payment->get_payment_status();

			if ( $is_refunded ) {
				$totals['total_paid']   = $calc->subtract( (string) $totals['total_paid'], (string) $refunded_amt );
				$totals['total_refund'] = $calc->add( (string) $totals['total_refund'], (string) $refunded_amt );
				continue;
			}

			foreach ( $discounts as $_key => $_ ) {
				if ( isset( $cart_totals[ 'total_' . $_key ] ) ) {
					$totals['total_discount'] = $calc->add( $totals['total_discount'], (string) $cart_totals[ 'total_' . $_key ] );
				}
			}

			foreach ( $fee_types['tax_inclusive'] ?? array() as $fee ) {
				$payments[ $p_id ][ $fee['name'] ]                = (string) ( $cart_totals[ 'total_' . $fee['name'] ] ?? '0.00' );
				$totals['tax_inclusive'][ $fee['name'] ]['label'] = $fee['label'];
				$totals['tax_inclusive'][ $fee['name'] ]['value'] = $calc->add(
					$totals['tax_inclusive'][ $fee['name'] ]['value'] ?? '0.00',
					$payments[ $p_id ][ $fee['name'] ]
				);
			}

			foreach ( $fee_types['tax_exclusive'] ?? array() as $fee ) {
				if ( 'gateway_fee' === $fee['name'] ) {
					continue;
				}
				$payments[ $p_id ][ $fee['name'] ]                = (string) ( $cart_totals[ 'total_' . $fee['name'] ] ?? '0.00' );
				$totals['tax_exclusive'][ $fee['name'] ]['label'] = $fee['label'];
				$totals['tax_exclusive'][ $fee['name'] ]['value'] = $calc->add(
					$totals['tax_exclusive'][ $fee['name'] ]['value'] ?? '0.00',
					$payments[ $p_id ][ $fee['name'] ]
				);
			}

			if ( isset( $fee_types['tax'], $cart_totals['total_tax'] ) ) {
				$payments[ $p_id ]['tax'] = (string) ( $cart_totals['total_tax'] ?? '0.00' );
				$totals['tax']            = array(
					'label' => $fee_types['tax']['label'],
					'value' => $calc->add(
						$totals['tax']['value'] ?? '0.00',
						$payments[ $p_id ]['tax']
					),
				);
			}

			$totals['total_paid']    = $calc->add(
				$totals['total_paid'] ?? '0.00',
				$payments[ $p_id ]['total']
			);
			$totals['total_deposit'] = $calc->add(
				$totals['total_deposit'] ?? '0.00',
				$payments[ $p_id ]['deposit']
			);
			$totals['payable']       = $calc->add(
				$totals['payable'] ?? '0.00',
				$payments[ $p_id ]['payable']
			);
			$totals['extra_charges'] = $calc->add(
				$totals['extra_charges'] ?? '0.00',
				$payments[ $p_id ]['extra_charges']
			);
			$totals['gateway_fee']   = $calc->add(
				$totals['gateway_fee'] ?? '0.00',
				$payments[ $p_id ]['gateway_fee']
			);
		}

		$totals['total_exclusive'] = (string) $this->get_total();

		$totals['due_exclusive'] = $calc->subtract(
			$totals['total_exclusive'],
			$totals['total_deposit']
		);

		$totals['subtotal'] = $calc->add( $totals['total_exclusive'], $totals['total_discount'] );

		$this->payments_data[ $key ] = compact( 'totals', 'payments' );

		return $this->payments_data[ $key ];
	}

	/**
	 * Get Booking End DateTime.
	 *
	 * @return string Booking End DateTime
	 * @since 6.8.3
	 */
	public function get_end_datetime(): string {
		$order_trips = $this->get_order_items();

		return $order_trips[0]['end_datetime'] ?? $this->get_nested_meta( 'wp_travel_engine_booking_setting.place_order.tenddate', '' );
	}

	/**
	 * Gets the booked quantity per price category for a package on a date/time.
	 *
	 * @param int $package_id Package ID.
	 *
	 * @return array<int, int> Category ID => booked quantity.
	 * @since 6.8.4
	 */
	public function get_booked_count( int $package_id ): array {
		$items = $this->get_cart_info( 'items' );

		if ( ! is_array( $items ) ) {
			return array();
		}

		$cart_key = ( new Inventory( $this->get_trip_id() ) )->get_cart_key( $package_id, $this->get_trip_datetime() );

		foreach ( $items as $item ) {
			if ( ! is_array( $item ) || ( $item['id'] ?? null ) !== $cart_key ) {
				continue;
			}

			return is_array( $item['pax'] ?? null ) ? $item['pax'] : array();
		}

		return array();
	}

	/**
	 * Get total refunded amount.
	 *
	 * @since 6.8.6
	 */
	public function get_refunded_amount(): float {
		return (float) $this->get_meta( 'total_refunded_amount' );
	}

	/**
	 * Deposit paid through the booking's completed payments, before fees.
	 *
	 * @param int|null $exclude_payment_id Payment to leave out, such as the one being made.
	 *
	 * @return float
	 * @since 6.9.0
	 */
	public function get_deposit_till_now( ?int $exclude_payment_id = null ): float {
		$calc    = PaymentCalculator::for( $this->get_currency() );
		$deposit = '0';
		foreach ( $this->get_payments() as $payment ) {
			if ( $payment->get_id() !== $exclude_payment_id && $payment->is_completed() ) {
				$deposit = $calc->add( $deposit, (string) $payment->get_deposit() );
			}
		}

		return (float) $deposit;
	}

	/**
	 * Refunds issued against the booking, oldest first.
	 *
	 * A booking refunded before the list was kept reports one row per refunded payment, so its
	 * refunds survive the list being saved.
	 *
	 * @return array<int, array{id: string, amount: float, reason: string, method: string, note: string, date: string}>
	 * @since 6.9.0
	 */
	public function get_refunds(): array {
		$refunds = $this->get_meta( 'refunds' );

		if ( is_array( $refunds ) ) {
			return $refunds;
		}

		$refunds = array();

		foreach ( $this->get_payments() as $payment ) {
			if ( ! $payment instanceof Payment || ! $payment->get_refunded_amount() ) {
				continue;
			}

			$refunds[] = array(
				'id'     => (string) $payment->ID,
				'amount' => $payment->get_refunded_amount(),
				'reason' => '',
				'method' => $payment->get_payment_gateway(),
				'note'   => '',
				'date'   => $payment->get_transaction_date(),
			);
		}

		return $refunds;
	}

	/**
	 * What may still be refunded: what has been collected, less what has gone back already.
	 *
	 * @return float Never negative, however the figures were recorded.
	 * @since 6.9.0
	 */
	public function get_refundable_amount(): float {
		return max( 0, $this->get_collected_amount() - $this->get_refunded_amount() );
	}

	/**
	 * Amount paid before refunds; legacy bookings store it net, so refunds are added back.
	 *
	 * @return float
	 * @since 6.9.0
	 */
	public function get_collected_amount(): float {
		$gross = (float) $this->get_meta( 'actual_total_paid_amount' );

		return $gross > 0 ? $gross : $this->get_total_paid_amount() + $this->get_refunded_amount();
	}

	/**
	 * Get the fallback note shown when a booking's initial deposit reflects a partial refund.
	 *
	 * @return string
	 * @since 6.8.6
	 */
	public static function get_refund_fallback_msg(): string {
		return __( 'Part of your deposit was refunded, so the Initial Deposit shown here reflects that adjustment.', 'wp-travel-engine' );
	}

	/**
	 * Cached per booking, not globally, since a `Trip` model is mutable.
	 *
	 * @param int|null $trip_id Defaults to this booking's own trip.
	 * @return Trip|null Null when there is no such trip.
	 * @since 6.9.0
	 */
	public function get_trip( ?int $trip_id = null ): ?Trip {
		$trip_id = (int) ( null === $trip_id ? $this->get_trip_id() : $trip_id );

		if ( ! $trip_id ) {
			return null;
		}

		if ( ! array_key_exists( $trip_id, $this->trips ) ) {
			$this->trips[ $trip_id ] = wptravelengine_get_trip( $trip_id );
		}

		return $this->trips[ $trip_id ];
	}

	/**
	 * A booked trip's length from its settings, as "7 Days / 6 Nights".
	 *
	 * @param int $trip_id Trip post ID.
	 * @return string Localised label; empty when the trip records no duration.
	 * @since 6.9.0
	 */
	public function trip_duration_label( int $trip_id ): string {
		$trip = $trip_id ? $this->get_trip( $trip_id ) : null;

		return $trip ? implode( ' / ', wptravelengine_get_trip_duration_arr( $trip ) ) : '';
	}

	/**
	 * The time of day a booked trip departs.
	 *
	 * Formatted in UTC: the stored value is already wall-clock.
	 *
	 * @param string $date Booked start date, with or without a time.
	 * @return string Time in the site's format; empty when the trip records no departure time.
	 * @since 6.9.0
	 */
	public function trip_start_time( string $date ): string {
		$utc = new \DateTimeZone( 'UTC' );

		foreach ( array( 'Y-m-d H:i:s', 'Y-m-d H:i', 'H:i:s', 'H:i' ) as $format ) {
			$parsed = \DateTimeImmutable::createFromFormat( $format, $date, $utc );

			if ( ! $parsed ) {
				continue;
			}

			// Midnight is what a date with no time parses to, which is not a departure time.
			if ( '00:00' === $parsed->format( 'H:i' ) ) {
				return '';
			}

			return wp_date( get_option( 'time_format' ) ?: 'g:i A', $parsed->getTimestamp(), $utc );
		}

		return '';
	}

	/**
	 * Gateway link for the amount still owed, or '' when the trip takes no part payment.
	 *
	 * @return string
	 */
	abstract public function get_due_payment_link(): string;

	/**
	 * Image, package, code and departure per booked trip, keyed by trip ID.
	 *
	 * The same trip booked twice keeps only the last entry.
	 *
	 * @return array<int, array<string, mixed>> Info per trip ID.
	 * @since 6.9.0
	 */
	public function get_trips_info(): array {
		$order_items = $this->get_order_items();
		$info        = array();

		foreach ( $this->cart_info->get_items() as $index => $item ) {
			$order_item = $order_items[ $index ] ?? array();

			if ( (int) ( $order_item['ID'] ?? 0 ) !== (int) $item->get_trip_id() ) {
				$order_item = array();
			}

			$info[ (int) $item->get_trip_id() ] = array(
				'thumbnail'      => $this->trip_thumbnail( $item->get_trip_id() ),
				'package'        => (string) ( $order_item['package_name'] ?? $item->get_package_name() ),
				'trip_code'      => $item->get_trip_code(),
				'start_time'     => $this->trip_start_time( (string) $item->get_trip_date() ),
				'duration_label' => $this->trip_duration_label( (int) $item->get_trip_id() ),
			);
		}

		return $info;
	}

	/**
	 * Travelers with blank required fields, counted per booked trip.
	 *
	 * @return array<int, int> Trip ID => incomplete travelers; trips with none are omitted.
	 * @since 6.9.0
	 */
	public function missing_traveler_counts(): array {
		$counts = array();

		foreach ( FormFields\BookingFormFields::for( $this )->travelers() as $trip_id => $travelers ) {
			$incomplete = 0;

			foreach ( $travelers as $fields ) {
				foreach ( $fields as $field ) {
					if ( empty( $field['validations']['required'] ) ) {
						continue;
					}

					$value = $field['value'] ?? '';
					$value = is_array( $value ) ? implode( '', $value ) : $value;

					if ( '' === trim( (string) $value ) ) {
						++$incomplete;
						break;
					}
				}
			}

			if ( $incomplete ) {
				$counts[ (int) $trip_id ] = $incomplete;
			}
		}

		return $counts;
	}

	/**
	 * Where the traveler pays what is still owed; checkout when there is no due-payment link.
	 *
	 * @return string URL, or '' when nothing is owed.
	 * @since 6.9.0
	 */
	public function due_payment_url(): string {
		if ( $this->get_total_due_amount() <= 0 ) {
			return '';
		}

		$link = $this->get_due_payment_link();

		return '' !== $link ? $link : wptravelengine_get_checkout_url();
	}

	/**
	 * @param int $trip_id Trip post ID.
	 * @return array `{ src, alt }`, both empty when the trip has no gallery image.
	 * @since 6.9.0
	 */
	public function trip_thumbnail( $trip_id ): array {
		$trip = $trip_id ? $this->get_trip( $trip_id ) : null;

		return $trip ? $trip->get_thumbnail() : array(
			'src' => '',
			'alt' => '',
		);
	}

	/**
	 * Whether any payment was refunded; the booking status is never set to `refunded`.
	 *
	 * @return bool
	 * @since 6.9.0
	 */
	public function is_refunded(): bool {
		return $this->get_refunded_amount() > 0;
	}

	/**
	 * Whether an operator created the booking by hand rather than a traveler at checkout.
	 *
	 * @return bool
	 * @since 6.9.0
	 */
	public function is_manual(): bool {
		return wptravelengine_toggled( $this->get_meta( '__is_manual' ) );
	}

	/**
	 * Whether the booking is cancelled.
	 *
	 * @return bool
	 * @since 6.9.0
	 */
	public function is_cancelled(): bool {
		return in_array( strtolower( $this->get_booking_status() ), array( 'canceled', 'cancelled' ), true );
	}

	/**
	 * Trip IDs this booking covers, in the order they were booked.
	 *
	 * @return array<int, int> Falls back to the booking's own trip when the cart holds no items.
	 * @since 6.9.0
	 */
	public function booked_trip_ids(): array {
		$ids = $this->cart_info->get_trip_ids();

		if ( $ids ) {
			return $ids;
		}

		// A legacy booking can carry no cart items while still naming its trip.
		$trip_id = (int) $this->get_trip_id();

		return $trip_id ? array( $trip_id ) : array();
	}

	/**
	 * Gateway display name, or the humanised key when the gateway is no longer registered.
	 *
	 * @param string $gateway Gateway key, e.g. `booking_only`.
	 * @return string Display name.
	 * @since 6.9.0
	 */
	protected function gateway_label( string $gateway ): string {
		if ( '' === $gateway ) {
			return '';
		}

		$gateways = wp_travel_engine_get_available_payment_gateways();
		$labels   = $gateways[ $gateway ] ?? array();
		$label    = (string) ( $labels['public_label'] ?? $labels['label'] ?? '' );

		if ( '' !== $label ) {
			return $label;
		}

		return wptravelengine_humanize_key( $gateway );
	}

	/**
	 * Checks Form Fields Snapshot is available.
	 *
	 * @return bool
	 * @since 6.9.0
	 */
	public function has_fields_snapshot(): bool {
		return $this->has_meta( 'form_fields_snapshot' );
	}

	/**
	 * Tax settings the booking was charged under.
	 *
	 * @return array<string, mixed>|null Null for a booking that predates the snapshot.
	 * @since 6.9.0
	 */
	public function get_tax_settings_snapshot(): ?array {
		if ( ! $this->has_meta( 'tax_snapshot' ) ) {
			return null;
		}

		$snapshot = $this->get_meta( 'tax_snapshot' );

		return is_array( $snapshot ) ? $snapshot : null;
	}

	/**
	 * Get Form Fields Snapshot.
	 *
	 * @param string|null $section Limit to one form section; null for every section.
	 * @return array<string, mixed> The snapshot, or the named section of it.
	 * @since 6.9.0
	 */
	public function get_fields_snapshot( ?string $section = null ): array {
		if ( null === $this->fields_snapshot ) {
			$this->fields_snapshot = ArrayUtility::to_array( $this->get_meta( 'form_fields_snapshot' ) );
		}

		$snapshot = $this->fields_snapshot;

		if ( null === $section ) {
			return $snapshot;
		}

		return (array) ( $snapshot[ $section ] ?? array() );
	}

	/**
	 * The booking's activity log, newest entry first.
	 *
	 * @return array<int, array<string, mixed>> Recorded entries; empty when nothing has been logged.
	 * @since 6.9.0
	 */
	public function get_activities(): array {
		return ArrayUtility::to_array( $this->get_meta( self::ACTIVITY_META_KEY ) );
	}
}
