<?php
/**
 * Class Coupons.
 *
 * This class handles overall functionality of coupons.
 *
 * @since 5.8.3
 */

namespace WPTravelEngine\Core;

/**
 * WP Travel Engine Coupons Class.
 */
class Coupons {

	/**
	 * Whether at least one active coupon exists site-wide for the checkout form to render.
	 *
	 * Trip-id restriction, trip-date window, minimum spend etc. are enforced when the
	 * coupon is actually applied (see ApplyCoupon controller). Hiding the form based on
	 * restricted_trips here prevented users on non-restricted-trip pages from seeing the
	 * form even when they had a valid code for another trip; we now only gate on
	 * coupon-level validity (status, usage cap, start/expiry window).
	 *
	 * @since 6.7.1 Refined the query to improve performance.
	 * @since 6.8.2  Stopped gating on restricted_trips so the form always appears when any active coupon exists.
	 */
	public static function is_coupon_available() {
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

			return true;
		}

		return false;
	}
}
