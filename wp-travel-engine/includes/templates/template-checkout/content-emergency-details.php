<?php
/**
 * @var WPTravelEngine\Builders\FormFields\EmergencyFormFields $emergency_contact_fields
 * @var bool $show_title
 * @since 6.3.0
 */
if ( 'hide' === ( $args['attributes']['travellers'] ?? '' ) ) {
	return;
}

if ( 'hide' === ( $args['attributes']['emergency'] ?? '' ) ) {
	return;
}

if ( isset( $emergency_contact_fields->fields ) && empty( $emergency_contact_fields->fields ) ) {
	return;
}
?>
<!-- Emergency Details Form -->
<div class="wpte-checkout__box collapsible <?php echo $show_title ? 'open' : ''; ?>">
	<?php if ( $show_title ) : ?>
	<h3 class="wpte-checkout__box-title">
		<span class="wpte-checkout__box-title-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72c.13.96.36 1.9.7 2.81a2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45c.9.34 1.85.57 2.81.7A2 2 0 0 1 22 16.92z"/></svg></span>
			<?php echo __( 'Emergency Details', 'wp-travel-engine' ); ?>
		<button type="button" class="wpte-checkout__box-toggle-button">
			<svg>
				<use xlink:href="#chevron-down"></use>
			</svg>
		</button>
	</h3>
	<?php endif; ?>
	<div class="wpte-checkout__box-content">
		<?php $emergency_contact_fields->render(); ?>
	</div>
</div>
