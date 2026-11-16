<?php
/**
 * @var WPTravelEngine\Builders\FormFields\FormField $note_form_fields
 * @var bool $show_title
 * @since 6.3.0
 */
if ( 'hide' === ( $args['attributes']['additional_note'] ?? '' ) ) {
	return;
}
?>
<!-- Additional Note -->
<div class="wpte-checkout__box collapsible <?php echo $show_title ? 'open' : ''; ?>">
	<?php if ( $show_title ) : ?>
		<h3 class="wpte-checkout__box-title">
			<span class="wpte-checkout__box-title-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="8" y="2" width="8" height="4" rx="1" ry="1"/><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/><path d="M12 11h4"/><path d="M12 16h4"/><path d="M8 11h.01"/><path d="M8 16h.01"/></svg></span>
			<?php echo __( 'Special Requests / Comments', 'wp-travel-engine' ); ?>
			<button type="button" class="wpte-checkout__box-toggle-button">
				<svg>
					<use xlink:href="#chevron-down"></use>
				</svg>
			</button>
		</h3>
		<?php
	endif;
	$note_form_fields->render();
	?>
</div>
