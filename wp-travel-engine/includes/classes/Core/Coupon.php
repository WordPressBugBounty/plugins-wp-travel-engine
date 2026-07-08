<?php
/**
 * Class Coupon
 *
 * This class represents a coupon in the WP Travel Engine plugin.
 *
 * @since 5.7.4
 */

namespace WPTravelEngine\Core;

/**
 * Class Coupon
 *
 * This class represents a coupon in the WP Travel Engine plugin.
 *
 * @since 5.7.4
 */
class Coupon {

	protected static string $post_type = 'wte-coupon';

	/**
	 * @var string
	 */
	public string $name;

	/**
	 * @var int
	 */
	public int $id;

	/**
	 * @var string
	 */
	public $code;

	/**
	 * @var array
	 */
	protected array $settings = array();

	/**
	 * @var string
	 * @since 6.0.0
	 */
	public $type = '';

	/**
	 * @var float
	 * @since 6.0.0
	 */
	public float $value = 0;

	public function __construct( int $coupon_id ) {
		$coupon = get_post( $coupon_id );
		if ( ! $coupon || $coupon->post_type !== static::$post_type ) {
			throw new \InvalidArgumentException( 'Invalid coupon ID' );
		}

		$settings = get_post_meta( $coupon_id, 'wp_travel_engine_coupon_metas', true );
		if ( is_array( $settings ) ) {
			$this->settings = $settings;
		}

		$this->id    = $coupon_id;
		$this->name  = get_the_title( $coupon_id );
		$this->code  = get_post_meta( $coupon_id, 'wp_travel_engine_coupon_code', true );
		$this->type  = $this->settings['general']['coupon_type'] ?? '';
		$this->value = $this->settings['general']['coupon_value'] ?? 0;
	}

	/**
	 * Retrieves a coupon object by its code.
	 *
	 * @param string $discount_code The code of the coupon to retrieve.
	 *
	 * @return \WP_Error|self Returns a coupon object if found, or a WP_Error object if not found.
	 *
	 * @since 6.8.2 Match is now case-sensitive; collation matches are filtered with a strict comparison.
	 */
	public static function by_code( string $discount_code ) {
		$args = array(
			'post_type'      => static::$post_type,
			'meta_query'     => array(
				array(
					'key'   => 'wp_travel_engine_coupon_code',
					'value' => $discount_code,
				),
			),
			'fields'         => 'ids',
			'posts_per_page' => -1,
		);

		// The meta_query comparison relies on the column collation, which is
		// case-insensitive by default. Fetch all collation matches and enforce a
		// strict, case-sensitive comparison below.
		$post_ids = get_posts( $args );

		foreach ( $post_ids as $post_id ) {
			if ( 0 !== strcmp( (string) get_post_meta( (int) $post_id, 'wp_travel_engine_coupon_code', true ), $discount_code ) ) {
				continue;
			}
			try {
				return new self( (int) $post_id );
			} catch ( \InvalidArgumentException $e ) {
				return new \WP_Error( 'coupon_not_found', __( 'Coupon not found', 'wp-travel-engine' ) );
			}
		}

		return new \WP_Error( 'coupon_not_found', __( 'Coupon not found', 'wp-travel-engine' ) );
	}

	public function is_active(): bool {
		return get_post_status( $this->id ) === 'publish';
	}

	public function start_date() {
		return $this->settings['general']['coupon_start_date'] ?? '';
	}

	public function expiry_date() {
		return $this->settings['general']['coupon_expiry_date'] ?? '';
	}

	public function limit() {
		return $this->settings['restriction']['coupon_limit_number'] ?? INF;
	}

	public function allowed_trips() {
		return $this->settings['restriction']['restricted_trips'] ?? array();
	}

	/**
	 * @since 6.8.2
	 */
	public function has_trip_date_restriction(): bool {
		return wptravelengine_toggled( $this->settings['general']['trip_date_restriction_enabled'] ?? 'no' );
	}

	/**
	 * @since 6.8.2
	 */
	public function trip_starts_after() {
		return $this->settings['general']['trip_starts_after'] ?? '';
	}

	/**
	 * @since 6.8.2
	 */
	public function trip_starts_before() {
		return $this->settings['general']['trip_starts_before'] ?? '';
	}

	/**
	 * Validate coupon against a trip's departure date.
	 *
	 * @since 6.8.2
	 */
	public function is_valid_for_trip_date( $trip_date ): bool {
		return wptravelengine_coupon_is_restriction_date(
			$this->has_trip_date_restriction(),
			$this->trip_starts_after(),
			$this->trip_starts_before(),
			$trip_date
		);
	}

	public function is_expired(): bool {
		$expiry_date = $this->expiry_date();
		if ( empty( $expiry_date ) ) {
			return false;
		}

		$expiry_date = strtotime( $expiry_date );
		if ( $expiry_date < time() ) {
			return true;
		}

		return false;
	}

	/**
	 * Checks if the discount coupon is valid for a given trip.
	 *
	 * @param int $trip_id Trip ID to check.
	 *
	 * @return bool
	 */
	public function is_valid_for_trip( int $trip_id ): bool {
		$allowed_trips = $this->allowed_trips();
		if ( empty( $allowed_trips ) ) {
			return true;
		}

		return in_array( $trip_id, $allowed_trips );
	}

	public function has_limit(): bool {
		return $this->limit() > 0;
	}

	public function calculated_value( $total ) {
		return static::calculate_value( $total, $this->type, $this->value );
	}

	/**
	 * Calculate discount amount by coupon type and value.
	 *
	 * @param float  $total
	 * @param string $coupon_type percentage|fixed
	 * @param float  $coupon_value
	 *
	 * @return float
	 * @since 6.0.0
	 */
	public static function calculate_value( $total, $coupon_type, $coupon_value ) {
		if ( $coupon_type === 'percentage' ) {
			return ( $total * $coupon_value ) / 100;
		}

		return $coupon_value;
	}

	/**
	 * @param $type
	 *
	 * @return void
	 * @since 6.0.0
	 */
	public function set_type( $type ) {
		$this->type = $type;
	}
}
