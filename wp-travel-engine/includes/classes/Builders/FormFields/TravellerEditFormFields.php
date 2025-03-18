<?php
/**
 *
 * @since 6.4.0
 */

namespace WPTravelEngine\Builders\FormFields;


use WPTravelEngine\Abstracts\BookingEditFormFields;
use WPTravelEngine\Builders\FormFields\DefaultFormFields;
use WPTravelEngine\Helpers\Countries;
class TravellerEditFormFields extends BookingEditFormFields {

	/**
	 * Traveller count
	 * @var int
	 */
	protected $count;

	public function __construct( array $defaults = array(), string $mode = 'edit' ) {
		parent::__construct( $defaults, $mode );
		static::$mode = $mode;
		$this->count = $defaults['index'] ?? $defaults['total_count'] + 1;
		$this->init( $this->map_fields( static::structure( $mode ) ) );
	}

	protected function map_field( $field ) {
		$name = null;

		$field = parent::map_field( $field );
		
		// Extract the name using regex patterns.
		if ( preg_match( "#\[([^\[]+)]$#", $field[ 'name' ], $matches ) ) {
			$name = $matches[ 1 ];
		} else if ( preg_match( "/^[^\s]+$/", $field[ 'name' ], $matches ) ) {
			$name = $matches[ 0 ];
		}

		// If a name was found, set field attributes.
		if ( $name ) {
			if ( $field[ 'type' ] === 'file' ) {
				$field[ 'name' ] = sprintf( '%s', $name );
				$field[ 'id' ]   = sprintf( '%s', $name );
			} else {
				$field[ 'name' ] = sprintf( 'travellers[%s][]', $name );
				$field[ 'id' ]   = '';
			}
			$field[ 'field_label' ] = isset( $field[ 'placeholder' ] ) && $field[ 'placeholder' ] !== '' ? $field[ 'placeholder' ] : $field[ 'field_label' ];

			$field[ 'default' ]     = $this->defaults[ $name ] ?? $field[ 'default' ] ?? '';
			$field['validations']['required'] = false;
			// Convert country code to country name to show in the billing form.
			$countries_list = Countries::list();
			foreach ( $countries_list as $key => $value ) {
				if ( $field[ 'default' ] === $value ) {
					$field[ 'default' ] = $key;
				}
			}
		}

		// Convert datepicker to text input to fix styling issue and to enable time selection.
		if ( $field[ 'type' ] === 'datepicker' ) {
			$field[ 'type' ]       = 'text';
			$field[ 'class' ]      = 'wpte-date-picker';
			$field[ 'attributes' ] = array(
				'data-options' => array(
					'enableTime' => true,
					'dateFormat' => 'Y-m-d H:i',
				),
			);
		}

		if( static::$mode !== 'edit' ){
			$field['option_attributes'] = array(
				'disabled' => 'disabled',
			);
			$field['attributes'] = array(
				'disabled' => 'disabled',
			);
		}

		if( $field['type'] == 'checkbox' || $field['type'] == 'radio' && isset( $this->count)) {
			$field['name'] = sprintf( 'travellers[%s][%s]', $name, $this->count);
		}
		return $field;
	}

	public static function structure( string $mode = 'edit' ): array {
		return DefaultFormFields::traveller( $mode );
	}

	public static function create( ...$args ): TravellerEditFormFields {
		return new static( ...$args );
	}
}
