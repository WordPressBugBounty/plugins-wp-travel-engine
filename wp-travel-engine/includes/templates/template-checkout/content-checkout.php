<?php
/**
 * Checkout V2 content Template.
 *
 * @since 6.3.0
 */

/**
 * @var object $booking_checkout
 * @var array $form_instances
 * @var array $attributes Checkout UI Attributes.
 * @var array $tour_details Tour Details.
 * @var array $booking_summary Booking Summary.
 * @var array $form_sections Form Sections.
 */

if ( 'show' === $attributes['checkout-steps'] ?? 'show' ) {
	wptravelengine_get_template( 'template-checkout/content-checkout-steps.php' );
}
global $post;
$shortcode_present = has_shortcode( $post->post_content, 'WP_TRAVEL_ENGINE_PLACE_ORDER' );
if ( ! $shortcode_present ) :
	?>
<main class="wpte-checkout__main">
	<div class="wpte-checkout__container">
		<h1 class="wpte-checkout__page-title"><?php echo __( 'Checkout', 'wp-travel-engine' ); ?></h1>
		<?php endif; ?>
		<div class="wpte-checkout__page-layout">
			<div class="wpte-checkout__sidebar">
				<?php
				do_action( 'checkout_template_parts_tour-details' );
				?>
				<div class="wpte-checkout__box wpte-checkout__booking-summary-box collapsible collapsible-mobile">
						<div class="wpte-checkout__box-title toggler-wrap">
							<h3><?php esc_html_e( 'Order Summary', 'wp-travel-engine' ); ?></h3>
							<button type="button" class="wpte-checkout__box-toggle-button" aria-label="<?php esc_attr_e( 'Toggle price breakdown', 'wp-travel-engine' ); ?>">
								<svg>
									<use xlink:href="#chevron-down"></use>
								</svg>
							</button>
						</div>
					<div class="wpte-checkout__box-content" data-cart-summary>
						<?php do_action( 'checkout_template_parts_cart-summary' ); ?>
					</div>
				</div>
			</div>
			<?php do_action( 'checkout_template_parts_checkout-form' ); ?>
		</div>
		<?php
		if ( ! $shortcode_present ) :
			?>
	</div>
</main>
<?php endif; ?>
<!-- Mobile sticky pay bar -->
<div class="wpte-checkout__mobile-bar" data-checkout-mobile-bar>
	<div class="wpte-checkout__mobile-bar-info">
		<span class="wpte-checkout__mobile-bar-label"><?php esc_html_e( 'Total due today', 'wp-travel-engine' ); ?></span>
		<span class="wpte-checkout__mobile-bar-amount" data-mobile-bar-amount></span>
	</div>
	<button type="button" class="wpte-checkout__mobile-bar-button" data-checkout-scroll-to-pay>
		<?php esc_html_e( 'Complete booking', 'wp-travel-engine' ); ?>
	</button>
</div>
<?php wptravelengine_get_template( 'template-checkout/content-sprite-svg.php' ); ?>
