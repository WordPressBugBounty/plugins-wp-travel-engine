<?php
/**
 * Legacy booking details block for the deprecated {booking_details} email tag.
 *
 * @var \WPTravelEngine\Booking\Email\Template_Tags $this
 */

$order_trips        = $this->order_trips;
$cart_info          = (array) $this->booking->get_cart_info();
$pricing_categories = get_terms(
	array(
		'taxonomy'   => 'trip-packages-categories',
		'hide_empty' => false,
		'orderby'    => 'term_id',
		'fields'     => 'id=>name',
	)
);

$count = 1;

foreach ( $order_trips as $trip ) :
	$trip = (object) $trip;
	?>
	<table width="100%" cellpadding="0" cellspacing="0">
		<tr>
			<td colspan="2"><b><?php echo esc_html( $trip->title ); ?></b></td>
		</tr>
		<tr>
			<td><?php esc_html_e( 'Package Name', 'wp-travel-engine' ); ?></td>
			<td class="alignright"><?php echo esc_html( $trip->package_name ); ?></td>
		</tr>
		<tr>
			<td><?php esc_html_e( 'Trip Date', 'wp-travel-engine' ); ?></td>
			<td class="alignright"><?php echo esc_html( $trip->has_time ? wp_date( 'Y-m-d H:i', strtotime( $trip->datetime ), new \DateTimeZone( 'utc' ) ) : wp_date( get_option( 'date-format', 'Y-m-d' ), strtotime( $trip->datetime ), new \DateTimeZone( 'utc' ) ) ); ?></td>
		</tr>
		<?php if ( isset( $trip->end_datetime ) ) : ?>
			<tr>
				<td><?php esc_html_e( 'Trip End Date', 'wp-travel-engine' ); ?></td>
				<td class="alignright"><?php echo esc_html( $trip->has_time ? wp_date( 'Y-m-d H:i', strtotime( $trip->end_datetime ), new \DateTimeZone( 'utc' ) ) : wp_date( get_option( 'date-format', 'Y-m-d' ), strtotime( $trip->end_datetime ), new \DateTimeZone( 'utc' ) ) ); ?></td>
			</tr>

		<?php endif; ?>
		<tr>
			<td><?php esc_html_e( 'Travelers', 'wp-travel-engine' ); ?></td>
			<td class="alignright"><?php echo esc_html( array_sum( $trip->pax ) ); ?></td>
		</tr>
		<tr>
			<td><?php esc_html_e( 'Trip Cost', 'wp-travel-engine' ); ?></td>
		</tr>
		<tr>
			<td>&nbsp;</td>
			<td class="alignright">
				<table width="100%" cellpadding="0" cellspacing="0">
					<?php
					$sum = 0;
					foreach ( $trip->pax as $pricing_category_id => $tcount ) {
						if ( ! isset( $trip->pax_cost ) || ! is_array( $trip->pax_cost ) || ! isset( $trip->pax_cost[ $pricing_category_id ] ) || +$tcount < 1 ) {
							continue;
						}
						$pax_cost = +$trip->pax_cost[ $pricing_category_id ] / +$tcount;
						$sum     += +$trip->pax_cost[ $pricing_category_id ];

						$label = $pricing_categories[ $pricing_category_id ] ?? $pricing_category_id;
						?>
							<tr>
								<td class="alignright"><?php echo esc_html( $label ); ?></td>
								<td><?php echo (int) $tcount . ' X ' . wptravelengine_the_price_with_decimal( $pax_cost, false ) . ' = ' . wptravelengine_the_price_with_decimal( $trip->pax_cost[ $pricing_category_id ] ?? 0, false ); ?></td>
							</tr>
							<?php
					}
					?>
					<tr>
						<td width="50%"><?php esc_html_e( 'Subtotal', 'wp-travel-engine' ); ?></td>
						<td width="50%"><?php echo wptravelengine_the_price_with_decimal( +$sum, false ); ?></td>
					</tr>
				</table>
			</td>
		</tr>
		<?php
		if ( ! $this->is_preview() ) {
			do_action( 'wptravelengine_email_template_before_extra_services', $cart_info ); }
		?>
		<?php if ( $trip->trip_extras && is_array( $trip->trip_extras ) ) : ?>
			<tr>
				<td colspan="2"><?php echo esc_html( $this->global_settings->get( 'extra_service_title', __( 'Extra Services:', 'wp-travel-engine' ) ) ); ?></td>
			</tr>
			<tr>
				<td>&nbsp;</td>
				<td class="alignright">
					<table width="100%" cellpadding="0" cellspacing="0">
						<?php
						$sum = 0;
						foreach ( $trip->trip_extras as $index => $tx ) {
							$tx_total = +$tx['qty'] * +$tx['price'];
							$sum     += $tx_total;
							?>
							<tr>
								<td><?php echo esc_html( $tx['extra_service'] ); ?></td>
								<td><?php echo (int) $tx['qty'] . ' X ' . wptravelengine_the_price_with_decimal( +$tx['price'], false ) . ' = ' . wptravelengine_the_price_with_decimal( +$tx_total, false ); ?></td>
							</tr>
							<?php
						}
						?>
						<tr>
							<td width="50%"><?php esc_html_e( 'Subtotal', 'wp-travel-engine' ); ?></td>
							<td widht="50%"><?php echo wptravelengine_the_price_with_decimal( +$sum, false ); ?></td>
						</tr>
					</table>
				</td>
			</tr>
		<?php endif; ?>
	</table>
	<?php
	++$count;
endforeach;
echo '<hr/>';
?>
<table width="100%">
	<tr>
		<td width="50%">&nbsp;</td>
		<td width="50%">
			<table width="100%">
				<tr>
					<td><?php esc_html_e( 'Subtotal', 'wp-travel-engine' ); ?></td>
					<td class="alignright"><?php echo wptravelengine_the_price_with_decimal( +$cart_info['subtotal'], false ); ?></td>
				</tr>
				<tr>
					<td><?php esc_html_e( 'Discount', 'wp-travel-engine' ); ?></td>
					<?php
					$discount_figure = 0;
					if ( ! empty( $cart_info['discounts'] ) ) {
						$discounts       = $cart_info['discounts'];
						$discount        = array_shift( $discounts );
						$discount_figure = 'percentage' === $discount['type'] ? +$cart_info['subtotal'] * ( +$discount['value'] / 100 ) : $discount['value'];
					}
					?>
					<td class="alignright">
						<?php echo wptravelengine_the_price_with_decimal( +$discount_figure, false ); ?>
					</td>
				</tr>
				<?php
				if ( ! $this->is_preview() ) {
					do_action( 'wptravelengine_email_template_before_tax_amount', $cart_info ); }
				?>
				<?php if ( ! empty( $cart_info['tax_amount'] ) ) : ?>
					<tr>
						<td><?php echo esc_html( wptravelengine_get_tax_label( $cart_info['tax_amount'] ) ); ?></td>
						<?php $tax_amount = wp_travel_engine_get_tax_detail( $cart_info ); ?>
						<td class="alignright">
							<?php echo wptravelengine_the_price_with_decimal( +$tax_amount['tax_actual'], false ); ?>
						</td>
					</tr>
				<?php endif; ?>
				<?php
				// Add new row before total amount calculation on email template.
				if ( ! $this->is_preview() ) {
					do_action( 'wptravelengine_email_template_trip_cost_rows', $cart_info );
				}
				?>
				<tr>
					<td><?php esc_html_e( 'Total', 'wp-travel-engine' ); ?></td>
					<td class="alignright">
						<?php
						echo wptravelengine_the_price_with_decimal( $cart_info['total'], false );

						if ( wptravelengine_toggled( $this->global_settings->get( 'tax_enable', false ) ) && wptravelengine_replace( $this->global_settings->get( 'tax_type_option' ), 'inclusive', true, false ) ) {
							$tax_percentage = $this->global_settings->get( 'tax_percentage' );
							printf( '<span class="wpte-inclusive-tax-label">%s</span>', sprintf( __( '(%s%% Incl. tax)', 'wp-travel-engine' ), esc_html( $tax_percentage ) ) );
						}
						?>
					</td>
				</tr>
			</table>
		</td>
	</tr>
</table>
