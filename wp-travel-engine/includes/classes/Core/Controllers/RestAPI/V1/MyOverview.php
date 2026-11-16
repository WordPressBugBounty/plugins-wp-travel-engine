<?php
/**
 * Registers every controller under `wptravelengine/v1/my`, and serves `my/overview`
 * itself. Add-on tabs are not served here — `UserAccount::get_account_dashboard_data()`
 * already renders those into the classic dashboard's mount point.
 *
 * @package WPTravelEngine
 * @since 6.9.0
 */

namespace WPTravelEngine\Core\Controllers\RestAPI\V1;

use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;
use WPTravelEngine\Abstracts\MyDashboard;
use WPTravelEngine\Core\Models\Post\Booking;
use WPTravelEngine\Core\Shortcodes\UserAccount;

class MyOverview extends MyDashboard {

	/**
	 * Controllers registered alongside this one, so `my/overview` is the single entry point
	 * for the account routes.
	 *
	 * @var array
	 */
	const CORE_CONTROLLERS = array(
		MyBookings::class,
		MyProfile::class,
	);

	/**
	 * Booking status meaning the trip is off, so it is neither chased nor listed as upcoming.
	 *
	 * @var string
	 */
	const CANCELLED_STATUS = 'canceled';

	/**
	 * Statuses that close a booking out: nothing further is owed on it either way.
	 *
	 * @var array<int, string>
	 */
	const SETTLED_STATUSES = array( 'canceled', 'refunded' );

	/**
	 * How many attention notices the overview raises at once.
	 *
	 * @var int
	 */
	const MAX_NOTICES = 3;

	/**
	 * How many upcoming trips the overview lists.
	 *
	 * @var int
	 */
	const MAX_NEXT_TRIPS = 3;

	public function register_routes() {
		foreach ( static::CORE_CONTROLLERS as $controller ) {
			( new $controller() )->register_routes();
		}

		register_rest_route(
			$this->namespace,
			"/$this->rest_base/overview",
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_overview' ),
					'permission_callback' => array( $this, 'logged_in_permissions_check' ),
				),
				'schema' => array( $this, 'get_public_item_schema' ),
			)
		);
	}

	/**
	 * `bookings` is unpaginated on purpose: "upcoming" and "balance due" are properties of
	 * the whole set, not of a page of it.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function get_overview( WP_REST_Request $request ): WP_REST_Response {
		$models = UserAccount::get_owned_bookings();

		// Keyed by ID so the notices can pair a summary row back to the model it came from
		// without building either twice.
		$rows = array();

		foreach ( $models as $model ) {
			$rows[ $model->get_id() ] = MyBookings::prepare( $model );
		}

		return rest_ensure_response(
			array(
				'bookings_count' => count( $rows ),
				'notices'        => $this->get_notices( $models, $rows ),
				'next_trips'     => $this->get_next_trips( $rows ),
			)
		);
	}

	/**
	 * What needs the traveler's attention before departure, capped at `MAX_NOTICES`: a
	 * remaining payment first, then missing traveler details, then whatever an add-on adds.
	 *
	 * @param array<Booking>    $models Booking models.
	 * @param array<int, array> $rows   Booking summary rows, keyed by booking ID.
	 * @return array<int, array<string, mixed>> Notices.
	 * @since 6.9.0 One notice per condition per trip, capped, with missing-details notices.
	 * @since 6.9.0 Ordered payment, then traveler details, then documents.
	 */
	protected function get_notices( array $models, array $rows ): array {
		$payment = array();
		$details = array();

		foreach ( $models as $model ) {
			$row = $rows[ $model->get_id() ] ?? array();

			if ( ! $row || ! $this->is_upcoming( $row ) ) {
				continue;
			}

			$notice = $this->balance_due_notice( $model, $row );

			if ( $notice ) {
				$payment[] = $notice;
			}

			$details = array_merge( $details, $this->missing_details_notices( $model, $row ) );
		}

		$notices = (array) apply_filters(
			'wptravelengine_my_overview_notices',
			array_merge( $payment, $details ),
			$models,
			$rows
		);

		return array_slice( $notices, 0, static::MAX_NOTICES );
	}

	/**
	 * One notice per booked trip whose travelers are missing required details — counting
	 * travelers with a blank required field, not the blank fields themselves.
	 *
	 * @param Booking $model Booking model.
	 * @param array   $row   Booking summary row.
	 * @return array<int, array<string, mixed>> Notices, empty when every traveler is complete.
	 * @since 6.9.0
	 */
	protected function missing_details_notices( $model, array $row ): array {
		$notices = array();
		$titles  = $this->trip_titles( $row );

		foreach ( $model->missing_traveler_counts() as $trip_id => $incomplete ) {
			$notices[] = array(
				'type'       => 'error',
				'icon'       => 'users',
				'title'      => sprintf(
					/* translators: 1: number of travelers, 2: trip title. */
					_n(
						'%1$d traveler\'s details are missing for %2$s',
						'%1$d travelers\' details are missing for %2$s',
						$incomplete,
						'wp-travel-engine'
					),
					$incomplete,
					$titles[ $trip_id ] ?? $this->trip_label( $row )
				),
				'content'    => __( 'Every traveler needs their details on file before departure.', 'wp-travel-engine' ),
				'link'       => '#/booking/' . $row['id'],
				'link_label' => __( 'Complete now', 'wp-travel-engine' ),
			);
		}

		return $notices;
	}

	/**
	 * An outstanding balance on an upcoming booking.
	 *
	 * @param Booking $model Booking model.
	 * @param array   $row   Booking summary row.
	 * @return ?array<string, mixed> Notice, or null when nothing is owed or it cannot be paid here.
	 * @since 6.9.0
	 */
	protected function balance_due_notice( $model, array $row ): ?array {
		$due = $this->is_settled( $row ) ? 0.0 : $model->get_total_due_amount();

		if ( $due <= 0 ) {
			return null;
		}

		// The due-payment link loads the booking back into the cart and lands on checkout. No link,
		// no way to pay here, e.g. a booking an add-on charges on a schedule; the booking view agrees.
		$payment_link = $model->get_due_payment_link();

		if ( '' === $payment_link ) {
			return null;
		}

		$due_label = html_entity_decode(
			wp_strip_all_tags( wte_get_formated_price( $due, $row['currency'] ?? '', '', true ) ),
			ENT_QUOTES,
			'UTF-8'
		);

		$title = $this->trip_label( $row );

		return array(
			'type'       => 'warning',
			'icon'       => 'billing',
			'title'      => $title
				? sprintf(
					/* translators: 1: formatted amount, 2: trip title. */
					__( 'Balance of %1$s due for %2$s', 'wp-travel-engine' ),
					$due_label,
					$title
				)
				: sprintf(
					/* translators: 1: formatted amount, 2: booking ID. */
					__( 'Balance of %1$s due on booking #%2$s', 'wp-travel-engine' ),
					$due_label,
					$row['id']
				),
			'content'    => __( 'Please complete the due payment before the trip starts.', 'wp-travel-engine' ),
			'link'       => $payment_link,
			'link_label' => __( 'Pay now', 'wp-travel-engine' ),
			'external'   => true,
		);
	}

	/**
	 * Every trip on a booking, named.
	 *
	 * @param array $row Booking summary row.
	 * @return string Comma-separated trip titles; empty when the booking recorded none.
	 * @since 6.9.0
	 */
	protected function trip_label( array $row ): string {
		return implode( ', ', $this->trip_titles( $row ) );
	}

	/**
	 * A booking's trip titles, keyed by trip ID so a per-trip notice can name its own.
	 *
	 * @param array $row Booking summary row.
	 * @return array<int, string> Titles, keyed by trip ID; trips with no title are omitted.
	 * @since 6.9.0
	 */
	protected function trip_titles( array $row ): array {
		$titles = array();

		foreach ( $row['booked_trips'] ?? array() as $trip ) {
			if ( ! empty( $trip['title'] ) ) {
				$titles[ (int) $trip['id'] ] = $trip['title'];
			}
		}

		return $titles;
	}

	/**
	 * The traveler's soonest upcoming trips, earliest first and flattened across bookings —
	 * two trips on one booking are two rows here.
	 *
	 * @param array<int, array> $rows Booking summary rows.
	 * @return array<int, array<string, mixed>> At most `MAX_NEXT_TRIPS` trips, each carrying
	 *                                          the `booking_id` its detail page lives under.
	 * @since 6.9.0
	 */
	protected function get_next_trips( array $rows ): array {
		$today = (int) current_time( 'Ymd' );
		$trips = array();

		foreach ( $rows as $row ) {
			if ( static::CANCELLED_STATUS === ( $row['booking_status'] ?? '' ) ) {
				continue;
			}

			foreach ( $row['booked_trips'] ?? array() as $trip ) {
				$start = $this->date_key( (string) ( $trip['start_datetime'] ?? '' ) );
				$end   = $this->date_key( (string) ( $trip['end_datetime'] ?? '' ) );

				// A trip already under way is still what's next, until the day it ends.
				if ( max( $start, $end ) < $today ) {
					continue;
				}

				$trip['booking_id'] = $row['id'];
				$trip['sort_key']   = $start ? $start : $end;

				$trips[] = $trip;
			}
		}

		usort(
			$trips,
			static function ( $a, $b ) {
				return $a['sort_key'] <=> $b['sort_key'];
			}
		);

		return array_map(
			static function ( $trip ) {
				unset( $trip['sort_key'] );

				return $trip;
			},
			array_slice( $trips, 0, static::MAX_NEXT_TRIPS )
		);
	}

	/**
	 * Whether a booking is cancelled or refunded, so nothing more is chased on it.
	 *
	 * @param array $booking Booking summary row.
	 * @return bool
	 */
	protected function is_settled( array $booking ): bool {
		return in_array( (string) ( $booking['booking_status'] ?? '' ), static::SETTLED_STATUSES, true );
	}

	/**
	 * Compares as `Ymd` integers so the answer turns over on the site's own date boundary,
	 * not a UTC instant.
	 *
	 * @param array $booking Booking summary row.
	 * @return bool
	 */
	protected function is_upcoming( array $booking ): bool {
		if ( static::CANCELLED_STATUS === ( $booking['booking_status'] ?? '' ) ) {
			return false;
		}

		$latest = 0;

		foreach ( $booking['booked_trips'] ?? array() as $trip ) {
			$latest = max( $latest, $this->date_key( (string) ( $trip['end_datetime'] ?? $trip['start_datetime'] ?? '' ) ) );
		}

		return $latest >= (int) current_time( 'Ymd' );
	}

	/**
	 * A trip date as the `Ymd` integer the comparisons above are made in.
	 *
	 * @param string $date Date string, in any format that leads with `Y-m-d`.
	 * @return int `Ymd`, or 0 when the string carries no date.
	 * @since 6.9.0
	 */
	protected function date_key( string $date ): int {
		return (int) preg_replace( '/\D/', '', substr( $date, 0, 10 ) );
	}

	/**
	 * The overview as this route returns it.
	 *
	 * @return array<string, mixed>
	 * @since 6.9.0
	 */
	public function get_item_schema(): array {
		if ( $this->schema ) {
			return $this->add_additional_fields_schema( $this->schema );
		}

		// A next trip is a booking's trip with the booking it came from named on it, since the
		// list is flattened across bookings and the detail page lives under the booking.
		$next_trip                             = MyBookings::trip_schema();
		$next_trip['properties']['booking_id'] = array(
			'description' => __( 'Booking the trip was taken on.', 'wp-travel-engine' ),
			'type'        => 'integer',
		);

		$this->schema = array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'wptravelengine_my_overview',
			'type'       => 'object',
			'properties' => array(
				'bookings_count' => array(
					'description' => __( 'How many bookings the traveler has.', 'wp-travel-engine' ),
					'type'        => 'integer',
					'readonly'    => true,
				),
				'notices'        => array(
					'description' => __( 'What needs the traveler\'s attention before departure.', 'wp-travel-engine' ),
					'type'        => 'array',
					'readonly'    => true,
					'items'       => array(
						'type'       => 'object',
						'properties' => array(
							'type'       => array(
								'description' => __( 'How urgently it reads.', 'wp-travel-engine' ),
								'type'        => 'string',
								'enum'        => array( 'error', 'warning', 'info', 'success' ),
							),
							'icon'       => array( 'type' => 'string' ),
							'title'      => array( 'type' => 'string' ),
							'content'    => array( 'type' => 'string' ),
							'link'       => array( 'type' => 'string' ),
							'link_label' => array( 'type' => 'string' ),
							'external'   => array(
								'description' => __( 'Whether the link leaves the dashboard.', 'wp-travel-engine' ),
								'type'        => 'boolean',
							),
						),
					),
				),
				'next_trips'     => array(
					'description' => __( 'Soonest upcoming trips, earliest first.', 'wp-travel-engine' ),
					'type'        => 'array',
					'items'       => $next_trip,
					'readonly'    => true,
				),
			),
		);

		return $this->add_additional_fields_schema( $this->schema );
	}
}
