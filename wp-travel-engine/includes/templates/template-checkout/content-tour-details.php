<?php
/**
 * Checkout content tour details
 *
 * @var array $tour_details Tour Details.
 * @var bool $show_title Show title.
 * @var bool $content_only Show title.
 * @since 6.3.0
 */

// Dynamic values are pre-escaped in Checkout::generate_trip_details_html(); this
// only allows the static icon markup it emits through as a defense-in-depth backstop.
$wpte_tour_details_kses = array_merge(
	wp_kses_allowed_html( 'post' ),
	array(
		'svg'    => array(
			'viewbox'         => array(),
			'fill'            => array(),
			'stroke'          => array(),
			'stroke-width'    => array(),
			'stroke-linecap'  => array(),
			'stroke-linejoin' => array(),
		),
		'path'   => array(
			'd' => array(),
		),
		'rect'   => array(
			'x'      => array(),
			'y'      => array(),
			'width'  => array(),
			'height' => array(),
			'rx'     => array(),
		),
		'circle' => array(
			'cx' => array(),
			'cy' => array(),
			'r'  => array(),
		),
	)
);
?>
<!-- Trip Details -->
<?php if ( ! $content_only ) : ?>
<div class="wpte-checkout__box wpte-checkout__trip-details-box collapsible open">
	<?php if ( $show_title ) : ?>
		<h5 class="wpte-checkout__form-title toggler-wrap">
			<?php echo __( 'Trip Details', 'wp-travel-engine' ); ?>
			<button type="button" class="wpte-checkout__box-toggle-button">
				<svg>
					<use xlink:href="#chevron-down"></use>
				</svg>
			</button>
		</h5>
	<?php endif; ?>
	<div class="wpte-checkout__box-content">
		<?php endif; // $tour_details_content_only ?>
		<?php foreach ( $tour_details as $cart_item ) : ?>
			<?php echo wp_kses( implode( '', (array) $cart_item ), $wpte_tour_details_kses ); ?>
		<?php endforeach; ?>
		<?php if ( ! $content_only ) : ?>
	</div>
</div>
<?php endif; // $tour_details_content_only ?>
