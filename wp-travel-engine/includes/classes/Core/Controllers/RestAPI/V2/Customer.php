<?php
/**
 * WP Travel Engine Customer Controller class.
 *
 * @package WPTravelEngine
 * @since 6.9.0
 */

namespace WPTravelEngine\Core\Controllers\RestAPI\V2;

use WP_Error;
use WP_Post;
use WP_Query;
use WP_REST_Controller;
use WP_REST_Request;
use WP_REST_Server;
use WPTravelEngine\Core\Models\Post\Customer as CustomerModel;

/**
 * Reads the customer records an administrator can start a booking against.
 *
 * @since 6.9.0
 */
class Customer extends WP_REST_Controller {

	/**
	 * Shortest search term the collection will run a search for.
	 *
	 * A single character matches most of the table, which is neither useful to the operator
	 * nor cheap to run, so below this the collection lists customers instead of searching.
	 */
	protected const MIN_SEARCH_LENGTH = 2;

	/**
	 * Constructor.
	 *
	 * @since 6.9.0
	 */
	public function __construct() {
		$this->namespace = 'wptravelengine/v2';
		$this->rest_base = 'customers';
	}

	/**
	 * Registers the customer collection route.
	 *
	 * @return void
	 * @since 6.9.0
	 */
	public function register_routes(): void {
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
				'schema' => array( $this, 'get_public_item_schema' ),
			)
		);
	}

	/**
	 * Only staff who may edit bookings can read the customer list.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return true|WP_Error
	 * @since 6.9.0
	 */
	public function get_items_permissions_check( $request ) {
		$post_type = get_post_type_object( 'booking' );

		if ( ! $post_type || ! current_user_can( $post_type->cap->edit_posts ) ) {
			return new WP_Error(
				'wptravelengine_rest_cannot_view',
				__( 'Sorry, you are not allowed to view customers.', 'wp-travel-engine' ),
				array( 'status' => rest_authorization_required_code() )
			);
		}

		return true;
	}

	/**
	 * Customers, either the whole list or the ones matching the search term.
	 *
	 * Without a usable search term the collection browses: the most recent customers, so a
	 * picker that filters client side has something to open with.
	 *
	 * A search takes three passes, each stopping early once the page is full: the exact email
	 * the model can resolve, then the post title (which is the email), then the name, which
	 * lives inside serialized meta that a title search never reads.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 * @since 6.9.0
	 */
	public function get_items( $request ) {
		$search   = trim( (string) $request->get_param( 'search' ) );
		$per_page = (int) $request->get_param( 'per_page' );
		$items    = array();

		if ( mb_strlen( $search ) < self::MIN_SEARCH_LENGTH ) {
			$this->collect(
				array(
					'posts_per_page' => $per_page,
					'orderby'        => 'date',
					'order'          => 'DESC',
				),
				'',
				$per_page,
				$items,
				$request
			);

			return rest_ensure_response( array_values( $items ) );
		}

		$exact_id = false !== strpos( $search, '@' ) ? CustomerModel::is_exists( $search ) : false;

		$passes = array(
			$exact_id ? array( 'p' => $exact_id ) : null,
			array( 's' => $search ),
			array(
				'meta_query' => array(
					array(
						'key'     => 'wp_travel_engine_booking_setting',
						'value'   => $search,
						'compare' => 'LIKE',
					),
				),
			),
		);

		foreach ( array_filter( $passes ) as $args ) {
			$this->collect( $args, $search, $per_page, $items, $request );
		}

		return rest_ensure_response( array_values( $items ) );
	}

	/**
	 * Runs one pass and adds the customers that really match, keyed by post ID.
	 *
	 * Every pass reaches wider than the name and email the response carries — `s` also
	 * searches post content, and the meta clause matches the serialized record as one blob,
	 * so an address can hit — which is why each candidate is confirmed before it is kept.
	 *
	 * @param array<string, mixed>             $args     Query arguments for this pass.
	 * @param string                           $search   Search term; empty keeps every candidate.
	 * @param int                              $per_page Response cap.
	 * @param array<int, array<string, mixed>> $items Response being built, by reference.
	 * @param WP_REST_Request                  $request  Request.
	 * @return void
	 * @since 6.9.0
	 */
	protected function collect( array $args, string $search, int $per_page, array &$items, $request ): void {
		if ( count( $items ) >= $per_page ) {
			return;
		}

		$query = new WP_Query(
			array_merge(
				array(
					'post_type'              => 'customer',
					'post_status'            => 'publish',
					'posts_per_page'         => min( $per_page * 5, 100 ),
					'orderby'                => 'title',
					'order'                  => 'ASC',
					'no_found_rows'          => true,
					'update_post_term_cache' => false,
				),
				$args
			)
		);

		$needle = mb_strtolower( $search );

		foreach ( $query->posts as $post ) {
			if ( count( $items ) >= $per_page ) {
				return;
			}

			if ( isset( $items[ $post->ID ] ) ) {
				continue;
			}

			$item = $this->prepare_item_for_response( $post, $request );

			if (
				'' === $needle
				|| false !== mb_strpos( mb_strtolower( $item['name'] ), $needle )
				|| false !== mb_strpos( mb_strtolower( $item['email'] ), $needle )
			) {
				$items[ $post->ID ] = $item;
			}
		}
	}

	/**
	 * Shapes one customer for the response.
	 *
	 * A customer record holds its details in one of two shapes: `place_order.booking` when
	 * checkout wrote it, or the lead entry of `wptravelengine_traveller_details` when it came
	 * from elsewhere. The model's getters only read the first, so a customer stored the
	 * second way would come back nameless and drop out of a name search.
	 *
	 * @param WP_Post         $item    Customer post.
	 * @param WP_REST_Request $request Request.
	 * @return array<string, mixed>
	 * @since 6.9.0
	 */
	public function prepare_item_for_response( $item, $request ) {
		$customer = new CustomerModel( $item->ID );
		$name     = trim( $customer->get_customer_fname() . ' ' . $customer->get_customer_lname() );
		$email    = $customer->get_customer_email();
		$lead     = $customer->get_customer_meta()['wptravelengine_traveller_details'][0] ?? array();

		if ( '' === $name ) {
			$name = trim( ( $lead['fname'] ?? '' ) . ' ' . ( $lead['lname'] ?? '' ) );
		}

		return array(
			'id'    => (int) $item->ID,
			'name'  => $name,
			// The post title is the last resort: `BookingProcess` titles a customer by email.
			'email' => (string) ( '' !== $email ? $email : ( $lead['email'] ?? $item->post_title ) ),
		);
	}

	/**
	 * Query parameters the collection accepts.
	 *
	 * @return array<string, mixed>
	 * @since 6.9.0
	 */
	public function get_collection_params() {
		return array(
			'search'   => array(
				'description' => __( 'Limit results to customers matching a name or email address.', 'wp-travel-engine' ),
				'type'        => 'string',
				'default'     => '',
			),
			'per_page' => array(
				'description' => __( 'Maximum number of customers to return.', 'wp-travel-engine' ),
				'type'        => 'integer',
				'default'     => 10,
				'minimum'     => 1,
				'maximum'     => 100,
			),
		);
	}

	/**
	 * Schema for one customer.
	 *
	 * @return array<string, mixed>
	 * @since 6.9.0
	 */
	public function get_item_schema() {
		return array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'customer',
			'type'       => 'object',
			'properties' => array(
				'id'    => array(
					'description' => __( 'Customer post ID.', 'wp-travel-engine' ),
					'type'        => 'integer',
					'readonly'    => true,
				),
				'name'  => array(
					'description' => __( 'Customer name.', 'wp-travel-engine' ),
					'type'        => 'string',
					'readonly'    => true,
				),
				'email' => array(
					'description' => __( 'Customer email address.', 'wp-travel-engine' ),
					'type'        => 'string',
					'readonly'    => true,
				),
			),
		);
	}
}
