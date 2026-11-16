<?php
/**
 *
 * @since 6.4.0
 */

namespace WPTravelEngine\Helpers;

use WPTravelEngine\Core\Models\Post\Trip;
use WPTravelEngine\Core\Cart\Items\PricingCategory;

class BookedItem {

	/**
	 * @var array
	 */
	protected array $data;

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
	 * @var mixed
	 */
	protected $id;

	/**
	 * @var mixed
	 */
	protected $trip_id;

	/**
	 * @var mixed
	 */
	protected $trip_package_id;

	/**
	 * @var mixed
	 */
	protected $trip_date;

	/**
	 * @var mixed
	 */
	protected $line_items;

	/**
	 * @var int
	 */
	public int $travelers_count = 0;

	protected string $trip_code;

	/**
	 * @var mixed|string
	 */
	protected $package_name;

	/**
	 * @var mixed|string
	 */
	protected $end_date;

	/**
	 * @var mixed|string
	 */
	protected $trip_end_time;

	/**
	 * @var string
	 * @since 6.9.0
	 */
	public string $custom_trip = '';

	/**
	 * @var ?Trip
	 * @since 6.9.0
	 */
	public ?Trip $trip = null;

	public function __construct( array $data ) {
		$this->data = $data;
		$this->parse( $data );
	}

	/**
	 * Reads the booked item from its cart row.
	 *
	 * @param array<string, mixed> $data Cart row.
	 * @return void
	 * @since 6.9.0 The end date falls back to the departure, not the current time, when the trip cannot work one out.
	 */
	protected function parse( $data ) {
		$this->id              = $data['id'] ?? '';
		$this->trip_package_id = $data['price_key'] ?? 0;
		$this->custom_trip     = $data['custom_trip_name'] ?? '';
		$this->trip_id         = $data['trip_id'] ?? $data['ID'] ?? 0;
		$this->travelers_count = $data['travelers_count'] ?? array_sum( $data['travelers'] ?? $data['pax'] ?? array() );

		$this->line_items = $this->parse_line_items( $data );

		$_time      = wp_date( 'Y-m-d H:i:s' );
		$time_range = is_array( $data['trip_time_range'] ?? '' ) ? $data['trip_time_range'] : array();

		$this->trip_end_time = (string) ( $time_range[1] ?? '' );
		$this->trip_date     = ! empty( $time_range[0] ?? '' ) ? $time_range[0] : ( empty( $data['trip_time'] ?? '' ) ? ( $data['trip_date'] ?? $data['datetime'] ?? $_time ) : $data['trip_time'] );
		$this->package_name  = ! empty( $data['trip_package'] ?? '' ) ? $data['trip_package'] : ( $data['package_name'] ?? '' );

		$this->trip = wptravelengine_get_trip( (int) $this->trip_id );

		$trip_end = $this->trip_date;

		if ( $this->trip ) {
			$this->trip_code = $this->trip->get_trip_code();
			$trip_end        = wptravelengine_format_trip_end_datetime( $this->trip_date, $this->trip, 'Y-m-d H:i:s' ) ?: $trip_end;
		} else {
			$this->trip_code = $this->trip_id ? "WTE-{$this->trip_id}" : '';
		}

		$this->end_date = ! empty( $data['end_date'] ?? '' ) ? $data['end_date'] : ( ! empty( $this->trip_end_time ) ? $this->trip_end_time : $trip_end );
	}

	/**
	 * Prepares line items.
	 *
	 * @param array $data Booked item data.
	 *
	 * @return array Line items.
	 * @since 6.8.3 Fills pricing_category ids from `subtotal_reservations.travelers`
	 */
	protected function parse_line_items( $data ) {
		$line_items = array( 'pricing_category' => array() );
		if ( defined( 'WTE_EXTRA_SERVICES_FILE_PATH' ) ) {
			$line_items = array_merge( $line_items, array( 'extra_service' => array() ) );
		}
		if ( $data['line_items'] ?? false ) {
			$line_items = ! $data['line_items'] ? array() : $data['line_items'];
		} elseif ( isset( $data['_cart_item_object'] ) ) { // Legacy Support.
			if ( $category_info = ( $data['_cart_item_object']['category_info'] ?? false ) ) {
				$line_items['pricing_category'] = array_map(
					function ( $item, $index ) use ( $data ) {
						$pax   = $data['_cart_item_object']['pax'][ $index ] ?? 0;
						$price = $item['price'] ?? 0;

						return array(
							'label'       => $item['label'] ?? '',
							'quantity'    => $pax,
							'price'       => $price,
							'total'       => $data['_cart_item_object']['pax_cost'][ $index ] ?? ( $pax * $price ),
							'_class_name' => PricingCategory::class,
						);
					},
					$category_info,
					array_keys( $category_info )
				);
			}
			if ( $extra_line_items = ( $data['_cart_item_object']['trip_extras'] ?? false ) ) {
				$line_items['extra_service'] = array_map(
					function ( $item ) {
						$qty   = $item['qty'] ?? 0;
						$price = $item['price'] ?? 0;

						return array(
							'label'       => $item['extra_service'] ?? '',
							'quantity'    => $qty,
							'price'       => $price,
							'total'       => ( $qty * $price ),
							'_class_name' => '',
						);
					},
					$extra_line_items
				);
			}
		}

		/**
		 * @since 6.8.3
		 */
		$items = $data['subtotal_reservations']['travelers'] ?? array();
		if ( ! empty( $line_items['pricing_category'] ) ) {
			$i = 0;
			foreach ( $items as $item ) {
				foreach ( $line_items['pricing_category'] as $j => $line_item ) {
					if ( ! isset( $line_item['id'] ) && $i === $j ) {
						$line_items['pricing_category'][ $j ]['id'] = $item['id'];
					}
				}
				++$i;
			}
		}

		return apply_filters( 'wptravelengine_edit_default_line_items', $line_items, $data, $this );
	}

	public function get_id() {
		return $this->id;
	}

	public function get_trip_id() {
		return $this->trip_id;
	}

	/**
	 * Title of the booked trip.
	 *
	 * @return string
	 * @since 6.9.0 Falls back to the title stored on the cart row when the trip no longer exists.
	 */
	public function get_trip_title(): string {
		return $this->trip ? $this->trip->get_title() : (string) ( $this->data['title'] ?? '' );
	}

	public function get_trip_package_id() {
		return $this->trip_package_id;
	}

	public function get_trip_date() {
		return $this->trip_date;
	}

	/**
	 * End date of the booked item.
	 *
	 * @return string
	 * @since 6.9.0 Declares a string return type.
	 */
	public function get_end_date(): string {
		return $this->end_date;
	}

	public function get_line_items(): array {
		return apply_filters( 'wptravelengine_booking_line_items', $this->line_items, $this );
	}

	public function travelers_count(): int {
		return $this->travelers_count;
	}

	public function get_trip_code(): string {
		return $this->trip_code;
	}

	public function get_package_name(): string {
		return $this->package_name;
	}

	public function get_custom_trip(): string {
		return ( $this->custom_trip ?? '' ) ?: get_the_title( $this->trip_id );
	}

	/**
	 * Departure of the booked item. Alias of get_trip_date()
	 *
	 * @return string
	 * @since 6.9.0
	 */
	public function get_start_date(): string {
		return $this->trip_date;
	}

	/**
	 * Booked trip, or null when it no longer exists.
	 *
	 * @return Trip|null
	 * @since 6.9.0
	 */
	public function get_trip(): ?Trip {
		return $this->trip;
	}
}
