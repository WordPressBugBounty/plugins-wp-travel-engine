<?php
/**
 * Travel insurance line items.
 *
 * @package WPTravelEngine
 * @since 6.9.0
 */

namespace WPTravelEngine\Core\Booking\LineItems;

/**
 * Insurance plans: keyed by `plan_id`, one per traveler.
 */
class TravelInsurance extends AbstractBase {

	/**
	 * @inheritDoc
	 */
	public function id_key(): string {
		return 'plan_id';
	}

	/**
	 * @inheritDoc
	 */
	public function normalize( array $lines ): array {
		foreach ( $lines as $at => $line ) {
			if ( '' === $this->row_key( $line ) ) {
				$lines[ $at ][ $this->id_key() ] = "manual_{$at}";
			}
		}

		return $lines;
	}

	/**
	 * @inheritDoc
	 */
	public function is_per_traveler(): bool {
		return true;
	}

	/**
	 * @inheritDoc
	 */
	public function class_name(): ?string {
		return 'TravelInsurance\\TravelInsurance';
	}
}
