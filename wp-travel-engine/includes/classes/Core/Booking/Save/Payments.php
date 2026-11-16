<?php
/**
 * Saves the booking's payments, refunds included.
 *
 * @package WPTravelEngine/Core/Booking
 * @since 6.9.0
 */

namespace WPTravelEngine\Core\Booking\Save;

use WPTravelEngine\Filters\Events;
use WPTravelEngine\Utilities\ArrayUtility;
use WPTravelEngine\Core\Models\Post\Payment;
use WPTravelEngine\Utilities\PaymentCalculator;
use WPTravelEngine\Core\Booking\ViewBooking;

/**
 * Payment writers for SaveBooking, which holds the state they read.
 *
 * @since 6.9.0
 */
trait Payments {

	/**
	 * Queues an activity entry, written to the booking when the save completes.
	 *
	 * @param string $type     What kind of event it was, e.g. `payment`.
	 * @param string $category Section the event belongs to.
	 * @param string $text     What happened, as shown in the log.
	 * @return void
	 */
	abstract public function record_activity( string $type, string $category, string $text ): void;

	/**
	 * Writes one key of the booking's cart.
	 *
	 * @param string $key   Cart key to write, e.g. `fees` or `items`.
	 * @param mixed  $value What the key now holds.
	 * @return void
	 */
	abstract protected function sync_cart( string $key, $value ): void;

	/**
	 * An amount formatted for an activity entry.
	 *
	 * The log is plain text the client prints as-is, so the symbol's HTML entity (`&#36;`) is decoded.
	 *
	 * @param float|string $amount   Amount to format.
	 * @param string       $currency Currency code the amount is in.
	 * @return string
	 * @since 6.9.0
	 */
	protected function activity_price( $amount, string $currency ): string {
		$price = wptravelengine_the_price(
			(float) $amount,
			false,
			array(
				'use_html'      => false,
				'currency_code' => $currency,
			)
		);

		return html_entity_decode( (string) $price, ENT_QUOTES, 'UTF-8' );
	}

	/**
	 * Saves per-payment fields from the REST edit shape, which the payload is known to carry.
	 *
	 * Matched by `id`, not position: the payload carries only what changed. A row without one is
	 * created; a row naming an `id` this booking does not hold is skipped. A row carrying `remove`
	 * deletes its payment, unless money was taken on it: completed, or refunded since. A refund issued
	 * on its own took nothing, so it is deleted, and leaves the booking's refunds with it. On a booking
	 * the operator created by hand, any payment can be deleted.
	 *
	 * @return void
	 * @since 6.9.0
	 */
	protected function set_payments(): void {
		$existing = array();

		foreach ( $this->booking->get_payments() as $payment ) {
			if ( $payment instanceof Payment ) {
				$existing[ $payment->ID ] = $payment;
			}
		}

		// Held so a row that opened its own record reads as recorded rather than updated.
		$existing_before = $existing;

		foreach ( $this->request['payments'] as $data ) {
			$id         = (int) ( $data['id'] ?? 0 );
			$payment    = $existing[ $id ] ?? null;
			$is_request = (bool) ( $data['is_payment_request'] ?? false );

			if ( ! empty( $data['remove'] ) ) {
				$is_refund = $payment instanceof Payment && $payment->is_refunded() && ! $payment->get_amount();

				if ( $payment instanceof Payment && ( $is_refund || ( ! $payment->is_completed() && ! $payment->is_refunded() ) || $this->booking->is_manual() ) ) {
					$this->booking->set_meta( 'payments', array_values( array_diff( (array) $this->booking->get_payment_detail(), array( $payment->ID ) ) ) );
					wp_trash_post( $payment->ID );
					unset( $existing[ $id ] );

					if ( $is_refund ) {
						// Written through: sync_totals() re-sums the refunds later in this save.
						$this->booking->sync_metas(
							array(
								'refunds' => array_values(
									array_filter(
										$this->booking->get_refunds(),
										static fn ( $refund ) => (int) ( $refund['id'] ?? 0 ) !== $payment->ID
									)
								),
							)
						);
					}

					$this->record_activity(
						'payment',
						'payments',
						sprintf(
							$is_refund
								/* translators: %d: payment ID. */
								? __( 'Deleted refund #%d.', 'wp-travel-engine' )
								/* translators: %d: payment ID. */
								: __( 'Deleted payment #%d.', 'wp-travel-engine' ),
							$payment->ID
						)
					);
				}

				continue;
			}

			if ( ! $payment instanceof Payment ) {
				if ( $id ) {
					continue;
				}

				$payment                  = Payment::create( $this->booking->ID, compact( 'is_request' ) );
				$existing[ $payment->ID ] = $payment;
			}

			$cart_totals = $this->get_payment_totals( $payment, $data );

			if ( 'checkout' === $payment->get_payment_source() ) {
				if ( $payment->is_completed() ) {
					continue;
				}
				$payment->set_meta( 'is_checkout_payment_edited', true );
			} else {
				$payment->set_payment_source( 'admin' );
			}

			$status = isset( $data['status'] ) ? (string) $data['status'] : null;

			if ( null !== $status ) {
				$payment->set_status( $status );
			}
			if ( isset( $data['gateway'] ) ) {
				$payment->set_payment_gateway( (string) $data['gateway'] );
			}
			if ( isset( $data['transaction_id'] ) ) {
				$payment->set_transaction_id( (string) $data['transaction_id'] );
			}
			if ( isset( $data['date'] ) ) {
				$payment->set_transaction_date( (string) $data['date'] );
			}
			if ( isset( $data['remark'] ) ) {
				$payment->set_remark( (string) $data['remark'] );
			}

			if ( isset( $data['is_payment_request'] ) && $is_request !== $payment->is_payment_request() ) {
				$payment->set_payment_request( $is_request );
				if ( $is_request ) {
					$payment->set_amount( 0 );
				}
			}

			if ( ! empty( $data['paid_amount'] ) ) {
				$payment->set_amount( (float) $data['paid_amount'] );
			}

			if ( $is_request && ! empty( $data['payable_amount'] ) ) {
				$payment->set_payable_amount( $data['payable_amount'] );
			}

			if ( $is_request && ( isset( $data['deposit_type'] ) || isset( $data['deposit_value'] ) ) ) {
				$held = $payment->get_request_deposit();

				$payment->set_request_deposit(
					(string) ( $data['deposit_type'] ?? $held['type'] ),
					(string) ( $data['deposit_value'] ?? $held['value'] )
				);
			}

			$payment->set_meta( 'cart_totals', $cart_totals )->save();

			// A record opened by this call has already announced itself as created.
			if ( ! isset( $existing_before[ $payment->ID ] ) ) {
				$this->record_activity(
					'payment',
					'payments',
					sprintf(
						/* translators: 1: payment ID, 2: amount recorded. */
						__( 'Recorded payment #%1$d of %2$s.', 'wp-travel-engine' ),
						$payment->ID,
						$this->activity_price( $payment->get_amount(), $payment->get_currency() )
					)
				);

				continue;
			}

			Events::payment_updated( $payment );

			$labels = wptravelengine_get_labels( 'payment_status' );

			$this->record_activity(
				'payment',
				'payments',
				null === $status
					? sprintf(
						/* translators: %d: payment ID. */
						__( 'Updated payment #%d.', 'wp-travel-engine' ),
						$payment->ID
					)
					: sprintf(
						/* translators: 1: payment ID, 2: payment status. */
						__( 'Marked payment #%1$d as %2$s.', 'wp-travel-engine' ),
						$payment->ID,
						$labels[ $status ] ?? $status
					)
			);
		}

		if ( $this->fees ) {
			$this->sync_cart( 'fees', array_values( $this->fees ) );
		}
	}

	/**
	 * A payment's `cart_totals`, with the fees the payload writes against it folded in.
	 *
	 * Fees are written to their own metas, but checkout and the booking's totals read `cart_totals`,
	 * whose `total_extra_charges` decides what is left owing.
	 *
	 * @param Payment              $payment Payment whose totals are being worked out.
	 * @param array<string, mixed> $data    The payload row: `fees` keyed by the name the cart charges
	 *                                      each under, `deposit` what went towards the trip. Either
	 *                                      left out keeps what the payment already holds.
	 * @return array<string, mixed> The totals as they now stand.
	 * @since 6.9.0
	 */
	protected function get_payment_totals( Payment $payment, array $data = array() ): array {
		$calc    = PaymentCalculator::for( $payment->get_currency() );
		$totals  = ArrayUtility::to_array( $payment->get_cart_totals() );
		$fees    = ArrayUtility::to_array( $data['fees'] ?? array() );
		$deposit = $data['deposit'] ?? null;

		$extra = '0.00';

		// A new booking has no charged cart to read fees from, so take what its form config offers.
		$available = $this->is_new_booking
			? ViewBooking::form_field_fees( $this->booking, array(), true )
			: ViewBooking::get_available_fees( $this->booking->ID );

		foreach ( $available as $fee ) {
			$slug = sanitize_key( (string) ( $fee['name'] ?? '' ) );

			if ( '' === $slug ) {
				continue;
			}

			$key = 'total_' . $slug;

			if ( isset( $fees[ $slug ] ) ) {
				if ( is_numeric( $fees[ $slug ] ) ) {
					$this->fees[ $slug ] = $fee;
					$totals[ $key ]      = $calc->normalize( (string) max( 0, (float) $fees[ $slug ] ) );
				} else {
					unset( $totals[ $key ] );
				}
			}

			$extra = $calc->add( $extra, (string) ( $totals[ $key ] ?? 0 ) );
		}

		$totals['total_extra_charges'] = $extra;

		$totals['deposit'] = null === $deposit || '' === $deposit ? $calc->subtract( (string) $payment->get_amount(), $extra ) : $calc->normalize( (string) max( 0, (float) $deposit ) );

		$totals['payable_now'] = is_numeric( $data['payable_amount'] ?? '' ) ? $data['payable_amount'] : ( $data['paid_amount'] ?? null );

		return $totals;
	}

	/**
	 * Saves the refunds issued against the booking, which the payload is known to carry.
	 *
	 * Replaced wholesale, and the paid and due totals re-derived from it.
	 *
	 * @return void
	 * @since 6.9.0
	 */
	protected function set_refunds(): void {
		$dated  = wp_list_pluck( $this->booking->get_refunds(), 'date', 'id' );
		$before = $this->booking->get_refunded_amount();

		$calc    = PaymentCalculator::for( $this->booking->get_currency() );
		$refunds = array();
		$total   = '0.00';

		foreach ( (array) $this->request['refunds'] as $row ) {
			$amount = $calc->normalize( (string) ( $row['amount'] ?? 0 ) );

			// A refund of nothing is not a record worth keeping.
			if ( ! $calc->is( $amount, '>', '0.00' ) ) {
				continue;
			}

			$id = (string) ( $row['id'] ?? '' );

			// A new refund is a payment in the refunded status; its ID names the refund from here on.
			if ( '' === $id ) {
				$payment = Payment::create(
					$this->booking->ID,
					array(
						'status'         => 'refunded',
						'payable_amount' => 0,
						'meta_input'     => array(
							'refunded_amount'  => (float) $amount,
							'payment_gateway'  => (string) ( $row['method'] ?? '' ),
							'transaction_date' => current_time( 'mysql', true ),
						),
					)
				);

				$id = (string) $payment->ID;
			} else {
				$payment = $this->booking->get_payment_by_id( (int) $id );

				// An edited refund keeps its payment in step, which is what its row in Payment Details reads.
				if ( $payment instanceof Payment && $payment->is_refunded() && ! $payment->get_amount() ) {
					$payment->set_meta( 'refunded_amount', (float) $amount );
					$payment->set_payment_gateway( (string) ( $row['method'] ?? '' ) );
					$payment->save();
				}
			}

			$refunds[] = array(
				'id'     => $id,
				'amount' => (float) $amount,
				'reason' => (string) ( $row['reason'] ?? '' ),
				'method' => (string) ( $row['method'] ?? '' ),
				'note'   => (string) ( $row['note'] ?? '' ),
				'date'   => (string) ( $dated[ $id ] ?? current_time( 'mysql', true ) ),
			);

			$total = $calc->add( $total, $amount );
		}

		$this->booking->sync_metas( compact( 'refunds' ) );

		$before = $calc->normalize( (string) $before );

		if ( $calc->is( $total, '==', $before ) ) {
			return;
		}

		$this->record_activity(
			'refund',
			'payments',
			sprintf(
				/* translators: 1: amount refunded by this change, 2: total refunded on the booking. */
				__( 'Refunded %1$s, taking the booking to %2$s refunded.', 'wp-travel-engine' ),
				$this->activity_price( $calc->abs( $calc->subtract( $total, $before ) ), $this->booking->get_currency() ),
				$this->activity_price( $total, $this->booking->get_currency() )
			)
		);
	}
}
