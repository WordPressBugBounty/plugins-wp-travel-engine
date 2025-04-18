<?php
/**
 * @since 6.4.0
 */

/**
 * @var Booking $booking
 */

use WPTravelEngine\Core\Models\Post\Booking;

?>

<div>
	<button type="button" class="wpte-button wpte-outlined"
			data-booking-id="<?php echo esc_attr( $booking->get_id() ); ?>" id="wte-resend-purchase-receipt"
			data-nonce="<?php echo wp_create_nonce( 'wte_resend_purchase_receipt' ); ?>">
		<svg width="20" height="20" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg">
			<path
				d="M1.66675 5.83331L8.47085 10.5962C9.02182 10.9819 9.29731 11.1747 9.59697 11.2494C9.86166 11.3154 10.1385 11.3154 10.4032 11.2494C10.7029 11.1747 10.9783 10.9819 11.5293 10.5962L18.3334 5.83331M5.66675 16.6666H14.3334C15.7335 16.6666 16.4336 16.6666 16.9684 16.3942C17.4388 16.1545 17.8212 15.772 18.0609 15.3016C18.3334 14.7668 18.3334 14.0668 18.3334 12.6666V7.33331C18.3334 5.93318 18.3334 5.23312 18.0609 4.69834C17.8212 4.22793 17.4388 3.84548 16.9684 3.6058C16.4336 3.33331 15.7335 3.33331 14.3334 3.33331H5.66675C4.26662 3.33331 3.56655 3.33331 3.03177 3.6058C2.56137 3.84548 2.17892 4.22793 1.93923 4.69834C1.66675 5.23312 1.66675 5.93318 1.66675 7.33331V12.6666C1.66675 14.0668 1.66675 14.7668 1.93923 15.3016C2.17892 15.772 2.56137 16.1545 3.03177 16.3942C3.56655 16.6666 4.26662 16.6666 5.66675 16.6666Z"
				stroke="currentColor" stroke-width="1.39" stroke-linecap="round" stroke-linejoin="round" />
		</svg>
		<?php echo __( 'Resend Purchase Receipt', 'wp-travel-engine' ); ?>
	</button>
</div>
