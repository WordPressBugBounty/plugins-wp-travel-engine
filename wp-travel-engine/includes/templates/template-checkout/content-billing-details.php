<?php

/**
 * @var WPTravelEngine\Builders\FormFields\BillingFormFields $billing_form_fields
 * @var int $lead_travellers_fields_count
 * @var bool $show_title
 * @since 6.3.0
 * @since 6.9.0 "Same as Lead Traveler" moved into the card header.
 */

$wpte_show_same_as_lead = wptravelengine_settings()->get( 'display_travellers_info' ) === 'yes'
	&& ( $payment_type ?? '' ) !== 'due'
	&& $lead_travellers_fields_count > 0;
?>
<!-- Billing Details Form -->
<div class="wpte-checkout__box collapsible <?php echo esc_attr( $show_title ? 'open' : '' ); ?>">
	<?php if ( $show_title ) : ?>
		<h3 class="wpte-checkout__box-title">
			<span class="wpte-checkout__box-title-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/><path d="M16 13H8"/><path d="M16 17H8"/><path d="M10 9H8"/></svg></span>
			<?php echo esc_html( apply_filters( 'wpte_billings_details_title', esc_html__( 'Billing Details', 'wp-travel-engine' ) ) ); ?>
			<?php if ( $wpte_show_same_as_lead ) : ?>
				<label class="wpte-checkout__header-check wpte-copy-from-lead-travelers">
					<input type="checkbox" id="wpte-copy-from-lead-travelers" name="wpte-copy-from-lead-travelers" value="1">
					<span><?php esc_html_e( 'Same as Lead Traveler', 'wp-travel-engine' ); ?></span>
				</label>
			<?php endif; ?>
			<button type="button" class="wpte-checkout__box-toggle-button">
				<svg>
					<use xlink:href="#chevron-down"></use>
				</svg>
			</button>
		</h3>
	<?php endif; ?>
	<div class="wpte-checkout__box-content">
		<?php $billing_form_fields->render(); ?>
	</div>
</div>
