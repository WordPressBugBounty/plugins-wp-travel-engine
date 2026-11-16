<?php
/**
 * Content cart summary template.
 *
 * @var array $cart_line_items
 * @var float $deposit_amount
 * @var float $due_amount
 * @var bool $is_partial_payment
 * @var bool $show_title
 * @var bool $show_coupon_form
 * @var Checkout $checkout
 * @var \WPTravelEngine\Core\Cart\Cart $_wte_cart
 * @var \WPTravelEngine\Core\Cart\Cart $wte_cart
 * @since 6.3.0
 * @since 6.9.0 Rendered as div-based markup instead of a table.
 */
use WPTravelEngine\Pages\Checkout;

global $wte_cart;

$checkout = $template_instance ?? new Checkout( $_wte_cart ?? $wte_cart );

$wpte_summary_kses = array_merge(
	wp_kses_allowed_html( 'post' ),
	array( 'svg' => array() ),
	array( 'use' => array( 'xlink:href' => array() ) )
);
?>
	<div class="wpte-checkout__booking-summary">
		<?php
		if ( $show_coupon_form ) {
			wptravelengine_get_template( 'template-checkout/content-coupon-form.php' );
		}
		?>
		<?php if ( $show_title ) : ?>
			<h5 class="wpte-checkout__booking-summary-title"><?php esc_html_e( 'Order Summary', 'wp-travel-engine' ); ?></h5>
		<?php endif; ?>
		<div class="wpte-checkout__summary-list">
			<?php
			do_action( 'wptravelengine_cart_before_line_items', $cart_line_items );
			foreach ( $cart_line_items as $key => $lines ) {
				do_action( "wptravelengine_before_{$key}_cart_line_items", $cart_line_items );
				if ( 'line_items' === $key ) {
					foreach ( $lines as $line ) {
						foreach ( $line as $_key => $row ) {
							do_action( "wptravelengine_before_{$_key}_cart_line_items", $cart_line_items );
							echo wp_kses( is_array( $row ) ? implode( '', $row ) : $row, $wpte_summary_kses );
							do_action( "wptravelengine_cart_after_{$_key}_line_items", $cart_line_items );
						}
					}
					continue;
				}
				echo wp_kses( is_array( $lines ) ? implode( '', $lines ) : $lines, $wpte_summary_kses );
				do_action( "wptravelengine_cart_after_{$key}_line_items", $cart_line_items );
			}
			do_action( 'wptravelengine_cart_after_line_items', $cart_line_items );

			if ( $wte_cart->is_curr_cart() ) :
				// Checkout v4.0 Fragments.
				$rows_after_line_items = $checkout->get_fragments_after_line_items();
				foreach ( $rows_after_line_items as $row ) {
					echo wp_kses( is_array( $row ) ? implode( '', $row ) : $row, $wpte_summary_kses );
				}
			elseif ( $is_partial_payment ) :
				// Partial Payment.
				$wpte_deposit_label = 'due' === $wte_cart->get_payment_type() ? __( 'Deposited:', 'wp-travel-engine' ) : __( 'Deposit Today:', 'wp-travel-engine' );
				?>
				<div class="wpte-checkout__summary-line wpte-checkout__summary-line--discount">
					<span class="wpte-checkout__summary-line__label"><?php echo esc_html( $wpte_deposit_label ); ?></span>
					<span class="wpte-checkout__summary-line__value">- <?php wptravelengine_the_price( $deposit_amount ); ?></span>
				</div>
				<div class="wpte-checkout__summary-line wpte-checkout__summary-line--total">
					<span class="wpte-checkout__summary-line__label"><?php esc_html_e( 'Amount Due:', 'wp-travel-engine' ); ?></span>
					<span class="wpte-checkout__summary-line__value"><?php wptravelengine_the_price( $due_amount ); ?></span>
				</div>
				<?php
			endif;
			do_action( 'wptravelengine_at_cart_summary_end' );
			?>
		</div>
	</div>
<?php
wptravelengine_get_template( 'template-checkout/content-sprite-svg.php' );
