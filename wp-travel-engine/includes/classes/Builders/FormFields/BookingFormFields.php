<?php
/**
 * Resolves the fields behind the values a booking captured.
 *
 * @package WPTravelEngine/Builders/FormFields
 * @since 6.9.0
 */

namespace WPTravelEngine\Builders\FormFields;

use WPTravelEngine\Traits\Factory;
use WPTravelEngine\Validator\Checkout;
use WPTravelEngine\Validator\Validator;
use WPTravelEngine\Core\Models\Post\Payment;
use WPTravelEngine\Core\Models\Post\Booking;

/**
 * Describes a booking's stored values as fields, types and values.
 *
 * @since 6.9.0
 */
final class BookingFormFields {

	use Factory {
		Factory::for as private cached_for;
	}

	/**
	 * Shared validator, built on first use.
	 *
	 * @var ?Validator
	 */
	private static ?Validator $validator = null;

	/**
	 * Booking being described.
	 *
	 * @var Booking
	 */
	private Booking $booking;

	/**
	 * Whether fields are described for the operator, who is held to no required field.
	 *
	 * @var bool
	 */
	private bool $is_admin;

	/**
	 * File fields per section, built on first use.
	 *
	 * @var array<string, array<string, FileField>>
	 */
	private array $file_fields = array();

	/**
	 * Private: use `for()`, which caches per booking.
	 *
	 * @param Booking $booking  Booking being described.
	 * @param bool    $is_admin Describe every field as not required.
	 */
	private function __construct( Booking $booking, bool $is_admin = false ) {
		$this->booking  = $booking;
		$this->is_admin = $is_admin;
	}

	/**
	 * Cached instance for a booking; the admin one is cached apart so it never reaches a traveler.
	 *
	 * @param Booking $booking  Booking being described.
	 * @param bool    $is_admin Describe every field as not required.
	 * @return BookingFormFields
	 */
	public static function for( $booking, bool $is_admin = false ): object {
		return self::cached_for( $booking->ID . ( $is_admin ? '_admin' : '' ), $booking, $is_admin );
	}

	/**
	 * Travelers as fields with values, lead first.
	 *
	 * One builder for the whole list: it counts calls to tell the lead from the rest.
	 *
	 * @param bool $blank Pad with blank rows up to a lead and one other traveler.
	 * @return array<int, array> Keyed by trip ID; one entry per traveler.
	 */
	public function travelers( bool $blank = false ): array {
		$travelers = $this->booking->get_travelers();

		if ( $blank ) {
			$travelers = array_pad( $travelers, 2, array() );
		}

		if ( ! $travelers ) {
			return array();
		}

		$builder     = new TravellerFormFields();
		$definitions = $this->field_definitions();
		$details     = array();

		foreach ( $travelers as $index => $traveler ) {
			$traveler = (array) $traveler;
			$values   = $traveler;

			unset( $values['cat_id'], $values['cat_label'] );

			$details[] = $this->describe(
				$values,
				$this->traveler_fields( $builder, $traveler ),
				$definitions[ 0 === $index ? 'lead_travelers_form' : 'other_travelers_form' ] ?? array(),
				'travellers'
			);
		}

		// Travelers are recorded against the booking, so every trip it covers carries the same list.
		$keyed = array();

		foreach ( $this->booking->booked_trip_ids() as $trip_id ) {
			$keyed[ $trip_id ] = $details;
		}

		return $keyed;
	}

	/**
	 * One traveler's live fields, valued from the row.
	 *
	 * Past the lead, the builder hands back the legacy traveler page's set, while the checkout
	 * asks `DefaultFormFields::traveller()`; only a booking taken on that page keeps the legacy set.
	 *
	 * @param TravellerFormFields  $builder Shared builder; it counts calls to tell the lead from the rest.
	 * @param array<string, mixed> $row     Traveler as stored.
	 * @return array<string, array<string, mixed>>
	 */
	private function traveler_fields( TravellerFormFields $builder, array $row ): array {
		$fields = $builder->with_values( $row, $this->booking );

		if ( $builder->traveller_count > 1 && 'old' !== $this->booking->get_meta( 'traveller_page_type' ) ) {
			$fields = array_intersect_key( $fields, DefaultFormFields::traveller() );
		}

		return $fields;
	}

	/**
	 * Lead traveler per trip.
	 *
	 * @return array<int, array> Keyed by trip ID.
	 */
	public function lead_traveler(): array {
		$leads = array();

		foreach ( $this->travelers() as $trip_id => $travelers ) {
			$leads[ $trip_id ] = $travelers[0] ?? array();
		}

		return $leads;
	}

	/**
	 * Travelers other than the lead, per trip.
	 *
	 * @return array<int, array> Keyed by trip ID.
	 */
	public function other_travelers(): array {
		$others = array();

		foreach ( $this->travelers() as $trip_id => $travelers ) {
			$others[ $trip_id ] = array_slice( $travelers, 1 );
		}

		return $others;
	}

	/**
	 * Emergency contacts as fields with values.
	 *
	 * @param bool $blank Describe one blank contact when none is stored.
	 * @return array<int, array<string, array<string, mixed>>> One entry per contact.
	 */
	public function emergency_contacts( bool $blank = false ): array {
		$contacts = $this->booking->get_emergency_contacts();

		if ( ! $contacts ) {
			if ( ! $blank ) {
				return array();
			}

			$contacts = array( array() );
		}

		$builder     = new EmergencyFormFields();
		$definitions = $this->field_definitions()['emergency_contacts_form'] ?? array();
		$details     = array();

		foreach ( $contacts as $contact ) {
			$contact = (array) $contact;

			$details[] = $this->describe(
				$contact,
				$builder->with_values( $contact, $this->booking ),
				$definitions,
				'emergency'
			);
		}

		return $details;
	}

	/**
	 * Billing details as fields with values.
	 *
	 * @param bool $blank Describe a blank row when none is stored.
	 * @return array<string, array<string, mixed>> Keyed by field key.
	 */
	public function billing( bool $blank = false ): array {
		$billing = (array) $this->booking->get_billing_info();

		if ( ! $billing && ! $blank ) {
			return array();
		}

		return $this->describe(
			$billing,
			( new BillingFormFields() )->with_values( $billing ),
			$this->field_definitions()['checkout_form'] ?? array(),
			'billing'
		);
	}

	/**
	 * Payments as fields with values.
	 *
	 * The builder reads the global `$post`, so it is swapped to this booking and restored.
	 *
	 * @return array<int, array<string, array<string, mixed>>> One entry per payment, newest last.
	 */
	public function payments(): array {
		global $post;

		$previous = $post;
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Restored below.
		$post = $this->booking->post;

		/*
		 * Carts before v4 recorded no per-payment totals, so there is nothing to break a payment
		 * down by and the amounts below stand on their own.
		 */
		$payments_data = $this->booking->is_curr_cart( '>=' )
			? ( $this->booking->get_payments_data( false )['payments'] ?? array() )
			: array();

		$statuses = array(
			'completed' => wptravelengine_success_payment_status(),
			'pending'   => wptravelengine_pending_payment_status(),
			'failed'    => wptravelengine_failed_payment_status(),
		);

		$details = array();

		try {
			foreach ( $this->booking->get_payments() as $payment ) {
				if ( ! $payment instanceof Payment ) {
					continue;
				}

				$recorded = $payments_data[ $payment->ID ] ?? array();
				$status   = $payment->get_payment_status();

				// The form offers the three the operator can set, not every status a gateway reports.
				foreach ( $statuses as $offered => $reported ) {
					if ( isset( $reported[ $status ] ) ) {
						$status = $offered;
						break;
					}
				}

				$date = $payment->get_transaction_date();

				if ( '' === $date ) {
					$date = (string) ( $payment->post->post_date ?? '' );
				}

				// The response is stored as the gateway sent it, which a text field cannot carry.
				$response = $payment->get_gateway_response();

				if ( ! is_scalar( $response ) ) {
					$response = empty( $response ) ? '' : wp_json_encode( $response );
				}

				$defaults = array(
					'id'               => $payment->ID,
					'status'           => $status,
					'gateway'          => $payment->get_payment_gateway(),
					'amount'           => $payment->get_amount(),
					'deposit'          => $recorded['deposit'] ?? 0,
					'refunded'         => $recorded['refunded_amt'] ?? 0.00,
					'payable'          => $payment->get_payable_amount(),
					'currency'         => $payment->get_payable_currency(),
					'gateway_fee'      => $payment->get_gateway_fee(),
					'payment_source'   => $payment->get_payment_source(),
					'transaction_id'   => $payment->get_transaction_id(),
					'remark'           => $payment->get_remark(),
					'date'             => $date,
					'gateway_response' => (string) $response,
				);

				$labels = array();

				// A fee this payment never carried is left out, so the builder drops its field.
				foreach ( $this->booking->get_fees() as $fee ) {
					$slug = (string) ( $fee['name'] ?? '' );

					if ( '' !== $slug && isset( $recorded[ $slug ] ) ) {
						$labels[ $slug ]   = $fee['label'];
						$defaults[ $slug ] = $recorded[ $slug ];
					}
				}

				/** This filter is documented in includes/classes/Core/Booking/ViewBooking.php */
				$defaults = apply_filters( 'wptravelengine_payment_edit_form_fields', $defaults, $payment );

				$fields = ( new PaymentEditFormFields( $defaults, 'edit', $labels ) )->get_my_fields();

				foreach ( $fields as $field_key => $field ) {
					$fields[ $field_key ]['value'] = FormField::resolve_display_value(
						$field['default'] ?? '',
						(string) ( $field['type'] ?? 'text' )
					);
				}

				$details[] = $fields;
			}
		} finally {
			// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Putting back what was there.
			$post = $previous;
		}

		return $details;
	}

	/**
	 * Write allow-list for a section: each field's type, label and whether it is required.
	 *
	 * @param string $section `travelers`, `emergency` or `billing`.
	 * @return array<string, array{type: string, required: bool, label: string}> Keyed by stored value key.
	 */
	public function rules( string $section ): array {
		switch ( $section ) {
			case 'billing':
				// One payer, so the section is already one row of fields.
				$fields = $this->billing();

				if ( ! $fields ) {
					$fields = ( new BillingFormFields() )->with_values( array() );
				}
				break;
			case 'emergency':
				$fields = $this->emergency_contacts()[0] ?? ( new EmergencyFormFields() )->with_values( array(), $this->booking );
				break;
			case 'travelers':
				// Every trip carries the same travelers, so one trip's list describes them all.
				$trips = $this->travelers();
				$first = reset( $trips );
				$rows  = is_array( $first ) ? $first : array();

				if ( ! $rows ) {
					// Nothing stored, so the live form is the only account of what may be written.
					$builder = new TravellerFormFields();
					$rows    = array(
						$this->traveler_fields( $builder, array() ),
						$this->traveler_fields( $builder, array() ),
					);
				}

				// The lead's field set carries no passport, date of birth or address, so a key is
				// writable if any traveler is asked for it; the lead's account of a shared one wins.
				$fields = array();

				foreach ( $rows as $row ) {
					$fields += (array) $row;
				}
				break;
			default:
				return array();
		}

		$rules = array();

		foreach ( (array) $fields as $field_key => $field ) {
			$key = self::field_value_key( (string) $field_key, (array) $field );

			// A field the form no longer asks is shown, never written.
			if ( '' === $key || ! empty( $field['deleted'] ) ) {
				continue;
			}

			$rules[ $key ] = array(
				'type'     => (string) ( $field['type'] ?? 'text' ),
				'required' => ! $this->is_admin && wptravelengine_toggled( $field['validations']['required'] ?? false ),
				'label'    => (string) ( $field['field_label'] ?? $key ),
			);
		}

		// The form editor strips file fields outside its own screens, a REST save included.
		foreach ( array_intersect_key( $this->file_fields( $section ), self::form_file_fields( $section ) ) as $key => $file_field ) {
			$rules[ $key ] ??= array(
				'type'     => 'file',
				'required' => ! $this->is_admin && $file_field->required,
				'label'    => $file_field->label,
			);
		}

		return $rules;
	}

	/**
	 * A section's file fields, as this booking asked them.
	 *
	 * @param string $section `billing`, `emergency` or `travelers`.
	 * @return array<string, FileField> Keyed by stored value key.
	 */
	public function file_fields( string $section ): array {
		return $this->file_fields[ $section ] ??= self::file_fields_in( $this->field_definitions(), $section );
	}

	/**
	 * A section's file fields as the form asks them now, for records that are not a booking's.
	 *
	 * @param string $section `billing`, `emergency` or `travelers`.
	 * @return array<string, FileField> Keyed by stored value key.
	 */
	public static function form_file_fields( string $section ): array {
		return self::file_fields_in( self::form_definitions(), $section );
	}

	/**
	 * A section's file fields among a set of definitions.
	 *
	 * @param array<string, array> $definitions From `field_definitions()` or `form_definitions()`.
	 * @param string               $section     `billing`, `emergency` or `travelers`.
	 * @return array<string, FileField> Keyed by stored value key.
	 */
	private static function file_fields_in( array $definitions, string $section ): array {
		$forms = array(
			'billing'   => array( 'checkout_form' ),
			'emergency' => array( 'emergency_contacts_form' ),
			'travelers' => array( 'lead_travelers_form', 'other_travelers_form' ),
		);

		$fields = array();

		foreach ( $forms[ $section ] ?? array() as $form ) {
			foreach ( (array) ( $definitions[ $form ] ?? array() ) as $definition ) {
				$key = (string) ( $definition['value_key'] ?? '' );

				// The lead's definition wins for a key both traveler forms ask, as in `rules()`.
				if ( '' === $key || isset( $fields[ $key ] ) || ! FileField::is_type( (string) ( $definition['type'] ?? '' ) ) ) {
					continue;
				}

				$fields[ $key ] = FileField::from_definition( $definition );
			}
		}

		return $fields;
	}

	/**
	 * The file field an upload path names on a booking.
	 *
	 * @param string $path `billing.<key>`, `emergency.<key>` or `travelers.<index>.<key>`.
	 * @return FileField|null Null for any other shape, or a key that is no file field.
	 */
	public function file_field_for( string $path ): ?FileField {
		$parts = explode( '.', $path );

		if ( 2 === count( $parts ) && in_array( $parts[0], array( 'billing', 'emergency' ), true ) ) {
			return $this->file_fields( $parts[0] )[ $parts[1] ] ?? null;
		}

		if ( 3 === count( $parts ) && 'travelers' === $parts[0] && ctype_digit( $parts[1] ) ) {
			return $this->file_fields( 'travelers' )[ $parts[2] ] ?? null;
		}

		return null;
	}

	/**
	 * A stored row with each file field under its own key, where the Form Editor add-on saved it under a shortened one.
	 *
	 * Its checkout strips `booking_`, `lead_traveller_`, `traveller_` and `traveler_` from the key,
	 * so `lead_traveller_passport` lands as `passport`. Only a list of files is moved, so a text
	 * field that happens to share the short key stays where it is.
	 *
	 * @param array<string, mixed> $row     Row as stored.
	 * @param string               $section `billing`, `emergency` or `travelers`.
	 * @return array<string, mixed>
	 */
	public function canonical( array $row, string $section ): array {
		foreach ( array_keys( $this->file_fields( $section ) ) as $key ) {
			$short = (string) preg_replace( '/^(booking_|lead_traveller_|traveller_|traveler_)/', '', $key );

			if ( $short === $key || isset( $row[ $key ] ) || ! is_array( $row[ $short ] ?? null ) || ! FileField::ids( $row[ $short ] ) ) {
				continue;
			}

			$row[ $key ] = $row[ $short ];
			unset( $row[ $short ] );
		}

		return $row;
	}

	/**
	 * A stored row with each file field holding the files it names rather than their IDs.
	 *
	 * Saving reads the `id` back out of each, so the row can be sent back as it came.
	 *
	 * @param array<string, mixed> $row     Row as stored.
	 * @param string               $section `billing`, `emergency` or `travelers`.
	 * @return array<string, mixed> The row; a file field as `array{id: int, name: string, mime: string, url: string}[]`.
	 */
	public function with_files( array $row, string $section ): array {
		$row = $this->canonical( $row, $section );

		foreach ( array_intersect_key( $this->file_fields( $section ), $row ) as $key => $field ) {
			$row[ $key ] = FileField::resolve( $row[ $key ] );
		}

		return $row;
	}

	/**
	 * Describes one stored row by its values, so a value whose field was deleted still shows.
	 *
	 * @param array<string, mixed> $values      Row as stored.
	 * @param array<string, array> $described   Live form fields for the row.
	 * @param array<string, array> $definitions The row's form, as `field_definitions()` gives it.
	 * @param string               $group       Input name group, e.g. `emergency`.
	 * @return array<string, array<string, mixed>> Keyed by field key, or value key when the field is gone.
	 */
	private function describe( array $values, array $described, array $definitions, string $group ): array {
		$file_section = array(
			'billing'    => 'billing',
			'emergency'  => 'emergency',
			'travellers' => 'travelers',
		)[ $group ] ?? '';
		$values       = $this->canonical( $values, $file_section );
		$by_value     = array_column( $definitions, null, 'value_key' );

		// The live form keys by field key; a stored value only knows the leaf of the input name.
		$field_keys = array();

		foreach ( $described as $field_key => $field ) {
			$leaf      = self::field_value_key( (string) $field_key, (array) $field );
			$value_key = (string) ( $definitions[ $field_key ]['value_key'] ?? $leaf );

			// A default field the checkout posted under its own key rather than its `id`: the row says which.
			if ( ! array_key_exists( $value_key, $values ) && array_key_exists( (string) $field_key, $values ) ) {
				$value_key = (string) $field_key;
			}

			// The checkout saves some fields under another key, `booking_phone` as `phone`; clients read it out of `name`.
			if ( $leaf !== $value_key ) {
				$described[ $field_key ]['name'] = sprintf( '%s[%s]', $group, $value_key );
			}

			$field_keys[ $value_key ] = $field_key;
		}

		$fields = array();

		foreach ( $values as $value_key => $value ) {
			$field_key = $field_keys[ (string) $value_key ] ?? null;

			if ( null === $field_key ) {
				$definition = $by_value[ (string) $value_key ] ?? array();
				$label      = (string) ( $definition['label'] ?? '' );

				// A file field the form editor stripped outside its own screens, not a deleted one.
				$file_field = $this->file_fields( $file_section )[ (string) $value_key ] ?? null;

				// Consumers read the stored key back out of `name`, so the format is load-bearing.
				$field = array(
					'name'          => sprintf( '%s[%s]', $group, $value_key ),
					'id'            => sprintf( '%s_%s', $group, $value_key ),
					'type'          => $file_field ? 'file' : 'text',
					'field_label'   => '' !== $label ? $label : wptravelengine_humanize_key( (string) $value_key ),
					'class'         => 'wpte-checkout__input',
					'wrapper_class' => 'wpte-checkout__form-col',
				);

				// No field claims the value, or the one that did was removed from the form editor.
				if ( ! $definition || ! empty( $definition['deleted'] ) ) {
					$field['deleted'] = true;
				}
			} else {
				$field    = $described[ $field_key ];
				$captured = 'snapshot' === ( $definitions[ $field_key ]['source'] ?? '' ) ? $definitions[ $field_key ] : array();
				$label    = (string) ( $captured['label'] ?? '' );
				$type     = (string) ( $captured['type'] ?? '' );

				// The snapshot records how the field was asked, so it wins where it says anything.
				$field['field_label'] = '' !== $label ? $label : (string) ( $field['field_label'] ?? '' );
				$field['type']        = '' !== $type ? $type : (string) ( $field['type'] ?? 'text' );
			}

			$field['default'] = $value;

			// After the overlay: a field captured as a datepicker renders as one, whatever it is now.
			$field['value'] = FormField::resolve_display_value( $value, $field['type'] );

			if ( FileField::is_type( (string) $field['type'] ) ) {
				$field['files'] = FileField::resolve( $value );
			}

			$fields[ $field_key ?? $value_key ] = $field;
		}

		/*
		 * Fields the form asks for that this booking never answered. They carry no value, so
		 * the card drops them and only the editor offers them - which is what lets a field
		 * added since the booking be filled in.
		 */
		foreach ( $described as $field_key => $field ) {
			if ( isset( $fields[ $field_key ] ) ) {
				continue;
			}

			$field['default'] = '';
			$field['value']   = '';

			$fields[ $field_key ] = $field;
		}

		// In the form's order, so a field never stored under its own key, like the price category, keeps its place; deleted fields follow.
		$fields = array_replace( array_intersect_key( $described, $fields ), $fields );

		if ( $this->is_admin ) {
			foreach ( $fields as $field_key => $field ) {
				$fields[ $field_key ]['validations']['required'] = false;
			}
		}

		return $fields;
	}

	/**
	 * Stored key from an input name: `emergency[fname]` => `fname`.
	 *
	 * @param string $name Input name.
	 * @return string
	 */
	private static function value_key( string $name ): string {
		$close = strrpos( $name, ']' );
		$open  = false === $close ? false : strrpos( substr( $name, 0, $close ), '[' );

		return false === $open ? $name : substr( $name, $open + 1, $close - $open - 1 );
	}

	/**
	 * Shared validator.
	 *
	 * @return Validator
	 */
	private static function validator(): Validator {
		return self::$validator ??= new Validator();
	}

	/**
	 * Merges submitted values into stored records.
	 *
	 * Required is checked only on values sent, so a newly required field cannot block other edits.
	 *
	 * @param array<int, array<string, mixed>> $records Stored records.
	 * @param array<int, array<string, mixed>> $input   Submitted values, keyed by record index.
	 * @param array<string, array>             $rules   From `rules()`.
	 * @param bool                             $strict  Fail the whole write on an unwritable value.
	 * @return array<int, array<string, mixed>>|\WP_Error
	 */
	public static function apply( array $records, array $input, array $rules, bool $strict = true ) {
		foreach ( $input as $index => $values ) {
			$index = (int) $index;

			if ( ! isset( $records[ $index ] ) ) {
				if ( $strict ) {
					return new \WP_Error(
						'rest_invalid_index',
						/* translators: %d: submitted index. */
						sprintf( __( 'There is no record at index %d on this booking.', 'wp-travel-engine' ), $index ),
						array( 'status' => 400 )
					);
				}

				$records[ $index ] = array();
			}

			foreach ( (array) $values as $key => $value ) {
				$key = (string) $key;

				if ( ! isset( $rules[ $key ] ) ) {
					continue;
				}

				$clean = self::sanitize( $value, $rules[ $key ]['type'], $key );

				if ( $rules[ $key ]['required'] && '' === trim( is_array( $clean ) ? implode( '', $clean ) : (string) $clean ) ) {
					if ( $strict ) {
						return new \WP_Error(
							'rest_missing_required',
							/* translators: %s: field label. */
							sprintf( __( '%s cannot be left empty.', 'wp-travel-engine' ), $rules[ $key ]['label'] ),
							array( 'status' => 400 )
						);
					}

					// Nothing to report the refusal through, so the stored value stands.
					continue;
				}

				$records[ $index ][ $key ] = $clean;
			}
		}

		return $records;
	}

	/**
	 * Sanitizes a submitted value by field type.
	 *
	 * @param mixed  $value Value; an array for a multi-choice field.
	 * @param string $type  Field type.
	 * @param string $key   Stored key, for phone and country, which the type does not tell apart.
	 * @return string|array<int|string, string>|int[] Attachment IDs for a file field.
	 */
	public static function sanitize( $value, string $type, string $key = '' ) {
		// Attachment IDs, already narrowed by `FileFieldChanges::merge()` to what the booking may hold.
		if ( FileField::is_type( $type ) ) {
			return FileField::ids( $value );
		}

		if ( is_array( $value ) ) {
			return array_map(
				function ( $item ) use ( $type, $key ) {
					return self::sanitize( $item, $type, $key );
				},
				$value
			);
		}

		$value = (string) $value;

		switch ( $type ) {
			case 'email':
				return sanitize_email( $value );
			case 'textarea':
				return sanitize_textarea_field( $value );
			case 'url':
				return esc_url_raw( $value );
		}

		// The form declares both of these as plain text, so the stored key is what names them.
		if ( 'phone' === $key ) {
			return self::validator()->sanitize_phone( $value );
		}

		if ( 'country' === $key ) {
			return self::validator()->sanitize_country( $value );
		}

		return sanitize_text_field( $value );
	}

	/**
	 * Field definitions for the edit screen: snapshot first, live form editor as fallback.
	 *
	 * @param array<string, array<int, string>|null> $value_keys Per section: null for every field, an empty list to skip.
	 * @return array<string, array<string, array<string, mixed>>>
	 */
	public function field_definitions( array $value_keys = array() ): array {
		$definitions = self::collect( (array) $this->booking->get_fields_snapshot(), $value_keys );

		if ( $this->is_admin ) {
			foreach ( $definitions as $section => $fields ) {
				foreach ( $fields as $key => $field ) {
					$definitions[ $section ][ $key ]['required'] = false;
				}
			}
		}

		return apply_filters( 'wptravelengine_booking_field_definitions', $definitions, $this->booking, $value_keys );
	}

	/**
	 * Live form editor fields, by section.
	 *
	 * @param array<string, array<int, string>|null> $value_keys See `field_definitions()`.
	 * @return array<string, array<string, array<string, mixed>>>
	 */
	public static function form_definitions( array $value_keys = array() ): array {
		return self::collect( array(), $value_keys );
	}

	/**
	 * The checkout's own fields by form editor section, for a site without the form editor.
	 *
	 * Read through the same `*_fields_display` filters the checkout renders from.
	 *
	 * @return array<string, array<string, array<string, mixed>>>
	 */
	private static function core_fields(): array {
		return array(
			'checkout_form'             => DefaultFormFields::billing(),
			'lead_travellers_info_form' => DefaultFormFields::lead_traveller_form_fields(),
			'travellers_info_form'      => DefaultFormFields::traveller_form_fields(),
			'emergency_contact_info'    => DefaultFormFields::emergency_contact(),
		);
	}

	/**
	 * Snapshot definitions merged over the live form editor's.
	 *
	 * @param array<string, array<string, array>>    $snapshot   Sections as the booking captured them.
	 * @param array<string, array<int, string>|null> $value_keys See `field_definitions()`.
	 * @return array<string, array<string, array<string, mixed>>>
	 */
	private static function collect( array $snapshot, array $value_keys ): array {
		static $live = null, $editor = false, $sections = null, $core_names = null;

		if ( null === $live ) {
			$editor = function_exists( 'wptravelengine_form_editor_get_form_fields' );
			$live   = $editor ? (array) wptravelengine_form_editor_get_form_fields() : self::core_fields();

			// The lead's default fields post under the same keys as the other travelers', so its first name lands as fname.
			$travelers  = wp_list_pluck( \WTE_Default_Form_Fields::traveller_information(), 'name' );
			$core_names = $travelers + wp_list_pluck( \WTE_Default_Form_Fields::emergency_contact(), 'name' );

			foreach ( $travelers as $field_key => $field_name ) {
				$core_names[ 'lead_' . $field_key ] = $field_name;
			}

			/*
			 * Response key => form editor section. The editor also holds enquiry and waitlist
			 * forms, which a booking has no values for, so they are not mapped.
			 */
			$sections = apply_filters(
				'wptravelengine_booking_form_field_sections',
				array(
					'checkout_form'           => 'checkout_form',
					'lead_travelers_form'     => 'lead_travellers_info_form',
					'other_travelers_form'    => 'travellers_info_form',
					'emergency_contacts_form' => 'emergency_contact_info',
				)
			);
		}

		$fields = array();

		foreach ( $sections as $section => $source ) {
			$every = null === ( $value_keys[ $section ] ?? null );

			// Flipped so membership is a hash hit rather than a scan of every wanted key.
			$wanted = array_flip( (array) ( $value_keys[ $section ] ?? array() ) );

			if ( ! $every && empty( $wanted ) ) {
				continue;
			}

			$captured = (array) ( $snapshot[ $source ] ?? array() );
			$current  = (array) ( $live[ $source ] ?? array() );

			// The live form's order, as the checkout renders it; fields only the snapshot holds follow.
			foreach ( array_keys( $current + $captured ) as $key ) {
				$from_snapshot = (array) ( $captured[ $key ] ?? array() );
				$from_settings = (array) ( $current[ $key ] ?? array() );

				// A disabled field still renders if captured, but the fallback must not add it.
				if ( empty( $from_snapshot ) && false === ( $from_settings['enabled'] ?? true ) ) {
					continue;
				}

				// Resolved before the merge, so a field the booking holds no value for costs nothing.
				$name = '' !== (string) ( $from_snapshot['name'] ?? '' ) ? $from_snapshot['name'] : ( $from_settings['name'] ?? '' );

				// With no live definition, as when the form editor is off, the snapshot names a default field by its key.
				if ( ! $from_settings && isset( $core_names[ $key ] ) ) {
					$name = $core_names[ $key ];
				}

				// The checkout posts a default field under its `id` (e.g. `[travelers][fname]`), so that is the stored key.
				if ( ! empty( $from_settings['default_field'] ) && false !== strpos( (string) ( $from_settings['id'] ?? '' ), '[' ) ) {
					$name = $from_settings['id'];
				}

				// Billing keys the checkout renames whatever the field's `id` says, `booking_phone` among them.
				if ( 'checkout_form' === $section && isset( Checkout::FIELD_NAME_MAPPING[ $key ] ) ) {
					$name = Checkout::FIELD_NAME_MAPPING[ $key ];
				}

				// Older form editor configs saved a file field's name as `x[]`, which leaves no key to read.
				$name = (string) $name;
				if ( '[]' === substr( $name, -2 ) ) {
					$name = substr( $name, 0, -2 );
				}

				$value_key = self::field_value_key( (string) $key, array( 'name' => $name ) );

				if ( ! $every && ! isset( $wanted[ $value_key ] ) ) {
					continue;
				}

				$definition = self::field_definition( (string) $key, $from_snapshot, $from_settings, $value_key );

				// Captured, then removed from the form editor: shown as it was answered, never written.
				if ( $editor && $live && ( ! $from_settings || false === ( $from_settings['enabled'] ?? true ) ) ) {
					unset( $definition['options'], $definition['conditional_logic'] );

					$definition['type']     = FileField::is_type( $definition['type'] ) ? $definition['type'] : 'text';
					$definition['required'] = false;
					$definition['deleted']  = true;
				}

				$fields[ $section ][ (string) $key ] = $definition;
			}
		}

		return $fields;
	}

	/**
	 * One field's snapshot and live definitions merged, the snapshot winning.
	 *
	 * @param string               $key           Field key.
	 * @param array<string, mixed> $from_snapshot Captured definition.
	 * @param array<string, mixed> $from_settings Live definition.
	 * @param string               $value_key     Stored value key.
	 * @return array<string, mixed>
	 */
	private static function field_definition( string $key, array $from_snapshot, array $from_settings, string $value_key ): array {
		// Not `strlen`: a snapshot may carry arrays (options, validations), which would fatal.
		$captured = array_filter(
			$from_snapshot,
			function ( $value ): bool {
				return is_array( $value ) ? array() !== $value : '' !== (string) $value;
			}
		);

		// The snapshot calls the label `label`; the form editor, `field_label`. Aligned so the snapshot's wins.
		if ( isset( $captured['label'] ) ) {
			$captured['field_label'] = $captured['label'];
		}

		$merged = array_merge( $from_settings, $captured );

		$definition = array(
			'key'         => $key,
			'value_key'   => $value_key,
			'type'        => (string) ( $merged['type'] ?? 'text' ),
			'label'       => (string) ( $merged['field_label'] ?? ( $merged['label'] ?? '' ) ),
			'placeholder' => (string) ( $merged['placeholder'] ?? '' ),
			'required'    => wptravelengine_toggled( $merged['validations']['required'] ?? false ),
			'order'       => (int) ( $merged['priority'] ?? 0 ),
			'width'       => (string) ( $merged['width'] ?? '' ),
			'source'      => empty( $from_snapshot ) ? 'settings' : 'snapshot',
		);

		// Omitted rather than empty: an empty PHP array serializes to `[]`, not `{}`.
		$optional = array(
			'options'           => self::decode_options( $merged['options'] ?? null ),
			'validations'       => (array) ( $merged['validations'] ?? array() ),
			'conditional_logic' => (array) ( $merged['conditional_logic'] ?? array() ),
		);

		foreach ( $optional as $name => $value ) {
			if ( ! empty( $value ) ) {
				$definition[ $name ] = $value;
			}
		}

		if ( ! empty( $merged['orientation'] ) ) {
			$definition['orientation'] = (string) $merged['orientation'];
		}

		if ( FileField::is_type( $definition['type'] ) ) {
			$definition['multiple']       = wptravelengine_toggled( $merged['multiple'] ?? false );
			$definition['max_file_size']  = (float) ( $merged['max_file_size'] ?? FileField::DEFAULT_MAX_MB );
			$definition['accepted_files'] = (string) ( $merged['accepted_files'] ?? FileField::DEFAULT_ACCEPT );
		}

		return apply_filters( 'wptravelengine_booking_field_definition', $definition, $key, $from_snapshot, $from_settings );
	}

	/**
	 * Stored value key, from the field's input name or else its key.
	 *
	 * @param string               $field_key Field key.
	 * @param array<string, mixed> $field     Field definition.
	 * @return string
	 */
	private static function field_value_key( string $field_key, array $field ): string {
		$name = (string) ( $field['name'] ?? '' );

		return self::value_key( '' !== $name ? $name : $field_key );
	}

	/**
	 * Choice list, decoding a JSON string.
	 *
	 * @param mixed $options Raw options.
	 * @return array<string, string>
	 */
	private static function decode_options( $options ): array {
		if ( is_string( $options ) && '' !== $options ) {
			$decoded = json_decode( $options, true );

			return is_array( $decoded ) ? $decoded : array();
		}

		return is_array( $options ) ? $options : array();
	}
}
