<?php
/**
 * Accommodation line items.
 *
 * @package WPTravelEngine
 * @since 6.9.0
 */

namespace WPTravelEngine\Core\Booking\LineItems;

/**
 * Rooms and room upgrades.
 */
class Accommodation extends AbstractBase {

	/**
	 * @inheritDoc
	 */
	public function reservation_key(): string {
		return 'accommodation';
	}

	/**
	 * @inheritDoc
	 */
	public function class_name(): ?string {
		return 'Accommodation\\Backend\\Accommodation';
	}

	/**
	 * @inheritDoc
	 */
	public function row_key( array $row ): string {
		$id = parent::row_key( $row );

		if ( '' === $id ) {
			return '';
		}

		if ( 'upgraded' === ( $row['accommodation_type'] ?? '' ) ) {
			return $id . '@' . ( $row['parent_id'] ?? '' );
		}

		return wptravelengine_toggled( $row['is_shared'] ?? false ) ? $id . ':shared' : $id;
	}

	/**
	 * @inheritDoc
	 */
	public function normalize( array $lines ): array {
		// Checkout writes upgrades without a `parent_id`; each belongs to the room row above it.
		$room = null;

		foreach ( $lines as $at => $line ) {
			if ( 'upgraded' !== ( $line['accommodation_type'] ?? '' ) ) {
				$room = $line['id'] ?? null;
			} elseif ( ! isset( $line['parent_id'] ) && null !== $room ) {
				$lines[ $at ]['parent_id'] = $room;
			}
		}

		return $lines;
	}

	/**
	 * @inheritDoc
	 */
	public function apply( array $line, array $row, bool $is_new ): array {
		$line = parent::apply( $line, $row, $is_new );

		if ( isset( $row['accommodation_type'] ) ) {
			$line['accommodation_type'] = 'upgraded' === $row['accommodation_type'] ? 'upgraded' : 'default';
		}

		if ( isset( $row['is_shared'] ) ) {
			$line['is_shared'] = $this->to_yes_no( $row['is_shared'] );
		}

		if ( isset( $row['parent_id'] ) && 'upgraded' === ( $line['accommodation_type'] ?? '' ) ) {
			$line['parent_id'] = is_numeric( $row['parent_id'] ) ? (int) $row['parent_id'] : sanitize_text_field( (string) $row['parent_id'] );
		}

		if ( isset( $row['no_of_guests'] ) ) {
			$line['no_of_guests'] = max( 0, (int) $row['no_of_guests'] );
		}

		if ( isset( $row['shared_travellers'] ) ) {
			$line['shared_travellers'] = max( 0, (int) $row['shared_travellers'] );
		}

		if ( wptravelengine_toggled( $line['is_manual'] ?? false ) ) {
			$line['accommodation_type'] = 'default';
			$line['is_shared']          = 'no';
			$line['shared_travellers']  = 0;
		}

		$line += array(
			'accommodation_type' => 'default',
			'is_shared'          => 'no',
			'no_of_guests'       => 0,
			'shared_travellers'  => 0,
		);

		if ( isset( $row['actual_price'] ) ) {
			$line['actual_price'] = $this->amount( $row['actual_price'] );
		} elseif ( 'no' === $line['is_shared'] || ! isset( $line['actual_price'] ) ) {
			$line['actual_price'] = $line['price'] ?? 0;
		}

		return $line;
	}

	/**
	 * @inheritDoc
	 */
	public function to_resource( array $row ): array {
		return parent::to_resource( $row ) + array(
			'accommodation_type' => (string) ( $row['accommodation_type'] ?? 'default' ),
			'actual_price'       => $this->amount( $row['actual_price'] ?? $row['price'] ?? 0 ),
			'is_shared'          => wptravelengine_toggled( $row['is_shared'] ?? 'no' ),
			'no_of_guests'       => (int) ( $row['no_of_guests'] ?? 0 ),
			'shared_travellers'  => (int) ( $row['shared_travellers'] ?? 0 ),
			'parent_id'          => $row['parent_id'] ?? null,
		);
	}

	/**
	 * @inheritDoc
	 */
	public function reserve( array $held, array $lines ): array {
		$rooms = array();

		// Rebuilt in the shape the accommodation add-on reads back when a payment link re-creates the cart: upgrades nest under their room.
		foreach ( $lines as $line ) {
			if ( 'upgraded' === ( $line['accommodation_type'] ?? '' ) ) {
				continue;
			}

			$rooms[ $this->row_key( $line ) ] = array(
				'id'                => $line['id'] ?? null,
				'label'             => $line['label'] ?? '',
				'quantity'          => (int) ( $line['quantity'] ?? 0 ),
				'actual_price'      => $line['actual_price'] ?? $line['price'] ?? 0,
				'total'             => $line['total'] ?? 0,
				'is_shared'         => wptravelengine_toggled( $line['is_shared'] ?? false ),
				'shared_travellers' => (int) ( $line['shared_travellers'] ?? 0 ),
				'manual'            => wptravelengine_toggled( $line['is_manual'] ?? false ),
				'roomUpgrades'      => array(),
			);
		}

		foreach ( $lines as $line ) {
			$parent = (string) ( $line['parent_id'] ?? '' );

			if ( 'upgraded' === ( $line['accommodation_type'] ?? '' ) && isset( $rooms[ $parent ] ) ) {
				$rooms[ $parent ]['roomUpgrades'][] = array(
					'id'       => $line['id'] ?? null,
					'label'    => $line['label'] ?? '',
					'quantity' => (int) ( $line['quantity'] ?? 0 ),
					'price'    => $line['price'] ?? 0,
					'total'    => $line['total'] ?? 0,
				);
			}
		}

		return array_values( $rooms );
	}
}
