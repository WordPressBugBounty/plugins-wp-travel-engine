<?php
/**
 * @var string $payment_mode
 * @var string $due_payment_amount
 * @var string $full_payment_amount
 * @var bool $full_payment_enabled
 * @var float $down_payment_amount
 * @since 6.3.0
 * @since 6.9.0 Rendered as selectable pay-amount cards.
 */
?>
<div class="wpte-checkout__payment-options wpte-checkout__pay-amount-grid">
	<?php if ( $full_payment_enabled ) : ?>
		<div class="wpte-checkout__form-control wpte-checkout__pay-amount">
			<label for="wp_travel_engine_payment_mode-full">
				<span class="wpte-checkout__pay-amount-head">
					<input type="radio" name="wp_travel_engine_payment_mode"
							value="full_payment"
							id="wp_travel_engine_payment_mode-full"
						<?php checked( 'full' === $payment_mode ); ?>
					>
					<span class="wpte-checkout__pay-amount-title"><?php echo esc_html( apply_filters( 'wptravelengine_checkout_full_pay_label', __( 'Pay in full', 'wp-travel-engine' ) ) ); ?></span>
				</span>
				<span class="wpte-checkout__pay-amount-value"><?php echo wp_kses_post( wptravelengine_the_price( $full_payment_amount, false, false ) ); ?></span>
				<span class="wpte-checkout__pay-amount-desc"><?php esc_html_e( 'Everything settled today — nothing left to pay later.', 'wp-travel-engine' ); ?></span>
			</label>
		</div>
	<?php endif; ?>
	<div class="wpte-checkout__form-control wpte-checkout__pay-amount">
		<label for="wp_travel_engine_payment_mode-partial">
			<span class="wpte-checkout__pay-amount-head">
				<input type="radio" name="wp_travel_engine_payment_mode" value="partial"
						id="wp_travel_engine_payment_mode-partial" <?php checked( 'partial' === $payment_mode ); ?>>
				<span class="wpte-checkout__pay-amount-title"><?php echo esc_html( apply_filters( 'wptravelengine_checkout_down_pay_label', __( 'Reserve with', 'wp-travel-engine' ) ) ); ?></span>
			</span>
			<span class="wpte-checkout__pay-amount-value"><?php echo wp_kses_post( wptravelengine_the_price( $down_payment_amount, false, false ) ); ?> <?php esc_html_e( 'today', 'wp-travel-engine' ); ?></span>
			<span class="wpte-checkout__pay-amount-desc">
				<?php
				/* translators: %s: remaining balance amount */
				printf( esc_html__( 'Balance %s due before your trip starts.', 'wp-travel-engine' ), wp_kses_post( wptravelengine_the_price( $due_payment_amount, false, false ) ) );
				?>
			</span>
		</label>
	</div>
	<?php do_action( 'wptravelengine_after_checkout_payment_modes_', $args ); ?>
</div>
