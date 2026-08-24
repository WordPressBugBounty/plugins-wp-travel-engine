<?php
/**
 * Additional note block for the {customer_note} email tag.
 *
 * @var \WPTravelEngine\Booking\Email\Template_Tags $this
 * @var string $customer_note
 */
?>
<table width="100%">
	<tr>
		<td class="title-holder" style="margin: 0;" valign="top">
			<h3 class="alignleft"><?php echo esc_html__( 'Additional Note', 'wp-travel-engine' ); ?></h3>
		</td>
	</tr>
	<tr>
		<td><?php echo esc_html( $customer_note ); ?></td>
	</tr>
</table>
