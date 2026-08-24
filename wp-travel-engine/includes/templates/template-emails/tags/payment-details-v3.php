<?php
/**
 * Old-cart (v3) payment details block, part of the {trip_payment_details} email tag.
 *
 * @var \WPTravelEngine\Booking\Email\Template_Tags $this
 */

$cart_info                 = (array) $this->booking->get_cart_info();
$is_customized_reservation = get_post_meta( $this->booking->ID, '_user_edited', true );
$deductible_items          = array();
$fees                      = array();
$subtotal                  = $this->cart_info->subtotal ?? 0;
$total                     = $this->booking->get_total();
$amount_paid               = $this->booking->get_total_paid_amount();
$amount_due                = $this->booking->get_total_due_amount();
$spacer_row                = "<tr>\n\t\t\t<td colspan='2' style='padding: 4px 0;'></td>\n\t\t</tr>";

if ( $is_customized_reservation ) {
	$cart_info = $this->booking->get_cart_info();
	if ( is_array( $cart_info ) ) {
		$deductible_items = $cart_info['deductible_items'] ?? array();
		$fees             = $cart_info['fees'] ?? array();
		$subtotal         = $cart_info['subtotal'] ?? 0;
		$total            = $cart_info['total'] ?? 0;
	}
}

$muted_color   = $this->color( 'muted' );
$success_color = $this->color( 'success' );
$warning_color = $this->color( 'warning' );
$highlight_bg  = $this->color( 'highlight' );

$tax_enable     = 'yes' === $this->global_settings->get( 'tax_enable' );
$tax_percentage = $this->global_settings->get( 'tax_percentage', 0 );
$tax_label      = $tax_enable && 'inclusive' === $this->global_settings->get( 'tax_type_option' ) ? sprintf( __( '(%s%% Incl. tax)', 'wp-travel-engine' ), esc_html( $tax_percentage ) ) : '';
$total_row      = '<tr style="font-size: 16px;">
			<td colspan="2">
				<span style="display: block;padding: 8px 16px;background-color: ' . $highlight_bg . ';border-radius: 4px;margin: 0 -16px;">
					<strong style="width: 50%;display: inline-block;">' . __( 'Total', 'wp-travel-engine' ) . '</strong>
					<strong style="width: 49%;text-align: right;display: inline-block;">
						' . wptravelengine_the_price_with_decimal( $total, false ) . '<span class="wpte-inclusive-tax-label">' . $tax_label . '</span>' . '
					</strong>
				</span>
			</td>
		</tr>';

$billing_fields = array(
	__( 'Booking Name:', 'wp-travel-engine' )    => $this->get_fullname(),
	__( 'Booking Email:', 'wp-travel-engine' )   => $this->get_user_email(),
	__( 'Booking Address:', 'wp-travel-engine' ) => $this->get_billing_address(),
	__( 'City:', 'wp-travel-engine' )            => $this->get_city(),
	__( 'Country:', 'wp-travel-engine' )         => $this->get_country(),
);
?>
<tr>
	<td colspan="2" style="font-size: 16px;line-height: 1.75;font-weight: bold;padding: 8px 0 4px;"><?php esc_html_e( 'Payment Details:', 'wp-travel-engine' ); ?></td>
</tr>
<tr>
	<td><strong><?php esc_html_e( 'Subtotal', 'wp-travel-engine' ); ?></strong></td>
	<td style="text-align: right;"><strong><?php echo wptravelengine_the_price_with_decimal( $subtotal, false ); ?></strong></td>
</tr>
<?php if ( ! $is_customized_reservation ) : ?>
	<?php
	echo $spacer_row;
	$discount_figure = 0;
	if ( ! empty( $cart_info['discounts'] ) ) {
		$discounts       = $cart_info['discounts'];
		$discount        = array_shift( $discounts );
		$discount_figure = 'percentage' === $discount['type'] ? +$cart_info['subtotal'] * ( +$discount['value'] / 100 ) : $discount['value'];
		?>
		<tr style="color: <?php echo $success_color; ?>;">
			<td><?php esc_html_e( 'Discount', 'wp-travel-engine' ); ?> <?php echo 'percentage' === $discount['type'] ? esc_html( '(' . $discount['name'] . ' ' . $discount['value'] ) . '%)' : esc_html( '(' . $discount['name'] . ')' ); ?></td>
			<td style="text-align: right;"><strong>-<?php echo wptravelengine_the_price_with_decimal( +$discount_figure, false ); ?></strong></td>
		</tr>
		<?php
	}
	?>

	<?php
	echo $total_row;
	echo $spacer_row;
	// Hooks for addon.
	do_action( 'wptravelengine_email_template_before_tax_amount', $cart_info );

	if ( isset( $cart_info['tax_amount'] ) && $cart_info['tax_amount'] > 0 ) {
		?>
	<tr style="color: <?php echo $warning_color; ?>;">
		<?php $tax_amount = wp_travel_engine_get_tax_detail( $cart_info ); ?>
	<td><?php echo esc_html( wptravelengine_get_tax_label( $cart_info['tax_amount'] ) ); ?></td>
	<td style="text-align: right;"><strong><?php echo wptravelengine_the_price_with_decimal( +$tax_amount['tax_actual'], false ); ?></strong></td>
	</tr>
		<?php
	}
	// Add new row before total amount calculation on email template.
	do_action( 'wptravelengine_email_template_trip_cost_rows', $cart_info );
	?>
<?php else : ?>
	<?php foreach ( $deductible_items as $deductible_item ) : ?>
		<tr style="color: <?php echo $success_color; ?>;">
			<td><strong><?php echo esc_html( $deductible_item['label'] ); ?></strong></td>
			<td style="text-align: right;"><strong>-<?php echo wptravelengine_the_price_with_decimal( $deductible_item['value'], false ); ?></strong></td>
		</tr>
	<?php endforeach; ?>
	<?php
	echo $total_row;
	echo $spacer_row;
	foreach ( $fees as $fee ) :
		if ( isset( $fee['value'] ) && $fee['value'] > 0 ) :
			?>
			<tr>
				<td><strong><?php echo esc_html( $fee['label'] ); ?></strong></td>
				<td style="text-align: right;"><strong><?php echo wptravelengine_the_price_with_decimal( $fee['value'], false ); ?></strong></td>
			</tr>
			<?php
		endif;
	endforeach;
	?>
<?php endif; ?>
<tr>
	<td colspan="2" style="padding: 4px 0;"></td>
</tr>
<tr>
	<td><strong><?php esc_html_e( 'Amount Paid', 'wp-travel-engine' ); ?></strong></td>
	<td style="text-align: right;font-size: 16px;"><strong><?php echo wptravelengine_the_price_with_decimal( $amount_paid, false ); ?></strong></td>
</tr>
<?php if ( $amount_due > 0 ) : ?>
	<tr>
		<td><strong><?php esc_html_e( 'Amount Due', 'wp-travel-engine' ); ?></strong></td>
		<td style="text-align: right;font-size: 16px;"><strong><?php echo wptravelengine_the_price_with_decimal( $amount_due, false ); ?></strong></td>
	</tr>
<?php endif; ?>
<tr>
	<td colspan="2" style="font-size: 16px;line-height: 1.75;font-weight: bold;padding: 8px 0 4px;"><?php esc_html_e( 'Billing Details:', 'wp-travel-engine' ); ?></td>
</tr>
<?php foreach ( $billing_fields as $label => $value ) : ?>
	<?php
	if ( empty( trim( $value ) ) ) {
		continue;
	}

	?>
	<tr>
		<td style="color: <?php echo $muted_color; ?>;"><?php echo esc_html( $label ); ?></td>
		<td style="width: 50%;text-align: right;"><strong><?php echo esc_html( $value ); ?></strong></td>
	</tr>
<?php endforeach; ?>
