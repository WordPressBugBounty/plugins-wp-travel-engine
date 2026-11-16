<?php
/**
 * Thank You Page Header — booking confirmation banner (region B).
 *
 * @package WPTravelEngine
 * @since 6.2.3
 *
 * @var string $thankyou_message  Admin-configured confirmation message (Settings > Checkout > Booking Confirmation Message). Supports the {email} merge tag.
 * @var string $booking_reference Trip code shown as the booking reference (e.g. "WTE-2762").
 * @var string $billing_email     Lead/billing email the confirmation was sent to.
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- template-scoped locals passed via compact().
$booking_reference = $booking_reference ?? '';
$billing_email     = $billing_email ?? '';
$account_created   = $account_created ?? false;

$wpte_confirm_message = strtr(
	$thankyou_message ?? '',
	array(
		'{email}' => ! empty( $billing_email ) ? '<strong>' . esc_html( $billing_email ) . '</strong>' : '',
	)
);
// phpcs:enable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
?>
<!-- ThankYou Confirmation Banner -->
<div class="wpte-thankyou__banner">
	<div class="wpte-thankyou__banner-inner">
		<div class="wpte-thankyou__banner-left">
			<div class="wpte-thankyou__banner-check">
				<svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="4 10 8 14 16 5" /></svg>
			</div>
			<div>
				<div class="wpte-thankyou__banner-title"><?php echo esc_html__( 'Booking Confirmed', 'wp-travel-engine' ); ?></div>
				<?php if ( ! empty( $wpte_confirm_message ) ) : ?>
					<div class="wpte-thankyou__confirm-line">
						<?php if ( ! empty( $billing_email ) ) : ?>
							<svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><rect x="2.5" y="4.5" width="15" height="11" rx="2" /><path d="M2.5 5.5l7.5 6 7.5-6" /></svg>
						<?php endif; ?>
						<span><?php echo wp_kses_post( $wpte_confirm_message ); ?></span>
					</div>
				<?php endif; ?>
				<?php if ( $account_created ) : ?>
					<div class="wpte-thankyou__confirm-line">
						<svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><rect x="4" y="8.5" width="12" height="8" rx="1.5" /><path d="M6.5 8.5V6a3.5 3.5 0 0 1 7 0v2.5" /></svg>
						<span>
							<strong><?php echo esc_html__( 'Your account has been created.', 'wp-travel-engine' ); ?></strong>
							<?php echo esc_html__( 'Check your email to set your password and access your bookings.', 'wp-travel-engine' ); ?>
						</span>
					</div>
				<?php endif; ?>
			</div>
		</div>
		<?php if ( ! empty( $booking_reference ) ) : ?>
			<div class="wpte-thankyou__banner-right">
				<div class="wpte-thankyou__ref-label"><?php echo esc_html__( 'Booking Reference', 'wp-travel-engine' ); ?></div>
				<div class="wpte-thankyou__ref-row">
					<span class="wpte-thankyou__ref-code"><?php echo '#' . esc_html( $booking_reference ); ?></span>
				</div>
			</div>
		<?php endif; ?>
	</div>
</div>
