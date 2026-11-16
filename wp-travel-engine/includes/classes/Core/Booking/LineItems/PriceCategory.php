<?php
/**
 * Price category line items.
 *
 * @package WPTravelEngine
 * @since 6.9.0
 */

namespace WPTravelEngine\Core\Booking\LineItems;

use WPTravelEngine\Core\Booking\SaveBooking;
use WPTravelEngine\Core\Cart\Item as CartItem;
use WPTravelEngine\Builders\FormFields\BookingFormFields;
use WPTravelEngine\Core\Cart\Items\PricingCategory as PricingCategoryItem;

/**
 * Traveler price categories, reserved under `travelers`.
 */
class PriceCategory extends AbstractBase {

	/**
	 * Added, removed, updated by the groups `set_travelers()` writes on the way, the pickups.
	 *
	 * @var array<int, int>
	 */
	protected array $side_counts = array( 0, 0, 0 );

	/**
	 * @inheritDoc
	 */
	public function reservation_key(): string {
		return 'travelers';
	}

	/**
	 * @inheritDoc
	 */
	public function class_name(): ?string {
		return PricingCategoryItem::class;
	}

	/**
	 * @inheritDoc
	 */
	public function apply( array $line, array $row, bool $is_new ): array {
		$line = parent::apply( $line, $row, $is_new );

		if ( isset( $row['pricingType'] ) ) {
			$line['pricingType'] = 'per-group' === $row['pricingType'] ? 'per-group' : 'per-person';
		}

		unset( $line['is_manual'] );

		return $line;
	}

	/**
	 * @inheritDoc
	 */
	protected function path( $key ): string {
		return "items.{$key}.line_items.pricing_category";
	}

	/**
	 * @inheritDoc
	 */
	protected function lines( $key ): array {
		// A head count is keyed by category, so a label-only row gets the trip's `id` for it; the first row per category wins, dropping $0 copies.
		$ids  = array();
		$kept = array();

		foreach ( (array) $this->cart()->get( "items.{$key}.category_info", array() ) as $id => $category ) {
			$ids[ (string) ( $category['label'] ?? '' ) ] = $id;
		}

		foreach ( parent::lines( $key ) as $line ) {
			if ( empty( $line['id'] ) && isset( $ids[ (string) ( $line['label'] ?? '' ) ] ) ) {
				$line['id'] = $ids[ (string) $line['label'] ];
			}

			$id = (string) ( $line['id'] ?? '' );

			if ( '' !== $id && isset( $kept[ $id ] ) ) {
				continue;
			}

			$kept[ '' !== $id ? $id : count( $kept ) . '_' ] = $line;
		}

		return array_values( $kept );
	}

	/**
	 * @inheritDoc
	 */
	public function write( SaveBooking $save, $key, array $rows ): array {
		// On the `traveler` instance the rows are traveler edits: they go onto the booking first, and the
		// category rows written are the head counts that follow from them.
		if ( 'traveler' === $this->key ) {
			$this->side_counts = array( 0, 0, 0 );

			$written = $this->set_travelers( $save, $rows, false, $key );

			if ( is_wp_error( $written ) || ! $written ) {
				return $this->side_counts;
			}

			// The category rows are the head counts that follow from the travelers just written.
			$rows = $this->rows_from_travelers( $key, $written );

			if ( ! $rows ) {
				return $this->side_counts;
			}
		}

		$counts = parent::write( $save, $key, $rows );

		if ( array_sum( $counts ) ) {
			$pax     = array();
			$cost    = array();
			$info    = (array) $this->cart()->get( "items.{$key}.category_info", array() );
			$package = false;

			foreach ( $this->lines( $key ) as $line ) {
				$id          = $line['id'] ?? '';
				$pax[ $id ]  = (int) ( $line['quantity'] ?? 0 );
				$cost[ $id ] = $this->amount( $line['total'] ?? 0 );

				if ( ! is_numeric( $id ) || isset( $info[ $id ] ) ) {
					continue;
				}

				// A category added after booking gets the snapshot of its package settings checkout keeps for the rest.
				if ( false === $package ) {
					$item    = (array) $this->cart()->get( "items.{$key}", array() );
					$trip    = wptravelengine_get_trip( (int) ( $item['trip_id'] ?? 0 ) );
					$package = $trip ? $trip->packages()->get_package( (int) ( $item['price_key'] ?? 0 ) ) : null;
				}

				// A package since deleted leaves only what the row itself says.
				$snapshot = $package ? ( CartItem::get_travelers_details( $package, array( $id => 1 ) )['info'] ?? array() ) : array();

				$info[ $id ] = $snapshot[ $id ] ?? array(
					'label'       => (string) ( $line['label'] ?? '' ),
					'price'       => $this->amount( $line['price'] ?? 0 ),
					'pricingType' => $line['pricingType'] ?? 'per-person',
				);
			}

			$this->cart()->set( "items.{$key}.category_info", $info );
			$this->cart()->set( "items.{$key}.travelers", $pax );
			$this->cart()->set( "items.{$key}.pax", $pax );
			$this->cart()->set( "items.{$key}.pax_cost", $cost );
		}

		if ( 'traveler' === $this->key ) {
			$counts = array_map( null, $counts, $this->side_counts );
			$counts = array_map( 'array_sum', $counts );
		}

		return $counts;
	}

	/**
	 * Writes the `traveler` group's rows onto the booking.
	 *
	 * Rows carry only what changed, addressed by `index`, and only keys the form declares are written.
	 * A row asking to be removed drops that traveler instead, and the rest close up behind it.
	 * Strictness is the caller's: an administrator may leave a newly required field empty, a customer may not.
	 *
	 * @param SaveBooking                      $save   The save in progress, for its booking and log.
	 * @param array<int, array<string, mixed>> $rows   Payload rows.
	 * @param bool                             $strict Whether an unwritable value fails the whole write.
	 * @param string|int|null                  $key    Cart item key, to write the travelers' pickups under; null leaves the cart alone.
	 * @return array<int, array<string, mixed>>|\WP_Error Travelers as written; empty when nothing changed.
	 */
	public function set_travelers( SaveBooking $save, array $rows, bool $strict = false, $key = null ) {
		$this->bind( $save );

		$travelers = array_map(
			function ( $traveler ) use ( $save ) {
				$traveler = $save->canonical( 'travelers', (array) $traveler );
				unset( $traveler['cat_label'] );

				return $traveler;
			},
			$save->booking->get_travelers()
		);

		$input   = array();
		$dropped = array();
		$pickups = array();
		$cat_ids = array();

		// For the activity log: positions and names, by what happened to the traveler.
		$moves = array(
			'added'   => array(),
			'updated' => array(),
			'removed' => array(),
		);
		$names = $moves;

		$name_of = static function ( array $row, int $position ): string {
			$name = trim( ( $row['fname'] ?? '' ) . ' ' . ( $row['lname'] ?? '' ) );

			/* translators: %d: position of the traveler on the booking. */
			return '' !== $name ? $name : sprintf( __( 'Traveler %d', 'wp-travel-engine' ), $position + 1 );
		};

		foreach ( $rows as $row ) {
			$traveler_index = (int) ( $row['index'] ?? -1 );

			if ( $traveler_index < 0 ) {
				continue;
			}

			unset( $row['group'], $row['index'], $row['trip_id'], $row['id'] );

			$remove = ! empty( $row['remove'] );

			unset( $row['remove'] );

			if ( $remove && 0 < $traveler_index ) {
				$save->files()->forget( $travelers[ $traveler_index ] ?? array(), $save->file_fields( 'travelers' ) );

				$dropped[]                  = $traveler_index;
				$moves['removed'][]         = $traveler_index;
				$names['removed'][]         = $name_of( $travelers[ $traveler_index ] ?? array(), $traveler_index );
				$pickups[ $traveler_index ] = array(
					'traveler_id' => $traveler_index,
					'remove'      => true,
				);
				continue;
			}

			// The pickup is answered on the traveler and priced as a cart row of its own.
			if ( is_array( $row['pickups'] ?? '' ) ) {
				$row['pickup_point'] = sanitize_text_field( (string) ( $row['pickups']['label'] ?? '' ) );

				$pickups[ $traveler_index ] = array(
					'traveler_id' => $traveler_index,
					'label'       => $row['pickup_point'],
					'price'       => $row['pickups']['price'] ?? 0,
					'is_manual'   => wptravelengine_toggled( $row['pickups']['is_manual'] ?? false ),
				);

				unset( $row['pickups'] );
			}

			if ( isset( $row['cat_id'] ) ) {
				$cat_ids[ $traveler_index ] = absint( $row['cat_id'] );
			}

			$is_moved = isset( $cat_ids[ $traveler_index ] ) && (int) ( $travelers[ $traveler_index ]['cat_id'] ?? 0 ) !== $cat_ids[ $traveler_index ];

			unset( $row['cat_id'] );

			$row = $save->files()->merge( "travelers.{$traveler_index}", $row, $travelers[ $traveler_index ] ?? array(), $save->file_fields( 'travelers' ) );

			if ( $row || $is_moved || ! isset( $travelers[ $traveler_index ] ) ) {
				$input[ $traveler_index ] = $row;
			}
		}

		if ( empty( $input ) && empty( $pickups ) ) {
			return array();
		}

		$merged = BookingFormFields::apply(
			$travelers,
			$input,
			BookingFormFields::for( $save->booking, $save->is_admin )->rules( 'travelers' ),
			$strict
		);

		if ( is_wp_error( $merged ) ) {
			return $merged;
		}

		foreach ( $cat_ids as $traveler_index => $cat_id ) {
			if ( isset( $merged[ $traveler_index ] ) ) {
				$merged[ $traveler_index ]['cat_id'] = $cat_id;
			}
		}

		// Dropped together rather than one at a time, so the indexes still address what they named.
		if ( $dropped ) {
			$merged = array_diff_key( $merged, array_flip( $dropped ) );
		}

		$written = array_values( $merged );

		$save->booking->set_traveller_details( $written );

		if ( null !== $key && $pickups ) {
			$this->side_counts = Manager::write( $save, $key, 'pickup_point', array_values( $pickups ) );
		}

		// A plan bought per traveler covers the party as it now stands.
		if ( null !== $key && count( $written ) !== count( $travelers ) ) {
			$plans = Manager::get( 'travel_insurance' )->normalize( (array) $this->cart()->get( "items.{$key}.line_items.travel_insurance", array() ) );
			$rows  = array_map(
				fn ( array $plan ): array => array(
					'plan_id'  => $plan['plan_id'],
					'quantity' => count( $written ),
				),
				$plans
			);

			if ( $rows ) {
				$this->side_counts = array_map( 'array_sum', array_map( null, $this->side_counts, Manager::write( $save, $key, 'travel_insurance', $rows ) ) );
			}
		}

		$position = 0;

		// A blank answer reads the same as one never given.
		$answers = static fn ( array $row ): array => array_filter( $row, static fn ( $value ) => '' !== $value && null !== $value && array() !== $value );

		foreach ( $merged as $index => $row ) {
			// A row sent back as it was stored, as the rest of the party is beside an edit, changed nothing.
			if ( isset( $input[ $index ] ) && ( ! isset( $travelers[ $index ] ) || $answers( $row ) != $answers( $travelers[ $index ] ) ) ) { // phpcs:ignore Universal.Operators.StrictComparisons.LooseNotEqual -- Same answers, whatever order or type they came back in.
				$kind = isset( $travelers[ $index ] ) ? 'updated' : 'added';

				$moves[ $kind ][] = $position;
				$names[ $kind ][] = $name_of( $row, $position );
			}

			++$position;
		}

		$labels = array(
			/* translators: %s: name of the traveler added. */
			'added'   => __( 'Added %s.', 'wp-travel-engine' ),
			/* translators: %s: name of the traveler changed. */
			'updated' => __( 'Updated %s details.', 'wp-travel-engine' ),
			/* translators: %s: name of the traveler removed. */
			'removed' => __( 'Removed %s.', 'wp-travel-engine' ),
		);

		// One entry per traveler; only the first carries the moves, which the client walks once per save.
		$extra = array( 'travelers' => $moves );

		foreach ( $names as $kind => $listed ) {
			foreach ( $listed as $name ) {
				$save->record_activity( 'traveler', 'travelers', sprintf( $labels[ $kind ], $name ), $extra );
				$extra = array();
			}
		}

		return $written;
	}

	/**
	 * Category rows that set each category's head count from the travelers.
	 *
	 * A category nobody is in any more goes at quantity zero, which removes it; one the cart already
	 * holds keeps its label, price and pricing type.
	 *
	 * @param string|int                       $key       Cart item key.
	 * @param array<int, array<string, mixed>> $travelers Travelers as written.
	 * @return array<int, array<string, mixed>> Empty when any traveler has no `cat_id`.
	 */
	protected function rows_from_travelers( $key, array $travelers ): array {
		$counts = array();

		foreach ( $travelers as $traveler ) {
			$id = (int) ( ( (array) $traveler )['cat_id'] ?? 0 );

			if ( ! $id ) {
				return array();
			}

			$counts[ $id ] = ( $counts[ $id ] ?? 0 ) + 1;
		}

		$held    = array_column( $this->lines( $key ), null, 'id' );
		$offered = $this->offered( $key );
		$rows    = array();

		foreach ( array_diff_key( $held, $counts ) as $id => $line ) {
			$rows[] = array(
				'id'       => $id,
				'quantity' => 0,
			);
		}

		foreach ( $counts as $id => $count ) {
			$line     = $held[ $id ] ?? array();
			$category = $offered[ $id ] ?? null;
			$term     = isset( $line['label'] ) || $category ? null : get_term( $id, 'trip-packages-categories' );

			$rows[] = array(
				'id'          => $id,
				'label'       => $line['label'] ?? ( $category->label ?? ( $term instanceof \WP_Term ? $term->name : "Category - {$id}" ) ),
				'quantity'    => $count,
				'price'       => $line['price'] ?? ( null !== $category ? $category->price : 0 ),
				'pricingType' => $line['pricingType'] ?? ( $category->pricing_type ?? 'per-person' ),
			);
		}

		return $rows;
	}

	/**
	 * The trip package's traveler categories, by ID.
	 *
	 * @param string|int $key Cart item key.
	 * @return array<int, object>
	 */
	protected function offered( $key ): array {
		$package_id = (int) $this->cart()->get( "items.{$key}.price_key", 0 );
		$trip_id    = (int) $this->cart()->get( "items.{$key}.trip_id", 0 );

		$trip    = $trip_id ? wptravelengine_get_trip( $trip_id ) : null;
		$package = $trip ? $trip->get_package( $package_id ) : null;

		// A booking can outlive its trip or package.
		if ( ! $package ) {
			return array();
		}

		$offered = array();

		foreach ( $package->get_traveler_categories() as $category ) {
			$offered[ (int) $category->id ] = $category;
		}

		return $offered;
	}
}
