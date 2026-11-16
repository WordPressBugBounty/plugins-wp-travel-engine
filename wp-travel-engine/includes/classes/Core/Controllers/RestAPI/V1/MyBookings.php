<?php
/**
 * Serves `wptravelengine/v1/my/bookings` and a booking's own detail route. The v2
 * `bookings` controller is admin-scoped (`edit_posts`), so customer-facing reads live here.
 *
 * @package WPTravelEngine
 * @since 6.9.0
 */

namespace WPTravelEngine\Core\Controllers\RestAPI\V1;

use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;
use WPTravelEngine\Abstracts\MyDashboard;
use WPTravelEngine\Builders\FormFields\BookingFormFields;
use WPTravelEngine\Core\Models\Post\Booking;
use WPTravelEngine\Core\Booking\SaveBooking;
use WPTravelEngine\Core\Booking\ViewBooking;
use WPTravelEngine\Core\Shortcodes\UserAccount;
use WPTravelEngine\Core\Controllers\RestAPI\V2\Booking as BookingController;

class MyBookings extends MyDashboard {

	public function register_routes() {
		add_filter( 'wptravelengine_can_view_file', array( $this, 'can_view_file' ), 10, 3 );

		// Detail route registered first so its extra path segment isn't shadowed by the
		// collection route below.
		register_rest_route(
			$this->namespace,
			"/$this->rest_base/bookings/(?P<id>[\d]+)",
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_item' ),
					'permission_callback' => array( $this, 'get_item_permissions_check' ),
					'args'                => array(
						'id' => array(
							'type'              => 'integer',
							'required'          => true,
							'sanitize_callback' => 'absint',
						),
					),
				),
				array(
					// EDITABLE covers POST, PUT and PATCH.
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_item' ),
					'permission_callback' => array( $this, 'get_item_permissions_check' ),
					'args'                => array(
						'id'        => array(
							'type'              => 'integer',
							'required'          => true,
							'sanitize_callback' => 'absint',
						),
						'travelers' => array(
							'type'        => 'object',
							'description' => __( 'Traveler values to write, keyed by traveler index.', 'wp-travel-engine' ),
						),
					),
				),
				'schema' => array( $this, 'get_public_item_schema' ),
			)
		);

		register_rest_route(
			$this->namespace,
			"/$this->rest_base/bookings",
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_items' ),
					'permission_callback' => array( $this, 'logged_in_permissions_check' ),
					'args'                => $this->get_collection_params(),
				),
				'schema' => array( $this, 'get_public_item_schema' ),
			)
		);
	}

	/**
	 * A booking is readable by the customer it belongs to, and by a manager who holds the
	 * booking capability — reading needs the view cap, writing the manage cap, so a view-only
	 * role cannot edit another customer's booking through the EDITABLE route.
	 *
	 * @param WP_REST_Request $request
	 * @return true|WP_Error
	 */
	public function get_item_permissions_check( $request ) {
		$logged_in = $this->logged_in_permissions_check();

		if ( is_wp_error( $logged_in ) ) {
			return $logged_in;
		}

		if ( in_array( (int) $request['id'], UserAccount::get_owned_booking_ids(), true ) ) {
			return true;
		}

		$cap = WP_REST_Server::READABLE === $request->get_method() ? 'read_wte_booking' : 'manage_wte_booking';

		if ( wptravelengine_curr_user_can( $cap ) ) {
			return true;
		}

		return new WP_Error(
			'rest_forbidden_context',
			__( 'Sorry, you are not allowed to view this booking.', 'wp-travel-engine' ),
			array( 'status' => rest_authorization_required_code() )
		);
	}

	/**
	 * The response for a booking that is not this user's to see.
	 *
	 * @return WP_Error
	 */
	protected function invalid_booking(): WP_Error {
		return new WP_Error(
			'rest_booking_invalid_id',
			__( 'Invalid booking ID.', 'wp-travel-engine' ),
			array( 'status' => 404 )
		);
	}

	/**
	 * Pagination parameters for the collection route.
	 *
	 * @return array
	 */
	public function get_collection_params(): array {
		return array(
			'page'     => array(
				'type'              => 'integer',
				'default'           => 1,
				'minimum'           => 1,
				'sanitize_callback' => 'absint',
			),
			'per_page' => array(
				'type'              => 'integer',
				'default'           => 10,
				'minimum'           => 1,
				'maximum'           => 50,
				'sanitize_callback' => 'absint',
			),
		);
	}

	/**
	 * One booking as this route returns it.
	 *
	 * Built from the same projection the booking screen reads, cut down to what the traveler
	 * may see, so the two never drift into describing a booking differently.
	 *
	 * @param Booking $booking    Booking to render.
	 * @param bool    $with_files Resolve file fields to the files they hold; see `ViewBooking::prepare_booking_data()`.
	 *
	 * @return array<string, mixed>
	 * @since 6.9.0 Drops `$basic`: field definitions ride on the mount config, so a full booking needs none.
	 */
	public static function prepare( Booking $booking, bool $with_files = false ): array {
		$properties = self::properties();
		$data       = array_intersect_key( ViewBooking::prepare_booking_data( $booking, $with_files ), $properties );
		$payment    = $properties['payments']['items']['properties'];

		$statuses = wptravelengine_get_labels( 'payment_status' );

		$data['payments'] = array_map(
			static function ( array $row ) use ( $statuses, $payment ): array {
				$row['status_label'] = $statuses[ $row['status'] ?? '' ] ?? ( $row['status'] ?? '' );

				return array_intersect_key( $row, $payment );
			},
			(array) ( $data['payments'] ?? array() )
		);

		// `booking_status` says all three; the dashboard is given the labels to read it with.
		$data['travelers_missing'] = 0;

		if ( UserAccount::collects_travelers() ) {
			$slots = 'only_lead' === wptravelengine_settings()->get( 'travellers_details_type', 'all' ) ? 1 : array_sum( array_column( (array) ( $booking->get_cart_info( 'items' )[0]['line_items']['pricing_category'] ?? array() ), 'quantity' ) );

			// Incomplete stored travelers, plus the slots nothing has been given for yet.
			$data['travelers_missing'] = array_sum( $booking->missing_traveler_counts() ) + max( 0, $slots - count( $booking->get_travelers() ) );
		}

		return $data;
	}

	/**
	 * Returns a page of the current user's bookings.
	 *
	 * @param WP_REST_Request $request
	 *
	 * @return WP_REST_Response
	 */
	public function get_items( $request ): WP_REST_Response {
		$bookings = UserAccount::get_owned_bookings();
		$per_page = (int) $request['per_page'];
		$page     = (int) $request['page'];
		$total    = count( $bookings );

		$items = array();

		/** @var Booking $booking */
		foreach ( array_slice( $bookings, ( $page - 1 ) * $per_page, $per_page ) as $booking ) {
			$items[] = static::prepare( $booking );
		}

		$response = rest_ensure_response( $items );
		$response->header( 'X-WP-Total', $total );
		$response->header( 'X-WP-TotalPages', $per_page > 0 ? (int) ceil( $total / $per_page ) : 0 );

		return $response;
	}

	/**
	 * One of the current user's bookings, by ID.
	 *
	 * The permission check already cached this user's bookings, so this reuses that list
	 * rather than rebuilding it — and a booking that is not on it is not this user's to read.
	 *
	 * @param int $booking_id Booking post ID.
	 *
	 * @return Booking|null Null when the booking is neither the current user's nor one a manager may reach.
	 */
	protected function owned_booking( int $booking_id ): ?Booking {
		foreach ( UserAccount::get_owned_bookings() as $booking ) {
			if ( $booking->get_id() === $booking_id ) {
				return $booking;
			}
		}

		// Not the user's own: the route's permission check has already applied the right cap for
		// the method, so a manager loading another customer's booking here is settled access.
		if ( wptravelengine_curr_user_can( 'read_wte_booking' ) || wptravelengine_curr_user_can( 'manage_wte_booking' ) ) {
			return wptravelengine_get_booking( $booking_id );
		}

		return null;
	}

	/**
	 * Lets a traveler read the files on their own booking.
	 *
	 * @param bool $allowed  Whether the user may read the file so far.
	 * @param int  $id       Attachment ID.
	 * @param int  $owner_id Post the file belongs to.
	 * @return bool
	 * @since 6.9.0
	 */
	public function can_view_file( $allowed, $id, $owner_id ): bool {
		return $allowed || ( 'booking' === get_post_type( (int) $owner_id ) && UserAccount::owns_booking( (int) $owner_id ) );
	}

	/**
	 * Keys a traveler row may carry that address the cart rather than describe the traveler.
	 *
	 * Refused outright rather than left to the writer: what a traveler is priced as, and what
	 * their pickup costs, is the operator's to set. Nothing downstream turns these into money
	 * today, so this is the boundary holding rather than a hole being plugged - which is the
	 * point, since the customer's own route is the one place that must never reprice a booking.
	 *
	 * @var array<string, bool>
	 * @since 6.9.0
	 */
	const PRICED_KEYS = array(
		'pricing_category' => true,
		'cat_id'           => true,
		'pickup_point'     => true,
		'pickup_location'  => true,
		'pickup_price'     => true,
		'pickups'          => true,
	);

	/**
	 * Returns one of the current user's bookings.
	 *
	 * @param WP_REST_Request $request
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_item( $request ) {
		$booking = $this->owned_booking( (int) $request['id'] );

		if ( ! $booking ) {
			return $this->invalid_booking();
		}

		return rest_ensure_response( $this->prepare_item( $booking ) );
	}

	/**
	 * Renders one booking as this route returns it.
	 *
	 * Takes the booking rather than looking it up, so a response following a write renders the
	 * instance written to; `owned_booking()` caches its own for the request.
	 *
	 * Records are raw values; `fields` describes them as this booking asked them.
	 *
	 * @param Booking $booking Booking to render.
	 *
	 * @return array<string, mixed> The booking, as the route's shape.
	 */
	protected function prepare_item( Booking $booking ): array {
		$data   = static::prepare( $booking, true );
		$fields = BookingFormFields::for( $booking, true );

		// The projection keeps only the first contact; this route edits them all.
		$data['emergency_contacts'] = array_values(
			array_map(
				function ( $contact ) use ( $fields ) {
					return $fields->with_files( (array) $contact, 'emergency' );
				},
				$booking->get_emergency_contacts()
			)
		);

		$data['fields']         = BookingFormFields::for( $booking )->field_definitions();
		$data['legacy_fields']  = 'old' === $booking->get_meta( 'traveller_page_type' );
		$data['note']           = $booking->get_customer_note();
		$data['available_fees'] = ViewBooking::get_available_fees( $booking->get_id() );
		$data['pickup_points']  = wptravelengine_is_addon_active( 'pickup_point' ) ? ( ViewBooking::get_pickup_points( $booking )[ (int) ( $data['booked_trips'][0]['id'] ?? 0 ) ] ?? array() ) : array();

		$data['travelers']           = array();
		$data['traveler_categories'] = array();

		// One label per head, in order, e.g. `[ 'Adult', 'Adult', 'Child' ]`.
		$labels = array();

		foreach ( (array) ( $booking->get_cart_info( 'items' )[0]['line_items']['pricing_category'] ?? array() ) as $line ) {
			$labels = array_merge( $labels, array_fill( 0, max( 0, (int) ( $line['quantity'] ?? 0 ) ), (string) ( $line['label'] ?? '' ) ) );
		}

		$slots     = UserAccount::collects_travelers() ? ( 'only_lead' === wptravelengine_settings()->get( 'travellers_details_type', 'all' ) ? 1 : count( $labels ) ) : 0;
		$travelers = $slots ? array_values( (array) ( $data['booked_trips'][0]['travelers'] ?? array() ) ) : array();

		// A booking taken before details were collected holds none; blank slots let them be added now.
		$travelers = array_pad( $travelers, $slots, array() );

		// Booking-wide, lead first; every trip carries the same list.
		foreach ( $travelers as $index => $traveler ) {
			$data['traveler_categories'][] = (string) ( $traveler['cat_label'] ?? $labels[ $index ] ?? '' );

			unset( $traveler['cat_id'], $traveler['cat_label'], $traveler['pickups'] );

			$data['travelers'][] = $traveler;
		}

		return $data;
	}

	/**
	 * Updates the travelers on a booking of the current user's.
	 *
	 * Written strictly and recorded against the traveler, since this is the customer revising
	 * their own details: a required field they empty is refused rather than quietly left alone.
	 *
	 * @param WP_REST_Request $request Request naming the booking and the values to write.
	 *
	 * @return WP_REST_Response|WP_Error The updated booking, as `prepare_item()` renders it.
	 */
	public function update_item( $request ) {
		$owned = $this->owned_booking( (int) $request['id'] );

		if ( ! $owned ) {
			return $this->invalid_booking();
		}

		if ( ! UserAccount::collects_travelers() ) {
			return new WP_Error(
				'rest_traveler_details_disabled',
				__( 'Traveler details are not collected for bookings.', 'wp-travel-engine' ),
				array( 'status' => 403 )
			);
		}

		$input = $request->get_param( 'travelers' );

		if ( ! is_array( $input ) || ! $input ) {
			return new WP_Error(
				'rest_nothing_to_update',
				__( 'The request named nothing that can be updated.', 'wp-travel-engine' ),
				array( 'status' => 400 )
			);
		}

		/*
		 * Filling in a blank field is always allowed - a booking taken with `fill in later`, or
		 * one whose checkout asked less, has gaps - but changing a value already given is the
		 * operator's to allow. Checked per field, so a partly filled traveler can still be completed.
		 */
		if ( ! UserAccount::travelers_may_edit() ) {
			$stored = array_values( (array) $owned->get_meta( 'wptravelengine_travelers_details' ) );

			foreach ( $input as $index => $values ) {
				if ( array_filter( array_intersect_key( (array) ( $stored[ (int) $index ] ?? array() ), (array) $values ) ) ) {
					return new WP_Error(
						'rest_traveler_edit_disabled',
						__( 'These details have already been given and cannot be changed here. Please get in touch instead.', 'wp-travel-engine' ),
						array( 'status' => 403 )
					);
				}
			}
		}

		// Ownership is settled above; SaveBooking checks nothing itself.
		$save = new SaveBooking( (int) $request['id'] );

		if ( ! $save->booking ) {
			return $this->invalid_booking();
		}

		$valid = $save->use_uploads( $request );

		if ( is_wp_error( $valid ) ) {
			return $valid;
		}

		// The strict write refuses an index the booking does not hold, so blank slots are stored first.
		$slots     = 'only_lead' === wptravelengine_settings()->get( 'travellers_details_type', 'all' ) ? 1 : array_sum( array_column( (array) ( $save->booking->get_cart_info( 'items' )[0]['line_items']['pricing_category'] ?? array() ), 'quantity' ) );
		$travelers = array_map(
			static function ( $traveler ) {
				return array_diff_key( (array) $traveler, array( 'cat_label' => true ) );
			},
			$save->booking->get_travelers()
		);

		if ( count( $travelers ) < $slots ) {
			$save->booking->sync_metas( array( 'wptravelengine_travelers_details' => array_pad( $travelers, (int) $slots, array() ) ) );
		}

		$rows = array();

		// The writer addresses a traveler by an `index` on the row itself.
		foreach ( $input as $index => $values ) {
			$rows[] = array( 'index' => (int) $index ) + array_diff_key( (array) $values, self::PRICED_KEYS );
		}

		$result = $save->set_travelers( $rows, true );

		if ( is_wp_error( $result ) ) {
			$save->files()->rollback();

			return $result;
		}

		$save->commit();

		return rest_ensure_response( $this->prepare_item( $save->booking ) );
	}

	/**
	 * The `v2/bookings` item schema, built once per request.
	 *
	 * @return array<string, mixed>
	 */
	private static function booking_schema(): array {
		static $schema = null;

		if ( null === $schema ) {
			$schema = ( new BookingController( 'booking' ) )->get_item_schema();
		}

		return $schema;
	}

	/**
	 * One booked trip, as `v2/bookings` describes it; shared with `my/overview`.
	 *
	 * @return array<string, mixed>
	 */
	public static function trip_schema(): array {
		return self::booking_schema()['properties']['booked_trips']['items'];
	}

	/**
	 * The `v2/bookings` properties a traveler may see, named rather than subtracted.
	 *
	 * Also the allow-list `prepare()` cuts responses down to, so a key added to the operator's
	 * schema stays out of this route until it is named here.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function properties(): array {
		static $properties = null;

		if ( null !== $properties ) {
			return $properties;
		}

		$properties = array_intersect_key(
			self::booking_schema()['properties'],
			array_flip(
				array(
					'id',
					'booking_status',
					'booked_date',
					'currency',
					'total_travelers',
					'total_amount',
					'total_paid_amount',
					'total_due_amount',
					'refunded_amount',
					'booked_trips',
					'discounts',
					'billing',
					'emergency_contacts',
					'payments',
					'customer',
					'payment_link',
				)
			)
		);

		$properties['payments']['items']['properties'] = array_intersect_key(
			$properties['payments']['items']['properties'],
			array_flip( array( 'id', 'label', 'gateway', 'date', 'status', 'transaction_id', 'currency', 'paid_amount', 'payable_amount', 'refunded', 'breakdown' ) )
		) + array( 'status_label' => array( 'type' => 'string' ) );

		$rows = array(
			'type'     => 'array',
			'items'    => array( 'type' => 'object' ),
			'readonly' => true,
		);

		// Still returned beside the v2 shape until the dashboard reads it.
		$properties += array(
			'travelers_missing'   => array(
				'description' => __( 'Travelers still missing a required detail.', 'wp-travel-engine' ),
				'type'        => 'integer',
				'readonly'    => true,
			),
			'travelers'           => array_merge( array( 'description' => __( 'Travelers, lead first, as the booking stores them.', 'wp-travel-engine' ) ), $rows ),
			'traveler_categories' => array(
				'description' => __( 'What each traveler is priced as, positional against `travelers`.', 'wp-travel-engine' ),
				'type'        => 'array',
				'items'       => array( 'type' => 'string' ),
				'readonly'    => true,
			),
			'fields'              => array(
				'description'          => __( 'Field definitions by form, as this booking asked them: the snapshot taken at checkout first, the live form editor after. A field the form no longer asks carries `deleted` and is read-only.', 'wp-travel-engine' ),
				'type'                 => 'object',
				'additionalProperties' => true,
				'readonly'             => true,
			),
			'legacy_fields'       => array(
				'description' => __( 'Whether the booking was taken on the old traveler form.', 'wp-travel-engine' ),
				'type'        => 'boolean',
				'readonly'    => true,
			),
			'note'                => array(
				'description' => __( 'What the traveler asked for at checkout; never the operator\'s notes.', 'wp-travel-engine' ),
				'type'        => 'string',
				'readonly'    => true,
			),
			'available_fees'      => array(
				'description' => __( 'Fees the booking\'s payments can carry, e.g. a booking fee, as `ViewBooking::get_available_fees()` describes them.', 'wp-travel-engine' ),
				'type'        => 'array',
				'items'       => array( 'type' => 'object' ),
				'readonly'    => true,
			),
			'pickup_points'       => array(
				'description' => __( 'Pickup points the booked trip offers, for the traveler form to choose from.', 'wp-travel-engine' ),
				'type'        => 'array',
				'items'       => array(
					'type'       => 'object',
					'properties' => array(
						'id'       => array( 'type' => 'string' ),
						'location' => array( 'type' => 'string' ),
						'price'    => array( 'type' => 'number' ),
					),
				),
				'readonly'    => true,
			),
		);

		return $properties;
	}

	/**
	 * The `v2/bookings` schema, without what is the operator's alone.
	 *
	 * @return array<string, mixed>
	 */
	public function get_item_schema(): array {
		if ( ! $this->schema ) {
			$this->schema = array_merge(
				self::booking_schema(),
				array(
					'title'      => 'wptravelengine_my_booking',
					'properties' => self::properties(),
				)
			);
		}

		return $this->add_additional_fields_schema( $this->schema );
	}
}
