<?php
/**
 * WP Travel Engine Trip Booking Controller class.
 *
 * @package WPTravelEngine
 * @since 6.5.2
 */

namespace WPTravelEngine\Core\Controllers\RestAPI\V2;

use WP_Error;
use WP_REST_Server;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Posts_Controller;
use WPTravelEngine\Core\Booking\SaveBooking;
use WPTravelEngine\Core\Booking\CreateBooking;
use WPTravelEngine\Core\Booking\ViewBooking;

/**
 * Class Booking
 *
 * @since 6.5.2
 */
class Booking extends WP_REST_Posts_Controller {

	/**
	 * Capability a caller needs to read bookings through this controller.
	 *
	 * @var string
	 * @since 6.9.0
	 */
	const VIEW_CAP = 'read_wte_booking';

	/**
	 * Capability a caller needs to write a booking through this controller.
	 *
	 * @var string
	 * @since 6.9.0
	 */
	const MANAGE_CAP = 'manage_wte_booking';

	/**
	 * Constructor.
	 *
	 * @param string $post_type Post type.
	 * @since 6.9.0 Filters the collection query through filter_collection_query().
	 */
	public function __construct( $post_type ) {
		parent::__construct( $post_type );
		$this->post_type = $post_type;
		$this->rest_base = 'bookings';
		$this->namespace = 'wptravelengine/v2';

		add_filter( 'rest_booking_query', array( $this, 'filter_collection_query' ), 10, 2 );
	}

	/**
	 * Translates the booking collection filters into meta queries.
	 *
	 * Hooked on `rest_booking_query`.
	 *
	 * @param array           $args    WP_Query arguments.
	 * @param WP_REST_Request $request Request instance.
	 * @return array
	 * @since 6.9.0
	 */
	public function filter_collection_query( $args, $request ) {
		$meta_query = $args['meta_query'] ?? array();

		if ( ! empty( $request['booking_status'] ) ) {
			$meta_query[] = array(
				'key'   => 'wp_travel_engine_booking_status',
				'value' => sanitize_text_field( $request['booking_status'] ),
			);
		}

		// Trip and email live in serialized meta, so both are LIKE scans. Do not add more.
		if ( ! empty( $request['trip_id'] ) ) {
			$meta_query[] = array(
				'key'     => 'cart_info',
				'value'   => 's:7:"trip_id";i:' . (int) $request['trip_id'] . ';',
				'compare' => 'LIKE',
			);
		}

		if ( ! empty( $request['customer_email'] ) ) {
			$meta_query[] = array(
				'key'     => 'billing_info',
				'value'   => sanitize_email( $request['customer_email'] ),
				'compare' => 'LIKE',
			);
		}

		if ( ! empty( $meta_query ) ) {
			$args['meta_query'] = $meta_query;
		}

		return $args;
	}

	/**
	 * Registers the collection and single-booking routes.
	 *
	 * @return void
	 * @since 6.5.2
	 * @since 6.9.0 Adds the writable single-booking route used by the admin edit app.
	 * @since 6.9.0 Writable route validates against the item schema; adds the availability sub-resource.
	 * @since 6.9.0 Adds the creatable collection route used by the admin Add New Booking wizard.
	 */
	public function register_routes() {
		register_rest_route(
			$this->namespace,
			"/$this->rest_base",
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_items' ),
					'permission_callback' => array( $this, 'get_items_permissions_check' ),
					'args'                => $this->get_collection_params(),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_item' ),
					'permission_callback' => array( $this, 'create_item_permissions_check' ),
					'args'                => $this->get_create_params(),
				),
				'schema' => array( $this, 'get_public_item_schema' ),
			)
		);

		register_rest_route(
			$this->namespace,
			"/$this->rest_base/(?P<id>[\d]+)",
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_item' ),
					'permission_callback' => array( $this, 'get_item_permissions_check' ),
					'args'                => $this->get_item_params(),
				),
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_item' ),
					'permission_callback' => array( $this, 'update_item_permissions_check' ),
					'args'                => $this->get_update_params(),
				),
				'schema' => array( $this, 'get_public_item_schema' ),
			)
		);
	}

	/**
	 * @inerhitDoc
	 * @since 6.5.2
	 * @since 6.9.0 Checks the booking view capability rather than the post type's `edit_posts`.
	 */
	public function get_items_permissions_check( $request ) {
		if ( ! wptravelengine_curr_user_can( self::VIEW_CAP ) ) {
			return new WP_Error(
				'rest_forbidden_context',
				__( 'Sorry, you are not allowed to view this resource.', 'wp-travel-engine' ),
				array( 'status' => rest_authorization_required_code() )
			);
		}

		return true;
	}

	/**
	 * @inheritDoc
	 * @since 6.5.2
	 * @since 6.9.0 Checks the capability before looking the booking up, so the 404 cannot be
	 *             used to probe which booking IDs exist; reads the ID from the route only.
	 */
	public function get_item_permissions_check( $request ) {
		$id = absint( $request->get_url_params()['id'] ?? 0 );

		$request->set_param( 'id', $id );

		$allowed = $this->check_read_permission( null );

		if ( is_wp_error( $allowed ) ) {
			return $allowed;
		}

		$post = $this->get_post( $id );

		if ( is_wp_error( $post ) ) {
			return $post;
		}

		if ( ! $post ) {
			return new WP_Error(
				'rest_post_invalid_id',
				__( 'Invalid booking ID.', 'wp-travel-engine' ),
				array( 'status' => 404 )
			);
		}

		return $this->check_read_permission( $post );
	}

	/**
	 * Writing a booking needs the manage capability, not the view one.
	 *
	 * The capability is checked before the booking is looked up: a caller who may not write
	 * gets the same 401/403 for every ID, so it cannot learn which ones exist.
	 *
	 * @param WP_REST_Request $request Request instance.
	 * @return true|WP_Error
	 * @since 6.9.0
	 */
	public function update_item_permissions_check( $request ) {
		if ( ! wptravelengine_curr_user_can( self::MANAGE_CAP ) ) {
			return new WP_Error(
				'rest_cannot_edit',
				__( 'Sorry, you are not allowed to edit this booking.', 'wp-travel-engine' ),
				array( 'status' => rest_authorization_required_code() )
			);
		}

		return $this->get_item_permissions_check( $request );
	}

	/**
	 * Creating a booking needs the manage capability; nothing is looked up before it is checked.
	 *
	 * @param WP_REST_Request $request Request instance.
	 * @return true|WP_Error
	 * @since 6.9.0
	 */
	public function create_item_permissions_check( $request ) {
		if ( ! wptravelengine_curr_user_can( self::MANAGE_CAP ) ) {
			return new WP_Error(
				'rest_cannot_create',
				__( 'Sorry, you are not allowed to create bookings.', 'wp-travel-engine' ),
				array( 'status' => rest_authorization_required_code() )
			);
		}

		return true;
	}

	/**
	 * @inheritDoc
	 * @return bool|WP_Error
	 * @since 6.5.2
	 * @since 6.9.0 Checks the booking view capability instead of the per-post `edit_post` meta
	 *             capability, which the view capability never granted - so a view-only user
	 *             could list bookings but not open one.
	 */
	public function check_read_permission( $post ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		if ( ! wptravelengine_curr_user_can( self::VIEW_CAP ) ) {
			return new WP_Error(
				'rest_forbidden_context',
				__( 'Sorry, you are not allowed to view this resource.', 'wp-travel-engine' ),
				array( 'status' => rest_authorization_required_code() )
			);
		}

		return true;
	}

	/**
	 * @inheritDoc
	 * @since 6.5.2
	 * @since 6.9.0 Returns the schema shape for both contexts.
	 */
	public function prepare_item_for_response( $item, $request ) {
		$booking = wptravelengine_get_booking( $item );

		if ( ! $booking ) {
			return rest_ensure_response( array() );
		}

		// The collection shares this method; only a single booking carries its files.
		return rest_ensure_response( ViewBooking::prepare_booking_data( $booking, isset( $request['id'] ) ) );
	}

	/**
	 * Creates a booking from a payload in the update shape and returns it in the edit shape.
	 *
	 * @param WP_REST_Request $request Request instance.
	 * @return WP_REST_Response|WP_Error
	 * @since 6.9.0
	 */
	public function create_item( $request ) {
		try {
			$save_booking = CreateBooking::create( $request );
		} catch ( \Throwable $e ) {
			// The message can name internals, so it goes to the log rather than the client.
			error_log( 'WP Travel Engine: booking create failed: ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log

			return new WP_Error(
				'rest_cannot_create',
				__( 'The booking could not be created. Nothing was saved.', 'wp-travel-engine' ),
				array( 'status' => 500 )
			);
		}

		if ( is_wp_error( $save_booking ) ) {
			return $save_booking;
		}

		$data            = ViewBooking::prepare_booking_data( $save_booking->booking, true );
		$data['editUrl'] = admin_url( 'post.php?post=' . $save_booking->booking->ID . '&action=edit' );

		$response = rest_ensure_response( $data );
		$response->set_status( 201 );

		return $response;
	}

	/**
	 * Updates booking data and returns the updated state in the edit shape.
	 *
	 * Reads the write params `get_update_params()` declares, which carry only what may be
	 * written and only the rows that changed - not the read projection echoed back. Writing is
	 * delegated to SaveBooking::process_update().
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response|WP_Error
	 * @since 6.9.0
	 */
	public function update_item( $request ) {
		$save_booking = new SaveBooking( (int) $request['id'], true );

		if ( ! $save_booking->booking ) {
			return new WP_Error(
				'rest_post_invalid_id',
				__( 'Invalid booking ID.', 'wp-travel-engine' ),
				array( 'status' => 404 )
			);
		}

		$result = $save_booking->process_update( $request );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response( ViewBooking::prepare_booking_data( $save_booking->booking, true ) );
	}

	/**
	 * Args for the single-booking routes.
	 *
	 * The collection params were previously passed here, which advertised paging and
	 * search arguments that a single-item route does not accept.
	 *
	 * @return array<string, array<string, mixed>>
	 * @since 6.9.0
	 */
	protected function get_item_params(): array {
		return array(
			'id'      => array(
				'description'       => __( 'Unique identifier for the booking.', 'wp-travel-engine' ),
				'type'              => 'integer',
				'sanitize_callback' => 'absint',
			),
			'context' => array(
				'description' => __( 'Scope under which the request is made.', 'wp-travel-engine' ),
				'type'        => 'string',
				'default'     => 'view',
				'enum'        => array( 'view', 'edit' ),
			),
		);
	}

	/**
	 * Args the update route accepts.
	 *
	 * Deliberately not `get_endpoint_args_for_item_schema()`: the item schema is a read
	 * projection, so deriving write args from it makes a client echo a whole booking back to
	 * change one field. These params carry only what may be written, and only the rows that
	 * changed - each addressed by identity rather than by position in a full array.
	 *
	 * @return array<string, array<string, mixed>>
	 * @since 6.9.0
	 */
	protected function get_update_params(): array {
		$row = static function ( string $description, array $properties ): array {
			return array(
				'description' => $description,
				'type'        => 'array',
				'items'       => array(
					'type'                 => 'object',
					'additionalProperties' => true,
					'properties'           => $properties,
				),
			);
		};

		$trip_id = array(
			'description' => __( 'Which booked trip the row belongs to, as its post ID. Omitted means the only one, which is all a single-trip booking has.', 'wp-travel-engine' ),
			'type'        => 'integer',
		);

		return array_merge(
			$this->get_item_params(),
			array(
				'status'             => array(
					'description' => __( 'Booking status to move to.', 'wp-travel-engine' ),
					'type'        => 'string',
					'enum'        => array_keys( wptravelengine_get_labels( 'booking_status' ) ),
				),
				'trip'               => array(
					'description' => __( 'One booked trip: its departure window and whether it is archived. Pricing is recalculated from the cart, so it is not accepted here.', 'wp-travel-engine' ),
					'type'        => 'object',
					'properties'  => array(
						'id'             => $trip_id,
						'key'            => array(
							'description' => __( 'Which booked trip, as `booked_trips[].key`. Preferred over `id`, which cannot tell apart two cart rows of the same trip booked on different dates.', 'wp-travel-engine' ),
							'type'        => array( 'string', 'integer' ),
						),
						'start_datetime' => array(
							'description' => __( 'Departure date, as `Y-m-d`, or `Y-m-dTH:i` when the package uses time slots.', 'wp-travel-engine' ),
							'type'        => 'string',
						),
						'end_datetime'   => array(
							'description' => __( 'Return date, at the same precision as `start_datetime`.', 'wp-travel-engine' ),
							'type'        => 'string',
						),
					),
				),
				'booked_date'        => array(
					'description' => __( 'When the booking was placed, as `Y-m-d H:i:s` in GMT. This is the post date, so it decides where the booking sorts in the list.', 'wp-travel-engine' ),
					'type'        => 'string',
				),
				'discounts'          => $row(
					__( 'Discounts to write. `name` addresses the row being rewritten, as `discounts[].name` reports it; without one a new row is written, replacing any held under the same name. `is_manual` says where the terms come from: on a manual discount `label`, `type` and `value` are the terms themselves, and on one taken from a coupon `label` is the code to resolve, which states its own. A row left out of the array keeps what it has.', 'wp-travel-engine' ),
					array(
						'name'      => array(
							'description' => __( 'Which held discount this writes, as `discounts[].name` reports it. Left out to add a new one.', 'wp-travel-engine' ),
							'type'        => array( 'null', 'string' ),
						),
						'label'     => array(
							'description' => __( 'What the discount is called, or the coupon code to resolve when `is_manual` is false.', 'wp-travel-engine' ),
							'type'        => 'string',
						),
						'type'      => array(
							'description' => __( 'Whether the deduction is a share of the subtotal or a set amount, which decides how `value` reads.', 'wp-travel-engine' ),
							'type'        => 'string',
							'enum'        => array( 'percentage', 'fixed' ),
						),
						'value'     => array(
							'description' => __( 'The rate, on a percentage discount, or the amount on a fixed one.', 'wp-travel-engine' ),
							'type'        => 'number',
							'minimum'     => 0,
						),
						'is_manual' => array(
							'description' => __( 'Whether the terms are the payload\'s own rather than a coupon\'s.', 'wp-travel-engine' ),
							'type'        => 'boolean',
						),
					)
				),
				'items'              => $row(
					__( 'Groups of a booked trip to write, shaped as `booked_trips[].line_items` reports them: `id` names the group and `items` carries its rows. `traveler` is the group of people on the booking, whose rows are addressed by `index` rather than by an id of their own. Send only the groups that changed; anything left out keeps what it has, and a group whose last row is removed is dropped from the cart.', 'wp-travel-engine' ),
					array(
						'trip_id' => $trip_id,
						'id'      => array(
							'description' => __( 'Which group the rows belong to: `traveler`, `pricing_category`, `extra_service`, `accommodation`, `travel_insurance`, or whatever an add-on registers through `wptravelengine_custom_line_item_class`.', 'wp-travel-engine' ),
							'type'        => 'string',
						),
						'items'   => array(
							'description' => __( 'The group\'s rows to write.', 'wp-travel-engine' ),
							'type'        => 'array',
							'items'       => array(
								'type'                 => 'object',
								'additionalProperties' => true,
								'properties'           => array(
									'id'        => array(
										'description' => __( 'Row ID within the group, for every group but `traveler`. A row being added carries the ID of the catalogue entry it was chosen from - the service, room, upgrade or plan - so adding the same one twice updates its row rather than doubling it. Null on a row typed by hand, which is given an ID of its own.', 'wp-travel-engine' ),
										'type'        => array( 'string', 'integer', 'null' ),
									),
									'index'     => array(
										'description' => __( 'Position of the row within its group, counted from zero. How a `traveler` row is addressed, since travelers carry no ID of their own; zero is the lead traveler.', 'wp-travel-engine' ),
										'type'        => 'integer',
									),
									'label'     => array(
										'description' => __( 'Row name to set. Only a custom row has one worth writing; a catalogue row takes its name from what it points at.', 'wp-travel-engine' ),
										'type'        => 'string',
									),
									'quantity'  => array(
										'description' => __( 'Quantity to set. Zero removes the row.', 'wp-travel-engine' ),
										'type'        => 'integer',
										'minimum'     => 0,
									),
									'price'     => array(
										'description' => __( 'Unit price to set. Left out keeps the price as booked.', 'wp-travel-engine' ),
										'type'        => 'number',
									),
									'is_manual' => array(
										'description' => __( 'True on a row typed by hand rather than chosen from the catalogue. A new one without an `id` is given a `manual_` one.', 'wp-travel-engine' ),
										'type'        => 'boolean',
									),
									'remove'    => array(
										'description' => __( 'True drops this row from the booking. Only a `traveler` row takes it, addressed by `index`; the lead traveler is index zero and is never dropped.', 'wp-travel-engine' ),
										'type'        => 'boolean',
									),
									'cat_id'    => array(
										'description' => __( 'Price category to move a `traveler` row to. Optional: left out or null keeps the category the traveler has.', 'wp-travel-engine' ),
										'type'        => array( 'integer', 'null' ),
									),
									'pickups'   => array(
										'description' => __( 'Where a `traveler` row is collected from. Optional: left out keeps the pickup the traveler has; an empty `label` clears it.', 'wp-travel-engine' ),
										'type'        => 'object',
										'properties'  => array(
											'label'     => array(
												'description' => __( 'Pickup location, as the trip lists it or as the operator names it.', 'wp-travel-engine' ),
												'type' => 'string',
											),
											'price'     => array(
												'description' => __( 'What the pickup costs.', 'wp-travel-engine' ),
												'type' => array( 'number', 'string' ),
											),
											'is_manual' => array(
												'description' => __( 'True when the trip does not offer this pickup and the operator arranged it.', 'wp-travel-engine' ),
												'type' => 'boolean',
											),
										),
									),
								),
							),
						),
					)
				),
				'billing'            => array(
					'description'          => __( 'Billing details to write. Keys are billing checkout fields, so the accepted set follows whatever the site collects.', 'wp-travel-engine' ),
					'type'                 => 'object',
					'additionalProperties' => true,
				),
				'emergency_contacts' => array(
					'description'          => __( 'Emergency contact to write, replacing what the booking holds. Keys are emergency-contact checkout fields.', 'wp-travel-engine' ),
					'type'                 => 'object',
					'additionalProperties' => true,
				),
				'payments'           => $row(
					__( 'Payment records to write, addressed by `id`. A row carrying no `id` opens a new record, for a payment the operator is recording by hand; a row naming an `id` this booking does not hold is skipped.', 'wp-travel-engine' ),
					array(
						'id'                 => array(
							'description' => __( 'Payment post ID. Null or omitted for a payment being recorded, which has none yet.', 'wp-travel-engine' ),
							'type'        => array( 'integer', 'null' ),
						),
						'is_payment_request' => array(
							'description' => __( 'What the payment is recording: false for money already taken, which settles on the details sent with it, or true for a request the customer has yet to pay, which owes what it is for and carries none of the details of settling. Written on a row naming an `id` too, so a record opened as one can be turned into the other. A row opening a new payment and leaving it out records money taken.', 'wp-travel-engine' ),
							'type'        => 'boolean',
						),
						'status'             => array(
							'description' => __( 'Raw payment status slug, as `payments[].status` reports it.', 'wp-travel-engine' ),
							'type'        => 'string',
						),
						'gateway'            => array(
							'description' => __( 'Gateway slug the payment was taken through.', 'wp-travel-engine' ),
							'type'        => 'string',
						),
						'transaction_id'     => array(
							'description' => __( 'Gateway transaction reference.', 'wp-travel-engine' ),
							'type'        => 'string',
						),
						'date'               => array(
							'description' => __( 'Transaction date, as `Y-m-d H:i`.', 'wp-travel-engine' ),
							'type'        => 'string',
						),
						'paid_amount'        => array(
							'description' => __( 'Amount this payment settled, fee and tax included. The booking\'s paid and due totals are re-derived from every completed payment when this is written. An empty string is a payment that has collected nothing, such as a request awaiting the customer.', 'wp-travel-engine' ),
							'type'        => array( 'number', 'string' ),
							'minimum'     => 0,
						),
						'payable_amount'     => array(
							'description' => __( 'Amount this payment asks for, before the fees it carries - the deposit alone, since the cart adds those on top when the customer settles it. Left out, it stays what the record was opened for.', 'wp-travel-engine' ),
							'type'        => array( 'number', 'string' ),
							'minimum'     => 0,
						),
						'deposit_type'       => array(
							'description' => __( 'Whether `deposit_value` is a share of the balance due or a set amount. Kept because a rate cannot be worked back out of what the deposit came to.', 'wp-travel-engine' ),
							'type'        => 'string',
							'enum'        => array( 'percent', 'fixed' ),
						),
						'deposit_value'      => array(
							'description' => __( 'The figure the deposit was worked out from, as the operator entered it. An empty string when the payment covers the whole balance.', 'wp-travel-engine' ),
							'type'        => array( 'number', 'string' ),
							'minimum'     => 0,
						),
						'deposit'            => array(
							'description' => __( 'What of `paid_amount` went towards the trip, before the fees it carried. Left out it is derived, as `paid_amount` less every fee; an empty string is a field the operator left blank, which derives it too.', 'wp-travel-engine' ),
							'type'        => array( 'number', 'string' ),
						),
						'fees'               => array(
							'description'          => __( 'Fees within `paid_amount`, keyed by the name the cart charges each under - `tax`, `booking_fee`, or whatever an add-on registered. A fee named here is rewritten; one left out keeps what the payment records. An empty string is a field the operator left blank, which charges nothing. What is left of `paid_amount` once the fees are off is derived, not sent.', 'wp-travel-engine' ),
							'type'                 => 'object',
							'additionalProperties' => array(
								'type' => array( 'number', 'string' ),
							),
						),
						'remark'             => array(
							'description' => __( 'Operator note on the payment. An empty string clears it.', 'wp-travel-engine' ),
							'type'        => 'string',
						),
						'remove'             => array(
							'description' => __( 'True deletes the payment named by `id`. A completed or refunded payment is never deleted, unless the booking was created manually.', 'wp-travel-engine' ),
							'type'        => 'boolean',
						),
					)
				),
				'refunds'            => $row(
					__( 'Refunds against the booking, replacing the list wholesale - a refund left out is deleted. Left out entirely the list is untouched. The booking\'s refunded, paid and due totals are re-derived from what is sent.', 'wp-travel-engine' ),
					array(
						'id'     => array(
							'description' => __( 'Identifier of an existing refund, which keeps its recorded date. Omitted for one just issued.', 'wp-travel-engine' ),
							'type'        => 'string',
						),
						'amount' => array(
							'description' => __( 'Amount refunded. A refund of zero or less is dropped rather than stored.', 'wp-travel-engine' ),
							'type'        => 'number',
						),
						'reason' => array(
							'description' => __( 'Why the refund was issued.', 'wp-travel-engine' ),
							'type'        => 'string',
						),
						'method' => array(
							'description' => __( 'How the money went back.', 'wp-travel-engine' ),
							'type'        => 'string',
						),
						'note'   => array(
							'description' => __( 'Internal note on the refund.', 'wp-travel-engine' ),
							'type'        => 'string',
						),
					)
				),
				'customer_notes'     => array(
					'description' => __( 'The note shown to the customer. Left out it is untouched; an empty string clears it.', 'wp-travel-engine' ),
					'type'        => 'string',
				),
				'admin_notes'        => $row(
					__( 'The internal notes, never shown to the customer, replacing the list wholesale - a note left out is deleted. Left out entirely the list is untouched.', 'wp-travel-engine' ),
					array(
						'id'      => array(
							'description' => __( 'Identifier of an existing note, which keeps its author and date. Omitted for a note just written.', 'wp-travel-engine' ),
							'type'        => 'string',
						),
						'content' => array(
							'description' => __( 'Note text. An empty one is dropped rather than stored.', 'wp-travel-engine' ),
							'type'        => 'string',
						),
					)
				),
			)
		);
	}

	/**
	 * Args the create route accepts: the update args, with `trip` saying what to book.
	 *
	 * A trip or package ID of 0 asks for a custom one, named by `title` / `package_name`.
	 *
	 * @return array<string, array<string, mixed>>
	 * @since 6.9.0
	 */
	protected function get_create_params(): array {
		$params = $this->get_update_params();

		unset( $params['id'], $params['context'] );

		$trip = &$params['trip'];

		$trip['required']    = true;
		$trip['description'] = __( 'The trip to book and its departure window.', 'wp-travel-engine' );

		unset( $trip['properties']['key'] );

		$trip['properties']['id']['minimum']              = 0;
		$trip['properties']['id']['description']          = __( 'Trip post ID, or 0 for a custom trip named by `title`.', 'wp-travel-engine' );
		$trip['properties']['start_datetime']['required'] = true;
		$trip['properties']['start_datetime']['pattern']  = '^\d{4}-\d{2}-\d{2}(T\d{2}:\d{2})?$';
		$trip['properties']['end_datetime']['pattern']    = '^(\d{4}-\d{2}-\d{2}(T\d{2}:\d{2})?)?$';

		$trip['properties']['title'] = array(
			'description' => __( 'Name of a custom trip. Ignored when `id` names one.', 'wp-travel-engine' ),
			'type'        => 'string',
		);

		$trip['properties']['package_id'] = array(
			'description' => __( 'Package post ID on the trip, or 0 for a custom package named by `package_name`.', 'wp-travel-engine' ),
			'type'        => 'integer',
			'minimum'     => 0,
		);

		$trip['properties']['package_name'] = array(
			'description' => __( 'Name of a custom package. Ignored when `package_id` names one.', 'wp-travel-engine' ),
			'type'        => 'string',
		);

		return $params;
	}

	/**
	 * Collection params for the bookings list.
	 *
	 * @return array<string, array<string, mixed>>
	 * @since 6.9.0
	 */
	public function get_collection_params(): array {
		$params = parent::get_collection_params();

		// The edit shape costs a Payment model per row; the app reads one booking at a time.
		$params['context']['enum']    = array( 'view' );
		$params['context']['default'] = 'view';

		unset( $params['sticky'], $params['search_columns'] );

		$params['booking_status'] = array(
			'description' => __( 'Limit results to bookings with this booking status.', 'wp-travel-engine' ),
			'type'        => 'string',
			'enum'        => array_keys( wptravelengine_get_labels( 'booking_status' ) ),
		);

		$params['trip_id'] = array(
			'description' => __( 'Limit results to bookings for this trip.', 'wp-travel-engine' ),
			'type'        => 'integer',
		);

		$params['customer_email'] = array(
			'description' => __( 'Limit results to bookings with this billing email.', 'wp-travel-engine' ),
			'type'        => 'string',
			'format'      => 'email',
		);

		return $params;
	}

	/**
	 * Schema for a booking resource.
	 *
	 * One snake_case shape for both contexts; `edit` only widens what may be written.
	 *
	 * @return array<string, mixed>
	 * @since 6.9.0
	 */
	public function get_item_schema(): array {
		if ( $this->schema ) {
			return $this->add_additional_fields_schema( $this->schema );
		}

		$both = array( 'view', 'edit' );
		$view = array( 'view' );

		/*
		 * Builds one property. Context falls back to both, which is what all but a couple of
		 * fields want; a null $is_readonly is left out of the result rather than written as null,
		 * since JSON Schema reads a present-but-null differently from an absent key.
		 */
		$prop = static function ( string $description, $type, ?bool $is_readonly = null, ?array $context = null, array $extra = array() ) use ( $both ): array {
			$property = array(
				'description' => $description,
				'type'        => $type,
				'context'     => $context ?? $both,
			);

			if ( null !== $is_readonly ) {
				$property['readonly'] = $is_readonly;
			}

			return array_merge( $property, $extra );
		};

		// Reused for every form type. Markup keys (wrapper_class, attributes, raw `name`)
		// stay unexposed: the client owns its DOM, and `value_key` distills `name`.
		$field_definition = array(
			'type'                 => 'object',

			// Add-ons register their own field types; declaring extra keys keeps them documented.
			'additionalProperties' => true,
			'properties'           => array(
				'key'               => array(
					'description' => __( 'Field key, as registered in the form editor.', 'wp-travel-engine' ),
					'type'        => 'string',
				),
				'value_key'         => array(
					'description' => __( 'Key this field\'s value is stored under, e.g. the `booking_first_name` field stores to `fname`. Custom fields store under their own key, so the two match.', 'wp-travel-engine' ),
					'type'        => 'string',
				),
				'type'              => array(
					'description' => __( 'Widget type, which decides both the control to render and the shape of the value: `checkbox` holds an array, everything else a string. Core types are `text`, `email`, `tel`, `number`, `textarea`, `select`, `radio`, `checkbox`, `country_dropdown`, `datepicker`, `dob` and `file`; add-ons may register others, so treat an unknown type as `text`.', 'wp-travel-engine' ),
					'type'        => 'string',
				),
				'label'             => array(
					'description' => __( 'Translated field label.', 'wp-travel-engine' ),
					'type'        => 'string',
				),
				'placeholder'       => array(
					'description' => __( 'Translated placeholder text. Empty when none is configured.', 'wp-travel-engine' ),
					'type'        => 'string',
				),
				'options'           => array(
					'description'          => __( 'Choices for `select`, `radio` and `checkbox`, as a value-to-label map, already decoded. Absent for field types that take free input. The form editor stores this as a JSON string; the API decodes it.', 'wp-travel-engine' ),
					'type'                 => 'object',
					'additionalProperties' => array( 'type' => 'string' ),
					'properties'           => array(),
				),
				'required'          => array(
					'description' => __( 'Whether the field must be filled in.', 'wp-travel-engine' ),
					'type'        => 'boolean',
				),
				'validations'       => array(
					'description'          => __( 'Extra constraints the form editor applies, such as `maxlength` or a character-class `type`. Keys vary by field, so treat this as an open map.', 'wp-travel-engine' ),
					'type'                 => 'object',
					'additionalProperties' => true,
					'properties'           => array(),
				),
				'order'             => array(
					'description' => __( 'Position of the field within its form, ascending. Taken from the form editor priority.', 'wp-travel-engine' ),
					'type'        => 'integer',
				),
				'width'             => array(
					'description' => __( 'Width the administrator chose for the field, as a percentage of the row, e.g. `50` for a half-width field.', 'wp-travel-engine' ),
					'type'        => 'string',
				),
				'orientation'       => array(
					'description' => __( 'How `radio` and `checkbox` choices are laid out. Absent for other field types.', 'wp-travel-engine' ),
					'type'        => 'string',
					'enum'        => array( 'vertical', 'horizontal' ),
				),
				'conditional_logic' => array(
					'description' => __( 'Rules deciding whether the field is shown at all. When `enabled` is false the field always renders.', 'wp-travel-engine' ),
					'type'        => 'object',
					'properties'  => array(
						'enabled' => array(
							'description' => __( 'Whether conditional logic applies to this field.', 'wp-travel-engine' ),
							'type'        => 'boolean',
						),
						'action'  => array(
							'description' => __( 'What to do when the rules match.', 'wp-travel-engine' ),
							'type'        => 'string',
							'enum'        => array( 'show', 'hide' ),
						),
						'match'   => array(
							'description' => __( 'Whether every rule must match or any single one is enough.', 'wp-travel-engine' ),
							'type'        => 'string',
							'enum'        => array( 'all', 'any' ),
						),
						'rules'   => array(
							'description' => __( 'Conditions evaluated against other fields on the same form.', 'wp-travel-engine' ),
							'type'        => 'array',
							'items'       => array(
								'type'                 => 'object',
								'additionalProperties' => true,
								'properties'           => array(),
							),
						),
					),
				),
				'source'            => array(
					'description' => __( 'Where this definition came from: `snapshot` is the form as captured when the booking was placed, `settings` is the live form editor, used when the booking carries no snapshot for the field. A `settings` definition can differ from what the customer actually saw.', 'wp-travel-engine' ),
					'type'        => 'string',
					'enum'        => array( 'snapshot', 'settings' ),
				),
				'deleted'           => array(
					'description' => __( 'Present and true when the field was captured at checkout and has since been removed from the form editor. It is described as `text`, or `file` for one holding files, and cannot be written.', 'wp-travel-engine' ),
					'type'        => 'boolean',
				),
			),
		);

		/**
		 * Filters the declared shape of one form field definition.
		 *
		 * Lets an add-on document keys it adds to a definition. Pair it with
		 * `wptravelengine_booking_field_definition` to emit them.
		 *
		 * @param array $field_definition Field definition schema.
		 * @since 6.9.0
		 */
		$field_definition = apply_filters( 'wptravelengine_rest_booking_field_definition_schema', $field_definition );

		$this->schema = array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'booking',
			'type'       => 'object',
			'properties' => array(
				'id'                 => $prop( __( 'Unique identifier for the booking.', 'wp-travel-engine' ), 'integer', true ),
				'booking_status'     => $prop( __( 'Where the booking stands. Written through the update endpoint\'s `status` param, not by sending this back.', 'wp-travel-engine' ), 'string', true ),
				'prev_status'        => $prop( __( 'The status the booking held before its current one, which unarchiving returns it to; empty when it has never changed.', 'wp-travel-engine' ), 'string', true ),
				'booked_date'        => $prop( __( 'Date the booking was placed, in GMT, as `Y-m-d H:i:s`.', 'wp-travel-engine' ), 'string', true ),
				'currency'           => $prop( __( 'Three-letter currency code the booking was taken in, e.g. `USD`. Every amount on this booking is in this currency.', 'wp-travel-engine' ), 'string', true ),
				'total_travelers'    => $prop( __( 'Number of travelers across every trip in the booking.', 'wp-travel-engine' ), 'integer', true ),
				'total_amount'       => $prop( __( 'Booking total after discounts, fees and tax.', 'wp-travel-engine' ), 'number', true ),
				'total_paid_amount'  => $prop( __( 'Sum of completed payments, net of any refunds.', 'wp-travel-engine' ), 'number', true ),
				'total_due_amount'   => $prop( __( 'Amount still outstanding: total minus paid. Zero once the booking is settled.', 'wp-travel-engine' ), 'number', true ),
				'collected_amount'   => $prop( __( 'What the traveler handed over, before anything went back to them. `total_paid_amount` is this less `refunded_amount`.', 'wp-travel-engine' ), 'number', true ),
				'refunded_amount'    => $prop( __( 'Total refunded against this booking.', 'wp-travel-engine' ), 'number', true ),
				'refunds'            => array(
					'description' => __( 'Refunds issued against this booking, oldest first. Held on the booking rather than against a payment, since a payment reports a refunded figure only while its own status sits in the refund group.', 'wp-travel-engine' ),
					'type'        => 'array',
					'context'     => $both,
					'items'       => array(
						'type'       => 'object',
						'properties' => array(
							'id'     => array(
								'description' => __( 'Identifier of the refund, which survives the list being rewritten.', 'wp-travel-engine' ),
								'type'        => 'string',
							),
							'amount' => array(
								'description' => __( 'Amount refunded.', 'wp-travel-engine' ),
								'type'        => 'number',
							),
							'reason' => array(
								'description' => __( 'Why the refund was issued, as the operator chose it.', 'wp-travel-engine' ),
								'type'        => 'string',
							),
							'method' => array(
								'description' => __( 'How the money went back, e.g. the gateway slug or a manual method.', 'wp-travel-engine' ),
								'type'        => 'string',
							),
							'note'   => array(
								'description' => __( 'Internal note on the refund. Never shown to the customer.', 'wp-travel-engine' ),
								'type'        => 'string',
							),
							'date'   => array(
								'description' => __( 'When the refund was recorded, in GMT, as `Y-m-d H:i:s`.', 'wp-travel-engine' ),
								'type'        => 'string',
							),
						),
					),
				),
				'booked_trips'       => array(
					'description' => __( 'Trips in the booking, derived from cart info. A booking normally holds one, but a multi-item cart holds several. Writable here are the departure window, the travelers, and the quantity and price of each priced row; everything else is projected from the trip or computed, and is marked read-only.', 'wp-travel-engine' ),
					'type'        => 'array',
					'context'     => $both,
					'items'       => array(
						'type'       => 'object',
						'properties' => array(
							'key'             => $prop( __( 'Position of this trip in the booking, counted from zero. The handle the update endpoint addresses a trip by: `id` cannot serve, since a cart may hold the same trip on two dates.', 'wp-travel-engine' ), 'integer', true ),
							'id'              => $prop( __( 'Trip post ID. Zero when the trip has since been deleted.', 'wp-travel-engine' ), 'integer', true ),
							'exists'          => $prop( __( 'Whether the trip is still on the site. False once it has been deleted or trashed.', 'wp-travel-engine' ), 'boolean', true ),
							'title'           => $prop( __( 'Trip title as booked, which survives the trip being renamed or deleted.', 'wp-travel-engine' ), 'string', true ),
							'url'             => $prop( __( 'Permalink to the trip. Empty when the trip no longer exists.', 'wp-travel-engine' ), 'string', true, null, array( 'format' => 'uri' ) ),
							'image'           => $prop( __( 'Featured image of the trip, at medium size. Empty when the trip has no featured image or no longer exists.', 'wp-travel-engine' ), 'string', true ),
							'trip_code'       => $prop( __( 'Trip code shown to the customer, e.g. `WTE-119`.', 'wp-travel-engine' ), 'string', true ),
							'start_datetime'  => array(
								'description' => __( 'Departure date, as `Y-m-d`, or `Y-m-dTH:i` when the package uses time slots.', 'wp-travel-engine' ),
								'type'        => 'string',
							),
							'end_datetime'    => array(
								'description' => __( 'Return date, always at the same precision as `start_datetime`: both carry a time, or neither does.', 'wp-travel-engine' ),
								'type'        => 'string',
							),
							'travelers_count' => $prop( __( 'Travelers priced on this trip, as the cart counts them.', 'wp-travel-engine' ), 'integer', true ),
							'package'         => array(
								'description' => __( 'Package selected for this trip.', 'wp-travel-engine' ),
								'type'        => 'object',
								'properties'  => array(
									'id'    => array(
										'description' => __( 'Trip package post ID.', 'wp-travel-engine' ),
										'type'        => 'integer',
									),
									'label' => $prop( __( 'Package name as booked.', 'wp-travel-engine' ), 'string', true ),
								),
							),
							'line_items'      => array(
								'description' => __( 'Priced groups making up the trip cost, in the order the cart holds them. `price_category` is the per-traveler-category pricing and leads the list; the rest are what add-ons priced, such as `extra_service` or `accommodation`. Cart-level fees and discounts are not part of this resource.', 'wp-travel-engine' ),
								'type'        => 'array',
								'items'       => array(
									'type'       => 'object',
									'properties' => array(
										'id'    => $prop( __( 'Line item group key, e.g. `price_category` or `extra_service`. A slug, not a numeric ID.', 'wp-travel-engine' ), 'string', true ),
										'items' => array(
											'description' => __( 'Priced rows inside this group.', 'wp-travel-engine' ),
											'type'        => 'array',
											'items'       => array(
												'type' => 'object',
												'properties' => array(
													'id' => $prop( __( 'Row ID: the ID of the catalogue entry the row was booked from, or one generated for a row typed by hand. The price category ID on a `price_category` row.', 'wp-travel-engine' ), array( 'string', 'integer' ), true ),
													'label' => $prop( __( 'Row label, e.g. `Adult` on a `price_category` row.', 'wp-travel-engine' ), 'string', true ),
													'quantity' => array(
														'description' => __( 'Quantity booked for this row; travelers, on a `price_category` row.', 'wp-travel-engine' ),
														'type'        => 'integer',
													),
													'price' => array(
														'description' => __( 'Unit price for this row at the time of booking.', 'wp-travel-engine' ),
														'type'        => 'number',
													),
													'total' => $prop( __( 'Row total: quantity times price. Negative for a discount.', 'wp-travel-engine' ), 'number', true ),
												),
											),
										),
									),
								),
							),
							'travelers'       => array(
								'description' => __( 'Travelers on this trip, in booking order; the first is the lead traveler. Keys are not fixed: every key except `cat_id` and `cat_label` is a checkout form field, so the set depends on which traveler fields the site collects and on anything add-ons inject through `wp_travel_engine_traveller_info_fields_display` and `wp_travel_engine_lead_traveller_info_fields_display`. Commonly present are `title`, `fname`, `lname`, `email`, `phone`, `country`, `address`, `city`, `postcode`, `passport` and `dob`, but none of them is guaranteed. A file field holds its files as `{ id, name, mime, url }` objects when one booking is read, and its attachment IDs in a list.', 'wp-travel-engine' ),
								'type'        => 'array',
								'items'       => array(
									'type'                 => 'object',
									'additionalProperties' => true,
									'properties'           => array(
										'cat_id'    => array(
											'description' => __( 'Price category ID this traveler was booked under. Always present: it is resolved from cart info rather than submitted on the form.', 'wp-travel-engine' ),
											'type'        => 'integer',
										),
										'cat_label' => array(
											'description' => __( 'Price category label, e.g. `Adult`. Always present, for the same reason as `cat_id`.', 'wp-travel-engine' ),
											'type'        => 'string',
										),
										'pickups'   => array(
											'description' => __( 'Where this traveler is collected from, when they are. Optional: absent on a traveler without a pickup.', 'wp-travel-engine' ),
											'type'        => 'object',
											'properties'  => array(
												'label' => array(
													'description' => __( 'Pickup location, as the trip lists it or as the operator named it.', 'wp-travel-engine' ),
													'type' => 'string',
												),
												'price' => array(
													'description' => __( 'What the pickup costs.', 'wp-travel-engine' ),
													'type' => 'number',
												),
												'is_manual' => array(
													'description' => __( 'True when the trip does not offer this pickup and the operator arranged it.', 'wp-travel-engine' ),
													'type' => 'boolean',
												),
											),
										),
									),
								),
							),
							'availability'    => array(
								'description' => __( 'Seat availability for this trip on its booked date, computed per request and never stored. Absent when the trip or package has been deleted.', 'wp-travel-engine' ),
								'type'        => 'object',
								'readonly'    => true,
								'properties'  => array(
									'date'         => array(
										'description' => __( 'Date the availability was computed for, as `Y-m-d`.', 'wp-travel-engine' ),
										'type'        => 'string',
									),
									'status'       => array(
										'description' => __( 'Seat state. `past` is a departure that has already gone; `unknown` means it could not be determined.', 'wp-travel-engine' ),
										'type'        => 'string',
										'enum'        => array( 'available', 'full', 'oversold', 'unlimited', 'past', 'unknown' ),
									),
									'is_unlimited' => array(
										'description' => __( 'True when neither the package nor the trip caps participants.', 'wp-travel-engine' ),
										'type'        => 'boolean',
									),
									'capacity'     => array(
										'description' => __( 'Seats the package allows on this date. Null when capacity is uncapped.', 'wp-travel-engine' ),
										'type'        => array( 'integer', 'null' ),
									),
									'seats_left'   => array(
										'description' => __( 'Capacity minus the pax of every other booking on this date. This booking is excluded, so it never counts against itself. Negative means oversold.', 'wp-travel-engine' ),
										'type'        => array( 'integer', 'null' ),
									),
									'booked_pax'   => array(
										'description' => __( 'Travelers this booking itself holds on the date.', 'wp-travel-engine' ),
										'type'        => 'integer',
									),
								),
							),
						),
					),
				),
				'discounts'          => array(
					'description' => __( 'Amounts taken off the booking, such as a coupon. Named by the cart in the same way a fee is, so a discount an add-on registered reads like the coupon core applies. Held against the cart rather than a trip, since one coupon covers the whole booking. Write these back through the matching `discounts` of the update endpoint, which takes rows of this same shape.', 'wp-travel-engine' ),
					'type'        => 'array',
					'context'     => $both,
					'readonly'    => true,
					'items'       => array(
						'type'       => 'object',
						'properties' => array(
							'name'        => array(
								'description' => __( 'Name the cart deducts this under, e.g. `coupon`. This is what a write passes back as `discount.slug`.', 'wp-travel-engine' ),
								'type'        => 'string',
							),
							'label'       => array(
								'description' => __( 'Label as it was applied, e.g. the coupon code.', 'wp-travel-engine' ),
								'type'        => 'string',
							),
							'description' => array(
								'description' => __( 'What the deduction is for, as the cart recorded it.', 'wp-travel-engine' ),
								'type'        => 'string',
							),
							'type'        => array(
								'description' => __( 'How the deduction is worked out: a share of the subtotal, or a set amount.', 'wp-travel-engine' ),
								'type'        => 'string',
								'enum'        => array( 'percentage', 'fixed' ),
							),
							'percentage'  => array(
								'description' => __( 'The rate, when the deduction is a percentage. Zero on a fixed one.', 'wp-travel-engine' ),
								'type'        => 'number',
							),
							'value'       => array(
								'description' => __( 'What the deduction came to, in the booking currency.', 'wp-travel-engine' ),
								'type'        => 'number',
							),
							'is_manual'   => array(
								'description' => __( 'Whether the terms were typed by hand rather than taken from a coupon. Sent back on a write so a row read from here is not mistaken for the coupon its name matches.', 'wp-travel-engine' ),
								'type'        => 'boolean',
							),
						),
					),
				),
				'billing'            => array(
					'description'          => __( 'Billing details for whoever paid. `fname`, `lname` and `email` are always collected; every other key is a billing checkout field the site has enabled, extendable through `wp_travel_engine_booking_fields_display`, so custom fields appear here under their own keys. A file field holds its files as `{ id, name, mime, url }` objects when one booking is read, and its attachment IDs in a list.', 'wp-travel-engine' ),
					'type'                 => 'object',
					'context'              => $both,
					'required'             => array( 'fname', 'lname', 'email' ),
					'additionalProperties' => true,
					'properties'           => array(
						'fname' => array(
							'description' => __( 'First name. Always collected.', 'wp-travel-engine' ),
							'type'        => 'string',
						),
						'lname' => array(
							'description' => __( 'Last name. Always collected.', 'wp-travel-engine' ),
							'type'        => 'string',
						),
						'email' => array(
							'description' => __( 'Email address the booking confirmation is sent to. Empty on an abandoned booking that never reached the billing step, so this is not validated as an email on read.', 'wp-travel-engine' ),
							'type'        => 'string',
						),
					),
				),
				'emergency_contacts' => array(
					'description'          => __( 'Emergency contact for the booking. Keys are not fixed: they are the emergency-contact checkout fields the site collects, extendable through `wp_travel_engine_emergency_contact_fields_display`. Commonly present are `fname`, `lname`, `phone`, `country` and `relation`, but none of them is guaranteed. A file field holds its files as `{ id, name, mime, url }` objects when one booking is read, and its attachment IDs in a list.', 'wp-travel-engine' ),
					'type'                 => 'object',
					'context'              => $both,
					'additionalProperties' => true,
					'properties'           => array(),
				),
				'payments'           => array(
					'description' => __( 'Payment records against this booking, oldest first. A deposit booking has several.', 'wp-travel-engine' ),
					'type'        => 'array',
					'context'     => $both,
					'readonly'    => true,
					'items'       => array(
						'type'       => 'object',
						'properties' => array(
							'id'                 => array(
								'description' => __( 'Payment post ID.', 'wp-travel-engine' ),
								'type'        => 'integer',
							),
							'label'              => array(
								'description' => __( 'Gateway name for display, e.g. `PayPal`.', 'wp-travel-engine' ),
								'type'        => 'string',
							),
							'gateway'            => array(
								'description' => __( 'Gateway slug the payment was taken through, e.g. `paypal`, `stripe`, `bank_transfer`.', 'wp-travel-engine' ),
								'type'        => 'string',
							),
							'date'               => array(
								'description' => __( 'Transaction date, as `Y-m-d H:i`. Set by the gateway or the operator; a payment carrying none reports the date its record was opened, so this is never empty.', 'wp-travel-engine' ),
								'type'        => 'string',
							),
							'source'             => array(
								'description' => __( 'Where the payment came from: `checkout` for one the customer made, `admin` for one recorded in wp-admin.', 'wp-travel-engine' ),
								'type'        => 'string',
								'enum'        => array( 'checkout', 'admin' ),
							),
							'is_payment_request' => array(
								'description' => __( 'Whether the record is a request the customer has yet to settle rather than money already taken. Stays true once it is paid - what became of the request is `status`.', 'wp-travel-engine' ),
								'type'        => 'boolean',
							),
							'payment_link'       => array(
								'description' => __( 'URL the customer settles this payment through. Empty unless `is_payment_request` is true, once the payment is completed, and on a payment whose booking cannot be resolved.', 'wp-travel-engine' ),
								'type'        => 'string',
							),
							'status'             => array(
								'description' => __( 'Raw payment status slug as the gateway reported it, e.g. `completed`, `capture`, `voucher-awaiting`. Deliberately unconstrained: the vocabulary is merged from four status-group helpers and extended through `wp_travel_engine_payment_status_options`, so gateways and add-ons add to it. Kept for fidelity and reconciliation — several slugs collapse onto one label, so the raw value cannot be recovered from a label. Use `status_type` to display it.', 'wp-travel-engine' ),
								'type'        => 'string',
							),
							'transaction_id'     => array(
								'description' => __( 'Gateway transaction reference, for reconciliation.', 'wp-travel-engine' ),
								'type'        => 'string',
							),
							'currency'           => array(
								'description' => __( 'Currency the payment was taken in. May differ from the booking currency when the gateway settled in another.', 'wp-travel-engine' ),
								'type'        => 'string',
							),
							'paid_amount'        => array(
								'description' => __( 'Amount this payment settled.', 'wp-travel-engine' ),
								'type'        => 'number',
							),
							'deposit_type'       => array(
								'description' => __( 'Whether `deposit_value` is a share of the balance due or a set amount. Empty on a payment recorded before this was kept.', 'wp-travel-engine' ),
								'type'        => 'string',
							),
							'deposit_value'      => array(
								'description' => __( 'The figure the deposit was worked out from, as it was entered.', 'wp-travel-engine' ),
								'type'        => 'string',
							),
							'payable_amount'     => array(
								'description' => __( 'Amount this payment is for, before the fees it carries.', 'wp-travel-engine' ),
								'type'        => 'number',
							),
							'refunded'           => array(
								'description' => __( 'Amount refunded from this payment.', 'wp-travel-engine' ),
								'type'        => 'number',
							),
							'remark'             => array(
								'description' => __( 'Operator note recorded against this payment. Never shown to the customer.', 'wp-travel-engine' ),
								'type'        => 'string',
							),
							'breakdown'          => array(
								'description'          => __( 'What `paid_amount` was made up of, keyed by the name the cart charged each fee under - so an add-on fee appears beside `booking_fee` and `tax` without being named here. `deposit` is derived, the amount less every fee, so the parts always sum back to `paid_amount`. A payment taken before the split was recorded reports zero for each fee and the whole amount as `deposit`.', 'wp-travel-engine' ),
								'type'                 => 'object',
								// A fee an add-on registered is keyed by its own name, which cannot be declared here.
								'additionalProperties' => array( 'type' => 'number' ),
								'properties'           => array(
									'deposit'     => array(
										'description' => __( 'What the payment covered before fee and tax.', 'wp-travel-engine' ),
										'type'        => 'number',
										'readonly'    => true,
									),
									'booking_fee' => array(
										'description' => __( 'Booking fee this payment carried.', 'wp-travel-engine' ),
										'type'        => 'number',
									),
									'tax'         => array(
										'description' => __( 'Tax this payment carried. Distinct from the tax on the whole booking, which a booking settled in several payments splits across them.', 'wp-travel-engine' ),
										'type'        => 'number',
									),
								),
							),
						),
					),
				),
				'notes'              => array(
					'description' => __( 'Notes attached to the booking: the note the customer left at checkout, and any notes staff have added since. `type` and `content` are writable; `date` and `author` are stamped by the server.', 'wp-travel-engine' ),
					'type'        => 'array',
					'context'     => $both,
					'items'       => array(
						'type'       => 'object',
						'properties' => array(
							'id'      => array(
								'description' => __( 'Identifier for the note, unique within the booking. `customer` for the checkout note, which is one per booking; an internal note carries its own.', 'wp-travel-engine' ),
								'type'        => 'string',
							),
							'by'      => array(
								'description' => __( 'Who the note came from. A customer note is visible to the customer; an admin note is internal.', 'wp-travel-engine' ),
								'type'        => 'string',
								'enum'        => array( 'customer', 'admin' ),
							),
							'content' => array(
								'description' => __( 'Note text.', 'wp-travel-engine' ),
								'type'        => 'string',
							),
							'date'    => $prop( __( 'When the note was added, as `Y-m-d H:i:s`.', 'wp-travel-engine' ), 'string', true ),
							'author'  => $prop( __( 'Display name of whoever added the note. Empty for a customer note.', 'wp-travel-engine' ), 'string', true ),
						),
					),
				),
				'activities'         => array(
					'description' => __( 'What has happened to the booking, newest first. Core records the edits made through this endpoint; `wptravelengine_booking_activities` may add to that list or replace it.', 'wp-travel-engine' ),
					'type'        => 'array',
					'context'     => $both,
					'readonly'    => true,
					'items'       => array(
						'type'                 => 'object',
						'additionalProperties' => true,
						'properties'           => array(
							'id'       => array(
								'description' => __( 'Identifier for the entry, unique within the booking.', 'wp-travel-engine' ),
								'type'        => array( 'string', 'integer' ),
							),
							'type'     => array(
								'description' => __( 'What kind of event it was, which decides the icon the client draws: `payment`, `refund`, `document`, `document_shared`, `traveler`, `addon` or `message`. Anything else renders as a plain entry, so an add-on may report its own.', 'wp-travel-engine' ),
								'type'        => 'string',
							),
							'category' => array(
								'description' => __( 'Which filter the entry falls under. Core files one under `payments`, `bookings` for the status and the date it was placed, `discounts`, `travelers` for billing, contacts and traveler details, `addons` for priced rows other than a traveler category, or `misc`. Not a closed set: an add-on may file its own, and anything the client does not know a filter for reads as `misc`.', 'wp-travel-engine' ),
								'type'        => 'string',
							),
							'actor'    => array(
								'description' => __( 'Who the entry is attributed to, which decides the badge.', 'wp-travel-engine' ),
								'type'        => 'string',
								'enum'        => array( 'admin', 'traveler' ),
							),
							'who'      => array(
								'description' => __( 'Display name for whoever acted, e.g. a traveler name, `You`, `System`.', 'wp-travel-engine' ),
								'type'        => 'string',
							),
							'date'     => array(
								'description' => __( 'When it happened, as `Y-m-d H:i:s`.', 'wp-travel-engine' ),
								'type'        => 'string',
							),
							'text'     => array(
								'description' => __( 'What happened, in a sentence, already translated.', 'wp-travel-engine' ),
								'type'        => 'string',
							),
						),
					),
				),
				'traveler_requests'  => array(
					'description' => __( 'Changes the traveler has asked for — a date move, a cancellation — newest first. Core stores none of its own: the list is whatever `wptravelengine_booking_traveler_requests` supplies, so it is empty unless an add-on fills it. Nothing here is applied automatically; each entry is a note for staff to act on.', 'wp-travel-engine' ),
					'type'        => 'array',
					'context'     => $both,
					'readonly'    => true,
					'items'       => array(
						'type'                 => 'object',
						'additionalProperties' => true,
						'properties'           => array(
							'id'         => array(
								'description' => __( 'Identifier for the request, unique within the booking.', 'wp-travel-engine' ),
								'type'        => array( 'string', 'integer' ),
							),
							'type'       => array(
								'description' => __( 'What is being asked for. `date_change` and `cancellation` are what core names; an add-on may report others, so treat an unknown type as a plain request.', 'wp-travel-engine' ),
								'type'        => 'string',
							),
							'title'      => array(
								'description' => __( 'One-line heading for the request, already translated.', 'wp-travel-engine' ),
								'type'        => 'string',
							),
							'date'       => array(
								'description' => __( 'When the traveler submitted it, as `Y-m-d H:i:s`.', 'wp-travel-engine' ),
								'type'        => 'string',
							),
							'meta'       => array(
								'description' => __( 'Extra context shown beside the date, e.g. `130 days before departure`. Empty when there is none.', 'wp-travel-engine' ),
								'type'        => 'string',
							),
							'summary'    => array(
								'description' => __( 'What the request amounts to, in a sentence.', 'wp-travel-engine' ),
								'type'        => 'string',
							),
							'notes'      => array(
								'description' => __( 'What the traveler wrote, verbatim. Empty when they left no note.', 'wp-travel-engine' ),
								'type'        => 'string',
							),
							'admin_note' => array(
								'description' => __( 'Guidance for staff on what handling this request involves.', 'wp-travel-engine' ),
								'type'        => 'string',
							),
							'status'     => array(
								'description' => __( 'Whether staff have dealt with it yet.', 'wp-travel-engine' ),
								'type'        => 'string',
								'enum'        => array( 'pending', 'handled' ),
							),
						),
					),
				),
				'customer'           => array(
					'description' => __( 'Customer the booking belongs to. Bookings are grouped under a customer record keyed by billing email.', 'wp-travel-engine' ),
					'type'        => 'object',
					'context'     => $view,
					'readonly'    => true,
					'properties'  => array(
						'id'     => array(
							'description' => __( 'Customer post ID.', 'wp-travel-engine' ),
							'type'        => 'integer',
						),
						'name'   => array(
							'description' => __( 'Customer display name.', 'wp-travel-engine' ),
							'type'        => 'string',
						),
						'email'  => array(
							'description' => __( 'Customer email address, which is what identifies the record. Empty when the booking carries no billing email to resolve a customer from.', 'wp-travel-engine' ),
							'type'        => 'string',
						),
						'avatar' => array(
							'description' => __( 'Gravatar URL for the customer email, at 112px. Empty when the booking carries no email or the site serves no avatars, in which case the customer is drawn from their initials instead.', 'wp-travel-engine' ),
							'type'        => 'string',
						),
					),
				),
				'payment_link'       => $prop(
					__( 'Checkout link for whatever the booking still owes. Empty when nothing is due.', 'wp-travel-engine' ),
					'string',
					true,
					null,
					array( 'format' => 'uri' )
				),
				'is_manual'          => $prop(
					__( 'Whether an operator created the booking by hand. Any payment on a manual booking can be deleted.', 'wp-travel-engine' ),
					'boolean',
					true
				),
				'original_booking'   => $prop(
					__( 'Admin edit link for the deprecated booking this one was migrated from. Empty unless it was migrated and the original still exists.', 'wp-travel-engine' ),
					'string',
					true,
					null,
					array( 'format' => 'uri' )
				),
				'wc_order_edit_link' => $prop(
					__( 'Admin edit link for the WooCommerce order this booking was paid through. Empty unless the WooCommerce payments add-on took the payment, in which case the amounts recorded here may not include tax, fees or partial payments WooCommerce applied.', 'wp-travel-engine' ),
					'string',
					true,
					null,
					array( 'format' => 'uri' )
				),
				'state_badges'       => array(
					'description' => __( 'Badges marking how the booking came about or has changed since, e.g. `Manual` or `Modified`, in display order. The same list the Bookings list shows; add-ons append through `wptravelengine_booking_state_badges`.', 'wp-travel-engine' ),
					'type'        => 'array',
					'context'     => $both,
					'readonly'    => true,
					'items'       => array(
						'type'       => 'object',
						'properties' => array(
							'modifier' => array(
								'description' => __( 'Class added beside `wpte-tag`, which sets the colour, e.g. `warning`. Empty for the neutral tag.', 'wp-travel-engine' ),
								'type'        => 'string',
							),
							'icon'     => array(
								'description' => __( 'Inline SVG markup shown before the label. Empty when the badge has no icon.', 'wp-travel-engine' ),
								'type'        => 'string',
							),
							'label'    => array(
								'description' => __( 'Translated badge text.', 'wp-travel-engine' ),
								'type'        => 'string',
							),
						),
					),
				),
			),
		);

		return $this->add_additional_fields_schema( $this->schema );
	}
}
