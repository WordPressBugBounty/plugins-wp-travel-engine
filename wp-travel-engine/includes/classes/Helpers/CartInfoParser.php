<?php
/**
 * Cart Info Parser.
 *
 * @since 6.4.0
 */

namespace WPTravelEngine\Helpers;

use WPTravelEngine\Traits\Factory;
use WPTravelEngine\Utilities\ArrayUtility;
use WPTravelEngine\Utilities\PaymentCalculator;

class CartInfoParser {

	/**
	 * Gives the parser `for( $booking_id )`, so one booking's cart is parsed once.
	 *
	 * @since 6.9.0
	 */
	use Factory {
		for as private factory_for;
		refresh as private factory_refresh;
	}

	/**
	 * The cart itself, which `get()` and `set()` reach through in dot notation.
	 *
	 * @var ArrayUtility
	 * @since 6.9.0
	 */
	protected ArrayUtility $cart;

	/**
	 * @var array
	 */
	protected array $totals = array();

	/**
	 * @var array
	 */
	protected array $deductible_items = array();

	/**
	 * @var array
	 */
	protected array $fees = array();

	/**
	 * @var array|mixed
	 */
	protected array $items = array();

	/**
	 * Lazily built `BookedItem` objects, null until `get_items()` runs.
	 *
	 * @var BookedItem[]|null
	 * @since 6.9.0
	 */
	protected ?array $parsed_items = null;

	/**
	 * @var string
	 */
	protected string $currency;

	/**
	 * Stores the cart fees by type.
	 *
	 * @var array
	 * @since 6.7.0
	 */
	protected array $fees_by_type = array();

	/**
	 * Stores the cart version.
	 *
	 * @var string
	 * @since 6.7.0
	 */
	public string $version = '3.0';

	/**
	 * Parses the given cart info.
	 *
	 * @param array $data Cart info.
	 * @return void
	 * @since 6.9.0 Keeps the cart as an ArrayUtility; the version is read in parse().
	 */
	public function __construct( array $data ) {
		$this->cart = ArrayUtility::make( $data );

		$this->parse( $data );
	}

	/**
	 * Reads totals, deductions, fees, items and currency from the cart.
	 *
	 * @param array $data Cart info.
	 * @return void
	 * @since 6.9.0 Resets fees by type and cached items and reads the version, so it can re-run after set() or remove().
	 */
	protected function parse( $data ) {
		$this->fees_by_type     = array();
		$this->parsed_items     = null;
		$this->version          = $data['version'] ?? '3.0';
		$this->totals           = $data['totals'] ?? array();
		$this->deductible_items = $this->parse_deductible_items( $data );
		$this->fees             = $this->parse_fees( $data );
		$this->items            = $data['items'] ?? array();
		$this->currency         = $data['currency'] ?? '';
	}

	protected function parse_deductible_items( $data ) {
		if ( isset( $data['deductible_items'] ) ) {
			return $data['deductible_items'];
		}

		if ( ! empty( $data['discounts'] ) ) {
			return array_map(
				function ( $item ) {
					return array(
						'name'                     => 'coupon',
						'order'                    => '-1',
						'label'                    => $item['name'],
						'description'              => '',
						'adjustment_type'          => $item['type'],
						'apply_to_actual_subtotal' => true,
						'percentage'               => $item['value'],
						'value'                    => $this->totals['discount_total'] ?? 0,
					);
				},
				$data['discounts']
			);
		}

		return array();
	}

	/**
	 * Parses the fees.
	 *
	 * @param mixed $data
	 * @return array
	 * @updated 6.7.0
	 * @since 6.9.0 An empty fees list falls back to the legacy tax_amount fee.
	 */
	protected function parse_fees( $data ) {
		if ( ! empty( $data['fees'] ) ) {
			$unique_fees = array();
			foreach ( $data['fees'] as $fee ) {
				$apply_tax = $fee['apply_tax'] ?? true;
				if ( 'tax' === $fee['name'] ) {
					$this->fees_by_type['tax'] = $fee;
				} elseif ( $apply_tax ) {
					$this->fees_by_type['tax_inclusive'][] = $fee;
				} else {
					$this->fees_by_type['tax_exclusive'][] = $fee;
				}
				$unique_fees[ $fee['name'] ] = $fee;
			}

			return array_values( $unique_fees );
		}

		if ( ! empty( $data['tax_amount'] ) ) {
			return array(
				array(
					'name'                     => 'tax',
					'order'                    => '-1',
					'label'                    => __( 'Tax', 'wp-travel-engine' ),
					'description'              => '',
					'adjustment_type'          => 'percentage',
					'apply_to_actual_subtotal' => false,
					'percentage'               => $data['tax_amount'],
					'value'                    => $this->totals['total_tax'] ?? 0,
				),
			);
		}

		return array();
	}

	/**
	 * @param string|null $key
	 *
	 * @return array|float|null
	 */
	public function get_totals( ?string $key = null ) {
		if ( $key ) {
			$value = $this->totals[ $key ] ?? 0;
			return is_numeric( $value ) ? round( $value, 2 ) : 0;
		}

		return $this->totals;
	}

	/**
	 * Deductions on the cart, each carrying what it came to.
	 *
	 * @param array<mixed>|null $keys Keys to keep from each row; null keeps the whole row.
	 *
	 * @return array<int, array<string, mixed>> Deductions.
	 * @since 6.9.0 Takes the keys to keep.
	 */
	public function get_deductible_items( ?array $keys = null ): array {
		$mask  = null === $keys ? null : ArrayUtility::key_mask( $keys );
		$items = array();

		foreach ( $this->deductible_items as $index => $item ) {
			$item['value']   = $this->get_totals( 'total_' . $item['name'] );
			$items[ $index ] = null === $mask ? $item : array_intersect_key( $item, $mask );
		}

		return $items;
	}

	public function get_fees(): array {
		return array_map(
			function ( $item ) {
				if ( ! isset( $item['value'] ) ) {
					$item['value'] = $this->get_totals( 'total_' . $item['name'] );
				}
				return $item;
			},
			$this->fees
		);
	}

	/**
	 * Booked items in the cart.
	 *
	 * @return BookedItem[]
	 * @since 6.9.0 Items are built once and reused; each one loads a Trip, so re-parsing per call was an N+1.
	 */
	public function get_items(): array {
		if ( null === $this->parsed_items ) {
			$this->parsed_items = array_map(
				function ( $line ) {
					return new BookedItem( $line );
				},
				$this->items
			);
		}

		return $this->parsed_items;
	}

	/**
	 * Which cart row holds a trip, by the key it sits under.
	 *
	 * @param int $trip_id Trip post ID the row named.
	 * @return string|int|null The row's key, or null when the cart holds no such trip.
	 * @since 6.9.0
	 */
	public function item_key( int $trip_id ) {
		foreach ( $this->items as $key => $item ) {
			if ( (int) ( $item['trip_id'] ?? $item['ID'] ?? 0 ) === $trip_id ) {
				return $key;
			}
		}

		return 1 === count( $this->items ) ? array_key_first( $this->items ) : null;
	}

	/**
	 * Trip IDs in the cart, in the order they were booked.
	 *
	 * @return array<int, int> Unique trip IDs; a cart holding no items gives an empty list.
	 * @since 6.9.0
	 */
	public function get_trip_ids(): array {
		$ids = array();

		foreach ( $this->items as $line ) {
			$trip_id = (int) ( $line['trip_id'] ?? $line['ID'] ?? 0 );

			if ( $trip_id ) {
				$ids[] = $trip_id;
			}
		}

		return array_values( array_unique( $ids ) );
	}

	public function get_item( ?string $id = null ): BookedItem {
		if ( $id ) {
			foreach ( $this->get_items() as $item ) {
				if ( $item['id'] == $id ) {
					return $item;
				}
			}
		}

		return $this->get_items()[0] ?? new BookedItem( array() );
	}

	public function get_currency(): string {
		return $this->currency;
	}

	/**
	 * Queues a value onto the cart, in dot notation.
	 *
	 * @param string $key   Where it belongs, e.g. `fees` or `items.0.line_items`.
	 * @param mixed  $value What to write there.
	 * @return self
	 * @since 6.9.0
	 */
	public function set( string $key, $value ): self {
		$this->cart->set( $key, $value );
		$this->parse( $this->cart->value() );
		return $this;
	}

	/**
	 * A value from the cart, with anything written since taken into account.
	 *
	 * @param string $key     Where to read from, in dot notation.
	 * @param mixed  $default What to answer when the cart holds nothing there.
	 * @return mixed
	 * @since 6.9.0
	 */
	public function get( string $key, $default = null ) {
		return $this->cart->get( $key, $default );
	}

	/**
	 * Re-sums the cart's subtotal from its lines, and brings the total back in step.
	 *
	 * A percentage discount is a share of what it discounts, so it moves with the subtotal; a
	 * fixed one stays at the figure it was applied at. Fees are carried over rather than
	 * recalculated: working them out again means running the cart engine, which needs a
	 * session a booking edit does not have.
	 *
	 * @return self
	 * @since 6.9.0
	 */
	public function recalculate_totals(): self {
		/** @var PaymentCalculator $calc */
		$calc     = PaymentCalculator::for( $this->get_currency() );
		$subtotal = '0.00';

		foreach ( $this->items as $item ) {
			foreach ( $item['line_items'] ?? array() as $lines ) {
				foreach ( (array) $lines as $line ) {
					$subtotal = $calc->add( $subtotal, (string) ( $line['total'] ?? 0 ) );
				}
			}
		}

		$deducted = '0.00';

		foreach ( $this->deductible_items as $item ) {
			$slug = sanitize_key( (string) ( $item['name'] ?? '' ) );

			if ( '' === $slug ) {
				continue;
			}

			$kind = (string) ( ( $item['type'] ?? '' ) ?: ( $item['adjustment_type'] ?? '' ) );

			$value = 'fixed' === $kind
				? $calc->normalize( (string) ( $this->totals[ 'total_' . $slug ] ?? 0 ) )
				: $calc->percentage_of( $subtotal, (string) ( $item['percentage'] ?? 0 ) );

			$this->set( "totals.total_{$slug}", (float) $value );

			$deducted = $calc->add( $deducted, $value );
		}

		$this->set( 'totals.subtotal', (float) $subtotal );
		$this->set( 'totals.total', (float) $calc->subtract( $subtotal, $deducted ) );

		return $this;
	}

	/**
	 * Drops a key from the cart, in dot notation.
	 *
	 * @param string $key What to drop, e.g. `items.0.line_items.extra_service`.
	 * @return self
	 * @since 6.9.0
	 */
	public function remove( string $key ): self {
		$this->cart->remove( $key );
		$this->parse( $this->cart->value() );
		return $this;
	}

	/**
	 * Reads a property, a get_* value, or a cart key.
	 *
	 * @param string $key Property or cart key.
	 * @return mixed
	 * @since 6.9.0 Falls back to the cart in dot notation.
	 */
	public function __get( $key ) {
		if ( 'exclusive_label' === $key ) {
			$fees = $this->fees;
			return self::get_exclusive_label( $fees );
		} elseif ( method_exists( $this, "get_{$key}" ) ) {
			return $this->{"get_{$key}"}();
		}

		return $this->{$key} ?? $this->cart->get( $key );
	}

	/**
	 * Checks if the cart version is 4.0.
	 *
	 * @param string $op Operator to compare.
	 * @param string $ver Version to compare with. Default is '4.0'.
	 *
	 * @return bool
	 * @since 6.7.0
	 */
	public function is_curr_cart_ver( string $op = '==', string $ver = '4.0' ): bool {
		return version_compare( $this->version, $ver, $op );
	}

	/**
	 * Summary of get_exclusive_label
	 *
	 * @param mixed $fees
	 * @return string
	 * @since 6.8.7
	 */
	final public static function get_exclusive_label( $fees ) {
		$excl  = '';
		$count = count( $fees );

		for ( $i = $count - 1; $i >= 0; $i-- ) {
			$label = $fees[ $i ]->label ?? $fees[ $i ]['label'] ?? '';
			$label = trim( preg_replace( '/\(.*$/', '', $label ) );
			$excl .= $label . ( $i === 1 ? ' & ' : ( $i > 0 ? ', ' : '' ) );
		}

		return $excl;
	}

	/**
	 * The cart as it was handed in, for a caller that has to write it back.
	 *
	 * @return array
	 * @since 6.9.0
	 */
	public function data(): array {
		return $this->cart->value();
	}

	/**
	 * The parser for one booking's cart, built once per booking.
	 *
	 * @param string|int $key  Booking the cart belongs to.
	 * @param array|null $data The cart to parse; read from the booking when omitted.
	 * @return self
	 * @since 6.9.0
	 */
	public static function for( $key, ?array $data = null ): self {
		return self::factory_for( $key, self::cart_of( $key, $data ) );
	}

	/**
	 * Re-parses a booking's cart, replacing whatever `for()` holds.
	 *
	 * @param string|int $key  Booking the cart belongs to.
	 * @param array|null $data The cart as it now stands; re-read from the booking when omitted.
	 * @return self
	 * @since 6.9.0
	 */
	public static function refresh( $key, ?array $data = null ): self {
		return self::factory_refresh( $key, self::cart_of( $key, $data ) );
	}

	/**
	 * The cart to parse: what the caller holds, or what the booking stores.
	 *
	 * Read through the booking, not the meta: a pre-4.0 cart has its items backfilled there.
	 *
	 * @param string|int $key  Booking the cart belongs to.
	 * @param array|null $data The cart the caller holds, or null to read it.
	 * @return array
	 * @since 6.9.0
	 */
	private static function cart_of( $key, ?array $data ): array {
		if ( null !== $data ) {
			return $data;
		}

		$booking = wptravelengine_get_booking( $key );

		return $booking ? (array) $booking->get_cart_info() : array();
	}

	/**
	 * Travelers across every row in the cart.
	 *
	 * @return int Total pax; a cart holding no items gives zero.
	 * @since 6.9.0
	 */
	public function total_travelers_count(): int {
		$total = 0;

		foreach ( $this->get_items() as $item ) {
			$total += (int) $item->travelers_count();
		}

		return $total;
	}

	/**
	 * The cart total, which is the subtotal with every deduction already taken off.
	 *
	 * @return array|float|null Rounded to two places; 0 when the cart holds no total.
	 * @since 6.9.0
	 */
	public function get_total() {
		return $this->get_totals( 'total' );
	}
}
