<?php
/**
 * Full trip booking details block for the {trip_booking_details} email tag.
 *
 * @var \WPTravelEngine\Booking\Email\Template_Tags $this
 * @var string $payment_gateway
 * @var string $customer_note
 */
$border_color = $this->color( 'border' );
$muted_color  = $this->color( 'muted' );

echo $this->get_trip_booking_summary();
?>
<table border="0" width="100%" cellspacing="0" cellpadding="0">
	<tr>
		<td colspan="2"><hr style="border: none;border-top: 1px solid <?php echo $border_color; ?>;"></td>
	</tr>
	<?php
	$this->called_from_booking_details = true;
	echo $this->get_trip_booking_payment();

	// Bank Details.
	if ( 'direct_bank_transfer' === $payment_gateway ) :
		$bank_accounts     = $this->global_settings->get( 'bank_transfer.accounts', array() );
		$bank_instructions = $this->global_settings->get( 'bank_transfer.instruction', '' );
		$bank_labels       = array(
			'account_name'   => __( 'Account Name', 'wp-travel-engine' ),
			'account_number' => __( 'Account Number', 'wp-travel-engine' ),
			'bank_name'      => __( 'Bank Name', 'wp-travel-engine' ),
			'sort_code'      => __( 'Sort Code', 'wp-travel-engine' ),
			'iban'           => __( 'IBAN', 'wp-travel-engine' ),
			'swift'          => __( 'BIC/Swift', 'wp-travel-engine' ),
		);
		?>
		<tr>
			<td colspan="2"><hr style="border: none;border-top: 1px solid <?php echo $border_color; ?>;"></td>
		</tr>
		<tr>
			<td colspan="2" style="font-size: 16px;line-height: 1.75;font-weight: bold;padding: 8px 0 4px;"><?php esc_html_e( 'Bank Details:', 'wp-travel-engine' ); ?></td>
		</tr>
		<?php if ( ! empty( $bank_instructions ) ) : ?>
		<tr>
			<td colspan="2" style="color: <?php echo $muted_color; ?>;"><?php echo wp_kses( nl2br( $bank_instructions ), array( 'br' => array() ) ); ?></td>
		</tr>
		<tr>
			<td colspan="2" style="padding: 4px 0;"></td>
		</tr>
			<?php
		endif;
		$is_first = true;
		foreach ( $bank_accounts as $account ) :
			if ( ! $is_first ) {
				?>
				<tr><td colspan="2"><hr style="border: none;border-top: 1px solid <?php echo $border_color; ?>;"></td></tr>
				<?php
			}
			$is_first = false;
			foreach ( $bank_labels as $key => $label ) :
				?>
				<tr>
					<td style="color: <?php echo $muted_color; ?>;"><?php echo esc_html( $label ); ?></td>
					<td style="width: 50%;text-align: right;"><strong><?php echo esc_html( $account[ $key ] ?? '' ); ?></strong></td>
				</tr>
				<?php
			endforeach;
		endforeach;
	endif;

	// Check Payment Details.
	if ( 'check_payments' === $payment_gateway ) :
		$check_instruction = wptravelengine_settings()->get( 'check_payment.instruction', '' );
		?>
		<tr>
			<td colspan="2"><hr style="border: none;border-top: 1px solid <?php echo $border_color; ?>;"></td>
		</tr>
		<tr>
			<td colspan="2" style="font-size: 16px;line-height: 1.75;font-weight: bold;padding: 8px 0 4px;"><?php esc_html_e( 'Check Payment Instructions:', 'wp-travel-engine' ); ?></td>
		</tr>
		<tr>
			<td colspan="2" style="color: <?php echo $muted_color; ?>;"><?php echo esc_html( $check_instruction ); ?></td>
		</tr>
		<?php
	endif;

	// Additional Notes.
	if ( ! empty( $customer_note ) ) :
		?>
		<tr>
			<td colspan="2"><hr style="border: none;border-top: 1px solid <?php echo $border_color; ?>;"></td>
		</tr>
		<tr>
			<td colspan="2" style="font-size: 16px;line-height: 1.75;font-weight: bold;padding: 8px 0 4px;"><?php esc_html_e( 'Additional Notes:', 'wp-travel-engine' ); ?></td>
		</tr>
		<tr>
			<td colspan="2" style="color: <?php echo $muted_color; ?>;"><?php echo esc_html( $customer_note ); ?></td>
		</tr>
		<?php
	endif;
	if ( ! $this->is_preview() ) {
		do_action( 'wptravelengine_email_template_after_additional_notes', $this );
	}
	?>
</table>
