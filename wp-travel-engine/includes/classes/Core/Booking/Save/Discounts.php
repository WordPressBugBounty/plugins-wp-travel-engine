<?php
/**
 * Saves the discounts applied to a booking.
 *
 * @package WPTravelEngine/Core/Booking
 * @since 6.9.0
 */

namespace WPTravelEngine\Core\Booking\Save;

use WPTravelEngine\Core\Coupon;
use WPTravelEngine\Utilities\ArrayUtility;
use WPTravelEngine\Core\Cart\Adjustments\CouponAdjustment;
use WPTravelEngine\Core\Cart\Adjustments\BaseCartAdjustment;

/**
 * Discount writers for SaveBooking, which holds the state they read.
 *
 * @since 6.9.0
 */
trait Discounts {

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
	 * Writes the cart back to the booking, keeping the parsed copy in step.
	 *
	 * @param array<string, mixed> $cart The cart as it now stands.
	 * @return void
	 */
	abstract protected function set_cart_info( array $cart ): void;

	/**
	 * Applies the discounts the payload carries, and drops the ones it marks removed.
	 *
	 * `name` addresses the row being rewritten, so renaming a discount edits it rather than
	 * leaving a second behind. Without one the row is found by the name the terms derive, or
	 * appended.
	 *
	 * A percentage row takes its rate from `percentage`, not `value`: the projection reports
	 * `value` as what the deduction came to, so reading that would turn a 20% discount into a
	 * 129.2% one the moment a row read back was sent again. Totals are recomputed once at the
	 * end, since nothing a row does reads them.
	 *
	 * @return void
	 * @since 6.9.0
	 */
	protected function set_discounts(): void {
		$changed = false;

		$removals = array();
		$writes   = array();

		foreach ( ArrayUtility::to_array( $this->request['discounts'] ) as $row ) {
			$row = ArrayUtility::to_array( $row );

			if ( empty( $row['remove'] ) ) {
				$writes[] = $row;
			} else {
				$removals[] = $row;
			}
		}

		/*
		 * Removals go first whatever order they arrived in: one may be making way for a row
		 * added in the same call, and read the other way round it would take that new row
		 * straight back out.
		 */
		foreach ( array_merge( $removals, $writes ) as $row ) {
			$held = ArrayUtility::to_array( $this->cart_info->get( 'deductible_items', array() ) );

			$is_manual = ! empty( $row['is_manual'] );
			$raw_code  = trim( (string) ( ( $row['name'] ?? '' ) ?: ( $row['label'] ?? '' ) ) );
			$code      = sanitize_key( $raw_code );
			$name      = $is_manual ? $code : 'coupon';

			if ( ! empty( $row['remove'] ) ) {
				// A row being dropped is addressed by the name it is held under, which is what
				// the projection reports. Nothing is derived here: the terms are about to go.
				$name = sanitize_key( (string) ( $row['name'] ?? '' ) );
				$at   = ArrayUtility::get_key( $held, compact( 'name' ) );

				if ( null === $at ) {
					continue;
				}

				$label = (string) ( $held[ $at ]['label'] ?? $name );

				$this->cart_info->remove( "discounts.{$name}" );
				$this->cart_info->remove( "totals.total_{$name}" );

				unset( $held[ $at ] );

				$this->cart_info->set( 'deductible_items', array_values( $held ) );

				$this->record_activity(
					'booking',
					'discounts',
					sprintf(
						/* translators: %s: what the discount was called. */
						__( 'Removed the discount %s.', 'wp-travel-engine' ),
						$label
					)
				);

				$changed = true;
				continue;
			}

			$at = ArrayUtility::get_key( $held, compact( 'name' ) );

			$coupon = $is_manual ? null : Coupon::by_code( $raw_code );
			if ( is_wp_error( $coupon ) ) {
				continue;
			}

			if ( $coupon instanceof Coupon ) {
				$is_fixed = 'fixed' === $coupon->type;
				$figure   = (float) $coupon->value;

				$terms = array(
					'name'            => 'coupon',
					'label'           => $coupon->label(),
					'adjustment_type' => $coupon->type,
					'percentage'      => $is_fixed ? 0 : $figure,
					'value'           => $is_fixed ? $figure : 0,
					'_class_name'     => CouponAdjustment::class,
				);
			} else {
				$is_fixed = 'fixed' === ( $row['type'] ?? '' );
				$figure   = (float) ( $row['value'] ?? 0 );

				$terms = array(
					'name'            => $code,
					'label'           => (string) ( $row['label'] ?? '' ),
					'adjustment_type' => $is_fixed ? 'fixed' : 'percentage',
					'percentage'      => $is_fixed ? 0 : $figure,
					'value'           => $is_fixed ? $figure : 0,
					'_class_name'     => BaseCartAdjustment::class,
					'is_manual'       => 'yes',
				);
			}

			if ( '' === $name || $figure <= 0 ) {
				continue;
			}

			$at ??= ArrayUtility::get_key( $held, compact( 'name' ) ) ?? count( $held );

			$this->cart_info->set(
				"deductible_items.{$at}",
				array_merge(
					array(
						'order'                    => -1,
						'description'              => '',
						'apply_to_actual_subtotal' => false,
					),
					$terms
				)
			);

			$this->cart_info->set(
				"discounts.{$name}",
				array(
					'name'  => $name,
					'type'  => $terms['adjustment_type'],
					'value' => $figure,
				)
			);

			$this->cart_info->set( "totals.total_{$name}", $figure );

			$this->record_activity(
				'booking',
				'discounts',
				sprintf(
					/* translators: %s: what the discount is called. */
					__( 'Applied the discount %s.', 'wp-travel-engine' ),
					$terms['label']
				)
			);

			$changed = true;
		}

		if ( $changed ) {
			$this->set_cart_info( $this->cart_info->data() );
		}
	}
}
