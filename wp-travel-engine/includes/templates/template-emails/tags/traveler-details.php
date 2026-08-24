<?php
/**
 * Traveler details block for the {traveler_details}/{traveler_details} email tags.
 *
 * @var \WPTravelEngine\Booking\Email\Template_Tags $this
 * @var array $travelers
 */

use WPTravelEngine\Helpers\Countries;
$countries_list = Countries::list();
?>
<table width="100%">
	<tr>
		<td class="title-holder" style="margin: 0;" valign="top">
			<h3 class="alignleft"><?php echo esc_html__( 'Traveler Details', 'wp-travel-engine' ); ?></h3>
		</td>
	</tr>
	<?php
	foreach ( $travelers as $index => $traveler_detail ) :
		$traveler_label = sprintf( __( 'Traveler %1$d%2$s', 'wp-travel-engine' ), $index + 1, $index === 0 ? __( ' (Lead Traveler)', 'wp-travel-engine' ) : '' );
		?>
		<tr>
			<td>
				<h3><?php echo esc_html( $traveler_label ); ?></h3>
			</td>
		</tr>
		<?php
		foreach ( $traveler_detail as $field ) :
			if ( empty( $field['value'] ) ) {
				continue;
			}
			$value = $field['value'] ?? '';
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
							<a href="<?php echo esc_url( $value ); ?>" target="_blank"><?php echo esc_html( basename( $value ) ); ?></a>
						<?php else : ?>
							<strong><?php echo is_array( $value ) ? esc_html( implode( ', ', $value ) ) : esc_html( $value ?? '' ); ?></strong>
						<?php endif; ?>
					</td>
				</tr>
			<?php
		endforeach;
	endforeach;
	?>
</table>
