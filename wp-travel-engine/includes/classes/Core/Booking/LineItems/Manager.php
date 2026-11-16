<?php
/**
 * Line item group registry.
 *
 * @package WPTravelEngine
 * @since 6.9.0
 */

namespace WPTravelEngine\Core\Booking\LineItems;

use WPTravelEngine\Core\Booking\SaveBooking;

/**
 * Resolves group handlers and writes groups into the cart.
 */
class Manager {

	/**
	 * Built handlers, by group key.
	 *
	 * @var array<string, AbstractBase>
	 */
	protected static array $handlers = array();

	/**
	 * Handler classes, by group key; null until resolved.
	 *
	 * @var array<string, class-string<AbstractBase>>|null
	 */
	protected static ?array $classes = null;

	/**
	 * Handler classes, by group key.
	 *
	 * @return array<string, class-string<AbstractBase>>
	 */
	protected static function classes(): array {
		if ( null === static::$classes ) {
			/**
			 * Filters the handler class for each line item group.
			 *
			 * @param array<string, class-string<AbstractBase>> $classes Handler class by group key.
			 * @since 6.9.0
			 */
			static::$classes = (array) apply_filters(
				'wptravelengine_save_booking_line_items',
				array(
					'pricing_category' => PriceCategory::class,
					'traveler'         => PriceCategory::class,
					'extra_service'    => ExtraService::class,
					'accommodation'    => Accommodation::class,
					'travel_insurance' => TravelInsurance::class,
					'pickup_point'     => PickupPoint::class,
				)
			);
		}

		return static::$classes;
	}

	/**
	 * Registered group keys.
	 *
	 * @return string[]
	 */
	public static function keys(): array {
		return array_map( 'strval', array_keys( static::classes() ) );
	}

	/**
	 * Handler for a group.
	 *
	 * @param string $group Group key.
	 * @return AbstractBase
	 */
	public static function get( string $group ): AbstractBase {
		if ( isset( static::$handlers[ $group ] ) ) {
			return static::$handlers[ $group ];
		}

		$class = static::classes()[ $group ] ?? Base::class;

		if ( ! is_subclass_of( $class, AbstractBase::class ) ) {
			$class = Base::class;
		}

		static::$handlers[ $group ] = new $class( $group );

		return static::$handlers[ $group ];
	}

	/**
	 * Writes a group's payload rows into the save's cart.
	 *
	 * @param SaveBooking                      $save  The save in progress.
	 * @param string|int                       $key   Cart item key.
	 * @param string                           $group Group key.
	 * @param array<int, array<string, mixed>> $rows  Payload rows.
	 * @return array<int, int> Added, removed, updated.
	 */
	public static function write( SaveBooking $save, $key, string $group, array $rows ): array {
		return static::get( $group )->write( $save, $key, $rows );
	}
}
