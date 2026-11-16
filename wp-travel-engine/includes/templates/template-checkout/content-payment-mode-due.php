<?php
/**
 * Remaining-balance payment mode — single selectable pay-amount card.
 *
 * Rendered when settling an existing booking's outstanding balance (payment_type
 * due/remaining_payment). Mirrors the `content-payment-modes.php` card markup so the
 * remaining-amount option matches the pay-in-full / reserve cards. Wrapped in a plain
 * `.wpte-checkout__payment-options` (not the 2-col grid) so the single card is full width.
 *
 * @var string $due_payment_amount
 * @var string $payment_mode
 * @since 6.3.0
 * @since 6.9.0 Restyled from a legacy `.wpte-bf-label` radio into a pay-amount card.
 */

global $wte_cart;
?>
<div class="wpte-checkout__payment-options wpte-checkout__pay-amount-grid wpte-checkout__pay-amount-grid--single">
	<div class="wpte-checkout__form-control wpte-checkout__pay-amount checked">
		<label for="wp_travel_engine_payment_mode-remaining">
			<span class="wpte-checkout__pay-amount-head">
				<input type="radio" name="wp_travel_engine_payment_mode"
						value="remaining_payment"
						id="wp_travel_engine_payment_mode-remaining" checked>
				<span class="wpte-checkout__pay-amount-title"><?php echo esc_html( apply_filters( 'wptravelengine_checkout_due_pay_title', __( 'Remaining Amount', 'wp-travel-engine' ) ) ); ?></span>
			</span>
			<span class="wpte-checkout__pay-amount-value"><?php echo wp_kses_post( wptravelengine_the_price( $due_payment_amount, false, false ) ); ?></span>
			<span class="wpte-checkout__pay-amount-desc"><?php esc_html_e( 'Outstanding balance for your booking — settle it to confirm.', 'wp-travel-engine' ); ?></span>
		</label>
	</div>
</div>
