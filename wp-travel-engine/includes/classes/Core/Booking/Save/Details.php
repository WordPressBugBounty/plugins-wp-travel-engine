<?php
/**
 * Saves the booking's status and the date it was placed.
 *
 * @package WPTravelEngine/Core/Booking
 * @since 6.9.0
 */

namespace WPTravelEngine\Core\Booking\Save;

/**
 * Status and booked-date writers for SaveBooking, which holds the state they read.
 *
 * @since 6.9.0
 */
trait Details {

	/**
	 * Queues an activity entry, written to the booking when the save completes.
	 *
	 * @param string $type     What kind of event it was, e.g. `payment`.
	 * @param string $category Section the event belongs to.
	 * @param string $text     What happened, as shown in the log.
	 * @return void
	 */
	abstract public function record_activity( string $type, string $category, string $text ): void;

	/**
	 * Saves the booking status, which the payload is known to carry.
	 *
	 * Only a recognised status is taken, and the model ignores one it already holds.
	 *
	 * @return void
	 * @since 6.9.0
	 */
	protected function set_status(): void {
		$status  = $this->request['status'];
		$allowed = array_keys( wptravelengine_get_labels( 'booking_status' ) );

		if ( ! in_array( $status, $allowed, true ) ) {
			return;
		}

		$previous = (string) $this->booking->get_booking_status();

		if ( $status === $previous ) {
			return;
		}

		$this->booking->set_status( $status );

		$labels = wptravelengine_get_labels( 'booking_status' );

		$this->record_activity(
			'status_changed',
			'bookings',
			sprintf(
				/* translators: 1: previous booking status, 2: new booking status. */
				__( 'Changed the booking status from %1$s to %2$s.', 'wp-travel-engine' ),
				$labels[ $previous ] ?? $previous,
				$labels[ $status ] ?? $status
			)
		);
	}

	/**
	 * Moves when the booking was placed, which the payload is known to carry.
	 *
	 * Stored as the post date, so it also decides where the booking sorts in the list.
	 *
	 * @return void
	 * @since 6.9.0
	 */
	protected function set_booked_date(): void {
		$date = (string) $this->request['booked_date'];

		if ( '' === $date || false === strtotime( $date ) ) {
			return;
		}

		$date = gmdate( 'Y-m-d H:i:s', strtotime( $date ) );

		if ( $date === (string) $this->booking->post->post_date_gmt ) {
			return;
		}

		$local = get_date_from_gmt( $date );

		$this->booking->update_post(
			array(
				'post_date_gmt' => $date,
				'post_date'     => $local,
			)
		);

		$this->booking->post->post_date_gmt = $date;
		$this->booking->post->post_date     = $local;

		$this->record_activity(
			'booking',
			'bookings',
			sprintf(
				/* translators: %s: the date the booking is now recorded as placed on. */
				__( 'Changed the booking date to %s.', 'wp-travel-engine' ),
				wp_date( (string) get_option( 'date_format' ), strtotime( $date ) )
			)
		);
	}
}
