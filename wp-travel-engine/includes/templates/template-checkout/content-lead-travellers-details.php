<?php
/**
 * @var WPTravelEngine\Builders\FormFields\FormField $lead_travellers_form_fields
 * @var bool $show_title
 * @since 6.4.3
 */
if ( 'hide' === ( $args['attributes']['lead-travellers'] ?? '' ) ) {
	return;
}
// Lead Traveller's Details Form.
if ( isset( $lead_travellers_form_fields ) && isset( $lead_travellers_form_fields[0]->fields ) && empty( $lead_travellers_form_fields[0]->fields ) ) {
	return;
}

$wpte_fill_later_message = wptravelengine_settings()->get( 'fill_later_message', '' );
if ( '' === $wpte_fill_later_message ) {
	$wpte_fill_later_message = __( "No problem - add them later. You can complete traveler details for this trip from your dashboard after completing checkout. Your booking isn't affected.", 'wp-travel-engine' );
}

$wpte_show_fill_later = apply_filters(
	'wptravelengine_checkout_show_fill_later',
	'all' === wptravelengine_settings()->get( 'travellers_details_type', 'all' )
	&& 'pay_later' === wptravelengine_settings()->get( 'traveler_details_timing', 'before_pay' )
	// No new account will be created in 'none' mode, but an already logged-in visitor has a dashboard to come back to regardless of that setting.
	&& ( 'none' !== wptravelengine_account_creation_mode() || is_user_logged_in() )
);
?>
<div class="wpte-checkout__box wpte-checkout__box--travelers">
	<?php if ( $show_title ) : ?>
		<h3 class="wpte-checkout__box-title">
			<span class="wpte-checkout__box-title-icon" aria-hidden="true">
				<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"
					stroke-linejoin="round">
					<path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2" />
					<circle cx="12" cy="7" r="4" />
				</svg>
			</span>
			<?php echo __( 'Traveler Details', 'wp-travel-engine' ); ?>
			<?php if ( $wpte_show_fill_later ) : ?>
				<label class="wpte-checkout__header-check wpte-checkout__fill-later">
					<input type="checkbox" id="wpte-fill-details-later" data-fill-details-later data-onchange="update">
					<span><?php esc_html_e( "I'll fill details later", 'wp-travel-engine' ); ?></span>
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
		<?php
		foreach ( $lead_travellers_form_fields as $lead_travellers_form_field ) {
			$lead_travellers_form_field->render();
		}
		// Additional travellers render inside this same box (one parent card).
		do_action( 'checkout_template_parts_travellers-details' );
		?>
	</div>
	<?php if ( $wpte_show_fill_later ) : ?>
		<div class="wpte-checkout__notice" data-fill-later-notice hidden>
			<span class="wpte-checkout__notice-icon" aria-hidden="true">
				<svg width="24" height="24" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
					<path
						d="M12 16V12M12 8H12.01M22 12C22 17.5228 17.5228 22 12 22C6.47715 22 2 17.5228 2 12C2 6.47715 6.47715 2 12 2C17.5228 2 22 6.47715 22 12Z"
						stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" />
				</svg>
			</span>
			<?php echo esc_html( $wpte_fill_later_message ); ?>
		</div>
	<?php endif; ?>
</div>