<?php
/**
 * Post Type Booking.
 *
 * @package WPTravelEngine/Core/PostTypes
 * @since 6.0.0
 */

namespace WPTravelEngine\Core\PostTypes;

use WPTravelEngine\Helpers\Asset;
use WPTravelEngine\Helpers\Functions;
use WPTravelEngine\Helpers\BookedItem;
use WPTravelEngine\Abstracts\PostType;
use WPTravelEngine\Core\Booking\ListBooking;
use WPTravelEngine\Core\Booking\ViewBooking;
use WPTravelEngine\Integrations\SliceWP\Commissions;
use WPTravelEngine\Core\Models\Post\Booking as BookingModel;

/**
 * Class Booking
 * This class represents a trip booking to the WP Travel Engine plugin.
 *
 * @since 6.8.0
 * @since 6.9.0 Uses the ListBooking trait, which holds the list screen.
 */
class Booking extends PostType {

	use ListBooking;

	/**
	 * Post type slug.
	 *
	 * @var string
	 * @since 6.9.0
	 */
	public const POST_TYPE = 'booking';

	/**
	 * Post type name.
	 *
	 * @var string
	 */
	protected string $post_type = 'booking';

	/**
	 * SVG icon for the "migrated" booking state badge (double-arrow exchange).
	 *
	 * @since 6.8.0
	 */
	public const BADGE_ICON_MIGRATED = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M3 7h14M13 3l4 4-4 4M17 13H3M7 9l-4 4 4 4"/></svg>';

	/**
	 * SVG icon for the "deprecated" booking state badge (clock).
	 *
	 * @since 6.8.0
	 */
	public const BADGE_ICON_DEPRECATED = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><circle cx="10" cy="10" r="8"/><path d="M10 6v4l2.5 2.5"/></svg>';

	/**
	 * SVG icon for the "modified" booking state badge (pencil).
	 *
	 * @since 6.8.0
	 */
	public const BADGE_ICON_MODIFIED = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M14.5 2.5a2.121 2.121 0 013 3L6 17l-4 1 1-4 11.5-11.5z"/></svg>';

	/**
	 * SVG icon for the "manual" booking state badge (person silhouette).
	 *
	 * @since 6.8.0
	 */
	public const BADGE_ICON_MANUAL = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><circle cx="10" cy="6" r="3"/><path d="M4 18v-1a6 6 0 0112 0v1"/></svg>';

	/**
	 * Constructor.
	 *
	 * @since 6.4.0
	 * @since 6.9.0 List screen hooks moved to ListBooking::register_list_hooks().
	 */
	public function __construct() {
		$this->register_list_hooks();

		add_action( "add_meta_boxes_{$this->post_type}", array( $this, 'meta_box_booking' ) );
		add_filter( 'wptravelengine_booking_line_item_group_title', array( $this, 'add_booking_line_item_title' ), 10, 2 );
		add_filter( 'wptravelengine_booking_line_items', array( $this, 'add_booking_line_items' ), 10, 2 );
		add_filter( 'wp_travel_engine_traveller_info_fields_display', array( $this, 'add_pricing_category_field_to_traveller' ), 10, 1 );
		add_filter( 'wp_travel_engine_lead_traveller_info_fields_display', array( $this, 'add_pricing_category_field_to_lead_traveller' ), 10, 1 );
		add_filter( 'wptravelengine_form_field_options', array( $this, 'add_none_option_to_select_options' ), 10, 1 );
		add_action( 'load-post-new.php', array( $this, 'redirect_new_booking' ) );
		add_action( 'admin_menu', array( $this, 'modify_submenu' ), 20 );

		\WP_Travel_Engine_Booking_Export::register_hooks();
		Commissions::register_hooks();
		ViewBooking::register_hooks();

		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
	}

	/**
	 * Adds the legacy Booking Details box.
	 *
	 * @param \WP_Post $post Booking being edited.
	 * @return void
	 * @since 6.4.0
	 * @since 6.9.0 Only for a booking the React app does not take over, e.g. a pre-4.0 cart.
	 */
	public function meta_box_booking( \WP_Post $post ): void {
		/** @var ViewBooking $booking_view */
		$booking_view = ViewBooking::instance( (int) $post->ID );

		if ( $booking_view->should_render_app() ) {
			return;
		}

		add_meta_box(
			'booking_details_id',
			__( 'Booking Details', 'wp-travel-engine' ),
			array( $booking_view, 'process_markup' ),
			$this->post_type,
			'normal',
			'high'
		);
	}

	/**
	 * Add booking line item title.
	 *
	 * @param string $title Title.
	 * @param array  $item  Item.
	 *
	 * @return string
	 * @since 6.7.0
	 */
	public function add_booking_line_item_title( string $title, array $item ): string {
		if ( 'pricing_category' === $title ) {
			$title = __( 'Traveler(s)', 'wp-travel-engine' );
		}
		if ( 'extra_service' === $title ) {
			$title = wptravelengine_settings()->get( 'extra_service_title' ) ?: __( 'Extra Services', 'wp-travel-engine' );
		}

		return $title;
	}

	/**
	 * Add booking line items.
	 *
	 * @param array      $line_items Line items.
	 * @param BookedItem $item       Item.
	 *
	 * @return array
	 * @since 6.4.0
	 */
	public function add_booking_line_items( array $line_items, BookedItem $item ): array {
		$line_items['pricing_category'] ??= array();
		if ( wptravelengine_is_addon_active( 'extra-services' ) ) {
			$line_items['extra_service'] ??= array();
		}
		if ( wptravelengine_is_addon_active( 'travel-insurance' ) ) {
			$line_items['travel_insurance'] ??= array();
		}

		return $line_items;
	}

	/**
	 * Retrieve the labels for the Booking post type.
	 *
	 * Returns an array containing the labels used for the Booking post type, including
	 * names for various elements such as the post type itself, singular and plural names,
	 * menu labels, and more.
	 *
	 * @return array An array containing the labels for the Booking post type.
	 * @since 6.9.0 The add_new_item label reads "Add New".
	 */
	public function get_labels(): array {
		return array(
			'name'               => _x( 'Bookings', 'post type general name', 'wp-travel-engine' ),
			'singular_name'      => _x( 'Booking', 'post type singular name', 'wp-travel-engine' ),
			'menu_name'          => _x( 'WP Travel Engine', 'admin menu', 'wp-travel-engine' ),
			'name_admin_bar'     => _x( 'Booking', 'add new on admin bar', 'wp-travel-engine' ),
			'add_new'            => _x( 'Add New', 'Booking', 'wp-travel-engine' ),
			'add_new_item'       => esc_html__( 'Add New', 'wp-travel-engine' ),
			'new_item'           => esc_html__( 'New Booking', 'wp-travel-engine' ),
			'edit_item'          => esc_html__( 'Edit Booking', 'wp-travel-engine' ),
			'view_item'          => esc_html__( 'View Booking', 'wp-travel-engine' ),
			'all_items'          => esc_html__( 'Bookings', 'wp-travel-engine' ),
			'search_items'       => esc_html__( 'Search Bookings', 'wp-travel-engine' ),
			'parent_item_colon'  => esc_html__( 'Parent Bookings:', 'wp-travel-engine' ),
			'not_found'          => esc_html__( 'No Bookings found.', 'wp-travel-engine' ),
			'not_found_in_trash' => esc_html__( 'No Bookings found in Trash.', 'wp-travel-engine' ),
		);
	}

	/**
	 * Retrieve the post type name.
	 *
	 * Returns the name of the post type.
	 *
	 * @return string The name of the post type.
	 */
	public function get_post_type(): string {
		return $this->post_type;
	}

	/**
	 * Retrieve the icon for the Booking post type.
	 *
	 * Returns the icon for the Booking post type.
	 *
	 * @return string The icon for the Booking post type.
	 */
	public function get_icon(): string {
		return 'data:image/svg+xml;base64,' . base64_encode( '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><g clip-path="url(#clip0_60_548)"><path d="M22.8963 12.1856C23.1956 11.7415 22.7501 11.3673 22.7501 11.3673C22.7501 11.3673 22.2301 11.1051 21.9322 11.5491C21.633 11.9932 20.8789 13.1159 20.8789 13.1159L17.8029 13.1871L17.287 13.954L19.8988 14.572L18.7272 15.9741C19.0916 16.1151 19.4014 16.3747 19.7525 16.5486L20.863 15.2085L22.4442 17.359L22.9602 16.5921L21.8418 13.7524C21.8431 13.7524 22.5984 12.6297 22.8963 12.1856Z" fill="white"></path><path d="M11.9222 11.5544C12.8513 11.5544 13.6045 10.8081 13.6045 9.88745C13.6045 8.96683 12.8513 8.22052 11.9222 8.22052C10.9931 8.22052 10.2399 8.96683 10.2399 9.88745C10.2399 10.8081 10.9931 11.5544 11.9222 11.5544Z" fill="white"></path><path d="M21.2379 13.4954C20.9587 13.3215 20.589 13.4045 20.4134 13.6825C18.7032 16.3733 16.9172 17.8439 15.2482 17.9335C13.1351 18.0495 11.744 16.011 10.5299 14.6498C9.8862 13.9276 9.30105 13.1568 8.79038 12.3371C8.3861 11.6901 7.93927 10.9166 7.93927 10.1339C7.93794 7.95699 9.72528 6.18596 11.9222 6.18596C14.1178 6.18596 15.9052 7.95699 15.9052 10.1339C15.9052 11.4371 14.3226 13.5244 12.9635 15.0477C12.7494 15.2875 12.7733 15.6525 13.0114 15.87C13.0154 15.8726 13.018 15.8766 13.022 15.8792C13.2641 16.1006 13.6444 16.0795 13.8625 15.8357C15.2668 14.2716 17.1034 11.8904 17.1034 10.1326C17.1021 7.30208 14.7788 5 11.9222 5C9.06567 5 6.74106 7.30208 6.74106 10.1339C6.74106 11.7876 8.36749 13.9935 9.73326 15.555L9.72927 15.5511C10.091 15.8897 10.4022 16.2996 10.744 16.6593C11.4076 17.3551 12.0858 18.0969 12.9382 18.5634C12.9396 18.5647 12.9422 18.5647 12.9475 18.5687C13.5181 18.877 14.2375 19.1235 15.0807 19.1235C15.1511 19.1235 15.223 19.1221 15.2961 19.1182C17.4039 19.0141 19.4666 17.3972 21.4255 14.3137C21.6023 14.037 21.5172 13.6707 21.2379 13.4954Z" fill="white"></path><path d="M10.6349 17.7979C10.4607 17.6345 10.2054 17.5937 9.98463 17.6859C9.58567 17.852 9.11889 17.9626 8.59625 17.9337C6.92727 17.844 5.14126 16.3735 3.4377 13.6919L2.11049 11.5137C1.94027 11.233 1.57189 11.1434 1.28996 11.312C1.0067 11.482 0.914938 11.8457 1.08649 12.1264L2.41902 14.3138C4.37791 17.3973 6.44054 19.0142 8.54838 19.1183C8.62152 19.1222 8.69333 19.1236 8.76381 19.1236C9.40082 19.1236 9.96867 18.9826 10.4541 18.7796C10.8544 18.6123 10.9528 18.0957 10.6376 17.7992L10.6349 17.7979Z" fill="white"></path></g></svg>' ); // phpcs:ignore WordPress.WP.EnsuredPHPCS.Base64Encode.FileWithoutSafety
	}

	/**
	 * Retrieve the arguments for the Booking post type.
	 *
	 * Returns an array containing the arguments used to register the Booing post type.
	 *
	 * @return array An array containing the arguments for the Booking post type.
	 * @since 6.8.7 Give Booking its own capability_type (`booking`/`bookings`) instead of sharing Trip's capabilities.
	 */
	public function get_args(): array {
		return array(
			'labels'             => $this->get_labels(),
			'description'        => esc_html__( 'Description.', 'wp-travel-engine' ),
			'public'             => false,
			'publicly_queryable' => false,
			'show_ui'            => true,
			'menu_icon'          => $this->get_icon(),
			'query_var'          => true,
			'rewrite'            => array( 'slug' => 'booking' ),
			'capability_type'    => array( 'booking', 'bookings' ),
			'map_meta_cap'       => true, // Set to `false`, if users are not allowed to edit/delete existing posts
			'has_archive'        => true,
			'hierarchical'       => false,
			'menu_position'      => 31,
			'supports'           => array( '' ),
		);
	}

	/**
	 * Get capabilities.
	 *
	 * @return array
	 * @since 6.4.0
	 * @since 6.8.7 deprecated, replaced by `get_capabilities()` in `WPTravelEngine\Core\PostTypes\Trip` class.
	 */
	public function get_capabilities(): array {
		return array(
			'edit_post'          => 'edit_trip',
			'read_post'          => 'read_trip',
			'delete_post'        => 'delete_trip',
			'edit_posts'         => 'edit_trips',
			'edit_others_posts'  => 'edit_others_trips',
			'publish_posts'      => 'publish_trips',
			'read_private_posts' => 'read_private_trips',
		);
	}

	/**
	 * Add "None" as first option for select fields on backend (same as frontend FormField filter).
	 *
	 * @param array $options Select field options.
	 * @return array Modified options.
	 * @since 6.7.6
	 */
	public function add_none_option_to_select_options( $options ): array {
		if ( ! is_array( $options ) ) {
			return $options;
		}

		if ( ! is_admin() || get_post_type() !== 'booking' ) {
			return $options;
		}

		return Functions::add_none_option_to_select( $options, true );
	}

	/**
	 * Add pricing category field to traveller info fields.
	 *
	 * @param array $fields Traveller info fields.
	 * @return array Modified fields array.
	 * @since 6.7.0
	 */
	public function add_pricing_category_field_to_traveller( $fields ) {
		return $this->add_pricing_category_field( $fields, 'traveller_pricing_category' );
	}

	/**
	 * Add pricing category field to lead traveller info fields.
	 *
	 * @param array $fields Lead traveller info fields.
	 * @return array Modified fields array.
	 * @since 6.7.0
	 */
	public function add_pricing_category_field_to_lead_traveller( $fields ) {
		return $this->add_pricing_category_field( $fields, 'pricing_category' );
	}

	/**
	 * Add pricing category field to fields array.
	 *
	 * @param array  $fields    Fields array.
	 * @param string $field_key Key to use for the field.
	 * @return array Modified fields array.
	 * @since 6.7.0
	 * @since 6.9.0 Sorts first wherever fields are ordered by priority.
	 */
	private function add_pricing_category_field( $fields, $field_key ) {
		// Only process on booking post type admin page.
		if ( get_post_type() !== 'booking' ) {
			return $fields;
		}
		global $post;

		$post_id = isset( $post->ID ) ? $post->ID : null;
		if ( $post_id ) {
			global $post;
			$booking      = BookingModel::for( $post_id, $post );
			$is_curr_cart = $booking->is_curr_cart();
			if ( ! $is_curr_cart ) {
				return $fields;
			}
		}

		// Ensure $fields is an array.
		if ( ! is_array( $fields ) ) {
			$fields = array();
		}

		// Return early if field already exists.
		if ( isset( $fields[ $field_key ] ) ) {
			return $fields;
		}

		// Get pricing categories.
		$pricing_categories = get_terms(
			array(
				'taxonomy'   => 'trip-packages-categories',
				'hide_empty' => false,
				'orderby'    => 'term_id',
				'fields'     => 'id=>name',
			)
		);

		// Handle get_terms error.
		if ( is_wp_error( $pricing_categories ) ) {
			$pricing_categories = array();
		}

		/**
		 * Sentinel to skip adding None option to the pricing category field.
		 */
		$pricing_categories['__skip_none_option__'] = true;
		// Create the pricing category field.
		$pricing_category_field = array(
			'type'          => 'select',
			'wrapper_class' => 'row-repeater',
			'field_label'   => __( 'Price Category', 'wp-travel-engine' ),
			'name'          => 'travelers[pricing_category]',
			'id'            => 'pricing_category',
			'class'         => 'input',
			'default_field' => true,
			'options'       => $pricing_categories,
			'priority'      => 1,
		);

		// Add the field at the beginning of the array.
		$fields = array( $field_key => $pricing_category_field ) + $fields;

		return $fields;
	}

	/**
	 * Tags the "Bookings" list item for `Capabilities::hide_submenu()` to target with CSS,
	 * and removes "Add New" outright — manual creation goes through
	 * `redirect_new_booking()`'s auto-draft flow, not this form.
	 *
	 * @return void
	 * @since 6.8.7
	 */
	public function modify_submenu(): void {
		global $submenu;

		$parent = "edit.php?post_type={$this->post_type}";

		if ( empty( $submenu[ $parent ] ) ) {
			return;
		}

		foreach ( $submenu[ $parent ] as &$item ) {
			if ( $parent === $item[2] ) {
				$item[4] = trim( ( $item[4] ?? '' ) . ' wpte-submenu-bookings' );
			}
		}

		remove_submenu_page( $parent, "post-new.php?post_type={$this->post_type}" );
	}

	/**
	 * Sends post-new.php for bookings to the list, where the add booking wizard opens.
	 *
	 * @return void
	 * @since 6.8.0
	 * @since 6.9.0 Opens the wizard on the list instead of creating an auto-draft for the legacy form.
	 */
	public function redirect_new_booking(): void {
		if ( ( $_GET['post_type'] ?? '' ) !== $this->post_type ) {
			return;
		}

		wp_safe_redirect( admin_url( "edit.php?post_type={$this->post_type}&add-booking=1" ) );
		exit;
	}

	/**
	 * Enqueues the booking edit app.
	 *
	 * @param string $hook_suffix Current admin page.
	 * @return void
	 * @since 6.9.0
	 */
	public function enqueue_scripts( string $hook_suffix ): void {
		if ( 'post.php' !== $hook_suffix ) {
			return;
		}

		global $post;

		$screen = get_current_screen();

		if ( ! $screen || $this->post_type !== $screen->post_type ) {
			return;
		}

		/**
		 * @var ?ViewBooking $booking_view
		 */
		$booking_view = $post instanceof \WP_Post ? ViewBooking::instance( (int) $post->ID ) : null;

		if ( ! $booking_view || ! $booking_view->should_render_app() ) {
			return;
		}

		wp_enqueue_script( 'wte-dropzone' );

		$handle = self::enqueue_app_script( 'wptravelengine-booking-edit-app', 'admin/booking-edit-app.js' );
		$style  = Asset::register( $handle, 'admin/booking-edit-app.css' );

		wp_enqueue_style( $handle, $style->source, array( 'wte-dropzone' ), $style->version );
		wp_add_inline_style( $handle, '.wptravelengine-booking-edit-screen .wrap > .wp-heading-inline, .wptravelengine-booking-edit-screen .wrap > .page-title-action { display: none; }' );
		wp_localize_script( $handle, 'wptravelengineBookingEditData', ViewBooking::get_edit_data( (int) $post->ID ) );

		add_filter(
			'admin_body_class',
			static function ( string $classes ): string {
				return $classes . ' wptravelengine-booking-edit-screen ';
			}
		);
	}
}
