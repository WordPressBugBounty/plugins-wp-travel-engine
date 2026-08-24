<?php
/**
 * Billing details block for the {billing_details} email tag.
 *
 * @var \WPTravelEngine\Booking\Email\Template_Tags $this
 * @var array $fields
 */

use WPTravelEngine\Helpers\Countries;

$countries_list = Countries::list();
?>
<table width="100%">
	<tr>
		<td colspan="2" style="font-size: 16px;line-height: 1.75;font-weight: bold;padding: 8px 0 4px;"><?php esc_html_e( 'Billing Details:', 'wp-travel-engine' ); ?></td>
	</tr>
	<?php
	foreach ( $fields as $field ) :
		$value = $field['value'] ?? '';

		if ( empty( $field['value'] ) ) {
			continue;
		}

		if ( $field['type'] == 'country_dropdown' ) {
			$value = $countries_list[ $value ] ?? '';
		}
		?>
		<tr>
			<td style="color: <?php echo $this->color( 'muted' ); ?>;"><?php echo esc_html( ucfirst( $field['field_label'] ) ); ?></td>
			<td style="width: 50%;text-align: right;">
				<?php
				if ( filter_var( $value, FILTER_VALIDATE_URL ) ) :
					?>
					<a href="<?php echo esc_url( $value ); ?>"
						target="_blank"><?php echo esc_html( basename( $value ) ); ?></a>
				<?php else : ?>
					<strong><?php echo is_array( $value ) ? esc_html( implode( ', ', $value ) ) : esc_html( $value ?? '' ); ?></strong>
				<?php endif; ?>
			</td>
		</tr>
	<?php endforeach; ?>
</table>
