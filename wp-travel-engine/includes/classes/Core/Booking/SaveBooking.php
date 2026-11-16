<?php
/**
 * Save Booking logic.
 *
 * @package WPTravelEngine/Core/Booking
 * @since 6.8.0
 */
namespace WPTravelEngine\Core\Booking;

use WPTravelEngine\Filters\Events;
use WPTravelEngine\Helpers\CartInfoParser;
use WPTravelEngine\Utilities\ArrayUtility;
use WPTravelEngine\Builders\FormFields\FileField;
use WPTravelEngine\Builders\FormFields\FileFieldChanges;
use WPTravelEngine\Builders\FormFields\BookingFormFields;
use WPTravelEngine\Core\Models\Post\Booking as BookingModel;

/**
 * Class SaveBooking
 * This class handles the saving logic for trip bookings.
 *
 * @since 6.8.0
 */
class SaveBooking {

	use Save\Details;
	use Save\Traveler;
	use Save\Notes;
	use Save\Payments;
	use Save\Items;
	use Save\Discounts;

	/**
	 * Booking model.
	 *
	 * @var BookingModel|null
	 */
	public ?BookingModel $booking;

	/**
	 * Request object.
	 *
	 * @var \WP_REST_Request|null
	 */
	public $request = null;

	/**
	 * Is new booking.
	 *
	 * @var bool
	 * @since 6.8.0
	 */
	protected bool $is_new_booking = false;

	/**
	 * Activity recorded by the current call, in the order the processors ran.
	 *
	 * Held until add_activities writes it, so a call that bails leaves no half-written trail.
	 *
	 * @var array<int, array<string, mixed>>
	 * @since 6.9.0
	 */
	protected array $activities = array();

	/**
	 * The booking's cart, parsed.
	 *
	 * @var CartInfoParser
	 * @since 6.9.0
	 */
	protected CartInfoParser $cart_info;

	/**
	 * Fee descriptors this call has seen, keyed by the slug the cart charges each under.
	 *
	 * @var array<string, array<string, mixed>>
	 * @since 6.9.0
	 */
	protected array $fees = array();

	/**
	 * Payload keys holding prose the operator typed, which may run to several lines.
	 *
	 * @var string[]
	 * @since 6.9.0
	 */
	const MULTILINE_KEYS = array( 'customer_notes', 'content', 'remark', 'note' );

	/**
	 * Whether the operator is making the change rather than the traveler.
	 *
	 * @var bool
	 * @since 6.9.0
	 */
	public bool $is_admin = false;

	/**
	 * Files the booking's form fields gain and lose in this save.
	 *
	 * @var FileFieldChanges|null
	 * @since 6.9.0
	 */
	protected ?FileFieldChanges $files = null;

	/**
	 * Constructor.
	 *
	 * @param int  $booking_id Booking post ID.
	 * @param bool $is_admin   Whether the operator is making the change rather than the traveler.
	 * @since 6.8.0
	 * @since 6.9.0 Takes whether the operator is making the change.
	 */
	public function __construct( int $booking_id, bool $is_admin = false ) {
		$this->is_admin  = $is_admin;
		$this->booking   = wptravelengine_get_booking( $booking_id, true );
		$this->cart_info = wptravelengine_get_cart_info(
			$booking_id,
			ArrayUtility::to_array( $this->booking ? $this->booking->get_cart_info() : array() )
		);
	}

	/**
	 * Writes the cart back to the booking, keeping the parsed copy in step.
	 *
	 * @param array<string, mixed> $cart The cart as it now stands.
	 * @return void
	 * @since 6.9.0
	 */
	protected function set_cart_info( array $cart ): void {
		$this->cart_info = wptravelengine_get_cart_info( $this->booking->ID, $cart );

		$this->booking->set_cart_info( $cart );
	}

	/**
	 * Writes one key of the booking's cart and saves it.
	 *
	 * @param string $key   Cart key to write, e.g. `fees` or `items`.
	 * @param mixed  $value What the key now holds.
	 * @return void
	 * @since 6.9.0
	 */
	protected function sync_cart( string $key, $value ): void {
		$cart = $this->cart_info->data();

		$cart[ $key ] = $value;

		$this->set_cart_info( $cart );
	}

	/**
	 * Files the booking's form fields gain and lose in this save.
	 *
	 * @return FileFieldChanges
	 * @since 6.9.0
	 */
	public function files(): FileFieldChanges {
		return $this->files ??= new FileFieldChanges( $this->booking->ID );
	}

	/**
	 * A section's file fields, keyed by stored key.
	 *
	 * @param string $section `billing`, `emergency` or `travelers`.
	 * @return array<string, FileField>
	 * @since 6.9.0
	 */
	public function file_fields( string $section ): array {
		return BookingFormFields::for( $this->booking, $this->is_admin )->file_fields( $section );
	}

	/**
	 * A stored row with its file fields under their own keys, so a save reads and writes them there.
	 *
	 * @param string               $section `billing`, `emergency` or `travelers`.
	 * @param array<string, mixed> $row     Row as stored.
	 * @return array<string, mixed>
	 * @since 6.9.0
	 */
	public function canonical( string $section, array $row ): array {
		return BookingFormFields::for( $this->booking, $this->is_admin )->canonical( $row, $section );
	}

	/**
	 * Takes the files sent with a save, refusing them all if any breaks its field's rules.
	 *
	 * @param \WP_REST_Request $request Save request.
	 * @return true|\WP_Error
	 * @since 6.9.0
	 */
	public function use_uploads( \WP_REST_Request $request ) {
		$fields      = BookingFormFields::for( $this->booking, $this->is_admin );
		$this->files = FileFieldChanges::from_request( $request, $this->booking->ID );

		return $this->files->validate( array( $fields, 'file_field_for' ) );
	}

	/**
	 * Applies a REST edit-shape payload to the booking.
	 *
	 * Each processor reads its own key off the payload, so nothing translates between schema and write.
	 *
	 * @param \WP_REST_Request $request Request carrying the edit-shape payload.
	 * @return true|\WP_Error An error when a file sent with the save breaks its field's rules; nothing is written then.
	 * @since 6.9.0
	 */
	public function process_update( \WP_REST_Request $request ) {
		if ( ! $this->booking ) {
			return true;
		}

		$valid = $this->use_uploads( $request );

		if ( is_wp_error( $valid ) ) {
			return $valid;
		}

		$params = array_diff_key( $request->get_params(), $request->get_url_params() );

		foreach ( wptravelengine_sanitize_params_recursive( $params, self::MULTILINE_KEYS ) as $key => $value ) {
			$request->set_param( $key, $value );
		}

		$this->request    = $request;
		$this->activities = array();
		$this->fees       = array();

		/**
		 * Filters whether the save goes ahead, once the payload is sanitized and before anything is written.
		 *
		 * @param true|\WP_Error   $valid   True to go ahead; an error stops the save and is returned to the caller.
		 * @param \WP_REST_Request $request The sanitized edit payload.
		 * @param SaveBooking      $save    The save about to run.
		 */
		$valid = apply_filters( 'wptravelengine_save_booking_validate', true, $request, $this );

		if ( is_wp_error( $valid ) ) {
			return $valid;
		}

		$this->booking->set_meta( '_user_edited', 'yes' );

		$this->booking->with_delayed_save(
			function () {
				if ( isset( $this->request['status'] ) ) {
					$this->set_status();
				}

				if ( isset( $this->request['billing'] ) ) {
					$this->set_billing();
				}

				if ( isset( $this->request['emergency_contacts'] ) ) {
					$this->set_emergency_contacts();
				}

				if ( isset( $this->request['admin_notes'] ) ) {
					$this->set_admin_notes();
				}

				if ( isset( $this->request['customer_notes'] ) ) {
					$this->set_user_notes();
				}

				if ( isset( $this->request['payments'] ) ) {
					$this->set_payments();
				}

				if ( isset( $this->request['items'] ) ) {
					$this->process_items();
				}

				if ( isset( $this->request['discounts'] ) ) {
					$this->set_discounts();
				}

				if ( isset( $this->request['booked_date'] ) ) {
					$this->set_booked_date();
				}

				if ( isset( $this->request['refunds'] ) ) {
					$this->set_refunds();
				}

				if ( isset( $this->request['trip'] ) ) {
					$this->process_trip();
				}

				/**
				 * Fires once the core sections are written, before totals are worked out and the booking saved.
				 *
				 * @param \WP_REST_Request $request The sanitized edit payload.
				 * @param SaveBooking      $save    The save in progress.
				 */
				do_action( 'wptravelengine_save_booking_process', $this->request, $this );

				$this->booking->sync_totals();

				$this->cart_info = $this->booking->cart_info;

				$this->booking->add_activities( $this->activities );
			}
		);

		$this->files->commit();

		$this->finalize();

		return true;
	}

	/**
	 * The cart as this save holds it.
	 *
	 * @return CartInfoParser
	 * @since 6.9.0
	 */
	public function get_cart_info(): CartInfoParser {
		return $this->cart_info;
	}


	/**
	 * Holds one entry until the payload has been applied.
	 *
	 * Batched because `set_meta()` queues into `__changes` while `get_meta()` reads `$this->data`,
	 * so a second write in one request would overwrite the entry the first queued.
	 *
	 * @param string               $type     What kind of event it was; decides the icon the client draws.
	 * @param string               $category Which filter the entry falls under, or '' when none fits.
	 * @param string               $text     What happened, in a sentence, already translated.
	 * @param array<string, mixed> $extra    Further keys the entry carries, e.g. the travelers it names.
	 * @return void
	 * @since 6.9.0
	 */
	public function record_activity( string $type, string $category, string $text, array $extra = array() ): void {
		$this->activities[] = BookingModel::record_activity( $type, $category, $text, $this->is_admin ? 'admin' : 'traveler', $extra );
	}

	/**
	 * Writes the held activity and saves the booking.
	 *
	 * For a caller reaching one setter directly; `process_update()` flushes in its own delayed save.
	 *
	 * @return void
	 * @since 6.9.0
	 */
	public function commit(): void {
		if ( ! $this->booking ) {
			return;
		}

		$this->booking->add_activities( $this->activities );

		$this->activities = array();

		$this->booking->save();

		$this->files()->commit();
	}

	/**
	 * Brings what is derived from the cart - `order_trips`, the place-order summary, the seats
	 * held - in step with it, then lets add-ons and listeners know.
	 *
	 * A row's keys the cart does not produce are kept, since add-ons write their own onto it.
	 * Add-on meta is synced before the seats, as the legacy save did.
	 *
	 * @return void
	 * @since 6.9.0
	 */
	protected function finalize(): void {
		$booking   = $this->booking;
		$cart_info = $this->get_cart_info()->data();
		$items     = array_filter(
			ArrayUtility::to_array( $cart_info['items'] ?? array() ),
			static fn ( $item ) => ! empty( $item['id'] ) && 'trip' === get_post_type( (int) ( $item['trip_id'] ?? 0 ) )
		);

		if ( $items ) {
			$prepared = BookingProcess::prepare_items( array_values( $items ) );
			$held     = ArrayUtility::to_array( $booking->get_meta( 'order_trips' ) );

			foreach ( $prepared['items'] as $key => $row ) {
				$prepared['items'][ $key ] = array_merge( ArrayUtility::to_array( $held[ $key ] ?? array() ), $row );
			}

			$booking->set_order_items( $prepared['items'] );
			$booking->set_nested_meta( 'wp_travel_engine_booking_setting.place_order', $prepared['settings']['place_order'] ?? array() );
			$booking->save();
		}

		do_action( 'wptravelengine_save_addon_meta', $this->request, $booking, $cart_info, $this->is_new_booking );

		$booking->maybe_update_inventory();

		if ( $this->is_new_booking ) {
			Events::booking_created( $booking );
		} else {
			wp_update_post( array( 'ID' => $this->booking->ID ) );

			Events::booking_updated( $booking );

			/**
			 * @var \WPTravelEngine\Core\Models\Post\Booking $booking
			 * This action is documented in includes/classes/Core/Models/Post/Booking.php
			 * */
			do_action( 'wptravelengine.booking.updated', $booking, $booking->get_data() ); // phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores
		}
	}
}
