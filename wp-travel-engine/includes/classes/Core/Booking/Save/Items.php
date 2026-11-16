<?php
/**
 * Saves the booked trip and its line items.
 *
 * @package WPTravelEngine/Core/Booking
 * @since 6.9.0
 */

namespace WPTravelEngine\Core\Booking\Save;

use WPTravelEngine\Core\Cart\Item;
use WPTravelEngine\Helpers\BookedItem;
use WPTravelEngine\Core\Booking\Inventory;
use WPTravelEngine\Utilities\ArrayUtility;
use WPTravelEngine\Core\Booking\LineItems\Manager as LineItemsManager;

/**
 * Trip and line-item writers for SaveBooking, which holds the state they read.
 *
 * @since 6.9.0
 */
trait Items {

	/**
	 * Queues an activity entry, written to the booking when the save completes.
	 *
	 * @param string               $type     What kind of event it was, e.g. `payment`.
	 * @param string               $category Section the event belongs to.
	 * @param string               $text     What happened, as shown in the log.
	 * @param array<string, mixed> $extra    Further keys the entry carries.
	 * @return void
	 */
	abstract public function record_activity( string $type, string $category, string $text, array $extra = array() ): void;

	/**
	 * Writes the cart back to the booking, keeping the parsed copy in step.
	 *
	 * @param array<string, mixed> $cart The cart as it now stands.
	 * @return void
	 */
	abstract protected function set_cart_info( array $cart ): void;

	/**
	 * Saves the departure window of one booked trip, which the payload is known to carry.
	 *
	 * Only the window: pricing is recalculated from the cart, and travelers arrive as `items` rows.
	 *
	 * @return void
	 * @since 6.9.0
	 */
	protected function process_trip(): void {
		$trip  = ArrayUtility::to_array( $this->request['trip'] );
		$start = (string) ( $trip['start_datetime'] ?? '' );
		$end   = (string) ( $trip['end_datetime'] ?? '' );

		// A booked trip always has a departure, so an empty one is read as not sent.
		if ( ( '' === $start && '' === $end ) || ( '' !== $start && false === strtotime( $start ) ) || ( '' !== $end && false === strtotime( $end ) ) ) {
			return;
		}

		$items = $this->cart_info->get_items();
		$key   = $this->booked_trip_key( $items, $trip );
		$cart  = $this->cart_info->data();

		if ( null === $key || empty( $cart['items'][ $key ] ) ) {
			return;
		}

		$item = $items[ $key ];
		$row  = ArrayUtility::to_array( $cart['items'][ $key ] );

		if ( '' === $start ) {
			$start = (string) $item->get_start_date();
		} elseif ( '' === $end ) {
			$end = (string) wptravelengine_format_trip_end_datetime( $start, $item->get_trip(), 10 === strlen( $start ) ? 'Y-m-d' : 'Y-m-d\TH:i' );
		}

		if ( '' === $end ) {
			$end = $item->get_end_date();
		}

		if ( $start === (string) $item->get_start_date() && $end === $item->get_end_date() ) {
			return;
		}

		$old_id = (string) ( $row['id'] ?? '' );
		$new_id = Item::get_item_id( $item->get_trip_id(), $row['price_key'] ?? '', $start );

		// The same fields CreateBooking::seed_trip() writes, so a moved trip reads as a new one would.
		$cart['items'][ $key ] = array_merge(
			$row,
			array(
				'id'              => $new_id,
				'trip_date'       => substr( $start, 0, 10 ),
				'trip_time'       => $start,
				'trip_end_date'   => substr( $end, 0, 10 ),
				'trip_end_time'   => $end,
				'end_date'        => $end,
				'trip_time_range' => array( $start, $end ),
				'datetime'        => substr( $start, 0, 10 ),
			)
		);

		$this->set_cart_info( $cart );

		if ( (string) array_key_first( $cart['items'] ) === $key ) {
			$this->booking->set_meta( 'trip_datetime', $start );
		}

		if ( '' !== $old_id && $old_id !== $new_id ) {
			// Seats are recorded under the cart ID, which carries the date; finalize() holds them on the new one.
			( new Inventory( $item->get_trip_id() ) )->update_pax( $old_id, 0, $item->get_trip_id(), $this->booking->ID );

			// Re-keyed rather than rebuilt, so the keys add-ons wrote onto the row carry over.
			$order_trips = ArrayUtility::to_array( $this->booking->get_meta( 'order_trips' ) );

			if ( isset( $order_trips[ $old_id ] ) ) {
				$order_trips[ $new_id ] = array_merge(
					ArrayUtility::to_array( $order_trips[ $old_id ] ),
					array(
						'datetime'     => $start,
						'end_datetime' => $end,
					)
				);

				unset( $order_trips[ $old_id ] );

				$this->booking->set_order_items( $order_trips );
			}
		}

		$format = (string) get_option( 'date_format' );

		if ( 10 !== strlen( $start ) ) {
			$format .= ' ' . get_option( 'time_format' );
		}

		$this->record_activity(
			'booking',
			'bookings',
			sprintf(
				/* translators: 1: new departure, 2: new return. */
				__( 'Changed the departure to %1$s, returning %2$s.', 'wp-travel-engine' ),
				wp_date( $format, strtotime( $start ) ),
				wp_date( $format, strtotime( $end ) )
			)
		);
	}

	/**
	 * Which cart row a `trip` param names.
	 *
	 * `key` addresses a row outright; only `id` falls back to the first row booked on that trip.
	 *
	 * @param array<string|int, BookedItem> $items Cart rows, keyed as the cart keys them.
	 * @param array<string, mixed>          $trip  The `trip` param as submitted.
	 * @return string|null The row's key, or null when the payload names none that exists.
	 * @since 6.9.0
	 */
	protected function booked_trip_key( array $items, array $trip ): ?string {
		if ( isset( $trip['key'] ) && isset( $items[ $trip['key'] ] ) ) {
			return (string) $trip['key'];
		}

		$trip_id = (int) ( $trip['id'] ?? 0 );

		foreach ( $items as $key => $item ) {
			if ( (int) $item->get_trip_id() === $trip_id ) {
				return (string) $key;
			}
		}

		return null;
	}

	/**
	 * Which booked trip row a trip ID names.
	 *
	 * Rows are keyed by cart ID, so the ID is matched against each; an unmatched one takes the first.
	 *
	 * @param array<string, array<string, mixed>> $order_trips The `order_trips` meta.
	 * @param int                                 $trip_id     Trip post ID the payload named.
	 * @return string|int Key of the row to write.
	 * @since 6.9.0
	 */
	protected function order_trip_key( array $order_trips, int $trip_id ) {
		foreach ( $order_trips as $key => $row ) {
			if ( (int) ( $row['trip_id'] ?? $row['ID'] ?? 0 ) === $trip_id ) {
				return $key;
			}
		}

		return array_key_first( $order_trips );
	}

	/**
	 * Applies the `items` payload, group by group.
	 *
	 * Travelers merge onto the traveler details; every other group is a priced cart row, repriced once.
	 *
	 * @return void
	 * @since 6.9.0
	 */
	protected function process_items(): void {
		$changed = array();
		$moves   = array();
		$names   = array();

		foreach ( (array) $this->request['items'] as $group ) {
			$id   = (string) ( $group['id'] ?? '' );
			$rows = ArrayUtility::to_array( $group['items'] ?? array() );

			if ( '' === $id || ! $rows ) {
				continue;
			}

			$trip_id = (int) ( $group['trip_id'] ?? 0 );
			$key     = $this->cart_info->item_key( $trip_id );

			if ( null === $key ) {
				continue;
			}

			$result = LineItemsManager::write( $this, $key, $id, $rows );

			$changed[] = $result;

			if ( array_sum( $result ) ) {
				// The traveler group logs each traveler itself; its head counts would only repeat that.
				if ( 'traveler' !== $id ) {
					$names[ $id ] = LineItemsManager::get( $id )->names();
				}

				$moves[] = array_merge(
					array(
						'trip_id' => $trip_id,
						'group'   => $id,
					),
					LineItemsManager::get( $id )->changes()
				);
			}
		}

		if ( ! array_sum( array_map( 'array_sum', $changed ) ) ) {
			return;
		}

		$this->set_cart_info( $this->cart_info->recalculate_totals()->data() );

		$labels = array(
			/* translators: %s: name of the item added, e.g. an extra service. */
			'added'   => __( 'Added %s.', 'wp-travel-engine' ),
			/* translators: %s: name of the item changed, e.g. an extra service. */
			'updated' => __( 'Updated %s.', 'wp-travel-engine' ),
			/* translators: %s: name of the item removed, e.g. an extra service. */
			'removed' => __( 'Removed %s.', 'wp-travel-engine' ),
		);
		$groups = wptravelengine_get_labels( 'line_item_group' );

		foreach ( $names as $group => $by_kind ) {
			$is_addon = ! in_array( $group, array( 'pricing_category', 'price_category' ), true );

			foreach ( $by_kind as $kind => $listed ) {
				foreach ( $listed as $name ) {
					$group_label = $groups[ $group ] ?? $group;

					$this->record_activity(
						$is_addon ? 'addon' : 'booking',
						$is_addon ? 'addons' : 'bookings',
						sprintf(
							$labels[ $kind ],
							'' !== $name
								/* translators: 1: line item group, e.g. Accommodation, 2: item name. */
								? sprintf( __( '%1$s - %2$s', 'wp-travel-engine' ), $group_label, $name )
								: $group_label
						)
					);
				}
			}
		}

		// The moves ride on the save's last entry, which a traveler-only change leaves as a traveler's.
		if ( $this->activities ) {
			$last = array_key_last( $this->activities );

			$this->activities[ $last ]['line_items'] = $moves;
		}
	}

	/**
	 * Writes the `traveler` group's rows onto the booking, leaving the cart alone.
	 *
	 * @param array<int, array<string, mixed>> $rows   Payload rows.
	 * @param bool                             $strict Whether an unwritable value fails the whole write.
	 * @return true|\WP_Error
	 * @since 6.9.0
	 */
	public function set_travelers( array $rows, bool $strict = false ) {
		$result = LineItemsManager::get( 'pricing_category' )->set_travelers( $this, $rows, $strict );
		return is_wp_error( $result ) ? $result : true;
	}
}
