<?php
/**
 * Booking Model.
 *
 * @package WPTravelEngine/Core/Models
 * @since 6.0.0
 */

namespace WPTravelEngine\Core\Models\Post;

use InvalidArgumentException;
use WP_POST;
use WPTravelEngine\Core\Cart\Cart;
use WPTravelEngine\Filters\Events;
use WPTravelEngine\Helpers\Functions;
use WPTravelEngine\Abstracts\PostModel;
use WPTravelEngine\Helpers\CartInfoParser;
use WPTravelEngine\Utilities\ArrayUtility;
use WPTravelEngine\Core\Booking\Inventory;
use WPTravelEngine\Utilities\PaymentCalculator;

/**
 * Trip booking model; read accessors live on `BookingAttributes`.
 *
 * @since 6.0.0
 * @since 6.9.0 Read accessors moved to the `BookingAttributes` trait.
 */
#[\AllowDynamicProperties]
class Booking extends PostModel {
	use BookingAttributes;

	/**
	 * Meta key holding the booking's activity log, newest entry first.
	 *
	 * @var string
	 * @since 6.9.0
	 */
	const ACTIVITY_META_KEY = 'tracked_activities';

	/**
	 * Max activity log entries, or null for no cap.
	 *
	 * @var int|null
	 * @since 6.9.0
	 */
	const ACTIVITY_LOG_LIMIT = null;

	/**
	 * Post type name.
	 *
	 * @var string
	 */
	protected string $post_type = 'booking';

	/**
	 * Indicates if the booking is trashed.
	 *
	 * @var bool
	 */
	protected $trashed = false;

	/**
	 * @param int $payment_id
	 *
	 * @return Booking
	 */
	public function add_payment( int $payment_id ): Booking {
		$payments = $this->get_meta( 'payments' );

		if ( ! is_array( $payments ) ) {
			$payments = array();
		}

		$payments[] = $payment_id;

		$this->set_meta( 'payments', array_unique( $payments ) );

		return $this;
	}

	/**
	 * Set Billing Info.
	 *
	 * @return void
	 */
	public function set_billing_info( array $billing_info ) {
		$this->set_meta( 'billing_info', $billing_info );
		$this->set_meta( 'wptravelengine_billing_details', $billing_info );
	}

	/**
	 * Set Order Items.
	 *
	 * @return void
	 */
	public function set_order_items( array $items ) {
		$this->set_meta( 'order_trips', $items );
	}

	/**
	 * Set Cart Information.
	 *
	 * @return Booking
	 */
	public function set_cart_info( array $data ): Booking {
		return $this->set_meta( 'cart_info', wp_slash( $data ) );
	}

	/**
	 * Queues a meta write, keeping the parsed cart in step.
	 *
	 * @param string $meta_key   Meta key being written.
	 * @param mixed  $meta_value Value being written, slashed as post meta is.
	 * @return Booking
	 * @since 6.9.0
	 */
	public function set_meta( $meta_key, $meta_value ): Booking {
		if ( 'cart_info' === $meta_key ) {
			$this->parsed_cart = wptravelengine_get_cart_info( $this->ID, (array) wp_unslash( $meta_value ) );
		}

		return parent::set_meta( $meta_key, $meta_value );
	}

	/**
	 * Writes metas straight through, keeping the parsed cart in step.
	 *
	 * @param array<mixed> $metadata Key-value pairs of metadata to be synced.
	 * @return void
	 * @since 6.9.0
	 */
	public function sync_metas( array $metadata = array() ) {
		if ( isset( $metadata['cart_info'] ) ) {
			$this->parsed_cart = wptravelengine_get_cart_info( $this->ID, (array) $metadata['cart_info'] );
		}

		parent::sync_metas( $metadata );
	}

	/**
	 * Sets booking status.
	 *
	 * @param string $status New booking status.
	 * @return void
	 * @since 6.4.0
	 * @since 6.8.0 No-op when status unchanged to preserve _prev_booking_status.
	 */
	public function set_status( $status ) {
		$_prev_booking_status = $this->get_booking_status();
		if ( $status !== $_prev_booking_status ) {
			$this->set_meta( '_prev_booking_status', $_prev_booking_status );
			$this->set_meta( 'wp_travel_engine_booking_status', $status );
		}
	}

	/**
	 * Saves booking status.
	 *
	 * @param string $status New booking status.
	 * @return $this
	 * @since 6.8.0 No-op when status unchanged to preserve _prev_booking_status.
	 */
	public function update_status( $status ): Booking {
		$_prev_booking_status = $this->get_booking_status();
		if ( $status !== $_prev_booking_status ) {
			$this->update_meta( '_prev_booking_status', $_prev_booking_status );
			$this->update_meta( 'wp_travel_engine_booking_status', $status );
		}
		return $this;
	}

	/**
	 * Update paid amount; replaces it when `$update` is false.
	 *
	 * @return $this
	 */
	public function update_paid_amount( $amount, bool $update = true ): Booking {
		$previous_amount = $this->get_paid_amount();

		$amount = $update ? $previous_amount + $amount : $amount;
		$this->update_meta( 'paid_amount', $amount );

		return $this;
	}

	/**
	 * Update Due Amount.
	 *
	 * @return $this
	 */
	public function update_due_amount( $amount, bool $update = true ): Booking {
		$previous_amount = $this->get_due_amount();

		$amount = $update ? max( $previous_amount - $amount, 0 ) : $amount;
		$this->update_meta( 'due_amount', $amount );

		return $this;
	}

	/**
	 * Save Booking.
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_POST $post Post Object.
	 * @param bool    $update Update Flag.
	 *
	 * @since 6.9.0 Coerce each traveler's `cat_id` to an integer before storing (defense-in-depth for stored XSS).
	 */
	public static function save_post_booking( int $post_id, WP_Post $post, bool $update = false ) {

		// Backward Compatibility with Old Checkout.
		$request = Functions::create_request( 'POST' );
		$booking = new static( $post );

		// Billing Info.
		if ( $billing_info = $request->get_param( 'billing_info' ) ) {
			$current_billing_info = $booking->get_billing_info();
			if ( is_array( $billing_info ) ) {
				foreach ( $billing_info as $key => $value ) {
					$current_billing_info[ $key ] = sanitize_text_field( wp_unslash( $value ) );
				}
				$booking->set_billing_info( $current_billing_info );
			}
		}

		// Sets Traveler's Information.
		$traveler_info = $request->get_param( 'wp_travel_engine_placeorder_setting' )['place_order'] ?? false;
		if ( ! $traveler_info ) {
			$traveler_info = $request->get_param( 'wp_travel_engine_booking_setting' )['place_order'] ?? false;
		}

		if ( is_array( $traveler_info ) && ! empty( $traveler_info ) ) {
			$travelers = array();
			if ( isset( $traveler_info['travelers'] ) && is_array( $traveler_info['travelers'] ) ) {
				foreach ( $traveler_info['travelers'] as $key => $value ) {
					// cat_id is a pricing-category term ID; keep it an integer here too (legacy getter reads this meta).
					$callback                       = 'cat_id' === $key ? 'absint' : 'sanitize_text_field';
					$travelers['travelers'][ $key ] = array_map( $callback, wp_unslash( $value ) );
				}
			}
			if ( isset( $traveler_info['relation'] ) && is_array( $traveler_info['relation'] ) ) {
				foreach ( $traveler_info['relation'] as $key => $value ) {
					$travelers['relation'][ $key ] = array_map( 'sanitize_text_field', wp_unslash( $value ) );
				}
			}

			// Backward Compatibility with Old Travelers Information Page.
			$travelers_detail   = $traveler_info['travelers'] ?? array();
			$emergency_contacts = $traveler_info['relation'] ?? array();

			if ( ! empty( $travelers_detail ) ) {
				$travelers_detail = static::sanitize_data_array( $travelers_detail );
				// cat_id is a pricing-category term ID; force int so a non-numeric value can't break out of the option attribute on the admin booking screen.
				foreach ( $travelers_detail as &$traveler ) {
					if ( isset( $traveler['cat_id'] ) && '' !== $traveler['cat_id'] ) {
						$traveler['cat_id'] = absint( $traveler['cat_id'] );
					}
				}
				unset( $traveler );
				$booking->set_meta( 'wptravelengine_travelers_details', $travelers_detail );
			}
			if ( ! empty( $emergency_contacts ) ) {
				$emergency_contacts = static::sanitize_data_array( $emergency_contacts );
				$booking->set_meta( 'wptravelengine_emergency_details', $emergency_contacts );
			}

			$booking->set_meta(
				'wp_travel_engine_placeorder_setting',
				array( 'place_order' => $travelers )
			);
		}

		// Order Trips.
		if ( $order_trips = $request->get_param( 'order_trips' ) ) {
			$current_order_trips = $booking->get_meta( 'order_trips' );
			$_data               = array();
			foreach ( array_keys( $current_order_trips ) as $cart_id ) {
				if ( ! isset( $order_trips[ $cart_id ] ) ) {
					$_data[ $cart_id ] = $current_order_trips[ $cart_id ];
					continue;
				}
				$cart_data = $order_trips[ $cart_id ];

				if ( isset( $cart_data['ID'] ) ) {
					$_data[ $cart_id ]['ID']    = sanitize_text_field( $cart_data['ID'] );
					$_data[ $cart_id ]['title'] = get_the_title( $_data[ $cart_id ]['ID'] );
				}
				if ( isset( $cart_data['datetime'] ) ) {
					$_data[ $cart_id ]['datetime'] = sanitize_text_field( $cart_data['datetime'] );
				}

				if ( isset( $cart_data['end_datetime'] ) ) {
					$_data[ $cart_id ]['end_datetime'] = sanitize_text_field( $cart_data['end_datetime'] );
				}

				if ( isset( $cart_data['pax'] ) ) {
					$_data[ $cart_id ]['pax'] = array_map( 'absint', $cart_data['pax'] );
				}

				if ( isset( $cart_data['pax_cost'] ) ) {
					foreach ( $cart_data['pax_cost'] as $_id => $pax_cost ) {
						if ( ! isset( $_data[ $cart_id ]['pax'][ $_id ] ) ) {
							continue;
						}
						$pax_count                             = (int) $_data[ $cart_id ]['pax'][ $_id ];
						$_data[ $cart_id ]['pax_cost'][ $_id ] = $pax_count * (float) $pax_cost;
					}
				}

				if ( isset( $cart_data['cost'] ) ) {
					$_data[ $cart_id ]['cost'] = sanitize_text_field( $cart_data['cost'] );
				}

				$_data[ $cart_id ] = wp_parse_args( $_data[ $cart_id ], $current_order_trips[ $cart_id ] );
			}

			$booking->set_order_items( $_data );
		}

		if ( $booking_status = $request->get_param( 'wp_travel_engine_booking_status' ) ) {
			$booking->set_meta( 'wp_travel_engine_booking_status', sanitize_text_field( $booking_status ) );
		}

		// Sets Paid amount.
		if ( is_numeric( $paid_amount = $request->get_param( 'paid_amount' ) ) ) {
			$booking->set_meta( 'paid_amount', $paid_amount );
		}

		// Sets due amount.
		if ( is_numeric( $due_amount = $request->get_param( 'due_amount' ) ) ) {
			$booking->set_meta( 'due_amount', $due_amount );
		}

		$booking->save();
		$booking->maybe_update_inventory();

		if ( $update ) {
			/**
			 * @param array $data Booking Data.
			 * @param Booking $booking Booking Object.
			 *
			 * @since 6.5.2
			 */
			do_action( 'wptravelengine.booking.updated', $booking->get_data(), $booking );
		}
	}

	/**
	 * Sanitize Data Array.
	 *
	 * @param array $form_data
	 *
	 * @return array
	 * @since 6.5.0
	 */
	public static function sanitize_data_array( array $form_data ): array {
		$sanitized = array();
		foreach ( $form_data as $key => $value ) {
			if ( is_array( $value ) && ! empty( $value ) ) {
				foreach ( array_values( $value ) as $i => $data ) {
					$sanitized[ $i ][ $key ] = is_array( $data )
						? array_map( 'sanitize_text_field', wp_unslash( $data ) )
						: sanitize_text_field( $data );
				}
			}
		}

		return $sanitized;
	}

	/**
	 * Handle post when trashing if post-type is booking.
	 *
	 * @param int $post_id Post ID.
	 *
	 * @return void
	 */
	public static function trashing_booking( int $post_id ): void {
		try {
			$booking = new static( $post_id );

			$booking->set_meta( '_prev_booking_status', $booking->get_booking_status() );
			$booking->update_status( 'canceled' );

			$booking->trashed = true;
			$booking->maybe_update_inventory();
		} catch ( \Exception $e ) {
			// Do nothing.
		}
	}

	/**
	 * Handle post when untrashing if post-type is booking.
	 *
	 * @param int $post_id Post ID.
	 *
	 * @return void
	 */
	public static function untrashing_booking( int $post_id ): void {
		try {
			$booking = new static( $post_id );

			$booking->update_status( $booking->get_meta( '_prev_booking_status' ) );
			$booking->untrashed = true;

			$booking->maybe_update_inventory();
		} catch ( \Exception $e ) {
			// Do nothing.
		}
	}

	/**
	 * Save Traveler's Information from the POST Request.
	 *
	 * @return void
	 */
	public static function save_travellers_information( $booking_id ) {

		if ( $booking_id ) {
			do_action( 'wp_travel_engine_before_traveller_information_save', $booking_id );
			static::save_post_booking( $booking_id, get_post( $booking_id ), true );
			do_action( 'wp_travel_engine_after_traveller_information_save', $booking_id );
			WTE()->session->delete( 'temp_tf_direction' );
		}
	}

	/**
	 * Maybe Update Inventory.
	 *
	 * @return void
	 */
	public function maybe_update_inventory(): void {
		$order_trips        = $this->get_meta( 'order_trips' );
		$cart_data          = $this->get_cart_info();
		$pricing_line_items = $cart_data['items'][0]['line_items']['pricing_category'] ?? array();

		if ( is_array( $order_trips ) ) {
			foreach ( $order_trips as $cart_id => $order_trip ) {
				$inventory = new Inventory( $order_trip['ID'] );
				$pax       = 0;

				if ( $this->trashed === true || 'canceled' === $this->get_booking_status() || 'refunded' === $this->get_booking_status() ) {
					$inventory->update_pax( $cart_id, 0, $order_trip['ID'], $this->ID );
					continue;
				}
				if ( is_array( $order_trip['pax'] ) ) {
					$pax = array_sum( $order_trip['pax'] );
				}
				if ( isset( $pricing_line_items ) && ! empty( $pricing_line_items ) && is_array( $pricing_line_items ) ) {
					$pax = array_sum( array_column( $pricing_line_items, 'quantity' ) );
				}

				$records = $inventory->get_inventory_record();
				if ( isset( $records[ $cart_id ][ $this->ID ] ) ) {
					$recorded_pax = $records[ $cart_id ][ $this->ID ];
					if ( $recorded_pax !== $pax ) {
						$inventory->update_pax( $cart_id, $pax, $order_trip['ID'], $this->ID );
					}
				} else {
					$inventory->update_pax( $cart_id, $pax, $order_trip['ID'], $this->ID );
				}
			}
		}
	}

	/**
	 * Get Booking by Payment ID.
	 *
	 * @param int|Payment $payment Payment ID or Payment Modal object.
	 *
	 * @return Booking|null
	 * @throws InvalidArgumentException If invalid Booking ID of Payment.
	 */
	public static function from_payment( $payment ): ?Booking {

		if ( $payment instanceof Payment ) {
			$payment = $payment->get_id();
		}

		$booking_id = get_post_meta( $payment, 'booking_id', true );

		if ( ! $booking_id ) {
			throw new InvalidArgumentException( 'Invalid Booking ID of Payment' );
		}

		return new static( $booking_id );
	}

	/**
	 * Set Total Due Amount.
	 *
	 * @param float $amount
	 *
	 * @return void
	 * @since 6.4.0
	 */
	public function set_total_due_amount( float $amount ): void {
		$this->update_meta( 'total_due_amount', floatval( $amount ) );
	}

	/**
	 * Set Total Paid Amount.
	 *
	 * @param float $amount
	 *
	 * @return void
	 * @since 6.4.0
	 */
	public function set_total_paid_amount( float $amount ): void {
		$this->update_meta( 'total_paid_amount', $amount );
	}

	/**
	 * Set Total Refunded Amount.
	 *
	 * @param float $amount Refunded across every payment.
	 * @return void
	 * @since 6.9.0
	 */
	public function set_refunded_amount( float $amount ): void {
		$this->set_meta( 'total_refunded_amount', $amount );
	}

	/**
	 * Get Remaining Payment Link.
	 *
	 * @return string URL for remaining payment or empty string if payment is not pending
	 * @since 6.9.0 Links any balance due, whether or not the trip takes partial payment; none for a canceled booking.
	 */
	public function get_due_payment_link(): string {
		$payment_key  = wptravelengine_generate_key( $this->get_id() );
		$payment_link = '';

		$this->set_payment_transient( $payment_key );

		if ( 'canceled' !== $this->get_booking_status() && $this->has_due_payment() ) {
			$payment_link = add_query_arg(
				array(
					'_payment_key' => $payment_key,
				),
				home_url()
			);
		}

		return apply_filters( 'wptravelengine_due_payment_link', $payment_link, $this->get_id() );
	}

	/**
	 * Set Payment Link.
	 *
	 * @param ?string $payment_key Payment Link
	 * @return void
	 * @since 6.6.10
	 */
	public function set_payment_transient( $payment_key = null ): void {
		$payment_key ??= wptravelengine_generate_key( $this->get_id() );

		// Set expiration to 365 days for remaining payment links so customers can complete payment at any time
		set_transient(
			"_payment_key_{$payment_key}",
			wp_json_encode(
				array(
					'action'     => 'remaining_payment',
					'booking_id' => $this->get_id(),
				)
			),
			365 * DAY_IN_SECONDS
		);
	}

	/**
	 * Set Traveller Details.
	 *
	 * @param array $data
	 */
	public function set_traveller_details( array $data ) {
		$this->set_meta( 'wptravelengine_travelers_details', $data );
	}

	/**
	 * Set Emergency Contact Details.
	 *
	 * @param array $data
	 */
	public function set_emergency_contact_details( array $data ) {
		$this->set_meta( 'wptravelengine_emergency_details', $data );
	}

	/**
	 * Set Cart Pricing.
	 *
	 * @param array $cart_pricing Cart Pricing
	 */
	public function set_cart_pricing( $cart_pricing ) {
		$this->set_meta( 'wptravelengine_cart_pricing', $cart_pricing );
		$this->save();
	}

	/**
	 * Set Additional Details.
	 *
	 * @param string $additional_details Additional Details
	 */
	public function set_additional_details( $additional_details ) {
		$this->set_meta( 'wptravelengine_additional_note', $additional_details );
		$this->save();
	}

	/**
	 * Set Notes.
	 *
	 * @param string $notes Notes
	 */
	public function set_notes( $notes ) {
		$this->set_meta( 'wptravelengine_admin_notes', $notes );
		$this->save();
	}

	/**
	 * Builds one activity entry stamped with actor and time, without writing it.
	 *
	 * @param string               $type     What kind of event it was; decides the icon the client draws.
	 * @param string               $category Which filter the entry falls under, or '' when none fits.
	 * @param string               $text     What happened, in a sentence, already translated.
	 * @param string               $actor    Who it is attributed to: `admin` or `traveler`.
	 * @param array<string, mixed> $extra    Further keys the entry carries, e.g. the travelers it names.
	 * @return array<string, mixed>
	 * @since 6.9.0
	 */
	public static function record_activity( string $type, string $category, string $text, string $actor = 'admin', array $extra = array() ): array {
		$who = wp_get_current_user()->display_name;

		if ( '' === $who ) {
			$who = 'traveler' === $actor
				? __( 'Traveler', 'wp-travel-engine' )
				: __( 'Admin', 'wp-travel-engine' );
		}

		return array_merge(
			$extra,
			array(
				'id'       => wp_generate_uuid4(),
				'type'     => $type,
				'category' => $category,
				'actor'    => $actor,
				'who'      => $who,
				'user_id'  => get_current_user_id(),
				'date'     => current_time( 'mysql', true ),
				'text'     => $text,
			)
		);
	}

	/**
	 * Queues entries at the front of the activity log; outside with_delayed_save() it needs a save().
	 *
	 * @param array<int, array<string, mixed>> $activities Entries to record, in the order they happened.
	 * @return void
	 * @since 6.9.0
	 */
	public function add_activities( array $activities ): void {
		if ( empty( $activities ) ) {
			return;
		}

		$this->set_meta(
			self::ACTIVITY_META_KEY,
			ArrayUtility::append( $activities, $this->get_activities(), self::ACTIVITY_LOG_LIMIT )
		);
	}

	/**
	 * Set Payment Gateway Response.
	 *
	 * @param string $key
	 *
	 * @return ?Booking
	 * @throws InvalidArgumentException
	 */
	public static function from_payment_key( string $key ): ?Booking {
		if ( empty( $key ) ) {
			throw new InvalidArgumentException( 'Invalid Payment Key' );
		}

		$payment_id = get_transient( 'payment_key_' . $key );

		if ( ! $payment_id ) {
			throw new InvalidArgumentException( 'Invalid Payment Key' );
		}

		return new static( $payment_id );
	}

	/**
	 * Ensure payment key is stored for this booking.
	 *
	 * @return string The payment key
	 * @since 6.7.0
	 */
	public function ensure_payment_key(): string {
		$payment_key = wptravelengine_generate_key( $this->get_id() );

		// Store payment key as post meta for efficient lookups
		$this->update_meta( '_payment_key', $payment_key );

		return $payment_key;
	}

	/**
	 * Syncs metas to the Payment and Booking posts.
	 *
	 * @param int   $payment_id The payment ID.
	 * @param float $paid_amount The paid amount received from the payment gateway response.
	 * @param array $args The arguments for payment, booking metadata to sync and whether to send booking emails.
	 * {
	 *  'payment_metadata'    => array<mixed>,
	 *  'booking_metadata'    => array<mixed>,
	 *  'send_booking_emails' => bool,
	 *  'send_payment_emails' => bool,
	 * }
	 * @return void
	 * @since 6.7.0
	 * @since 6.7.1 Added support for send_booking_emails, send_payment_emails arguments and payment event trigger.
	 * @since 6.7.11 Added wptravelengine_verify_payment_success_status filter to allow bypassing completed-status guard.
	 * @since 6.9.0 Sets the booking payment status to `partially-paid` while a balance is still due.
	 */
	public function sync_payment_success_metas( int $payment_id, float $paid_amount, array $args = array() ): void {
		$args = wp_parse_args(
			$args,
			array(
				'payment_metadata'    => array(),
				'booking_metadata'    => array(),
				'send_booking_emails' => false,
				'send_payment_emails' => false,
			)
		);

		/** @var PaymentCalculator $calculator */
		$calculator = PaymentCalculator::for( $this->get_currency() );

		$gateway_fee = (string) ( $args['payment_metadata']['gateway_fee'] ?? '0.00' );

		$_payment            = null;
		$total_extra_charges = '0.00';
		$total_paid_amount   = (string) $paid_amount;
		$total_gateway_fee   = $gateway_fee;

		$payments = $this->get_payments();

		foreach ( $payments as $payment ) {
			if ( $payment->ID === $payment_id ) {
				$_payment = $payment;
			} elseif ( ! $payment->is_completed() ) {
				continue;
			} else {
				$total_paid_amount = $calculator->add( $total_paid_amount, (string) $payment->get_amount() );
			}
			$total_extra_charges = $calculator->add( $total_extra_charges, (string) $payment->get_cart_totals( 'total_extra_charges' ) );
			$total_gateway_fee   = $calculator->add( $total_gateway_fee, (string) $payment->get_gateway_fee() );
		}

		if ( ! ( $_payment instanceof Payment ) ) {
			throw new InvalidArgumentException( 'Payment ID #' . $payment_id . ' is not found in the booking #' . $this->get_id() . '.' );
		}

		$previous_payment_status = $_payment->get_payment_status();

		if ( apply_filters( 'wptravelengine_verify_payment_success_status', true, $this ) && 'completed' === $previous_payment_status ) {
			return;
		}

		$args['payment_metadata'] = is_array( $args['payment_metadata'] ) ? $args['payment_metadata'] : array();
		$args['booking_metadata'] = is_array( $args['booking_metadata'] ) ? $args['booking_metadata'] : array();

		$payable_amount = $calculator->subtract( (string) $_payment->get_payable_amount(), (string) $paid_amount );
		$paid_amount    = $calculator->add( (string) $paid_amount, $gateway_fee );

		$_payment_metas = array(
			'payable'              => array(
				'amount'   => $payable_amount,
				'currency' => $this->get_currency(),
			),
			'payment_amount'       => array(
				'value'    => $paid_amount,
				'currency' => $this->get_currency(),
			),
			'_prev_payment_status' => $previous_payment_status,
			'payment_status'       => 'completed',
			'gateway_fee'          => $gateway_fee,
		);

		$_payment->sync_metas( array_merge( $args['payment_metadata'], $_payment_metas ) );

		$actual_total_paid_amount = $calculator->add( $total_paid_amount, $gateway_fee );
		$total_paid_amount        = $calculator->subtract( $actual_total_paid_amount, (string) $this->get_refunded_amount() );

		$total_due_amount = $calculator->subtract(
			$calculator->add( (string) $this->get_total(), $total_extra_charges ),
			$calculator->subtract( $actual_total_paid_amount, $total_gateway_fee )
		);

		$_booking_metas = array(
			'total_paid_amount'                       => $total_paid_amount,
			'actual_total_paid_amount'                => $actual_total_paid_amount,
			'total_due_amount'                        => $total_due_amount,
			'_prev_booking_status'                    => $this->get_booking_status(),
			'wp_travel_engine_booking_payment_status' => $calculator->is( $total_due_amount, '>', '0.00' ) ? 'partially-paid' : 'completed',
			'wp_travel_engine_booking_status'         => 'booked',
		);

		$this->sync_metas( array_merge( $args['booking_metadata'], $_booking_metas ) );

		if ( $args['send_booking_emails'] ) {
			wptravelengine_send_booking_emails( $payment_id, 'order', 'all' );
		}

		if ( $args['send_payment_emails'] ) {
			wptravelengine_send_booking_emails( $payment_id, 'order_confirmation', 'all' );
		}

		Events::add_event( 'wptravelengine.booking.payment.completed', $_payment->get_id(), $_payment->get_post_type() );
	}

	/**
	 * Syncs metas to the Payment and Booking posts.
	 *
	 * @param int   $payment_id The payment ID.
	 * @param float $amount The amount received from the payment gateway response.
	 * @param array $args The arguments with payment and booking IDs and its respective metadata to sync.
	 * {
	 *  'payment_metadata' => array<mixed>,
	 *  'booking_metadata' => array<mixed>,
	 * }
	 * @return void
	 * @since 6.7.0
	 * @since 6.7.1 Added support for payment event trigger and _prev_payment_status updation.
	 */
	public function sync_payment_pending_metas( int $payment_id, float $amount, array $args = array() ): void {
		$_payment = $this->get_payment_by_id( $payment_id );

		if ( ! ( $_payment instanceof Payment ) ) {
			throw new InvalidArgumentException( 'Payment ID #' . $payment_id . ' is not found in the booking #' . $this->get_id() . '.' );
		}

		$args = wp_parse_args(
			$args,
			array(
				'payment_metadata' => array(),
				'booking_metadata' => array(),
			)
		);

		$previous_payment_status = $_payment->get_payment_status();

		$_payment_metas = array(
			'payable'              => array(
				'currency' => $this->get_currency(),
				'amount'   => $amount,
			),
			'payment_status'       => 'pending',
			'_prev_payment_status' => $previous_payment_status,
			'gateway_fee'          => '0.00',
		);

		$_payment->sync_metas( array_merge( $args['payment_metadata'], $_payment_metas ) );

		$payment_gateway  = $_payment->get_payment_gateway();
		$reserve_gateways = array( 'booking_only', 'direct_bank_transfer', 'check_payments' );

		$_booking_metas = array(
			'_prev_booking_status'                    => $this->get_booking_status(),
			'wp_travel_engine_booking_payment_status' => 'pending',
			'wp_travel_engine_booking_status'         => in_array( $payment_gateway, $reserve_gateways ) ? 'reserved' : 'pending',
		);

		$this->sync_metas( array_merge( $args['booking_metadata'], $_booking_metas ) );

		if ( 'pending' !== $previous_payment_status ) {
			Events::add_event( 'wptravelengine.booking.payment.pending', $_payment->get_id(), $_payment->get_post_type() );
		}
	}

	/**
	 * Syncs metas to the Payment and Booking posts.
	 *
	 * @param int   $payment_id The payment ID.
	 * @param float $amount The amount received from the payment gateway response.
	 * @param array $args The arguments with payment and booking IDs and its respective metadata to sync.
	 * {
	 *  'payment_metadata' => array<mixed>,
	 *  'booking_metadata' => array<mixed>,
	 * }
	 * @return void
	 * @since 6.7.0
	 * @since 6.7.1 Added support for payment event trigger and _prev_payment_status updation.
	 */
	public function sync_payment_failed_metas( int $payment_id, float $amount, array $args = array() ): void {

		$_payment = $this->get_payment_by_id( $payment_id );

		if ( ! ( $_payment instanceof Payment ) ) {
			throw new InvalidArgumentException( 'Payment ID #' . $payment_id . ' is not found in the booking #' . $this->get_id() . '.' );
		}

		$args = wp_parse_args(
			$args,
			array(
				'payment_metadata' => array(),
				'booking_metadata' => array(),
			)
		);

		$previous_payment_status = $_payment->get_payment_status();

		$_payment_metas = array(
			'payable'              => array(
				'currency' => $this->get_currency(),
				'amount'   => $amount,
			),
			'payment_status'       => 'failed',
			'_prev_payment_status' => $previous_payment_status,
			'gateway_fee'          => '0.00',
		);

		$_payment->sync_metas( array_merge( $args['payment_metadata'], $_payment_metas ) );

		$last_payment = $this->get_last_payment();
		$is_current   = $last_payment instanceof Payment && $last_payment->get_id() === $payment_id;

		$_booking_metas = array(
			'wp_travel_engine_booking_payment_status' => 'failed',
		);

		if ( $is_current ) {
			$_booking_metas['_prev_booking_status']            = $this->get_booking_status();
			$_booking_metas['wp_travel_engine_booking_status'] = 'pending';
		}

		$this->sync_metas( array_merge( $args['booking_metadata'], $_booking_metas ) );

		if ( 'failed' !== $previous_payment_status ) {
			Events::add_event( 'wptravelengine.booking.payment.failed', $_payment->get_id(), $_payment->get_post_type() );
		}
	}

	/**
	 * Recalculates cart totals, the paid/due meta and the payment status, flushed in one save.
	 *
	 * @return Booking
	 * @since 6.9.0
	 */
	public function sync_totals(): Booking {
		return $this->with_delayed_save(
			function ( Booking $booking ): void {
				/** @var CartInfoParser $cart_info */
				$cart_info = $booking->cart_info;

				$cart = $cart_info->recalculate_totals();
				$calc = PaymentCalculator::for( $booking->get_currency() );

				$booking->set_cart_info( $cart->data() );

				// A refunded payment was still taken, so it counts as collected and its refund comes off.
				$collected = '0.00';
				$deposited = '0.00';
				foreach ( $booking->get_payments() as $payment ) {
					if ( ! $payment instanceof Payment || ! ( $payment->is_completed() || $payment->is_refunded() ) ) {
						continue;
					}

					$collected = $calc->add( $collected, (string) $payment->get_amount() );

					if ( $payment->is_completed() ) {
						$deposited = $calc->add( $deposited, (string) $payment->get_deposit() );
					}
				}

				$refunded   = $calc->sum( wp_list_pluck( $booking->get_refunds(), 'amount' ) );
				$total_paid = $calc->max( '0.00', $calc->subtract( $collected, $refunded ) );
				// A refund comes off what was paid, not off what the trip still owes.
				$total_due = $calc->max( '0.00', $calc->subtract( (string) $cart->get_total(), $deposited ) );

				$booking->set_refunded_amount( (float) $refunded );
				$booking->set_total_due_amount( (float) $total_due );
				$booking->set_total_paid_amount( (float) $total_paid );
				$booking->set_meta( 'actual_total_paid_amount', (float) $collected );

				if ( ! $calc->is_positive( $collected ) ) {
					$payment_status = 'pending';
				} elseif ( $calc->is_positive( $refunded ) && $calc->is( $refunded, '>=', $collected ) ) {
					$payment_status = 'refunded';
				} else {
					$payment_status = $calc->is_positive( $total_due ) ? 'partially-paid' : 'completed';
				}

				$booking->set_meta( 'wp_travel_engine_booking_payment_status', $payment_status );
			}
		);
	}

	/**
	 * Set payment gateway reference and link it to the booking.
	 *
	 * @param string $gateway Gateway slug.
	 * @param string $ref     Gateway-issued reference string.
	 * @since 6.8.0
	 */
	public function set_payment_gateway_ref( string $gateway, string $ref ): void {
		$this->set_meta( "wte_pg_{$gateway}_ref", $ref )
			->set_meta( 'wp_travel_engine_booking_payment_method', $gateway )
			->set_meta( 'wp_travel_engine_booking_payment_gateway', $gateway );
	}

	/**
	 * Default meta datas for the booking.
	 *
	 * @param array
	 * @since 6.8.0
	 */
	public static function default_metadatas(): array {
		return array(
			'wp_travel_engine_booking_payment_status' => 'pending',
			'wp_travel_engine_booking_payment_method' => __( 'N/A', 'wp-travel-engine' ),
			'billing_info'                            => array(),
			'cart_info'                               => array(
				'cart_total'   => 0,
				'cart_partial' => 0,
				'due'          => 0,
				'version'      => Cart::CURRENT_VERSION,
			),
			'payments'                                => array(),
			'paid_amount'                             => 0,
			'due_amount'                              => 0,
			'wp_travel_engine_booking_status'         => 'pending',
		);
	}

	/**
	 * Create a booking post with required default values.
	 *
	 * @param array $args {} Optional. Arguments for creating booking post. Default empty array.
	 *
	 * @since 6.8.0
	 */
	public static function create_booking( array $args = array() ): Booking {
		$booking_args = wp_parse_args(
			$args,
			array(
				'post_status' => 'publish',
				'post_type'   => 'booking',
				'post_title'  => 'booking',
				'meta_input'  => self::default_metadatas(),
			)
		);

		return parent::create_post( $booking_args );
	}
}
