<?php
/**
 * Trip booking summary block for the {trip_booking_summary} email tag.
 *
 * @var \WPTravelEngine\Booking\Email\Template_Tags $this
 * @var array  $cart_info Booking's cart_info.
 * @var array  $line_items Cart_info's line_items for the current item.
 * @var array<int, array{label: string, quantity: int, price: float, total: float}> $pricing_category_items One row per booked pricing category.
 */

$muted_color = $this->color( 'muted' );
?>
<table border="0" width="100%" cellspacing="0" cellpadding="0">
	<tr>
		<td colspan="2" style="font-size: 16px;line-height: 1.75;font-weight: bold;padding: 8px 0 4px;"><?php esc_html_e( 'Booking Details:', 'wp-travel-engine' ); ?></td>
	</tr>
	<tr>
		<td style="color: <?php echo $muted_color; ?>;"><?php esc_html_e( 'Package Name:', 'wp-travel-engine' ); ?></td>
		<td style="width: 50%;text-align: right;"><strong><?php echo esc_html( $this->trip->package_name ); ?></strong></td>
	</tr>
	<tr>
		<td style="color: <?php echo $muted_color; ?>;"><?php esc_html_e( 'Trip Date:', 'wp-travel-engine' ); ?></td>
		<td style="width: 50%;text-align: right;"><strong><?php echo esc_html( $this->get_trip_start_date() ); ?></strong></td>
	</tr>
	<tr>
		<td style="color: <?php echo $muted_color; ?>;"><?php esc_html_e( 'Trip End Date:', 'wp-travel-engine' ); ?></td>
		<td style="width: 50%;text-align: right;"><strong>
		<?php echo esc_html( $this->get_trip_end_date() ); ?>
			</strong>
		</td>
	</tr>
	<tr>
		<td style="color: <?php echo $muted_color; ?>;"><?php esc_html_e( 'Travelers:', 'wp-travel-engine' ); ?></td>
		<td style="width: 50%;text-align: right;"><strong><?php echo esc_html( $this->get_no_of_travelers() ); ?></strong></td>
	</tr>
	<tr>
		<td colspan="2"><strong><?php esc_html_e( 'Traveler(s):', 'wp-travel-engine' ); ?></strong></td>
	</tr>
	<?php
	if ( ! $this->is_manual_trigger ) {

		foreach ( $pricing_category_items as $item ) :
			?>
			<tr>
				<td style="color: <?php echo $muted_color; ?>;"><?php echo esc_html( $item['label'] ) . ': ' . esc_html( $item['quantity'] ) . ' x ' . wptravelengine_the_price( $item['price'], false ); ?></td>
				<td style="width: 50%;text-align: right;"><strong><?php echo wptravelengine_the_price( $item['total'], false ); ?></strong></td>
			</tr>
			<?php
		endforeach;

		if ( ! $this->is_preview() ) {
			do_action( 'wptravelengine_email_template_before_extra_services', $cart_info );
		}

		if ( $this->trip->trip_extras && is_array( $this->trip->trip_extras ) ) :
			?>
			<tr>
				<td colspan="2"><strong><?php echo esc_html( $this->global_settings->get( 'extra_service_title', __( 'Extra Services:', 'wp-travel-engine' ) ) ); ?></strong></td>
			</tr>
			<?php
			foreach ( $this->trip->trip_extras as $tx ) :
				$tx_total = +$tx['qty'] * +$tx['price'];
				?>
				<tr>
					<td style="color: <?php echo $muted_color; ?>;">
					<?php
					echo esc_html( $tx['extra_service'] . ': ' );
					echo (int) $tx['qty'] . ' x ' . wptravelengine_the_price( +$tx['price'], false );
					?>
					</td>
					<td style="width: 50%;text-align: right;"><strong><?php echo wptravelengine_the_price( +$tx_total, false ); ?></strong></td>
				</tr>
				<?php
			endforeach;
		endif;

		if ( ! $this->is_preview() ) {
			do_action( 'wptravelengine_email_template_after_extra_services', $cart_info );
		}
	} else {
		foreach ( $line_items as $line_item => $_items ) {
			if ( empty( $_items ) ) {
				continue;
			}

			if ( ! has_action( "wptravelengine_email_manual_trigger_$line_item" ) ) {
				$label = $this->get_line_item_label( $line_item );
				if ( $label ) {
					?>
					<tr>
						<td colspan="2"><strong><?php echo esc_html( $label ); ?></strong></td>
					</tr>
					<?php
				}
				foreach ( $_items as $item ) {
					?>
					<tr>
						<td style="color: <?php echo $muted_color; ?>;"><?php echo esc_html( $item['label'] ?? '' ) . ': ' . esc_html( $item['quantity'] ) . ' x ' . wptravelengine_the_price( $item['price'], false ); ?></td>
						<td style="width: 50%;text-align: right;"><strong><?php echo wptravelengine_the_price( $item['total'], false ); ?></strong></td>
					</tr>
					<?php
				}
			}

			do_action( "wptravelengine_email_manual_trigger_{$line_item}", $_items, $cart_info );
		}
	}
	?>
</table>
