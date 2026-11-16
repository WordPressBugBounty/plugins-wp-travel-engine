<?php
/**
 * Create Booking logic.
 *
 * @package WPTravelEngine/Core/Booking
 * @since 6.9.0
 */

namespace WPTravelEngine\Core\Booking;

use WP_Error;
use Throwable;
use RuntimeException;
use WP_REST_Request;
use WPTravelEngine\Core\Tax;
use WPTravelEngine\Core\Cart\Item;
use WPTravelEngine\Core\Models\Post\Trip;
use WPTravelEngine\Core\Models\Post\Booking;

/**
 * Creates a booking from a payload in the update route's shape.
 *
 * A new booking has no trip row for `process_update()` to write to, so one is seeded from
 * `trip` first; everything after that is the update path, unchanged.
 *
 * @since 6.9.0
 */
class CreateBooking extends SaveBooking {

	/**
	 * Creates a booking from a payload in the update shape.
	 *
	 * Everything is checked before anything is written, and whatever was written - booking,
	 * custom trip, manual package - is removed again if a later step throws.
	 *
	 * @param WP_REST_Request $request Request carrying the create payload.
	 * @return CreateBooking|WP_Error
	 * @throws RuntimeException When a post the booking needs cannot be created.
	 * @throws Throwable         When writing fails after validation; the caller reports it.
	 */
	public static function create( WP_REST_Request $request ) {
		$trip = self::resolve_trip( (array) $request['trip'] );

		if ( is_wp_error( $trip ) ) {
			return $trip;
		}

		$created = array();
		$discard = static function () use ( &$created ): void {
			foreach ( array_reverse( $created ) as $post_id ) {
				wp_delete_post( $post_id, true );
			}
		};

		try {
			if ( ! $trip['trip'] ) {
				$trip['trip'] = Trip::create( $trip['title'] );

				if ( ! $trip['trip'] ) {
					throw new RuntimeException( 'Custom trip could not be created.' );
				}

				$created[] = $trip['trip']->ID;
			}

			if ( ! $trip['package_id'] ) {
				$trip['package_id'] = $trip['trip']->create_manual_package(
					array(
						'name'   => $trip['package_name'],
						'prices' => self::category_prices( (array) $request['items'] ),
					)
				);

				if ( ! $trip['package_id'] ) {
					throw new RuntimeException( 'Manual package could not be created.' );
				}

				$created[] = $trip['package_id'];
			}

			$booking = Booking::create_booking( array( 'post_status' => 'auto-draft' ) );

			if ( ! $booking->ID ) {
				throw new RuntimeException( 'Booking post could not be created.' );
			}

			$created[] = $booking->ID;

			$booking->sync_metas(
				array(
					'__is_manual'  => 'yes',
					'cart_version' => '4.0',
				)
			);

			$save = new static( $booking->ID, true );

			$save->is_new_booking = true;

			$save->seed_trip( $trip );

			// Saved before the processors run: a payment opened among them reads its currency off the stored cart.
			$save->booking->save();

			$result = $save->process_update( $request );

			// A file that breaks its field's rules is refused before anything is written to the booking.
			if ( is_wp_error( $result ) ) {
				$discard();

				return $result;
			}
		} catch ( Throwable $e ) {
			$discard();

			throw $e;
		}

		return $save;
	}

	/**
	 * Checks the `trip` param of a create payload and resolves what it names.
	 *
	 * A trip or package ID of 0 asks for a custom one, named by `title` / `package_name`.
	 *
	 * @param array<string, mixed> $param The `trip` param.
	 * @return array{trip: ?Trip, title: string, package_id: int, package_name: string, start: string, end: string}|WP_Error
	 */
	protected static function resolve_trip( array $param ) {
		$trip_id    = absint( $param['id'] ?? 0 );
		$package_id = absint( $param['package_id'] ?? 0 );
		$start      = (string) ( $param['start_datetime'] ?? '' );
		$end        = (string) ( $param['end_datetime'] ?? '' );

		$resolved = array(
			'trip'         => null,
			'title'        => sanitize_text_field( (string) ( $param['title'] ?? '' ) ),
			'package_id'   => $package_id,
			'package_name' => sanitize_text_field( (string) ( $param['package_name'] ?? '' ) ),
			'start'        => $start,
			'end'          => $end,
		);

		if ( ! self::is_datetime( $start ) || ( '' !== $end && ! self::is_datetime( $end ) ) ) {
			return new WP_Error( 'rest_invalid_param', __( 'The departure dates are not valid.', 'wp-travel-engine' ), array( 'status' => 400 ) );
		}

		if ( $trip_id ) {
			$resolved['trip'] = WP_TRAVEL_ENGINE_POST_TYPE === get_post_type( $trip_id ) ? wptravelengine_get_trip( $trip_id ) : null;

			if ( ! $resolved['trip'] ) {
				return new WP_Error( 'rest_invalid_param', __( 'That trip does not exist.', 'wp-travel-engine' ), array( 'status' => 400 ) );
			}

			$resolved['title'] = $resolved['trip']->get_title();
		} elseif ( '' === $resolved['title'] ) {
			return new WP_Error( 'rest_invalid_param', __( 'A custom trip needs a title.', 'wp-travel-engine' ), array( 'status' => 400 ) );
		}

		if ( $package_id ) {
			if ( ! $trip_id || ! Trip::package_exists( $trip_id, $package_id, true ) ) {
				return new WP_Error( 'rest_invalid_param', __( 'That package does not belong to the trip.', 'wp-travel-engine' ), array( 'status' => 400 ) );
			}

			$resolved['package_name'] = get_the_title( $package_id );
		} elseif ( '' === $resolved['package_name'] ) {
			$resolved['package_name'] = __( 'Custom Package', 'wp-travel-engine' );
		}

		return $resolved;
	}

	/**
	 * Whether a value is a departure as the payload states one: `Y-m-d` or `Y-m-dTH:i`.
	 *
	 * @param string $value Value to check.
	 * @return bool
	 */
	protected static function is_datetime( string $value ): bool {
		$format = 10 === strlen( $value ) ? 'Y-m-d' : 'Y-m-d\TH:i';
		$parsed = \DateTime::createFromFormat( $format, $value );

		return $parsed && $value === $parsed->format( $format );
	}

	/**
	 * Prices of the `pricing_category` rows, keyed by category ID, for a manual package.
	 *
	 * @param array<int, array<string, mixed>> $groups The `items` param.
	 * @return array<int, float>
	 */
	protected static function category_prices( array $groups ): array {
		$prices = array();

		foreach ( $groups as $group ) {
			if ( 'pricing_category' !== ( $group['id'] ?? '' ) ) {
				continue;
			}

			foreach ( (array) ( $group['items'] ?? array() ) as $row ) {
				$prices[ absint( $row['id'] ?? 0 ) ] = max( 0, (float) ( $row['price'] ?? 0 ) );
			}
		}

		unset( $prices[0] );

		return $prices;
	}

	/**
	 * Writes the one trip row a new booking starts from, with no line items yet.
	 *
	 * @param array{trip: Trip, package_id: int, package_name: string, start: string, end: string} $trip Resolved `trip` param.
	 * @return void
	 */
	protected function seed_trip( array $trip ): void {
		$start = $trip['start'];
		$end   = $trip['end'];

		if ( '' === $end ) {
			$format = 10 === strlen( $start ) ? 'Y-m-d' : 'Y-m-d\TH:i';
			$end    = wptravelengine_format_trip_end_datetime( $start, $trip['trip'], $format );
		}

		$item = array(
			'id'                    => Item::get_item_id( $trip['trip']->ID, $trip['package_id'], $start, substr( $start, 0, 10 ) ),
			'trip_id'               => $trip['trip']->ID,
			'price_key'             => $trip['package_id'],
			'package_name'          => $trip['package_name'],
			'trip_price'            => 0,
			'trip_date'             => substr( $start, 0, 10 ),
			'trip_time'             => $start,
			'trip_end_date'         => substr( $end, 0, 10 ),
			'trip_end_time'         => $end,
			'trip_time_range'       => array( $start, $end ),
			'datetime'              => substr( $start, 0, 10 ),
			'pax'                   => array(),
			'pax_cost'              => array(),
			'travelers'             => array(),
			'multi_pricing_used'    => false,
			'trip_extras'           => array(),
			'subtotal_reservations' => array(),
			'line_items'            => array(),
		);

		$this->set_cart_info(
			array(
				'version'          => '4.0',
				'currency'         => wptravelengine_settings()->get( 'currency_code', 'USD' ),
				'totals'           => array(
					'subtotal' => 0,
					'total'    => 0,
				),
				'deductible_items' => array(),
				'fees'             => array(),
				'items'            => array( $item ),
			)
		);

		$this->booking->set_meta( 'trip_datetime', $start );

		$this->booking->set_meta( 'tax_snapshot', ( new Tax() )->get_settings() );
	}

	/**
	 * Writes one blank traveler per seat counted, in the category it was counted under.
	 *
	 * Checkout collects a row per traveler; the wizard only counts them, so the booking would
	 * otherwise list nobody. The names are filled in later from the booking edit screen.
	 *
	 * @since 6.9.0
	 * @return void
	 */
	protected function seed_travelers(): void {
		if ( $this->booking->get_travelers() ) {
			return;
		}

		$items = $this->get_cart_info()->data()['items'] ?? array();
		$rows  = array();

		foreach ( (array) ( $items[0]['travelers'] ?? array() ) as $cat_id => $count ) {
			for ( $i = 0; $i < (int) $count; $i++ ) {
				$rows[] = array( 'cat_id' => (int) $cat_id );
			}
		}

		if ( $rows ) {
			$this->booking->set_traveller_details( $rows );
		}
	}

	/**
	 * Publishes the booking before its derived data is written and it is announced as created.
	 *
	 * @return void
	 */
	protected function finalize(): void {
		$booking = $this->booking;
		$name    = trim( $booking->get_billing_fname() . ' ' . $booking->get_billing_lname() );

		$this->seed_travelers();

		$booking->update_post(
			array(
				'post_status' => 'publish',
				/* translators: 1: customer name, 2: booking ID. */
				'post_title'  => sprintf( __( '%1$s #%2$d', 'wp-travel-engine' ), '' !== $name ? $name : __( 'Booking', 'wp-travel-engine' ), $booking->ID ),
			)
		);

		$booking->post->post_status = 'publish';

		parent::finalize();
	}
}
