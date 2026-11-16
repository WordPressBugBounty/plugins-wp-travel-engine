<?php
/**
 * Thank You Page Content Template.
 *
 * @package WPTravelEngine
 * @since 6.3.3
 */

?>
<div class="wpte-thankyou__main">
	<div class="wpte-thankyou__container">
		<div class="wpte-thankyou__page-layout">
			<div class="wpte-thankyou__col-main">
				<?php do_action( 'wptravelengine_thankyou_booking_details' ); ?>
			</div>
			<aside class="wpte-thankyou__sidebar">
				<?php do_action( 'wptravelengine_thankyou_cart_summary' ); ?>
			</aside>
		</div>
	</div>
</div>
