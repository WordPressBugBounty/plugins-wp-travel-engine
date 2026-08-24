<?php
/**
 * Bank details block for the {bank_details} email tag.
 *
 * @var array  $accounts     Bank accounts, each keyed by $labels' keys.
 * @var string $instructions Bank transfer instructions.
 * @var array  $labels       Field label => localized label.
 */

$border_bg   = $this->color( 'border' );
$muted_color = $this->color( 'muted' );
?>
<table cellspacing="0" cellpadding="0" style="width: 100%;">
	<tr>
		<td colspan="2"><hr style="border: none;border-top: 1px solid <?php echo $border_bg; ?>;"></td>
	</tr>
	<tr>
		<td colspan="2" style="font-size: 16px;line-height: 1.75;font-weight: bold;padding: 8px 0 4px;"><?php esc_html_e( 'Bank Details:', 'wp-travel-engine' ); ?></td>
	</tr>
	<?php if ( ! empty( $instructions ) ) : ?>
	<tr>
		<td colspan="2" style="color: <?php echo $muted_color; ?>;"><?php echo wp_kses( nl2br( $instructions ), array( 'br' => array() ) ); ?></td>
	</tr>
	<tr>
		<td colspan="2" style="padding: 4px 0;"></td>
	</tr>
		<?php
	endif;
	$is_first = true;
	foreach ( $accounts as $account ) :
		if ( ! $is_first ) {
			?>
			<tr><td colspan="2"><hr style="border: none;border-top: 1px solid <?php echo $border_bg; ?>;"></td></tr>
			<?php
		}
		$is_first = false;
		foreach ( $labels as $key => $label ) :
			?>
			<tr>
				<td style="color: <?php echo $muted_color; ?>;"><?php echo esc_html( $label ); ?></td>
				<td style="width: 50%;text-align: right;"><strong><?php echo esc_html( $account[ $key ] ?? '' ); ?></strong></td>
			</tr>
			<?php
		endforeach;
	endforeach;
	?>
</table>
