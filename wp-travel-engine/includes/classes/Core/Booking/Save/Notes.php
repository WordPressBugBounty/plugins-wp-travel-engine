<?php
/**
 * Saves the customer and admin notes on a booking.
 *
 * @package WPTravelEngine/Core/Booking
 * @since 6.9.0
 */

namespace WPTravelEngine\Core\Booking\Save;

/**
 * Note writers for SaveBooking, which holds the state they read.
 *
 * @since 6.9.0
 */
trait Notes {

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
	 * Process user notes, which the payload is known to carry.
	 *
	 * @return void
	 * @since 6.7.0
	 * @since 6.8.0 Split from process_notes(); moved to SaveBooking.
	 * @since 6.9.0 Saves an explicitly submitted empty value so a note can be cleared.
	 * @since 6.9.0 Records the change against the booking's activity log.
	 */
	protected function set_user_notes(): void {
		$customer_notes = (string) $this->request->get_param( 'customer_notes' );

		$this->booking->set_additional_details( $customer_notes );

		$this->record_activity(
			'message',
			'misc',
			'' === trim( $customer_notes )
				? __( 'Cleared the note shown to the customer.', 'wp-travel-engine' )
				: __( 'Updated the note shown to the customer.', 'wp-travel-engine' )
		);
	}

	/**
	 * Process admin notes, which the payload is known to carry.
	 *
	 * Replaced wholesale; a row naming an existing note keeps its author and date.
	 *
	 * @return void
	 * @since 6.7.0
	 * @since 6.8.0 Split from process_notes(); moved to SaveBooking.
	 * @since 6.9.0 Takes a list of notes rather than one string.
	 */
	protected function set_admin_notes(): void {
		$existing = array_column( $this->booking->get_my_notes( 'admin' ), null, 'id' );

		$author = wp_get_current_user()->display_name;
		$author = '' === $author ? __( 'Admin', 'wp-travel-engine' ) : $author;
		$notes  = array();
		$legacy = '';

		foreach ( (array) $this->request['admin_notes'] as $row ) {
			$row     = (array) $row;
			$content = (string) ( $row['content'] ?? '' );

			if ( '' === trim( $content ) ) {
				continue;
			}

			$id = sanitize_key( (string) ( $row['id'] ?? '' ) );

			if ( 'legacy' === $id ) {
				$legacy = $content;
				continue;
			}

			$notes[] = isset( $existing[ $id ] )
				? array_merge( $existing[ $id ], array( 'content' => $content ) )
				: array(
					'id'      => '' === $id ? wp_generate_uuid4() : $id,
					'by'      => 'admin',
					'content' => $content,
					'author'  => $author,
					'date'    => current_time( 'mysql', true ),
				);
		}

		$this->booking->set_meta( 'wptravelengine_internal_notes', $notes );
		$this->booking->set_notes( $legacy );

		$before = count( $existing );
		$after  = count( $notes ) + (int) ( '' !== $legacy );

		if ( 0 === $after ) {
			$text = __( 'Cleared the internal notes.', 'wp-travel-engine' );
		} elseif ( $after > $before ) {
			$text = _n(
				'Added an internal note, which travelers never see.',
				'Added internal notes, which travelers never see.',
				$after - $before,
				'wp-travel-engine'
			);
		} elseif ( $after < $before ) {
			$text = sprintf(
				/* translators: %d: how many internal notes were deleted. */
				_n( 'Deleted %d internal note.', 'Deleted %d internal notes.', $before - $after, 'wp-travel-engine' ),
				$before - $after
			);
		} else {
			$text = __( 'Updated the internal notes, which travelers never see.', 'wp-travel-engine' );
		}

		$this->record_activity( 'message', 'misc', $text );
	}
}
