<?php
/**
 * Traveler Category Model.
 *
 * @package WPTravelEngine
 * @since 6.0.0
 */

namespace WPTravelEngine\Core\Models\Post;

use WPTravelEngine\Helpers\Translators;

#[\AllowDynamicProperties]
/**
 * Class TravelerCategory.
 * This class represents a traveler category to the WP Travel Engine plugin.
 *
 * @since 6.0.0
 */
class TravelerCategory {

	/**
	 * The traveler category id.
	 *
	 * @var int
	 */
	public int $id;

	/**
	 * The trip object.
	 *
	 * @var Trip
	 */
	protected Trip $trip;

	/**
	 * The package object.
	 *
	 * @var TripPackage
	 */
	protected TripPackage $package;

	/**
	 * The traveler category price.
	 *
	 * @var float|string
	 */
	public $price;

	/**
	 * The traveler category sale price.
	 *
	 * @var float|string
	 */
	public $sale_price;

	/**
	 * @var string
	 * @since 6.4.3
	 */
	public string $label;

	/**
	 * Traveler Category Model Constructor.
	 *
	 * @param Trip        $trip The trip object.
	 * @param TripPackage $package The trip package object.
	 * @param array       $package_category_data The package category data.
	 */
	public function __construct( Trip $trip, TripPackage $package, array $package_category_data ) {
		$this->trip    = $trip;
		$this->package = $package;

		$key_mapping = array(
			'c_ids'         => 'id',
			'labels'        => 'label',
			'prices'        => 'price',
			'pricing_types' => 'pricing_type',
			'sale_prices'   => 'sale_price',
			'min_paxes'     => 'min_pax',
			'max_paxes'     => 'max_pax',
			'enabled_sale'  => 'has_sale',
		);

		foreach ( $package_category_data as $property => $value ) {
			if ( isset( $key_mapping[ $property ] ) ) {
				$mapped_property = $key_mapping[ $property ];
				if ( in_array( $property, array( 'prices', 'sale_prices' ), true ) ) {
					$value = is_numeric( $value ) ? max( 0, (float) $value ) : '';
				}
				if ( $value instanceof \WP_Term ) {
					$value = $value->name;
				}
				$this->{$mapped_property} = $value;
				continue;
			}
			$this->{$property} = $value;
		}
	}

	/**
	 * Get the property value.
	 *
	 * @param string $name Name of the property
	 *
	 * @return mixed
	 * @since 6.8.7
	 */
	public function __get( string $name ) {
		return $this->$name ?? null;
	}

	/**
	 * @return string
	 * @since 6.4.3
	 * @since 6.7.4 Update label retrieval to support WPML translation.
	 */
	public function get_label(): string {
		$language = '';

		if ( Translators::is_wpml_multilingual_active() ) {
			$language = apply_filters( 'wpml_current_language', null ) ?? substr( get_locale(), 0, 2 );
		} elseif ( isset( $_GET['lang'] ) ) {
			$language = substr( sanitize_text_field( wp_unslash( $_GET['lang'] ) ), 0, 2 );
		}

		$category_term_meta = get_term_meta( $this->id, 'pll_category_name', true );
		return is_array( $category_term_meta ) && isset( $category_term_meta[ $language ] ) ? $category_term_meta[ $language ] : $this->label;
	}

	/**
	 * Get category value.
	 *
	 * @param mixed $key The key to get.
	 * @param mixed $default The default value to return if the key is not set.
	 *
	 * @return mixed
	 * @since 6.8.8 Included group pricing mapping, sale price checks, and open-ended tier appending for it.
	 */
	public function get( $key, $default = null ) {
		switch ( $key ) {
			case 'group_pricing':
				$group_pricing = $this->package->get_group_pricing()[ $this->id ] ?? array();

				$value = array();
				$total = count( $group_pricing );
				$i     = 0;

				foreach ( $group_pricing as $gp ) {
					++$i;

					$to    = is_numeric( $gp['to'] ) ? (int) $gp['to'] : '';
					$from  = is_numeric( $gp['from'] ) ? (int) $gp['from'] : 0;
					$price = ( is_numeric( $gp['price'] ) && 0.0 !== (float) $gp['price'] ) ? (float) $gp['price'] : $this->get_actual_price();

					$value[] = compact( 'from', 'to', 'price' );

					if ( $total === $i && is_numeric( $gp['from'] ) && is_numeric( $gp['to'] ) && is_numeric( $gp['price'] ) ) {
						$value[] = array(
							'from'  => (int) $gp['to'] + 1,
							'to'    => '',
							'price' => $this->get_actual_price(),
						);
					}
				}

				break;
			case 'description':
				$value = get_term_by( 'id', $this->id, 'trip-packages-categories' )->{$key};
				break;
			case 'label':
				$value = $this->get_label();
				break;
			default:
				$value = $this->{$key} ?? $default;
		}

		return $value;
	}

	/**
	 * Calculate Sale Percentage.
	 *
	 * @return float
	 */
	public function sale_percentage(): float {
		return ( ! $this->price ) ? 0 : ( ( $this->price - $this->sale_price ) / $this->price ) * 100;
	}

	/**
	 * Get the traveler category actual price.
	 *
	 * @return float|string
	 */
	public function get_price() {
		return $this->price;
	}

	/**
	 * Get the traveler category sale price.
	 *
	 * @return float|string
	 */
	public function get_sale_price() {
		return $this->sale_price;
	}

	/**
	 * Check if traveler category has sale price.
	 *
	 * @return bool
	 * @since 6.8.8
	 */
	public function has_sale(): bool {
		return $this->has_sale ?? false;
	}

	/**
	 * Get actual price.
	 *
	 * @return float|string
	 * @since 6.8.8
	 */
	public function get_actual_price() {
		return $this->has_sale() ? $this->sale_price : $this->price;
	}
}
