<?php
/**
 * Check payment instructions block for the {check_payment_instruction} email tag.
 */
?>
<table class="invoice-items">
	<tr>
		<td colspan="2">
			<h3><?php echo esc_html__( 'Check Payment Instructions:', 'wp-travel-engine' ); ?></h3>
		</td>
	</tr>
	<tr>
		<td colspan="2">
			<?php echo wp_kses_post( $instruction ); ?>
		</td>
	</tr>
</table>
