<?php
/**
 * Class Coupons.
 *
 * This class handles overall functionality of coupons.
 *
 * @since 5.8.3
 */

namespace WPTravelEngine\Core;

use WPTravelEngine\Modules\CouponCode;

/**
 * WP Travel Engine Coupons Class.
 */
class Coupons {

	/**
	 * Whether at least one active coupon exists for the checkout form to render.
	 *
	 * Coupon-level validity (status, usage cap, start/expiry window) is always checked. When
	 * `$trip_ids` is given, a coupon also has to be unrestricted or restricted to one of those
	 * trips — otherwise the coupon field shows up at checkout with nothing that can be applied.
	 *
	 * @since 6.7.1 Refined the query to improve performance.
	 * @since 6.8.2 Stopped gating on restricted_trips so the form always appears when any active coupon exists.
	 * @since 6.9.0 Re-added trip-id gating via `$trip_ids`, scoped to the trips actually in the cart.
	 *
	 * @param array<int> $trip_ids Trip IDs to match against each coupon's restricted trips. Empty means site-wide.
	 *
	 * @return bool
	 */
	public static function is_coupon_available( array $trip_ids = array() ) {
		$args = array(
			'post_type'   => 'wte-coupon',
			'post_status' => 'publish',
			'numberposts' => -1,
			'meta_query'  => array(
				array(
					'key'     => 'wp_travel_engine_coupon_metas',
					'compare' => 'EXISTS',
				),
			),
		);

		$coupons = get_posts( $args );

		$today = wp_date( 'Y-m-d' );

		foreach ( $coupons as $coupon ) {
			$meta = get_post_meta( $coupon->ID, 'wp_travel_engine_coupon_metas', true );
			if ( ! is_array( $meta ) ) {
				continue;
			}

			// Usage count vs limit.
			$usage_count = (int) get_post_meta( $coupon->ID, 'wp_travel_engine_coupon_usage_count', true );
			$limit       = isset( $meta['restriction']['coupon_limit_number'] ) ? (int) $meta['restriction']['coupon_limit_number'] : 0;
			if ( $limit > 0 && $usage_count >= $limit ) {
				continue;
			}

			// Expiry date.
			$expiry = $meta['general']['coupon_expiry_date'] ?? '';
			if ( $expiry && $expiry < $today ) {
				continue;
			}

			// Start date.
			$start = $meta['general']['coupon_start_date'] ?? '';
			if ( $start && $start > $today ) {
				continue;
			}

			if ( ! empty( $trip_ids ) ) {
				$applies_to_cart = false;
				foreach ( $trip_ids as $trip_id ) {
					if ( CouponCode::coupon_can_be_applied( $coupon->ID, $trip_id ) ) {
						$applies_to_cart = true;
						break;
					}
				}
				if ( ! $applies_to_cart ) {
					continue;
				}
			}

			return true;
		}

		return false;
	}
}
