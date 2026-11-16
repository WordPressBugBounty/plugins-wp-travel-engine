<?php
/**
 * Billing details model.
 *
 * @package WPTravelEngine/Core/Models
 * @since 6.9.0
 */

namespace WPTravelEngine\Core\Models\User;

use WPTravelEngine\Abstracts\User;
use WPTravelEngine\Builders\FormFields\DefaultFormFields;

/**
 * The billing record a user is invoiced under, and the whole shape of it.
 *
 * Kept against the user, not the `customer` post: a user can be billed without one existing.
 * A fixed slot is written `billing_*`, a checkout field keeps its own `booking_*` key, and
 * reading a slot tries both — a record written under either spelling still reads.
 *
 * @since 6.9.0
 */
class BillingDetails extends User {

	/**
	 * Meta key the record is stored under.
	 *
	 * @var string
	 */
	const META_KEY = 'wp_travel_engine_customer_billing_details';

	/**
	 * The spelling the record's fixed slots are written in.
	 *
	 * @var string
	 */
	const PREFIX = 'billing_';

	/**
	 * The spelling the checkout keys its own fields by, read as well as the one above.
	 *
	 * @var string
	 */
	const ALT_PREFIX = 'booking_';

	/**
	 * Per-request memo. `DefaultFormFields::billing()` runs the whole
	 * `wp_travel_engine_booking_fields_display` pipeline, and this is asked once per field
	 * while building a form. Static because the answer depends on the request, not the user.
	 *
	 * @var array<string, mixed>
	 */
	protected static array $memo = array();

	/**
	 * @return array<string, array> The checkout's billing fields, keyed by field key.
	 */
	public static function fields(): array {
		if ( ! isset( static::$memo['fields'] ) ) {
			static::$memo['fields'] = DefaultFormFields::billing();
		}

		return static::$memo['fields'];
	}

	/**
	 * Forgets the memo, for a caller that changes the form config mid-request.
	 *
	 * @return void
	 */
	public static function flush(): void {
		static::$memo = array();
	}

	/**
	 * The record's fixed slots.
	 *
	 * @return array<string, string> Form field name => slot name.
	 */
	public static function slots(): array {
		return array(
			'customer_billing_address' => 'address',
			'customer_billing_city'    => 'city',
			'customer_billing_state'   => 'state',
			'customer_zip_code'        => 'zip_code',
			'customer_country'         => 'country',
			'customer_phone'           => 'phone',
			'customer_billing_company' => 'company',
			'customer_first_name'      => 'first_name',
			'customer_last_name'       => 'last_name',
			'customer_email'           => 'email',
		);
	}

	/**
	 * Params a traveler cannot change about themselves; enforced wherever the record is written.
	 *
	 * @return array<string, string> Form field name => meta key it keeps its stored value from.
	 */
	public static function locked_params(): array {
		return (array) apply_filters(
			'wptravelengine_locked_billing_fields',
			array( 'customer_email' => static::PREFIX . 'email' )
		);
	}

	/**
	 * Params a traveler must fill in beyond the ones the form marks required itself.
	 *
	 * @return array<string, string> Form field name => label.
	 */
	public static function required_params(): array {
		return (array) apply_filters( 'wp_travel_engine_save_customer_billing_details_required_fields', array() );
	}

	/**
	 * The fixed slots as the meta key each is stored under.
	 *
	 * @return array<string, string> Form field name => meta key.
	 */
	public static function params(): array {
		$params = array();

		foreach ( static::slots() as $param => $slot ) {
			$params[ $param ] = static::PREFIX . $slot;
		}

		return $params;
	}

	/**
	 * The fixed slot a checkout field belongs in — matched by trying both spellings the record
	 * uses, rather than by keeping a list of pairs.
	 *
	 * @param string $field_key Field key, as `DefaultFormFields::billing()` keys it.
	 * @return string Param name, or '' when no fixed slot matches.
	 */
	public static function param_for( string $field_key ): string {
		$base  = 0 === strpos( $field_key, 'booking_' ) ? substr( $field_key, 8 ) : $field_key;
		$slots = static::slots();

		foreach ( array( "customer_{$base}", "customer_billing_{$base}" ) as $param ) {
			if ( isset( $slots[ $param ] ) ) {
				return $param;
			}
		}

		return '';
	}

	/**
	 * Slots for the fields a site added to its checkout beyond the fixed record, each keeping
	 * its answer under its own field key.
	 *
	 * @return array<string, string> Form field name => meta key.
	 */
	public static function custom_params(): array {
		if ( ! isset( static::$memo['custom'] ) ) {
			$params = array();

			foreach ( static::fields() as $field_key => $field ) {
				if ( '' !== static::param_for( (string) $field_key ) ) {
					continue;
				}

				$params[ (string) $field_key ] = static::meta_key( (string) $field_key );
			}

			static::$memo['custom'] = $params;
		}

		return static::$memo['custom'];
	}

	/**
	 * The meta key a custom field's answer is stored under: its own, except one spelled like a
	 * fixed slot, which is re-keyed so it cannot sit on that slot's name.
	 *
	 * @param string $key Field key.
	 * @return string Meta key.
	 */
	public static function meta_key( string $key ): string {
		return 0 === strpos( $key, static::PREFIX )
			? static::ALT_PREFIX . substr( $key, strlen( static::PREFIX ) )
			: $key;
	}

	/**
	 * The meta key a value is written under, leaving an already-keyed one alone.
	 *
	 * @param string $key Slot name, with or without a prefix.
	 * @return string Meta key.
	 */
	public static function storage_key( string $key ): string {
		foreach ( array( static::PREFIX, static::ALT_PREFIX ) as $prefix ) {
			if ( 0 === strpos( $key, $prefix ) ) {
				return $key;
			}
		}

		return static::PREFIX . $key;
	}

	/**
	 * The slots answered with option keys rather than free text — keys, not the labels they
	 * carry today, so renaming an option does not orphan the answers given under it.
	 *
	 * @return array<string, string> Meta key => field type.
	 */
	public static function choice_slots(): array {
		if ( ! isset( static::$memo['choices'] ) ) {
			$slots = array();

			foreach ( static::fields() as $field_key => $field ) {
				if ( ! in_array( $field['type'] ?? '', array( 'select', 'radio', 'checkbox' ), true ) ) {
					continue;
				}

				$slots[ static::meta_key( (string) $field_key ) ] = (string) $field['type'];
			}

			static::$memo['choices'] = $slots;
		}

		return static::$memo['choices'];
	}

	/**
	 * An answer as the option keys it selects. Takes an array, as a form posts it, or one
	 * comma-joined string, as an older record holds it.
	 *
	 * @param mixed $value Stored or submitted answer.
	 * @return array<int, string> Option keys, in the order given.
	 */
	public static function to_keys( $value ): array {
		$list = is_array( $value ) ? $value : explode( ',', (string) $value );

		$list = array_map(
			function ( $key ) {
				return trim( (string) $key );
			},
			$list
		);

		return array_values(
			array_filter(
				$list,
				function ( $key ) {
					return '' !== $key;
				}
			)
		);
	}

	/**
	 * A record with every choice answer stored as its option keys.
	 *
	 * @param array $record Record, keyed in the current spelling.
	 * @return array<string, mixed>
	 */
	public static function as_option_keys( array $record ): array {
		foreach ( array_keys( static::choice_slots() ) as $meta_key ) {
			if ( array_key_exists( $meta_key, $record ) ) {
				$record[ $meta_key ] = static::to_keys( $record[ $meta_key ] );
			}
		}

		return $record;
	}

	/**
	 * The stored record, exactly as it sits in meta.
	 *
	 * @return array<string, mixed>
	 */
	public function all(): array {
		$record = $this->get_meta( static::META_KEY );

		return is_array( $record ) ? $record : array();
	}

	/**
	 * One slot, in whichever spelling it was stored under.
	 *
	 * @param string $slot     Slot name, with or without a prefix.
	 * @param string $fallback Value to use when the record holds neither spelling.
	 * @return string
	 */
	public function get( string $slot, string $fallback = '' ): string {
		$record = $this->all();
		$base   = preg_replace( '/^(' . static::PREFIX . '|' . static::ALT_PREFIX . ')/', '', $slot );

		foreach ( array( static::PREFIX . $base, static::ALT_PREFIX . $base ) as $key ) {
			if ( ! isset( $record[ $key ] ) ) {
				continue;
			}

			// A choice answer is a list of keys; joined here so the callers that expect one
			// value keep getting one. `get_keys()` is for the callers that want the list.
			$value = is_array( $record[ $key ] ) ? implode( ',', $record[ $key ] ) : (string) $record[ $key ];

			if ( '' !== $value ) {
				return $value;
			}
		}

		return $fallback;
	}

	/**
	 * One slot as the option keys it selects.
	 *
	 * @param string $slot Slot name, with or without a prefix.
	 * @return array<int, string> Option keys; empty when the slot holds nothing.
	 */
	public function get_keys( string $slot ): array {
		return static::to_keys( $this->get( $slot ) );
	}

	/**
	 * Writes the record, merged over what is stored so a slot this save did not carry keeps its
	 * value. Keys are stored as given; only a bare slot name is prefixed.
	 *
	 * @param array $values Values to write, keyed by slot or meta key.
	 * @return $this
	 */
	public function put( array $values ) {
		$keyed = array();

		foreach ( $values as $key => $value ) {
			$keyed[ static::storage_key( (string) $key ) ] = $value;
		}

		$this->update_meta( static::META_KEY, static::as_option_keys( $keyed + $this->all() ) );

		return $this;
	}
}
