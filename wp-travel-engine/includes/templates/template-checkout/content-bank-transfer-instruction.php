<?php
/**
 * Direct Bank Transfer Instructions template.
 *
 * @since 6.3.3
 * @since 6.9.0 Rendered as a thank-you info card with label/value rows (matches Billing/Payment).
 *
 * @var string $instruction  Instructions.
 * @var array  $bank_details Bank details (array of accounts, each a map of field => [label, value]).
 */

?>
<div class="wpte-card">
	<div class="wpte-card__header">
		<h2 class="wpte-card__title">
			<span class="wpte-card__title-icon">
				<svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M3 8l7-4 7 4" /><path d="M4.5 8v7M8 8v7M12 8v7M15.5 8v7" /><path d="M2.5 17.5h15" /></svg>
			</span>
			<?php echo esc_html__( 'Bank Details', 'wp-travel-engine' ); ?>
		</h2>
	</div>
	<div class="wpte-card__body">
		<?php if ( ! empty( $instruction ) ) : ?>
			<p class="wpte-thankyou__bank-instruction"><?php echo wp_kses_post( nl2br( $instruction ) ); ?></p>
		<?php endif; ?>
		<?php if ( isset( $bank_details[0] ) ) : ?>
			<?php $wpte_multi_account = count( $bank_details ) > 1; ?>
			<?php foreach ( $bank_details as $wpte_index => $wpte_bank ) : ?>
				<div class="wpte-thankyou__bank-account">
					<?php if ( $wpte_multi_account ) : ?>
						<div class="wpte-thankyou__bank-account-label">
							<?php
							/* translators: %d: bank account number in the list. */
							printf( esc_html__( 'Account %d', 'wp-travel-engine' ), (int) $wpte_index + 1 );
							?>
						</div>
					<?php endif; ?>
					<div class="wpte-thankyou__ic-rows">
						<?php foreach ( $wpte_bank as $wpte_detail ) : ?>
							<div class="wpte-thankyou__ic-row">
								<span class="wpte-thankyou__ic-label"><?php echo esc_html( rtrim( $wpte_detail['label'], ':' ) ); ?></span>
								<span class="wpte-thankyou__ic-value"><?php echo esc_html( $wpte_detail['value'] ); ?></span>
							</div>
						<?php endforeach; ?>
					</div>
				</div>
			<?php endforeach; ?>
		<?php endif; ?>
	</div>
</div>
