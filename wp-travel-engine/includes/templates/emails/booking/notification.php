<?php
/**
 *
 * New Booking order template.
 *
 * @since 4.3.0
 */
use WPTravelEngine\Helpers\Countries;
$trip_details    = array(
	'{tdate}'    => __( 'Trip Start Date', 'wp-travel-engine' ),
	'{traveler}' => __( 'Total Traveller(s)', 'wp-travel-engine' ),
);
$billing_details = array(
	'{fullname}'        => __( 'Name', 'wp-travel-engine' ),
	'{user_email}'      => __( 'Email', 'wp-travel-engine' ),
	'{billing_address}' => __( 'Billing Address', 'wp-travel-engine' ),
	'{city}'            => __( 'City', 'wp-travel-engine' ),
	'{country}'         => __( 'Country', 'wp-travel-engine' ),
);
$payment_details = array(
	'{tprice}'     => __( 'Trip Price', 'wp-travel-engine' ),
	'{total_cost}' => __( 'Total Cost', 'wp-travel-engine' ),
	'{due}'        => __( 'Due', 'wp-travel-engine' ),
);
?>
<table class="main" width="100%" cellpadding="0" cellspacing="0">
	<tr>
		<td class="content-wrap aligncenter">
			<table width="100%" cellpadding="0" cellspacing="0">
				<tr>
					<td class="content-block">
						<h1 class="aligncenter"><?php echo esc_html( WTE_Booking_Emails::get_string( 'order', $args['sent_to'], 'heading' ) ); ?></h1>
					</td>
				</tr>
				<tr>
					<td class="content-block aligncenter">
						<table class="invoice">
							<tr>
								<td style="margin: 0; padding: 5px 0;" valign="top"><?php echo esc_html( WTE_Booking_Emails::get_string( 'order', $args['sent_to'], 'greeting' ) ); ?><br><br>
								<?php echo esc_html( WTE_Booking_Emails::get_string( 'order', $args['sent_to'], 'greeting_byline' ) ); ?>
								</td>
							</tr>
							<br>
							<tr>
								<td style="margin: 0; padding: 5px 0;" valign="top">
									<table class="invoice-items" cellpadding="0" cellspacing="0">
										<tr>
											<td class="title-holder" style="margin: 0;" valign="top" colspan="2">
												<h3 class="alignleft"><?php echo esc_html__( 'Booking Trips ({booking_trips_count})', 'wp-travel-engine' ); ?></h3>
											</td>
										</tr>
										<tr><td colspan="2">{booking_details}</td></tr>
										<tr>
											<td class="title-holder" style="margin: 0;" valign="top">
												<h3 class="alignleft"><?php echo esc_html__( 'Billing Details', 'wp-travel-engine' ); ?></h3>
											</td>
										</tr>
										<?php foreach ( $billing_details as $tag => $label ) : 
											$countries_list = Countries::list();
											if ( isset( $countries_list[ $tag ] ) ) {
												$tag = $countries_list[ $tag ];
											}
											?>
											<tr>
												<td><?php echo esc_html( $label ); ?></td>
												<td class="alignright"><?php echo esc_html( $tag ); ?></td>
											</tr>
										<?php endforeach; ?>
										<!-- Bank Details -->
										<tr>
											<td colspan="2">{bank_details}</td>
										</tr>
										<!-- Check Payment Instructions -->
										<tr>
											<td colspan="2">{check_payment_instruction}</td>
										</tr>
									</table>
								</td>
							</tr>
						</table>
					</td>
				</tr>
				<?php if ( 'admin' === $args['sent_to'] ) : ?>
				<tr>
					<td class="content-block aligncenter">
						<a href="{booking_url}"><?php esc_html_e( 'View booking on your website', 'wp-travel-engine' ); ?></a>
					</td>
				</tr>
				<?php endif; ?>
				<tr>
					<td class="content-block aligncenter">
						{sitename}
					</td>
				</tr>
			</table>
		</td>
	</tr>
</table>
<?php
