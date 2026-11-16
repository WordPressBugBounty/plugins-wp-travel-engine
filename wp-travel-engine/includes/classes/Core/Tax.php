<?php

namespace WPTravelEngine\Core;

use WPTravelEngine\Interfaces\Tax as TaxInterface;

class Tax implements TaxInterface {

	protected array $settings = array();

	/**
	 * Tax percentage
	 *
	 * @var float
	 */
	protected float $tax_percentage = 0;

	/**
	 * Tax as the given settings define it.
	 *
	 * @param array<string, mixed>|null $settings Tax settings to answer for, such as a booking's own; null reads the site's.
	 * @since 6.9.0 Takes the settings to answer for.
	 */
	public function __construct( ?array $settings = null ) {
		$settings     ??= wptravelengine_settings()->get();
		$this->settings = is_array( $settings ) ? $settings : array();

		$this->tax_percentage = (float) ( $this->settings['tax_percentage'] ?? 0 );
	}

	/**
	 * Tax as the given booking was charged it, from its snapshot (the site's settings for a legacy booking without one).
	 *
	 * @param int $booking_id Booking to answer for.
	 * @return self
	 * @since 6.9.0
	 */
	public static function for_booking( int $booking_id ): self {
		$booking = wptravelengine_get_booking( $booking_id, true );

		return new self( $booking ? $booking->get_tax_settings_snapshot() : null );
	}

	/**
	 * Get Tax label
	 *
	 * @param null $tax_percentage
	 *
	 * @return string
	 */
	public function get_tax_label( $tax_percentage = null ): string {
		$label_format = $this->settings['tax_label'] ?? __( 'Tax (%s%%)', 'wp-travel-engine' );

		if ( is_null( $tax_percentage ) ) {
			$tax_percentage = $this->tax_percentage;
		}

		return apply_filters( __FUNCTION__, sprintf( $label_format, $tax_percentage ), $tax_percentage );
	}

	/**
	 * Get Tax percentage
	 *
	 * @return float
	 */
	public function get_tax_percentage(): float {
		return $this->tax_percentage;
	}

	/**
	 * Get Tax amount
	 *
	 * @param float $price
	 *
	 * @return float
	 */
	public function get_tax_amount( float $price ): float {
		$tax_percentage = $this->get_tax_percentage();

		return ( $price * $tax_percentage ) / 100;
	}

	public function is_inclusive(): bool {
		return ( $this->settings['tax_type_option'] ?? false ) === 'inclusive';
	}

	public function is_exclusive(): bool {
		return ( $this->settings['tax_type_option'] ?? false ) === 'exclusive';
	}

	public function is_taxable(): bool {
		return ( $this->settings['tax_enable'] ?? 'no' ) === 'yes';
	}

	/**
	 * Set tax percentage
	 *
	 * @since 6.0.0
	 */
	public function set_tax_percentage( float $percentage ) {
		$this->tax_percentage = $percentage;
	}

	/**
	 * The `tax_*` settings this answers for; the rest of the plugin settings are left out.
	 *
	 * @return array<string, mixed>
	 * @since 6.9.0
	 */
	public function get_settings(): array {
		$settings = array();
		foreach ( $this->settings as $key => $value ) {
			if ( 0 === strpos( (string) $key, 'tax_' ) ) {
				$settings[ $key ] = $value;
			}
		}

		$settings['tax_percentage'] = $this->tax_percentage;

		return $settings;
	}
}
