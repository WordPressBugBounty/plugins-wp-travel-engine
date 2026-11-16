<?php
/**
 * The dashboard's view of a user: their billing details, and the one write they have over the
 * account — their password.
 *
 * Serves `my/billing`, `my/address` (the classic dashboard's own menu key) and `my/password`.
 *
 * @package WPTravelEngine
 * @since 6.9.0
 */

namespace WPTravelEngine\Core\Controllers\RestAPI\V1;

use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;
use WP_User;
use Wp_Travel_Engine_Form_Handler;
use WPTravelEngine\Abstracts\MyDashboard;
use WPTravelEngine\Core\Models\Post\Customer;
use WPTravelEngine\Builders\FormFields\FileField;
use WPTravelEngine\Builders\FormFields\FileFieldChanges;
use WPTravelEngine\Core\Models\User\BillingDetails;
use WPTravelEngine\Core\Models\User\EmergencyContact;
use WPTravelEngine\Builders\FormFields\BillingFormFields;
use WPTravelEngine\Builders\FormFields\BookingFormFields;
use WPTravelEngine\Builders\FormFields\EmergencyFormFields;

class MyProfile extends MyDashboard {

	public function register_routes() {
		add_filter( 'wptravelengine_can_view_file', array( $this, 'can_view_file' ), 10, 3 );

		register_rest_route(
			$this->namespace,
			"/$this->rest_base/(?:billing|address)",
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_item' ),
					'permission_callback' => array( $this, 'logged_in_permissions_check' ),
				),
				array(
					// EDITABLE covers POST, PUT and PATCH, so a partial update can use PATCH.
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_item' ),
					'permission_callback' => array( $this, 'logged_in_permissions_check' ),
					'args'                => $this->get_writable_params(),
				),
				'schema' => array( $this, 'get_public_item_schema' ),
			)
		);

		register_rest_route(
			$this->namespace,
			"/$this->rest_base/password",
			array(
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_password' ),
					'permission_callback' => array( $this, 'logged_in_permissions_check' ),
					'args'                => $this->get_password_params(),
				),
				'schema' => array( $this, 'get_public_item_schema' ),
			)
		);

		register_rest_route(
			$this->namespace,
			"/$this->rest_base/password/reset",
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'send_password_reset' ),
				'permission_callback' => array( $this, 'logged_in_permissions_check' ),
			)
		);

		register_rest_route(
			$this->namespace,
			"/$this->rest_base/emergency",
			array(
				'methods'             => WP_REST_Server::EDITABLE,
				'callback'            => array( $this, 'update_emergency_contact' ),
				'permission_callback' => array( $this, 'logged_in_permissions_check' ),
				'args'                => array(
					'emergency' => array(
						'type'     => 'object',
						'required' => true,
					),
				),
			)
		);
	}

	/**
	 * Saves the emergency contact on the account, against the checkout's own fields; every field is optional.
	 *
	 * @param WP_REST_Request $request Request carrying `emergency`, keyed by stored key.
	 * @return WP_REST_Response|WP_Error
	 */
	public function update_emergency_contact( $request ) {
		$contact = EmergencyContact::get();
		$stored  = $contact ? $contact->all() : array();
		$rules   = array();
		$files   = array();
		$changes = $this->apply_files( $request, 'emergency', (array) $request->get_param( 'emergency' ), $stored, $files );

		if ( is_wp_error( $changes ) ) {
			return $changes;
		}

		foreach ( ( new EmergencyFormFields() )->with_values( $stored ) as $field ) {
			$key = preg_match( '#\[([^\[]+)]$#', $field['name'], $matches ) ? $matches[1] : $field['name'];

			$rules[ $key ] = array(
				'type'     => (string) ( $field['type'] ?? 'text' ),
				'required' => false,
				'label'    => (string) ( $field['field_label'] ?? $key ),
			);
		}

		$merged = BookingFormFields::apply( array( $stored ), array( (array) $request->get_param( 'emergency' ) ), $rules );

		if ( is_wp_error( $merged ) ) {
			if ( $changes ) {
				$changes->rollback();
			}

			return $merged;
		}

		$contact->put( array_merge( $merged[0], $files ) );

		if ( $changes ) {
			$changes->commit();
		}

		return rest_ensure_response( $this->prepare_user() );
	}

	/**
	 * Emails the current user a password reset link, as the core lost-password form does.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function send_password_reset() {
		$result = retrieve_password( wp_get_current_user()->user_login );

		return is_wp_error( $result ) ? $this->as_rest_error( $result ) : rest_ensure_response( array( 'sent' => true ) );
	}

	/**
	 * The current user as the dashboard reads them.
	 *
	 * @param WP_REST_Request $request Request.
	 *
	 * @return WP_REST_Response
	 */
	public function get_item( $request ): WP_REST_Response {
		return rest_ensure_response( $this->prepare_user() );
	}

	/**
	 * Saves the current user's billing details.
	 *
	 * @param WP_REST_Request $request Request.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function update_item( $request ) {
		$record  = wptravelengine_get_user( wp_get_current_user() );
		$files   = array();
		$changes = $this->apply_files( $request, 'billing', $request->get_params(), $this->stored_billing_files(), $files );

		if ( is_wp_error( $changes ) ) {
			return $changes;
		}

		$fields = $this->fill_absent( $this->writable_params( $request ), $this->saved_values() );
		$result = Wp_Travel_Engine_Form_Handler::persist_billing_details( get_current_user_id(), $fields );

		if ( is_wp_error( $result ) ) {
			if ( $changes ) {
				$changes->rollback();
			}

			return $this->as_rest_error( $result );
		}

		if ( $changes ) {
			$record->put( $files );
			$changes->commit();
		}

		return rest_ensure_response( $this->prepare_user() );
	}

	/**
	 * Applies a profile section's file fields: keeps, adds and removes the user's own documents.
	 *
	 * The form editor drops file fields from its field lists outside its own screens, so they
	 * are read from its settings here. A profile's files belong to the user's customer record.
	 *
	 * @param WP_REST_Request      $request   Save request, carrying any new files.
	 * @param string               $section   `billing` or `emergency`.
	 * @param array<string, mixed> $submitted Submitted values, keyed by stored key.
	 * @param array<string, mixed> $stored    The record as stored.
	 * @param array<string, int[]> $files     Receives each touched file field's attachment IDs.
	 * @return FileFieldChanges|WP_Error|null Changes to commit once the record is written; null when no file field was touched.
	 * @since 6.9.0
	 */
	protected function apply_files( WP_REST_Request $request, string $section, array $submitted, array $stored, array &$files ) {
		$fields    = BookingFormFields::form_file_fields( $section );
		$submitted = array_intersect_key( $submitted, $fields );

		if ( ! $submitted && ! $request->get_file_params() ) {
			return null;
		}

		$changes = FileFieldChanges::from_request( $request, $this->customer_id() );
		$valid   = $changes->validate(
			static function ( string $path ) use ( $fields, $section ): ?FileField {
				return 0 === strpos( $path, "{$section}." ) ? ( $fields[ substr( $path, strlen( $section ) + 1 ) ] ?? null ) : null;
			}
		);

		if ( is_wp_error( $valid ) ) {
			return $valid;
		}

		$files = array_intersect_key( $changes->merge( $section, $submitted, $stored, $fields ), $fields );

		return $changes;
	}

	/**
	 * The billing file fields' stored values, keyed by field key.
	 *
	 * @return array<string, mixed>
	 * @since 6.9.0
	 */
	protected function stored_billing_files(): array {
		$record = wptravelengine_get_user( wp_get_current_user() )->all();
		$stored = array();

		foreach ( array_keys( BookingFormFields::form_file_fields( 'billing' ) ) as $key ) {
			$stored[ $key ] = $record[ BillingDetails::storage_key( $key ) ] ?? '';
		}

		return $stored;
	}

	/**
	 * The current user's customer record, which a profile's files belong to; made on first need.
	 *
	 * @return int Customer post ID, or 0 when none could be made.
	 * @since 6.9.0
	 */
	protected function customer_id(): int {
		$user     = wp_get_current_user();
		$customer = wptravelengine_get_customer( $user );

		if ( ! $customer ) {
			$customer = Customer::create_post(
				array(
					'post_status' => 'publish',
					'post_type'   => 'customer',
					'post_title'  => sanitize_email( $user->user_email ),
				)
			);
		}

		return (int) $customer->ID;
	}

	/**
	 * Lets a user read the files on their own customer record.
	 *
	 * @param bool $allowed  Whether the user may read the file so far.
	 * @param int  $id       Attachment ID.
	 * @param int  $owner_id Post the file belongs to.
	 * @return bool
	 * @since 6.9.0
	 */
	public function can_view_file( $allowed, $id, $owner_id ): bool {
		if ( $allowed || 'customer' !== get_post_type( (int) $owner_id ) || ! is_user_logged_in() ) {
			return (bool) $allowed;
		}

		$customer = wptravelengine_get_customer( wp_get_current_user() );

		return $customer && (int) $customer->ID === (int) $owner_id;
	}

	/**
	 * A profile section's file fields with the files they hold, shaped like the form's own fields.
	 *
	 * @param string               $section `billing` or `emergency`.
	 * @param array<string, mixed> $stored  The record as stored, keyed by field key.
	 * @return array<int, array<string, mixed>>
	 * @since 6.9.0
	 */
	protected function file_field_rows( string $section, array $stored ): array {
		$form = 'billing' === $section ? 'checkout_form' : 'emergency_contacts_form';
		$rows = array();

		foreach ( (array) ( BookingFormFields::form_definitions()[ $form ] ?? array() ) as $definition ) {
			if ( ! FileField::is_type( (string) ( $definition['type'] ?? '' ) ) ) {
				continue;
			}

			$key   = (string) $definition['value_key'];
			$value = FileField::ids( $stored[ $key ] ?? '' );

			$rows[] = array(
				'name'           => $key,
				'type'           => 'file',
				'field_label'    => (string) $definition['label'],
				'placeholder'    => (string) $definition['placeholder'],
				'width'          => (string) $definition['width'],
				'value'          => $value,
				'default'        => $value,
				'files'          => FileField::resolve( $value ),
				'multiple'       => $definition['multiple'] ?? false,
				'max_file_size'  => $definition['max_file_size'] ?? FileField::DEFAULT_MAX_MB,
				'accepted_files' => $definition['accepted_files'] ?? FileField::DEFAULT_ACCEPT,
				'validations'    => array( 'required' => (bool) $definition['required'] ),
				'param'          => $key,
				'locked'         => false,
			);
		}

		return $rows;
	}

	/**
	 * Changes the current user's password.
	 *
	 * The only account write the dashboard has: a traveler's name and email are locked (see
	 * `Wp_Travel_Engine_Form_Handler::locked_account_fields()`), and this route carries no
	 * field that could edit them.
	 *
	 * @param WP_REST_Request $request Request.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function update_password( $request ) {
		$user = wp_get_current_user();

		$fields = array(
			'password_current' => (string) $request->get_param( 'password_current' ),
			'password_1'       => (string) $request->get_param( 'password_1' ),
			'password_2'       => (string) $request->get_param( 'password_2' ),
		);

		// The shared handler sets no rule; the dashboard promises 8+ characters with a number and a symbol.
		$weak = mb_strlen( $fields['password_1'] ) < 8 || ! preg_match( '/\d/', $fields['password_1'] ) || ! preg_match( '/[^A-Za-z0-9]/', $fields['password_1'] );

		if ( '' !== $fields['password_1'] && $weak ) {
			return new WP_Error(
				'wptravelengine_password_too_weak',
				__( 'Use at least 8 characters with a number and a symbol.', 'wp-travel-engine' ),
				array( 'status' => 400 )
			);
		}

		// The shared handler validates the account as a whole, so it is given the stored name
		// and email to check. A site that filters the field lock off would otherwise see a
		// password change fail on required fields it was never asked for.
		$fields = $this->fill_absent(
			$fields,
			array(
				'account_first_name' => $user->first_name,
				'account_last_name'  => $user->last_name,
				'account_email'      => $user->user_email,
			)
		);

		$result = Wp_Travel_Engine_Form_Handler::persist_account_details( $user->ID, $fields );

		return is_wp_error( $result )
			? $this->as_rest_error( $result )
			: rest_ensure_response( $this->prepare_user() );
	}

	/**
	 * Saved billing values, keyed by the field names the form handler reads.
	 *
	 * @return array<string, string> Param name => stored value.
	 * @since 6.9.0 Read straight off the stored record.
	 */
	protected function saved_values(): array {
		$billing = $this->billing_record();
		$values  = array();

		$slots = BillingDetails::params() + BillingDetails::custom_params();

		$record = wptravelengine_get_user( wp_get_current_user() );

		foreach ( $slots as $param => $meta_key ) {
			$values[ $param ] = $record->get( $meta_key, (string) ( $billing[ $meta_key ] ?? '' ) );
		}

		return $values;
	}

	/**
	 * The stored record, with the account's own name and email standing in where it has none.
	 *
	 * @return array<string, string> Billing meta.
	 */
	protected function billing_record(): array {
		$user    = wp_get_current_user();
		$record  = wptravelengine_get_user( $user );
		$billing = $record->all();

		// A slot may have been written under either spelling; normalised to the fixed one here,
		// so this controller and everything reading its response keep one name for a slot.
		foreach ( BillingDetails::params() as $meta_key ) {
			if ( empty( $billing[ $meta_key ] ) ) {
				$billing[ $meta_key ] = $record->get( $meta_key );
			}
		}

		$fallbacks = array(
			'billing_first_name' => $user->first_name,
			'billing_last_name'  => $user->last_name,
			'billing_email'      => $user->user_email,
		);

		foreach ( $fallbacks as $meta_key => $fallback ) {
			if ( empty( $billing[ $meta_key ] ) ) {
				$billing[ $meta_key ] = $fallback;
			}
		}

		return $billing;
	}

	/**
	 * The billing params this route accepts.
	 *
	 * @return array<string, array<string, string>> Param name => schema.
	 */
	protected function get_writable_params(): array {
		$slots  = BillingDetails::params() + BillingDetails::custom_params();
		$choice = BillingDetails::choice_slots();

		$params = array();

		foreach ( $slots as $param => $meta_key ) {
			if ( ! isset( $choice[ $meta_key ] ) ) {
				$params[ $param ] = array( 'type' => 'string' );

				continue;
			}

			$params[ $param ] = array(
				'type'              => array( 'array', 'string' ),
				'sanitize_callback' => static function ( $value ) {
					return BillingDetails::to_keys( $value );
				},
			);
		}

		// Locked fields are left out of the schema so the route does not advertise a write it
		// will not perform; `persist_billing_details()` is what actually refuses them.
		return array_diff_key( $params, BillingDetails::locked_params() );
	}

	/**
	 * Field names match the classic dashboard form so the password rules stay in one place.
	 *
	 * @return array
	 */
	protected function get_password_params(): array {
		return array(
			'password_current' => array( 'type' => 'string' ),
			'password_1'       => array( 'type' => 'string' ),
			'password_2'       => array( 'type' => 'string' ),
		);
	}

	/**
	 * An account that has never saved billing falls back to its own name and email.
	 *
	 * @return array
	 */
	protected function prepare_user(): array {
		$billing        = $this->billing_record();
		$emergency_form = new EmergencyFormFields();

		return array(
			'firstName' => $billing['billing_first_name'],
			'lastName'  => $billing['billing_last_name'],
			'email'     => $billing['billing_email'],
			'avatar'    => $this->avatar_url( wp_get_current_user() ),
			'fields'    => array_merge( $this->billing_fields(), $this->file_field_rows( 'billing', $this->stored_billing_files() ) ),
			'emergency' => array_merge(
				array_values( $emergency_form->with_values( wptravelengine_get_user_emergency_contact() ) ),
				$this->file_field_rows( 'emergency', wptravelengine_get_user_emergency_contact() )
			),
		);
	}

	/**
	 * The billing form as the checkout declares it, carrying what this user has saved.
	 *
	 * Built from the same builder the checkout renders from, so a site that renamed, reordered
	 * or added a billing field gets it here without this controller naming fields of its own.
	 *
	 * @return array<int, array<string, mixed>> Fields, each carrying the `param` it writes to
	 *                                          and whether it is `locked`.
	 * @since 6.9.0
	 */
	protected function billing_fields(): array {
		$locked = BillingDetails::locked_params();
		$saved  = $this->saved_values();
		$params = array();
		$values = array();

		foreach ( BillingDetails::fields() as $field_key => $field ) {
			$param = BillingDetails::param_for( (string) $field_key ) ?: ( isset( BillingDetails::custom_params()[ $field_key ] ) ? $field_key : '' );

			if ( '' === $param ) {
				continue;
			}

			$key            = $this->field_key( (string) ( $field['name'] ?? '' ) );
			$params[ $key ] = $param;
			$values[ $key ] = (string) ( $saved[ $param ] ?? '' );
		}

		$prepared = array();

		foreach ( ( new BillingFormFields() )->with_values( $values ) as $field ) {
			$key = $this->field_key( (string) ( $field['name'] ?? '' ) );

			if ( ! isset( $params[ $key ] ) ) {
				continue;
			}

			$param = $params[ $key ];

			// `with_values()` renders `value` for reading — a country as its name, a date in the
			// site's format. This form writes the value back, so it takes the stored one.
			$field['value']  = $field['default'] ?? '';
			$field['param']  = $param;
			$field['locked'] = isset( $locked[ $param ] );

			// The form config spells this flag as `'false'` as often as `false`, and a string is
			// truthy wherever the response is read, so it is settled to a boolean here.
			$field['validations']['required'] = wptravelengine_toggled( $field['validations']['required'] ?? false );

			$prepared[] = $field;
		}

		return $prepared;
	}

	/**
	 * The key a field writes to, e.g. `billing[fname]` gives `fname`.
	 *
	 * @param string $name Field name.
	 *
	 * @return string Key, or the name itself when it carries no bracket.
	 */
	protected function field_key( string $name ): string {
		return preg_match( '#\[([^\[\]]+)]$#', $name, $matches ) ? $matches[1] : $name;
	}

	/**
	 * The uploaded profile photo, falling back to whatever avatar the site would show.
	 *
	 * @param WP_User $user User.
	 *
	 * @return string
	 */
	protected function avatar_url( WP_User $user ): string {
		$users_meta = get_user_meta( $user->ID, 'wte_users_meta', true );
		$avatar_id  = is_array( $users_meta ) && ! empty( $users_meta['user_profile_image_id'] )
			? absint( $users_meta['user_profile_image_id'] )
			: 0;

		$uploaded = $avatar_id ? wp_get_attachment_image_url( $avatar_id, 'thumbnail' ) : '';

		return (string) ( $uploaded ? $uploaded : get_avatar_url( $user->ID ) );
	}

	/**
	 * The user as both of this controller's routes answer with.
	 *
	 * The billing values themselves are not properties here: they are written under the
	 * param names `get_writable_params()` registers, and read back off `fields`.
	 *
	 * @return array<string, mixed>
	 * @since 6.9.0
	 */
	public function get_item_schema(): array {
		if ( $this->schema ) {
			return $this->add_additional_fields_schema( $this->schema );
		}

		$this->schema = array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'wptravelengine_my_profile',
			'type'       => 'object',
			'properties' => array(
				'firstName' => array(
					'description' => __( 'First name, from billing or the account.', 'wp-travel-engine' ),
					'type'        => 'string',
					'readonly'    => true,
				),
				'lastName'  => array(
					'description' => __( 'Last name, from billing or the account.', 'wp-travel-engine' ),
					'type'        => 'string',
					'readonly'    => true,
				),
				'email'     => array(
					'description' => __( 'Email, from billing or the account.', 'wp-travel-engine' ),
					'type'        => 'string',
					'format'      => 'email',
					'readonly'    => true,
				),
				'avatar'    => array(
					'description' => __( 'Profile photo, or the avatar the site would show.', 'wp-travel-engine' ),
					'type'        => 'string',
					'format'      => 'uri',
					'readonly'    => true,
				),
				'fields'    => array(
					'description' => __( 'The billing form as the checkout declares it, carrying what is saved.', 'wp-travel-engine' ),
					'type'        => 'array',
					'readonly'    => true,
					'items'       => array(
						'type'       => 'object',
						'properties' => array(
							'param'         => array(
								'description' => __( 'Which request param this field writes to.', 'wp-travel-engine' ),
								'type'        => 'string',
							),
							'locked'        => array(
								'description' => __( 'Whether the field is refused on write.', 'wp-travel-engine' ),
								'type'        => 'boolean',
							),
							'name'          => array( 'type' => 'string' ),
							'id'            => array( 'type' => 'string' ),
							'type'          => array( 'type' => 'string' ),
							'field_label'   => array( 'type' => 'string' ),
							'class'         => array( 'type' => array( 'array', 'string' ) ),
							'wrapper_class' => array( 'type' => array( 'array', 'string' ) ),
							'priority'      => array( 'type' => array( 'integer', 'string' ) ),
							'value'         => array( 'type' => array( 'array', 'string' ) ),
							'default'       => array( 'type' => array( 'array', 'string' ) ),
							'options'       => array(
								'description' => __( 'Choices, for a field that has them.', 'wp-travel-engine' ),
								'type'        => array( 'array', 'object' ),
							),
							'validations'   => array(
								'type'       => 'object',
								'properties' => array(
									'required' => array( 'type' => 'boolean' ),
								),
							),
						),
					),
				),
			),
		);

		return $this->add_additional_fields_schema( $this->schema );
	}
}
