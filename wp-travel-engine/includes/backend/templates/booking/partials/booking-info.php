<?php
/**
 * @var CartInfoParser $cart_info
 * @var OrderTripEditFormFields $order_trip_form_fields
 */

use WPTravelEngine\Builders\FormFields\OrderTripEditFormFields;
use WPTravelEngine\Helpers\CartInfoParser;

$order_trip = $cart_info->get_item();
?>

<?php if ( 'edit' !== $template_mode ) : ?>
	<h2 class="wpte-booking-trip-name">
		<a href="<?php echo get_the_permalink( $order_trip->get_trip_id() ); ?>"
			target="_blank"><?php echo get_the_title( $order_trip->get_trip_id() ); ?>
			<svg width="20" height="20" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg">
				<path
					d="M17.5 7.50001L17.5 2.50001M17.5 2.50001H12.5M17.5 2.50001L10 10M8.33333 2.5H6.5C5.09987 2.5 4.3998 2.5 3.86502 2.77248C3.39462 3.01217 3.01217 3.39462 2.77248 3.86502C2.5 4.3998 2.5 5.09987 2.5 6.5V13.5C2.5 14.9001 2.5 15.6002 2.77248 16.135C3.01217 16.6054 3.39462 16.9878 3.86502 17.2275C4.3998 17.5 5.09987 17.5 6.5 17.5H13.5C14.9001 17.5 15.6002 17.5 16.135 17.2275C16.6054 16.9878 16.9878 16.6054 17.2275 16.135C17.5 15.6002 17.5 14.9001 17.5 13.5V11.6667"
					stroke="#859094" stroke-width="1.39" stroke-linecap="round" stroke-linejoin="round" />
			</svg>
		</a>
	</h2>
<?php endif ?>
<!-- booking-detail-fields -->
<div class="wpte-form-section" data-target-id="trip-details">
	<div class="wpte-fields-grid" data-columns="3">
		<?php $order_trip_form_fields->render(); ?>
	</div>
</div>
