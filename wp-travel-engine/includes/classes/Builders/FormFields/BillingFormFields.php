<?php
/**
 * Billing Form Fields.
 *
 * @since 6.3.0
 */

namespace WPTravelEngine\Builders\FormFields;

/**
 * Form field class to render billing form fields.
 *
 * @since 6.3.0
 */
class BillingFormFields extends FormField {

	public function __construct( array $args = array() ) {
		parent::__construct( false );

		$this->init( $this->map_fields( \WTE_Default_Form_Fields::billing_form_fields(), $args['booking_ref'] ?? null ) );
	}

	/**
	 * @inheritDoc
	 */
	public function render(): void {
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
	 * @param array    $fields      Billing form fields.
	 * @param int|null $booking_ref Booking post ID.
	 * @return array
	 * @since 6.9.0 Label falls back to the placeholder only when the field has none.
	 */
	protected function map_fields( $fields, $booking_ref ) {
		$billing_form_data = $this->get_billing_form_data( $booking_ref );

		return array_map(
			function ( $field ) use ( $billing_form_data ) {
				$name = null;

				// Extract the name using regex patterns.
				if ( preg_match( '#\[([^\[]+)]$#', $field['name'], $matches ) ) {
						$name = $matches[1];
				} elseif ( preg_match( '/^[^\s]+$/', $field['name'], $matches ) ) {
					$name = $matches[0];
				}

				// If a name was found, set field attributes.
				if ( $name ) {
					$field['class']         = 'wpte-checkout__input';
					$field['wrapper_class'] = 'wpte-checkout__form-col';
					if ( $field['type'] === 'file' ) {
						$field['name'] = sprintf( '%s', $name );
						$field['id']   = sprintf( '%s', $name );
					} else {
						$field['name'] = sprintf( 'billing[%s]', $name );
						$field['id']   = sprintf( 'billing_%s', $name );
					}
					$field['field_label'] = ! empty( $field['field_label'] ) ? $field['field_label'] : ( $field['placeholder'] ?? '' );
					$field['default']     = $billing_form_data[ $name ] ?? $field['default'] ?? '';

				}

				return $field;
			},
			$fields
		);
	}

	/**
	 * Resolves billing form data from booking, customer, or session.
	 *
	 * Priority: booking meta > session data (merged with customer defaults) > empty array.
	 *
	 * @param int|null $booking_ref Booking post ID.
	 *
	 * @return array
	 * @since 6.8.0
	 * @since 6.9.0 Customer defaults come from Customer::get_checkout_defaults().
	 */
	protected function get_billing_form_data( $booking_ref ): array {
		if ( $booking_ref ) {
			$booking_data = get_post_meta( $booking_ref, 'wptravelengine_billing_details', true );
			if ( is_array( $booking_data ) && ! empty( $booking_data ) ) {
				return $booking_data;
			}
		}

		$session_data = WTE()->session->get( 'billing_form_data' );
		$session_data = is_array( $session_data ) ? $session_data : array();

		$user = get_user_by( 'id', get_current_user_id() );

		if ( ! $user ) {
			return $session_data;
		}

		$customer = wptravelengine_get_customer( $user );

		if ( ! $customer ) {
			return $session_data;
		}

		// The checkout prefills from what this customer last booked under, not from what they
		// have since saved on their account — the opposite priority to the model's own getters,
		// which is why this reads `get_checkout_defaults()` rather than them. The gaps are
		// filled from the account filling the form, which need not be the customer's own.
		return array_merge( $customer->get_checkout_defaults( $user->user_email ), array_filter( $session_data ) );
	}

	/**
	 * @param array $form_data
	 * @return array
	 * @since 6.9.0 Label falls back to the placeholder only when the field has none.
	 */
	public function with_values( array $form_data ) {
		$this->fields = DefaultFormFields::billing_form_fields();

		return array_map(
			function ( $field ) use ( $form_data ) {
				$name = null;

				// Extract the name using regex patterns.
				if ( preg_match( '#\[([^\[]+)]$#', $field['name'], $matches ) ) {
					$name = $matches[1];
				} elseif ( preg_match( '/^[^\s]+$/', $field['name'], $matches ) ) {
					$name = $matches[0];
				}

				// If a name was found, set field attributes.
				if ( $name ) {
					$field['class']         = 'wpte-checkout__input';
					$field['wrapper_class'] = 'wpte-checkout__form-col';
					if ( $field['type'] === 'file' ) {
						$field['name'] = sprintf( '%s', $name );
						$field['id']   = sprintf( '%s', $name );
					} else {
						$field['name'] = sprintf( 'billing[%s]', $name );
						$field['id']   = sprintf( 'billing_%s', $name );
					}
					$field['field_label'] = ! empty( $field['field_label'] ) ? $field['field_label'] : ( $field['placeholder'] ?? '' );
					$value                = $form_data[ $name ] ?? $field['default'] ?? '';
					$field['default']     = $value;
					$field['value']       = self::resolve_display_value( $value, $field['type'] );
				}

				return $field;
			},
			$this->fields
		);
	}
}
