<?php
/**
 * Pickup point line items.
 *
 * @package WPTravelEngine
 * @since 6.9.0
 */

namespace WPTravelEngine\Core\Booking\LineItems;

use WPTravelEngine\Core\Booking\SaveBooking;

/**
 * Pickup points, one priced row per traveler collected.
 */
class PickupPoint extends AbstractBase {

	/**
	 * @inheritDoc
	 */
	public function write( SaveBooking $save, $key, array $rows ): array {
		// Rows are keyed by position, `pickup_point_{traveler_id}`, so dropping a traveler renumbers every
		// row behind it; the `traveler_id`s sent predate the drop. A row naming its `id` is written as is.
		$this->bind( $save );

		$lines   = array();
		$dropped = array();
		$wanted  = array();

		foreach ( $rows as $row ) {
			if ( isset( $row['id'] ) ) {
				$lines[] = $row;
				continue;
			}

			$at = (int) ( $row['traveler_id'] ?? $row['traveller_id'] ?? -1 );

			if ( $at < 0 ) {
				continue;
			}

			if ( empty( $row['remove'] ) ) {
				$wanted[ $at ] = $row;
			} else {
				$dropped[] = $at;
			}
		}

		$moved_to = function ( int $at ) use ( $dropped ): int {
			return $at - count(
				array_filter(
					$dropped,
					function ( $index ) use ( $at ) {
						return $index < $at;
					}
				)
			);
		};

		$moving = array();

		if ( $dropped ) {
			foreach ( $this->lines( $key ) as $line ) {
				$at = (int) ( $line['traveller_id'] ?? -1 );

				if ( $at < 0 || ( ! in_array( $at, $dropped, true ) && $moved_to( $at ) === $at ) ) {
					continue;
				}

				// Removed first, so a row coming back under a freed position is added rather than dropped.
				$lines[] = array(
					'id'       => 'pickup_point_' . $at,
					'quantity' => 0,
				);

				if ( ! in_array( $at, $dropped, true ) ) {
					$moving[ $moved_to( $at ) ] = $line;
				}
			}
		}

		foreach ( $wanted as $at => $row ) {
			if ( ! in_array( $at, $dropped, true ) ) {
				$moving[ $moved_to( $at ) ] = $row;
			}
		}

		foreach ( $moving as $at => $row ) {
			$line = self::line_for( array_merge( $row, array( 'traveler_id' => $at ) ) );

			if ( null !== $line ) {
				$lines[] = $line;
			}
		}

		return $lines ? parent::write( $save, $key, $lines ) : array( 0, 0, 0 );
	}

	/**
	 * @inheritDoc
	 */
	public function row_key( array $row ): string {
		$id = parent::row_key( $row );

		return '' === $id && isset( $row['traveller_id'] ) ? 'pickup_point_' . (int) $row['traveller_id'] : $id;
	}

	/**
	 * The cart row for one traveler's pickup.
	 *
	 * One with no location comes back with a quantity of zero, which removes the row; a free one is kept at 0.
	 *
	 * @param array<string, mixed> $row `{ traveler_id, label, price, is_manual }`, `label` being the location.
	 * @return array<string, mixed>|null Null when the row names no traveler.
	 */
	public static function line_for( array $row ): ?array {
		$traveler = (int) ( $row['traveler_id'] ?? $row['traveller_id'] ?? -1 );

		if ( $traveler < 0 ) {
			return null;
		}

		$location = trim( (string) ( $row['label'] ?? '' ) );
		$price    = max( 0, (float) ( $row['price'] ?? 0 ) );
		$line     = array(
			'id'       => 'pickup_point_' . $traveler,
			'quantity' => 0,
		);

		if ( '' !== $location ) {
			$line = array_merge(
				$line,
				array(
					'label'        => $location,
					'quantity'     => 1,
					'price'        => $price,
					'traveller_id' => $traveler,
					'is_manual'    => wptravelengine_toggled( $row['is_manual'] ?? false ),
				)
			);
		}

		return $line;
	}

	/**
	 * @inheritDoc
	 */
	public function to_resource( array $row ): array {
		$traveler_id = (int) ( $row['traveller_id'] ?? -1 );
		$label       = preg_replace( '/ \(Travell?er \d+\)$/', '', wptravelengine_decode_entity( $row['label'] ?? '' ) );

		return array(
			'id'           => $this->key . '_' . $traveler_id,
			'label'        => sprintf(
				/* translators: 1: pickup location. 2: the traveler's position on the trip. */
				__( '%1$s (Traveler %2$d)', 'wp-travel-engine' ),
				$label,
				$traveler_id + 1
			),
			'quantity'     => 1,
			'price'        => (float) ( $row['price'] ?? 0 ),
			'total'        => (float) ( $row['price'] ?? 0 ),
			'traveller_id' => $traveler_id,
			'is_manual'    => wptravelengine_toggled( $row['is_manual'] ?? 'no' ),
		);
	}
}
