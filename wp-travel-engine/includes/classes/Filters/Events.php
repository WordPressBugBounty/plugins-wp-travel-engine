<?php
/**
 * Domain event emitters — queue events for the Events dispatcher.
 *
 * @since 6.5.2
 */

namespace WPTravelEngine\Filters;

use WPTravelEngine\Core\Models\Post;
use WPTravelEngine\Core\Models\Review;
use WPTravelEngine\Helpers\Translators;
use WPTravelEngine\Abstracts\EventTable;

/**
 * Domain event vocabulary plus the queue's cron dispatch, write path, and
 * existence checks; extends `EventTable`, which owns table creation/migration
 * and pruning of old triggered rows.
 *
 * @since 6.8.3 Made child class of new `EventTable` parent.
 */
class Events extends EventTable {

	/**
	 * Table name.
	 *
	 * @var string
	 */
	protected static string $table_name;

	/**
	 * List of events
	 *
	 * @since 6.7.1
	 */
	private static array $events_data = array();

	/**
	 * @inheritDoc
	 */
	public function __construct() {
		parent::__construct();
		static::$table_name = $this->table_name();
		add_action( 'updated_postmeta', array( $this, 'trigger_payment_status_update' ), 10, 4 );
		add_action( 'wptravelengine_check_events', array( $this, 'check_events' ) );
		add_action( 'shutdown', array( $this, 'process_events' ) );
	}

	/**
	 * Process events.
	 *
	 * @since 6.7.1
	 * @since 6.7.9 Added support for TranslatePress language.
	 * @since 6.8.3 Writes via `Table::upsert()`; resets `triggered`/`triggered_at` on collision.
	 */
	public function process_events() {

		if ( empty( self::$events_data ) ) {
			return;
		}

		$processed_events = array();
		foreach ( self::$events_data as $__data ) :

			if ( Translators::is_wpml_multilingual_active() ) {
				$__data['event_data']['wpml_lang'] = apply_filters( 'wpml_current_language', null );
			} elseif ( Translators::is_translatepress_active() ) {
				// @since 6.7.9 Added support for TranslatePress language.
				$__data['event_data']['trp_lang'] = Translators::get_translatepress_language();
			}

			$__data['trigger_time'] ??= gmdate( 'Y-m-d H:i:s', time() + 60 );

			$result = $this->upsert(
				array(
					'object_id'        => $__data['object_id'],
					'event_name'       => $__data['event_name'],
					'object_type'      => $__data['object_type'],
					'event_data'       => wp_json_encode( $__data['event_data'] ),
					'trigger_time'     => $__data['trigger_time'],
					'event_created_at' => current_time( 'mysql', true ),
					'triggered'        => 0,
					'triggered_at'     => null,
				)
			);

			if ( $result ) {
				$processed_events[ $__data['object_id'] . '_' . $__data['object_type'] ] = $result;
			}

		endforeach;

		if ( ! empty( $processed_events ) ) {
			do_action( 'wptravelengine_events_processed', $processed_events );
		}
	}

	/**
	 * Schedule events.
	 *
	 * @return void
	 * @since 6.6.9
	 * @since 6.8.3 Added `void` return type.
	 */
	public static function schedule(): void {
		if ( ! wp_next_scheduled( 'wptravelengine_check_events' ) ) {
			wp_schedule_event( time(), 'every_minute', 'wptravelengine_check_events' );
		}
	}

	/**
	 * Trigger customer creation event.
	 *
	 * @return void
	 * @since 6.8.3 Filters on `triggered = 0` and marks rows `triggered` instead of deleting them.
	 */
	public function check_events() {

		$now = current_time( 'mysql', true );

		$events = $this->where(
			array(
				array( 'triggered', '=', 0 ),
				array( 'trigger_time', '<=', $now ),
			)
		);

		$triggered_ids = array();

		foreach ( $events as $event ) :

			extract( $event );

			try {
				switch ( $object_type ) :
					case 'customer':
						$object = new Post\Customer( $object_id );
						break;
					case 'enquiry':
						$object = new Post\Enquiry( $object_id );
						break;
					case 'booking':
						$object = new Post\Booking( $object_id );
						break;
					case 'wte-payments':
						$object = new Post\Payment( $object_id );
						break;
					case 'comment':
						$comment = get_comment( $object_id );
						if ( ! $comment instanceof \WP_Comment ) {
							continue 2;
						}
						$object = new Review( $comment );
						break;
					case 'log_file':
						// Handle log file cleanup via Logger class
						\WPTravelEngine\Logger\Logger::handle_log_file_cleanup( json_decode( $event_data, true ) );
						$object = $object_id;
						break;
					default:
						$object = $object_id;
				endswitch;
			} catch ( \Exception $e ) {
				continue;
			}

			do_action( $event_name, $object, json_decode( $event_data, true ) );

			$triggered_ids[] = (int) $id;

		endforeach;

		if ( ! empty( $triggered_ids ) ) {
			$this->update_where(
				array( array( 'id', 'IN', $triggered_ids ) ),
				array(
					'triggered'    => 1,
					'triggered_at' => current_time( 'mysql', true ),
				)
			);
		}
	}

	/**
	 * Trigger payment status update.
	 *
	 * @param int    $meta_id Meta ID.
	 * @param int    $post_id Post ID.
	 * @param string $meta_key Meta key.
	 * @param mixed  $meta_value Meta value.
	 *
	 * @return void
	 * @since 6.7.8 Made compatible with `payment_created` and `due_payment_completed` methods
	 */
	public function trigger_payment_status_update( int $meta_id, int $post_id, string $meta_key, $meta_value ) {

		if ( 'payment_status' !== $meta_key ) {
			return;
		}

		$post = get_post( $post_id );

		$success_values = array_keys( wptravelengine_payment_status() );

		if ( 'wte-payments' === $post->post_type && in_array( $meta_value, $success_values, true ) ) {
			$payment = new Post\Payment( $post );
			if ( wptravelengine_toggled( $payment->get_meta( 'is_due_payment' ) ) ) {
				static::due_payment_completed( $payment );
			} else {
				static::payment_created( $payment );
			}
		}
	}

	/**
	 * Payment created event.
	 *
	 * @param Post\Payment $payment Payment instance.
	 * @since 6.7.8
	 */
	public static function payment_created( Post\Payment $payment ) {
		static::add_event( 'wptravelengine.booking.payment.completed', $payment->get_id(), $payment->get_post_type() );
	}

	/**
	 * Due payment completed event.
	 *
	 * @param Post\Payment $payment Payment instance.
	 * @since 6.7.8
	 */
	public static function due_payment_completed( Post\Payment $payment ) {
		static::add_event( 'wptravelengine.booking.due.payment.completed', $payment->get_id(), $payment->get_post_type() );
	}

	/**
	 * Payment updated event.
	 *
	 * @param Post\Payment $payment Payment instance.
	 * @since 6.8.0
	 */
	public static function payment_updated( Post\Payment $payment ) {
		static::add_event( 'wptravelengine.booking.payment.updated', $payment->get_id(), $payment->get_post_type() );
	}

	/**
	 * Customer created event.
	 *
	 * @param Post\Customer $customer Customer instance.
	 * @return void
	 */
	public static function customer_created( Post\Customer $customer ) {
		static::add_event( 'wptravelengine.customer.created', $customer->get_id(), $customer->get_post_type() );
	}

	/**
	 * Enquiry created event.
	 *
	 * @param Post\Enquiry $enquiry Enquiry instance.
	 *
	 * @return void
	 */
	public static function enquiry_created( Post\Enquiry $enquiry ) {
		static::add_event( 'wptravelengine.enquiry.created', $enquiry->get_id(), $enquiry->get_post_type() );
	}

	/**
	 * Booking created event.
	 *
	 * @param Post\Booking $booking Booking instance.
	 *
	 * @return void
	 */
	public static function booking_created( Post\Booking $booking ) {
		static::add_event( 'wptravelengine.booking.created', $booking->get_id(), $booking->get_post_type() );
	}

	/**
	 * Booking updated event.
	 *
	 * @param Post\Booking $booking Booking instance.
	 *
	 * @return void
	 */
	public static function booking_updated( Post\Booking $booking ) {
		static::add_event( 'wptravelengine.booking.updated', $booking->get_id(), $booking->get_post_type() );
	}

	/**
	 * Review created event.
	 *
	 * @param Review $review Review instance.
	 *
	 * @return void
	 */
	public static function review_created( Review $review ) {
		static::add_event( 'wptravelengine.review.created', $review->get_id(), 'comment' );
	}

	/**
	 * Add event.
	 *
	 * @param string $event_name Event name.
	 * @param int    $object_id Object ID.
	 * @param string $object_type Object type.
	 * @param string $trigger_time Trigger time.
	 * @param array  $event_data Data.
	 *
	 * @return void
	 * @since 6.7.1 Added support for events data storage.
	 */
	public static function add_event( string $event_name, $object_id, $object_type, $trigger_time = null, $event_data = array() ) {
		self::$events_data[] = compact( 'event_name', 'object_id', 'object_type', 'trigger_time', 'event_data' );
	}

	/**
	 * Event Exists.
	 *
	 * Checks both database and pending in-memory events to prevent race conditions.
	 *
	 * @param string $event_name Event name.
	 * @param int    $object_id Object ID.
	 * @param string $object_type Object type.
	 *
	 * @return bool
	 * @since 6.8.3 Uses `Table::exists_where()`; only counts rows where `triggered = 0`.
	 */
	public static function exists( string $event_name, int $object_id, string $object_type ): bool {
		// Check pending in-memory events first (race condition prevention)
		foreach ( self::$events_data as $event ) {
			if ( $event['event_name'] === $event_name &&
				$event['object_id'] === $object_id &&
				$event['object_type'] === $object_type ) {
				return true;
			}
		}

		// Check database.
		return static::instance()->exists_where(
			array(
				array( 'object_id', '=', $object_id ),
				array( 'event_name', '=', $event_name ),
				array( 'object_type', '=', $object_type ),
				array( 'triggered', '=', 0 ),
			)
		);
	}
}
