<?php
/**
 * Emergency Form Fields.
 *
 * @since 6.3.0
 */

namespace WPTravelEngine\Builders\FormFields;

use WTE_Default_Form_Fields;
use WPTravelEngine\Builders\FormFields\DefaultFormFields;

/**
 * Form field class to render emergency form fields.
 *
 * @since 6.3.0
 */
class EmergencyFormFields extends FormField {

	/**
	 * @var array
	 */
	public $fields;

	public function __construct() {
		parent::__construct( false );

		$fields = DefaultFormFields::emergency_form_fields();

		$this->init( $fields );
	}

	public function render() {
		$this->fields = $this->map_fields( $this->fields );
		if ( empty( $this->fields ) ) {
			return;
		}
		?>
		<div class="wpte-checkout__form-section">
			<div class="wpte-checkout__form-row">
				<?php parent::render(); ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Names the fields for checkout and fills in their defaults.
	 *
	 * @param array $fields Emergency form fields.
	 * @return array
	 * @since 6.9.0 Prefills from the contact saved on the account; what this session typed wins.
	 */
	protected function map_fields( $fields ) {
		$form_data = WTE()->session->get( 'emergency_form_data' );
		$form_data = is_array( $form_data ) ? $form_data : array();
		$form_data = array_merge( wptravelengine_get_user_emergency_contact(), array_filter( $form_data ) );

		return array_map(
			function ( $field ) use ( $form_data ) {
				$name = preg_match( '#\[([^\[]+)]$#', $field['name'], $matches ) ? $matches[1] : $field['name'];
				if ( $name ) {
						$field['class']         = 'wpte-checkout__input';
						$field['wrapper_class'] = 'wpte-checkout__form-col';
						$field['name']          = sprintf( 'emergency[%s]', $name );

						$field['id'] = sprintf( 'emergency_%s', $name );

				}
				$field['field_label'] = ! empty( $field['field_label'] ) ? $field['field_label'] : ( $field['placeholder'] ?? '' );
				$field['default']     = $form_data[ $name ] ?? $field['default'] ?? '';

				return $field;
			},
			$fields
		);
	}

	/**
	 * Function to map fields with values.
	 *
	 * @param array $form_data
	 *
	 * @return array
	 * @since 6.4.0
	 * @since 6.9.0 Label falls back to the placeholder only when the field has none.
	 */
	public function with_values( $form_data, $booking = null ): array {
		if ( $booking && $booking->get_meta( 'traveller_page_type' ) == 'old' ) {
			$this->fields = WTE_Default_Form_Fields::emergency_contact();
		} else {
			$this->fields = DefaultFormFields::emergency( 'readonly' );
		}

		return array_map(
			function ( $field ) use ( $form_data ) {
				$name = preg_match( '#\[([^\[]+)]$#', $field['name'], $matches ) ? $matches[1] : $field['name'];
				if ( $name ) {
						$field['class']         = 'wpte-checkout__input';
						$field['wrapper_class'] = 'wpte-checkout__form-col';
						$field['name']          = sprintf( 'emergency[%s]', $name );

						$field['id'] = sprintf( 'emergency_%s', $name );

				}
				$field['field_label'] = ! empty( $field['field_label'] ) ? $field['field_label'] : ( $field['placeholder'] ?? '' );
				$value                = $form_data[ $name ] ?? $field['default'] ?? '';
				$field['default']     = $value;
				$field['value']       = self::resolve_display_value( $value, $field['type'] );

				return $field;
			},
			$this->fields
		);
	}
}
