<?php
/**
 * Thank You — sidebar order-summary card (region I).
 *
 * @package WPTravelEngine
 * @since 6.3.3
 *
 * @var string $payment_status Human-readable payment status label.
 * @var bool   $is_completed   Whether the payment is fully settled.
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- template-scoped locals passed via compact().
$payment_status = $payment_status ?? '';
$is_completed   = $is_completed ?? false;
// phpcs:enable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
?>
<div class="wpte-thankyou__cart-summary wpte-card">
	<div class="wpte-thankyou__summary-head">
		<span class="wpte-thankyou__summary-heading"><?php echo esc_html__( 'Order Summary', 'wp-travel-engine' ); ?></span>
		<?php if ( ! empty( $payment_status ) ) : ?>
			<span class="wpte-thankyou__paid-badge<?php echo $is_completed ? '' : ' wpte-thankyou__paid-badge--pending'; ?>">
				<?php if ( $is_completed ) : ?>
					<svg width="12" height="12" viewBox="0 0 14 14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="2 7 5.5 10.5 12 3.5" /></svg>
				<?php endif; ?>
				<?php echo esc_html( $payment_status ); ?>
			</span>
		<?php endif; ?>
	</div>
	<div class="wpte-thankyou__summary-body">
		<?php
		// Established thank-you template-part hook name (kept for back-compat).
		// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound, WordPress.NamingConventions.ValidHookName.UseUnderscores
		do_action( 'thankyou_template_parts_cart-summary' );
		// phpcs:enable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound, WordPress.NamingConventions.ValidHookName.UseUnderscores
		?>
	</div>
</div>
