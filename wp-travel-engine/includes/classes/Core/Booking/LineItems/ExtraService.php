<?php
/**
 * Extra service line items.
 *
 * @package WPTravelEngine
 * @since 6.9.0
 */

namespace WPTravelEngine\Core\Booking\LineItems;

use WPTravelEngine\Core\Booking\SaveBooking;
use WPTravelEngine\Core\Cart\Items\ExtraService as ExtraServiceItem;

/**
 * Extra services, reserved under `extraServices`.
 */
class ExtraService extends AbstractBase {

	/**
	 * @inheritDoc
	 */
	public function reservation_key(): string {
		return 'extraServices';
	}

	/**
	 * @inheritDoc
	 */
	public function class_name(): ?string {
		return ExtraServiceItem::class;
	}

	/**
	 * @inheritDoc
	 */
	public function write( SaveBooking $save, $key, array $rows ): array {
		$counts = parent::write( $save, $key, $rows );

		if ( array_sum( $counts ) ) {
			$this->cart()->set(
				"items.{$key}.trip_extras",
				array_map(
					static function ( array $line ): array {
						return array(
							'extra_service' => (string) ( $line['label'] ?? '' ),
							'price'         => $line['price'] ?? 0,
							'qty'           => (int) ( $line['quantity'] ?? 0 ),
						);
					},
					$this->lines( $key )
				)
			);
		}

		return $counts;
	}
}
