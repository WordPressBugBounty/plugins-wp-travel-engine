<?php
/**
 * Traveller Form Fields.
 *
 * @since 6.3.0
 */

namespace WPTravelEngine\Builders\FormFields;

/**
 * Form field class to render billing form fields.
 *
 * @since 6.3.0
 */
class TravellerFormFields extends FormField {

	public function __construct() {
		parent::__construct( false );
	}


	public function render() {
		echo '<div class="wpte-checkout__form-row">';
		parent::render();
		echo '</div>';
	}
}
