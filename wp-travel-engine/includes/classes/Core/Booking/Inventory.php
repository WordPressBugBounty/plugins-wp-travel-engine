<?php
/**
 * Booking Inventory.
 *
 * @since 5.5.3
 */
namespace WPTravelEngine\Core\Booking;

use WPTravelEngine\Core\Cart\Item;

class Inventory {

	/**
	 * Positional pointers for cart_key parts (index 0 is the `cart` prefix).
	 *
	 * @since 6.8.4
	 */
	public const CART_KEY_POINTERS = array(
		'trip_id'    => 1,
		'package_id' => 2,
		'trip_date'  => 3,
		'trip_time'  => 4,
	);

	/**
	 * Constructor.
	 *
	 * @param int $trip_id Trip ID.
	 */
	public int $trip_id;

	/**
	 * Booking Object.
	 */
	protected $trip_booking;

	public function __construct( $trip_id ) {
		$this->trip_id = (int) $trip_id;
	}

	public function set( $key, $value ) {
		$this->{$key} = $value;
	}

	/**
	 * Get the Current Trip Inventory Record.
	 *
	 * @since 6.0.0
	 * @return array
	 */
	public function get_inventory_record(): array {
		return $this->get_booking_inventory_record( $this->trip_id );
	}

	/**
	 * Resolve the original-language trip ID via WPML, falling back to the input if WPML is absent or returns null.
	 *
	 * @param int|string $trip_id Trip post ID (may be a translated post ID on WPML sites).
	 * @return int Original-language trip post ID, or the cast input as a fallback.
	 * @since 6.8.1 Added fallback trip_id
	 */
	public static function get_original_trip_id( $trip_id ): int {
		$original_id = apply_filters( 'wpml_object_id', (int) $trip_id, WP_TRAVEL_ENGINE_POST_TYPE, true, apply_filters( 'wpml_default_language', null ) );
		return (int) ( $original_id ?? $trip_id );
	}

	public function get_booking_inventory_record( $trip_id ) {
		$original_trip_id = self::get_original_trip_id( $trip_id );
		$dates            = get_post_meta( $original_trip_id, '_booking_inventory', true );
		if ( ! is_array( $dates ) ) {
			$dates = array();
		}
		return $dates;
	}

	/**
	 * Writes a trip's seat record and announces it, so caches built from the seats can be dropped.
	 *
	 * @param int                            $trip_id Trip ID.
	 * @param array<string, array<int, int>> $data    Seats held, by record key then booking ID.
	 *
	 * @return void
	 * @since 6.9.0 Fires `wptravelengine_booking_inventory_updated`.
	 */
	public function update_booking_inventory_record( int $trip_id, array $data = array() ): void {
		$original_trip_id = self::get_original_trip_id( $trip_id );

		update_post_meta( $original_trip_id, '_booking_inventory', $data );

		do_action( 'wptravelengine_booking_inventory_updated', $original_trip_id, $data );
	}

	/**
	 * The key a cart row's seats are recorded under: its cart ID, on the original-language trip and package.
	 *
	 * @param string $cart_id Cart row ID, `cart_{trip}_{package}_{Y-m-d}_{H-i}`.
	 * @return string Empty when the ID is not one.
	 * @since 6.9.0
	 */
	public static function record_key( string $cart_id ): string {
		$parts = explode( '_', $cart_id );

		if ( count( $parts ) < 5 ) {
			return '';
		}

		list( , $trip_id, $price_key, $trip_date, $trip_time ) = $parts;

		$trip_id = self::get_original_trip_id( $trip_id );

		if ( ! $trip_id ) {
			return '';
		}

		$original_package = get_post_meta( (int) $price_key, '_original_package_id', true );

		if ( ! empty( $original_package ) ) {
			$price_key = $original_package;
		}

		return "cart_{$trip_id}_{$price_key}_{$trip_date}_{$trip_time}";
	}

	/**
	 * Records how many seats a booking holds on one departure; zero releases them.
	 *
	 * @param string $date_key   Cart row ID the seats are held under.
	 * @param int    $pax        Seats held.
	 * @param int    $trip_id    Unused; the trip is read off the key.
	 * @param int    $booking_id Booking holding them.
	 * @return void
	 * @since 6.9.0 Normalizes the key through record_key().
	 */
	public function update_pax( $date_key, $pax = 0, $trip_id = 0, $booking_id = 0 ) {
		if ( ! $booking_id || ! $date_key ) {
			return;
		}

		$date_key = self::record_key( (string) $date_key );

		if ( '' === $date_key ) {
			return;
		}

		$trip_id = (int) explode( '_', $date_key )[1];

		$dates = $this->get_booking_inventory_record( $trip_id );
		if ( $pax <= 0 ) {
			unset( $dates[ $date_key ][ $booking_id ] );
		} else {
			$dates[ $date_key ][ $booking_id ] = $pax;
		}
		$this->update_booking_inventory_record( $trip_id, $dates );
	}

	/**
	 * Update Inventory by Booking.
	 */
	public function update_inventory_by_booking( $booking ) {
		$items = get_post_meta( $booking->ID, 'order_trips', true );

		foreach ( $items as $cart_key => $item ) {
			$item    = (object) $item;
			$trip_id = $item->ID;

			$pax = 0;
			if ( is_array( $item->pax ) ) {
				$pax = array_sum( $item->pax );
			}

			if ( $pax <= 0 ) {
				continue;
			}

			$this->update_pax( $cart_key, $pax, $trip_id, $booking->ID );
		}
	}

	/**
	 * @param $cart_key
	 */
	public static function get_date_from_cart_key( $cart_key ) {
		preg_match( '/(cart)_(\d+)_(\d+)_([\d-]+)_([\d-]+)/', $cart_key, $chunks );
		$datetime = new \DateTime();
		$datetime->setTimezone( new \DateTimeZone( 'utc' ) );

		// Validate and parse date.
		$date_parts = explode( '-', $chunks[4] ?? '' );
		if ( count( $date_parts ) === 3 ) {
			$datetime->setDate( $date_parts[0], $date_parts[1], $date_parts[2] );
		}

		// Validate and parse time.
		$time_parts = explode( '-', $chunks[5] ?? '' );
		if ( count( $time_parts ) === 2 ) {
			$datetime->setTime( $time_parts[0], $time_parts[1] );
		}

		return $datetime;
	}

	public static function booking_inventory( $data, $trip_id ) {

		$original_trip_id = self::get_original_trip_id( $trip_id );

		$instance   = new self( $trip_id );
		$inventory  = $instance->get_booking_inventory_record( $original_trip_id );
		$_inventory = array();
		if ( is_array( $inventory ) ) {
			foreach ( $inventory as $cart_id => $pax ) {
				$datetime                 = self::get_date_from_cart_key( $cart_id );
				$timestamp                = $datetime->getTimestamp();
				$_inventory[ $timestamp ] = array(
					'booked'  => array_sum( $pax ),
					'datestr' => $timestamp,
				);
				$pattern                  = '/cart_\d+_(\d+)_\d{4}-\d{2}-\d{2}_\d{2}-\d{2}/';
				$package                  = 0;
				if ( preg_match( $pattern, $cart_id, $matches ) ) {
					$package = $matches[1];
				}

				// Added package specific data to fix multiple package with same date issue.
				$_inventory[ $timestamp + $package ] = array(
					'booked'  => array_sum( $pax ),
					'datestr' => $timestamp + $package,
				);
			}
		}

		$old_booking_record = get_post_meta( $original_trip_id, 'wte_fsd_booked_seats', true );
		if ( is_array( $old_booking_record ) ) {
			$_inventory = array_column( array_merge( $old_booking_record, $_inventory ), null, 'datestr' );
		}

		return $_inventory;
	}

	/**
	 *
	 * @since 6.0.0
	 * @return array
	 */
	public function inventory(): array {
		$original_trip_id = self::get_original_trip_id( $this->trip_id );

		$inventory_record = $this->get_booking_inventory_record( $original_trip_id );

		$_records = array();
		if ( is_array( $inventory_record ) ) {
			foreach ( $inventory_record as $cart_id => $pax ) {
				$pattern = '/cart_(\d+)_(\d+)_(\d{4}-\d{2}-\d{2})_(\d{2}-\d{2})/';
				preg_match( $pattern, $cart_id, $matches );

				if ( count( $matches ) > 4 ) {
					list( $cart_key, $trip_id, $package_id, $trip_date, $trip_time )              = $matches;
					$_records[ $package_id ][ $trip_date ][ str_replace( '-', ':', $trip_time ) ] = array_sum( $pax );
				}
			}
		}

		return $_records;
	}

	/**
	 * Get the invetory of only given ids.
	 *
	 * @param array $ids IDs to filter.
	 *
	 * @return array
	 * @since 6.6.7
	 */
	public function inventory_of_( array $ids ): array {
		return array_intersect_key( $this->inventory(), $ids );
	}

	/**
	 * Gets the cart-item key for a package on a given date/time.
	 *
	 * @param int    $package_id Package ID.
	 * @param string $date_time  `Y-m-d` or `Y-m-dTH:i`.
	 *
	 * @return string
	 * @since 6.8.4
	 */
	public function get_cart_key( int $package_id, string $date_time ): string {
		$parts = explode( 'T', $date_time );

		if ( ! empty( $parts[1] ) ) {
			$trip_date = $parts[0];
			$trip_time = "{$trip_date} {$parts[1]}";
		} else {
			$trip_date = $date_time;
			$trip_time = '';
		}

		return Item::get_item_id( $this->trip_id, $package_id, $trip_time, $trip_date );
	}

	/**
	 * Dissects a cart_key, plucking out a single named limb.
	 *
	 * cart_key shape: `cart_{trip_id}_{package_id}_{trip_date}_{trip_time}`.
	 *
	 * @param string $cart_key `cart_...` key.
	 * @param string $target   `trip_id`, `package_id`, `trip_date`, or `trip_time`.
	 *
	 * @return string|null
	 * @since 6.8.4
	 */
	public static function pick_cart_key( string $cart_key, string $target ): ?string {
		$parts = explode( '_', $cart_key );

		return $parts[ self::CART_KEY_POINTERS[ $target ] ?? -1 ] ?? null;
	}

	/**
	 * Whether a key has the full `cart_{trip}_{package}_{date}_{time}` shape.
	 *
	 * A four-part key makes `pick_cart_key()` return the date where the package ID belongs.
	 *
	 * @param string $cart_key Key to test.
	 * @return bool
	 * @since 6.9.0
	 */
	public static function is_cart_key( string $cart_key ): bool {
		$parts = explode( '_', $cart_key );

		return 5 === count( $parts ) && 'cart' === $parts[0];
	}

	/**
	 * Pax booked against a package on a date, optionally ignoring one booking.
	 *
	 * Rows are matched, not rebuilt: `update_pax()` remaps the package and the stored key
	 * carries the booked time slot, so a key composed from the date alone misses time-slot
	 * trips. Slots on one date are summed, as `get_booked_per_cat_date()` also does.
	 *
	 * @param int    $package_id         Package ID as it appears on the booking.
	 * @param string $date               Y-m-d.
	 * @param int    $exclude_booking_id Booking to omit. 0 counts every booking.
	 * @return int
	 * @since 6.9.0
	 */
	public function pax_on_date( int $package_id, string $date, int $exclude_booking_id = 0 ): int {
		$original = get_post_meta( $package_id, '_original_package_id', true );
		$package  = empty( $original ) ? $package_id : (int) $original;
		$total    = 0;

		foreach ( $this->get_inventory_record() as $cart_key => $pax ) {
			$cart_key = (string) $cart_key;

			if ( ! is_array( $pax ) || ! self::is_cart_key( $cart_key ) ) {
				continue;
			}

			if ( (int) self::pick_cart_key( $cart_key, 'package_id' ) !== $package ) {
				continue;
			}

			if ( self::pick_cart_key( $cart_key, 'trip_date' ) !== $date ) {
				continue;
			}

			if ( $exclude_booking_id ) {
				unset( $pax[ $exclude_booking_id ] );
			}

			$total += array_sum( $pax );
		}

		return (int) $total;
	}
}
