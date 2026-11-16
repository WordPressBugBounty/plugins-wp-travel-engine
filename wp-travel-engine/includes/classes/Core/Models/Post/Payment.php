<?php
/**
 * Payment Model.
 *
 * @package WPTravelEngine/Core/Models
 * @since 6.0.0
 */

namespace WPTravelEngine\Core\Models\Post;

use Error;
use InvalidArgumentException;
use WPTravelEngine\Filters\Events;
use WPTravelEngine\Abstracts\PostModel;
use WPTravelEngine\Utilities\ArrayUtility;

/**
 * Class Payment.
 * This class represents a payment to the WP Travel Engine plugin.
 *
 * @since 6.0.0
 */
class Payment extends PostModel {

	/**
	 * Post type name.
	 *
	 * @var string
	 */
	protected string $post_type = 'wte-payments';

	/**
	 * Get Payment Amount.
	 *
	 * @return float
	 */
	public function get_amount(): float {
		return (float) ( $this->get_meta( 'payment_amount' )['value'] ?? 0 );
	}

	/**
	 * Get Payment Currency.
	 *
	 * @return string
	 */
	public function get_currency(): string {
		return $this->get_meta( 'payment_amount' )['currency'] ?? $this->get_payable_currency();
	}

	/**
	 * Get Payment Status.
	 *
	 * @return string
	 */
	public function get_payment_status(): string {
		return $this->get_meta( 'payment_status' ) ?: 'pending';
	}

	/**
	 * Get Payment Gateway Response.
	 *
	 * @return string|array
	 */
	public function get_gateway_response() {
		return $this->get_meta( 'gateway_response' );
	}

	/**
	 * Get Payment Gateway.
	 *
	 * @return string
	 */
	public function get_payment_gateway(): string {
		return $this->get_meta( 'payment_gateway' ) ?? '';
	}

	/**
	 * Get Payment Source.
	 *
	 * Returns 'checkout' for payments created through checkout, 'admin' for manual/admin payments.
	 * For legacy payments without payment_source, falls back to inferring from billing_info.
	 *
	 * @return string
	 * @since 6.7.3
	 * @since 6.9.0 A source recorded on the payment wins over the billing info, so an operator's edit reads as theirs.
	 */
	public function get_payment_source(): string {
		$payment_source = (string) $this->get_meta( 'payment_source' );

		if ( '' !== $payment_source ) {
			return $payment_source;
		}

		return $this->get_billing_info() ? 'checkout' : 'admin';
	}

	/**
	 * Records where the payment was last recorded from.
	 *
	 * @param string $source `checkout` or `admin`.
	 * @return void
	 * @since 6.9.0
	 */
	public function set_payment_source( string $source ): void {
		$this->set_meta( 'payment_source', $source );
	}

	/**
	 * Get Billing Information.
	 *
	 * @return array
	 */
	public function get_billing_info(): array {
		return $this->get_meta( 'billing_info' ) ?: array();
	}

	/**
	 * Get Payable Amount.
	 *
	 * @return string
	 */
	public function get_payable_amount(): float {
		return (float) ( $this->get_meta( 'payable' )['amount'] ?? 0 );
	}

	/**
	 * Get Tax Amount.
	 *
	 * @return float
	 * @since 6.7.0
	 */
	public function get_tax_amount(): float {
		$booking_id = $this->get_meta( 'booking_id' );
		$booking    = Booking::make( $booking_id );
		$tax_amount = $booking->get_tax_amount();
		return (float) ( $tax_amount ?? 0 );
	}

	/**
	 * Get Payable Currency.
	 *
	 * @return string
	 */
	public function get_payable_currency(): string {
		return $this->get_meta( 'payable' )['currency'] ?? '';
	}

	/**
	 * Checks if payment is successful.
	 *
	 * @return bool
	 * @updated 6.7.0
	 */
	public function is_completed(): bool {
		$success_status = wptravelengine_success_payment_status();
		return isset( $success_status[ $this->get_payment_status() ] );
	}

	/**
	 * Checks if payment is failed.
	 *
	 * @return bool
	 * @since 6.7.1
	 */
	public function is_failed(): bool {
		$failed_status = wptravelengine_failed_payment_status();
		return isset( $failed_status[ $this->get_payment_status() ] );
	}

	/**
	 * Checks if payment is pending.
	 *
	 * @return bool
	 * @since 6.7.1
	 */
	public function is_pending(): bool {
		$pending_status = wptravelengine_pending_payment_status();
		return isset( $pending_status[ $this->get_payment_status() ] );
	}

	/**
	 * Checks if payment is refunded.
	 *
	 * @return bool
	 * @since 6.8.6
	 */
	public function is_refunded(): bool {
		$refunded_status = wptravelengine_refund_payment_status();
		return isset( $refunded_status[ $this->get_payment_status() ] );
	}

	/**
	 * Get Refunded Amount.
	 *
	 * @return float
	 * @since 6.8.6
	 */
	public function get_refunded_amount(): float {
		if ( ! $this->is_refunded() ) {
			return 0.00;
		}
		$refunded = $this->get_meta( 'refunded_amount' );
		return is_numeric( $refunded ) ? (float) $refunded : 0.00;
	}

	/**
	 * Update Payment Status.
	 *
	 * @return void
	 * @since 6.7.1 Added previous payment status meta.
	 */
	public function update_status( $status ) {
		$this->set_status( $status );
		$this->save();

		unset( $this->data['_prev_payment_status'] );
		unset( $this->data['payment_status'] );
	}

	/**
	 * Generates Payment Key.
	 *
	 * @return string
	 */
	public function get_payment_key(): string {
		return wptravelengine_generate_key( $this->get_id() );
	}

	/**
	 * Get Booking.
	 *
	 * @return ?Booking
	 */
	public function get_booking(): ?Booking {
		return wptravelengine_get_booking( $this->get_meta( 'booking_id' ) );
	}

	/**
	 * Set Payment Status.
	 *
	 * @param string $status Payment Status.
	 * @since 6.7.1 Added previous payment status meta.
	 */
	public function set_status( string $status ) {
		$this->set_meta( '_prev_payment_status', $this->get_payment_status() );
		$this->set_meta( 'payment_status', $status );
	}

	/**
	 * Set Payment Gateway.
	 *
	 * @param string $gateway Payment Gateway.
	 */
	public function set_payment_gateway( string $gateway ) {
		$this->set_meta( 'payment_gateway', $gateway );
	}

	/**
	 * Set Payment Gateway Response.
	 *
	 * @param string $payment_key
	 *
	 * @return ?Payment
	 * @throws InvalidArgumentException
	 * @since 6.7.11 Fixed transient lookup failure by adding meta-query fallback when transient is missing (e.g. object-cache issues).
	 */
	public static function from_payment_key( string $payment_key ): ?Payment {
		if ( empty( $payment_key ) ) {
			throw new InvalidArgumentException( 'Invalid Payment Key' );
		}

		$payment_id = get_transient( 'payment_key_' . $payment_key );

		// Fallback: query payment by meta if transient not found (e.g., object cache issues).
		if ( ! $payment_id ) {
			$query = new \WP_Query(
				array(
					'post_type'        => 'wte-payments',
					'posts_per_page'   => 1,
					'meta_key'         => 'payment_key',
					'meta_value'       => $payment_key,
					'fields'           => 'ids',
					'no_found_rows'    => true,
					'suppress_filters' => true,
				)
			);
			if ( ! empty( $query->posts ) ) {
				$payment_id = $query->posts[0];
				set_transient( 'payment_key_' . $payment_key, $payment_id, 24 * HOUR_IN_SECONDS );
			}
		}

		if ( ! $payment_id ) {
			throw new InvalidArgumentException( 'Invalid Payment Key' );
		}

		return new static( $payment_id );
	}

	/**
	 * @return string
	 * @since 6.4.0
	 */
	public function get_transaction_id(): string {
		return $this->get_meta( 'transaction_id' ) ?: $this->get_meta( '_transaction_id' ) ?: '';
	}

	/**
	 * @param string $data
	 *
	 * @return void
	 * @since 6.4.0
	 */
	public function set_transaction_id( string $data ) {
		$this->set_meta( 'transaction_id', $data );
	}

	/**
	 * @return string
	 * @since 6.4.0
	 */
	public function get_transaction_date(): string {
		return $this->get_meta( 'transaction_date' );
	}

	/**
	 * @param string $data
	 *
	 * @return void
	 * @since 6.4.0
	 */
	public function set_transaction_date( string $data ) {
		$this->set_meta( 'transaction_date', $data );
	}

	/**
	 * Sets what this payment settled, keeping the currency it was taken in.
	 *
	 * The amount is what the booking's paid and due totals are summed from, so a caller that
	 * changes it is responsible for refreshing those - see Booking::sync_totals().
	 *
	 * @param float $amount Amount settled, in the payment's own currency.
	 * @return void
	 * @since 6.9.0
	 */
	public function set_amount( float $amount ): void {
		$this->set_meta(
			'payment_amount',
			array(
				'value'    => $amount,
				'currency' => $this->get_currency(),
			)
		);
	}

	/**
	 * How a request's deposit was arrived at, as `{ type, value }`.
	 *
	 * Kept beside the amount rather than derived from it: a rate cannot be worked back out of
	 * what the deposit came to, since the balance it was taken from moves.
	 *
	 * @return array{type: string, value: string} Empty strings when the payment records none.
	 * @since 6.9.0
	 */
	public function get_request_deposit(): array {
		$deposit = ArrayUtility::to_array( $this->get_meta( 'payment_request_depost' ) );

		return array(
			'type'  => (string) ( $deposit['type'] ?? '' ),
			'value' => (string) ( $deposit['value'] ?? '' ),
		);
	}

	/**
	 * Records how a request's deposit was arrived at.
	 *
	 * @param string $type  `percent` or `fixed`.
	 * @param string $value The figure it was worked out from, as the operator entered it.
	 * @return void
	 * @since 6.9.0
	 */
	public function set_request_deposit( string $type, string $value ): void {
		$this->set_meta(
			'payment_request_depost',
			array(
				'type'  => $type,
				'value' => $value,
			)
		);
	}

	/**
	 * Sets what this payment is for, which is what a request asks the customer to settle.
	 *
	 * @param float $amount Amount payable.
	 * @return void
	 * @since 6.9.0
	 */
	public function set_payable_amount( float $amount ): void {
		$this->set_meta(
			'payable',
			array(
				'currency' => $this->get_payable_currency(),
				'amount'   => $amount,
			)
		);
	}

	/**
	 * The operator's note on this payment, never shown to the customer.
	 *
	 * @return string
	 * @since 6.9.0
	 */
	public function get_remark(): string {
		return (string) $this->get_meta( '_remark' );
	}

	/**
	 * Records the operator's note on this payment.
	 *
	 * @param string $remark Note on the payment.
	 * @return void
	 * @since 6.9.0
	 */
	public function set_remark( string $remark ): void {
		$this->set_meta( '_remark', $remark );
	}

	/**
	 * What the payment covered before the fees it carried.
	 *
	 * @return float The whole amount on a record that never split it.
	 * @since 6.9.0
	 */
	public function get_deposit(): float {
		$totals = (array) $this->get_cart_totals();

		return isset( $totals['deposit'] ) ? (float) $totals['deposit'] : $this->get_amount();
	}

	/**
	 * What this payment collects now, fees included.
	 *
	 * @return float The amount on a record whose cart never worked one out.
	 * @since 6.9.0
	 */
	public function get_payable_now(): float {
		$totals = (array) $this->get_cart_totals();

		return isset( $totals['payable_now'] ) ? (float) $totals['payable_now'] : $this->get_amount();
	}

	/**
	 * @return array
	 * @since 6.5.2
	 */
	public function get_data(): array {
		$booking = $this->get_booking();

		$data = array(
			'id'             => $this->ID,
			'status'         => $this->get_payment_status(),
			'paid_amount'    => $this->get_amount(),
			'currency'       => $this->get_currency(),
			'payment_method' => $this->get_payment_gateway(),
		);
		if ( $booking ) {
			$data['booking_id']     = $booking->get_id();
			$data['booking_status'] = $booking->get_booking_status();
			$data['booked_trip']    = array(
				'id'              => $booking->get_trip_id(),
				'title'           => $booking->get_trip_title(),
				'url'             => get_permalink( $booking->get_trip_id() ),
				'trip_start_date' => $booking->get_order_trip()->datetime,
			);
			$data['customer']       = $booking->get_customer();
		}

		return $data;
	}

	/**
	 * Get Cart totals.
	 *
	 * @param string|null $key Optional. Specific cart total key to retrieve.
	 *
	 * @return float|array
	 * @since 6.7.0
	 */
	public function get_cart_totals( $key = null ) {
		$cart_totals = $this->get_meta( 'cart_totals' ) ?: array();
		return null === $key ? $cart_totals : ( $cart_totals[ $key ] ?? 0 );
	}

	/**
	 * Get Payment Status Label.
	 *
	 * @return string
	 * @since 6.7.3
	 */
	public function get_payment_status_label(): string {
		$all_payment_details = wptravelengine_payment_status();
		return $all_payment_details[ $this->get_payment_status() ] ?? $this->get_payment_status();
	}

	/**
	 * Get Payment Gateways extra charges.
	 *
	 * @return float
	 * @since 6.7.8
	 */
	public function get_gateway_fee(): float {
		return (float) ( $this->get_meta( 'gateway_fee' ) ?: 0 );
	}

	/**
	 * Whether this payment is a request the customer has yet to settle.
	 *
	 * @return bool
	 * @since 6.9.0
	 */
	public function is_payment_request(): bool {
		return wptravelengine_toggled( $this->get_meta( 'is_payment_request' ) );
	}

	/**
	 * Records whether this payment is a request the customer settles for themselves.
	 *
	 * @param bool $is_request Whether the payment was opened as a request.
	 * @return void
	 * @since 6.9.0
	 */
	public function set_payment_request( bool $is_request ): void {
		$this->set_meta( 'is_payment_request', wptravelengine_replace( $is_request, true, 'yes', 'no' ) );
	}

	/**
	 * The URL the customer settles this payment through.
	 *
	 * @return string Empty when the payment is not a request, is already completed, or carries no booking to return to, or the booking is canceled.
	 * @since 6.9.0
	 */
	public function get_payment_link(): string {
		$booking_id = (int) $this->get_meta( 'booking_id' );

		if ( ! $this->is_payment_request() || $this->is_completed() || ! $booking_id || 'canceled' === get_post_meta( $booking_id, 'wp_travel_engine_booking_status', true ) ) {
			return '';
		}

		$payment_key = $this->get_payment_key();

		// A link the operator may send on has to outlive the session it was raised in.
		set_transient(
			"_payment_key_{$payment_key}",
			wp_json_encode(
				array(
					'action'     => 'remaining_payment',
					'booking_id' => $booking_id,
					'payment_id' => $this->ID,
				)
			),
			365 * DAY_IN_SECONDS
		);

		$payment_link = add_query_arg( array( '_payment_key' => $payment_key ), home_url() );

		return apply_filters( 'wptravelengine_payment_link', $payment_link, $this->get_id() );
	}

	/**
	 * Opens a payment record against a booking.
	 *
	 * @param int   $booking_id Booking the record is attached to.
	 * @param array $args {
	 *     Optional. Opening state; every key falls back to an unsettled admin record.
	 *
	 *     @type bool   $is_request     Record is a request the customer settles.
	 *     @type string $source         `admin` or `checkout`.
	 *     @type string $status         Raw payment status slug.
	 *     @type string $currency       Currency both amounts are in.
	 *     @type float  $payment_amount What the record settles.
	 *     @type float  $payable_amount What it is raised against.
	 *     @type bool   $is_due_payment Settles a balance, which the due-payment email keys off.
	 *     @type string $post_title     Title the record is listed under.
	 *     @type array  $meta_input     Further meta to write with the post; overrides the keys above.
	 * }
	 * @return Payment The new record, already attached to the booking.
	 * @throws InvalidArgumentException When no booking answers to the given ID.
	 * @since 6.9.0
	 */
	public static function create( int $booking_id, array $args = array() ): Payment {
		$booking = wptravelengine_get_booking( $booking_id, true );

		if ( ! $booking ) {
			throw new InvalidArgumentException( 'Invalid Booking ID' );
		}

		$args = wp_parse_args(
			$args,
			array(
				'is_request'     => false,
				'source'         => 'admin',
				'status'         => 'pending',
				'currency'       => (string) $booking->get_currency(),
				'payment_amount' => 0,
				'payable_amount' => $booking->get_total_due_amount(),
				'is_due_payment' => false,
				'post_title'     => sprintf(
					/* translators: %s: booking ID the payment is raised against. */
					__( 'Payment for booking #%s', 'wp-travel-engine' ),
					$booking->ID
				),
				'meta_input'     => array(),
			)
		);

		/*
		 * Written with the post rather than queued on the model: the fields below are what the
		 * caller reads back off the record, and a queued write is not readable until it saves.
		 */
		$payment = static::create_post(
			array(
				'post_type'   => 'wte-payments',
				'post_status' => 'publish',
				'post_title'  => (string) $args['post_title'],
				'meta_input'  => wp_parse_args(
					(array) $args['meta_input'],
					array(
						'booking_id'         => $booking->ID,
						'payment_source'     => (string) $args['source'],
						'payment_status'     => (string) $args['status'],
						'is_payment_request' => wptravelengine_replace( (bool) $args['is_request'], true, 'yes', 'no' ),
						'is_due_payment'     => wptravelengine_replace( (bool) $args['is_due_payment'], true, 'yes', 'no' ),
						'payment_amount'     => array(
							'value'    => (float) $args['payment_amount'],
							'currency' => (string) $args['currency'],
						),
						'payable'            => array(
							'currency' => (string) $args['currency'],
							'amount'   => (float) $args['payable_amount'],
						),
					)
				),
			)
		);

		// Held with the booking's other writes under `with_delayed_save()`; `get_payments()` reads it back.
		$booking->update_meta(
			'payments',
			array_merge( ArrayUtility::to_array( $booking->get_payment_detail() ), array( $payment->ID ) )
		);

		Events::payment_created( $payment );

		return $payment;
	}
}
