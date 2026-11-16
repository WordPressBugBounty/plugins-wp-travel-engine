<?php
/**
 * Thank You — payment details card (region G).
 *
 * @package WPTravelEngine
 * @since 6.3.3
 *
 * @var float  $payment_amount
 * @var string $transaction_id
 * @var string $transaction_date
 * @var string $payment_gateway
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- template-scoped locals passed via compact().
$transaction_id   = $transaction_id ?? '';
$transaction_date = $transaction_date ?? '';
$payment_gateway  = $payment_gateway ?? '';
// phpcs:enable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
?>
<div class="wpte-card">
	<div class="wpte-card__header">
		<h2 class="wpte-card__title">
			<span class="wpte-card__title-icon">
				<svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M13.3334 15L15 16.6667L18.3334 13.3333M18.3334 8.33333H1.66669M18.3334 10V6.83333C18.3334 5.89991 18.3334 5.4332 18.1517 5.07668C17.9919 4.76308 17.7369 4.50811 17.4233 4.34832C17.0668 4.16667 16.6001 4.16667 15.6667 4.16667H4.33335C3.39993 4.16667 2.93322 4.16667 2.5767 4.34832C2.2631 4.50811 2.00813 4.76308 1.84834 5.07668C1.66669 5.4332 1.66669 5.89991 1.66669 6.83333V13.1667C1.66669 14.1001 1.66669 14.5668 1.84834 14.9233C2.00813 15.2369 2.2631 15.4919 2.5767 15.6517C2.93322 15.8333 3.39993 15.8333 4.33335 15.8333H10" /></svg>
			</span>
			<?php echo esc_html__( 'Payment Details', 'wp-travel-engine' ); ?>
		</h2>
	</div>
	<div class="wpte-card__body">
		<div class="wpte-thankyou__ic-rows">
			<?php if ( ! empty( $transaction_id ) ) : ?>
				<div class="wpte-thankyou__ic-row">
					<span class="wpte-thankyou__ic-label"><?php echo esc_html__( 'Transaction ID', 'wp-travel-engine' ); ?></span>
					<span class="wpte-thankyou__ic-value"><?php echo esc_html( $transaction_id ); ?></span>
				</div>
			<?php endif; ?>
			<?php if ( ! empty( $transaction_date ) ) : ?>
				<div class="wpte-thankyou__ic-row">
					<span class="wpte-thankyou__ic-label"><?php echo esc_html__( 'Payment date', 'wp-travel-engine' ); ?></span>
					<span class="wpte-thankyou__ic-value"><?php echo esc_html( $transaction_date ); ?></span>
				</div>
			<?php endif; ?>
			<?php if ( ! empty( $payment_gateway ) ) : ?>
				<div class="wpte-thankyou__ic-row">
					<span class="wpte-thankyou__ic-label"><?php echo esc_html__( 'Payment gateway', 'wp-travel-engine' ); ?></span>
					<span class="wpte-thankyou__ic-value"><?php echo esc_html( $payment_gateway ); ?></span>
				</div>
			<?php endif; ?>
			<div class="wpte-thankyou__ic-row">
				<span class="wpte-thankyou__ic-label"><?php echo esc_html__( 'Amount paid', 'wp-travel-engine' ); ?></span>
				<span class="wpte-thankyou__ic-value wpte-thankyou__ic-value--primary">
					<?php wptravelengine_the_price_with_decimal( $payment_amount ); ?>
				</span>
			</div>
		</div>
	</div>
</div>
