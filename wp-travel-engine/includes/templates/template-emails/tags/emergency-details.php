<?php
/**
 * Emergency contact details block for the {emergency_details} email tag.
 *
 * @var \WPTravelEngine\Booking\Email\Template_Tags $this
 * @var array $contacts
 */

use WPTravelEngine\Helpers\Countries;

$countries_list = Countries::list();
?>
<table width="100%">
	<tr>
		<td class="title-holder" style="margin: 0;" valign="top">
			<h3 class="alignleft"><?php echo esc_html__( 'Emergency Details', 'wp-travel-engine' ); ?></h3>
		</td>
	</tr>
	<?php
	foreach ( $contacts as $index => $fields ) :
		if ( count( $contacts ) > 1 ) :
			?>
			<tr>
				<td>
					<h3><?php echo esc_html( sprintf( __( 'Emergency Contact %d', 'wp-travel-engine' ), $index + 1 ) ); ?></h3>
				</td>
			</tr>
			<?php
		endif;
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
				<td><?php echo esc_html( ucfirst( $field['field_label'] ) ); ?></td>
				<td>
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
	<?php endforeach; ?>
</table>
