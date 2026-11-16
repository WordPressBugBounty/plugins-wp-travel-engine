<?php
/**
 * Send payment reminder controller.
 *
 * @package WPTravelEngine/Core/Controllers
 * @since 6.9.0
 */

namespace WPTravelEngine\Core\Controllers\Ajax;

use WPTravelEngine\Email\Email;
use WPTravelEngine\Abstracts\AjaxController;
use WPTravelEngine\Core\Models\Post\Booking as BookingModel;

/**
 * Emails the customer a reminder of what their booking still owes.
 *
 * A sibling of ResendPurchaseReceipt: the booking screen sends both the same way.
 *
 * @since 6.9.0
 */
class SendPaymentReminder extends AjaxController {

	const NONCE_KEY    = 'nonce';
	const NONCE_ACTION = 'wte_send_payment_reminder';
	const ACTION       = 'wte_send_payment_reminder';
	const ALLOW_NOPRIV = false;

	/**
	 * Sends the reminder named by the request.
	 *
	 * @return void
	 * @since 6.9.0
	 */
	protected function process_request() {
		if ( ! wptravelengine_curr_user_can( 'manage_wte_booking' ) ) {
			wp_send_json_error(
				array(
					'code'    => 'SEND_REMINDER_FORBIDDEN',
					'message' => __( 'Sorry, you are not allowed to edit this booking.', 'wp-travel-engine' ),
				)
			);
		}

		$booking = wptravelengine_get_booking( (int) $this->request->get_param( 'booking_id' ), true );

		if ( ! $booking ) {
			wp_send_json_error(
				array(
					'code'    => 'SEND_REMINDER_ERROR',
					'message' => __( 'Invalid booking ID.', 'wp-travel-engine' ),
				)
			);
		}

		$sent = $this->send_reminder( $booking, (string) $this->request->get_param( 'message' ) );

		if ( is_wp_error( $sent ) ) {
			wp_send_json_error(
				array(
					'code'    => $sent->get_error_code(),
					'message' => $sent->get_error_message(),
				)
			);
		}

		wp_send_json_success(
			array( 'message' => __( 'Payment reminder sent.', 'wp-travel-engine' ) )
		);
	}

	/**
	 * The reminder body the dialog opens on, with the booking's own figures already in it.
	 *
	 * @param BookingModel $booking Booking the reminder is about.
	 * @return string
	 * @since 6.9.0
	 */
	public static function template( BookingModel $booking ): string {
		$billing = (array) $booking->get_billing_info();
		$name    = trim( ( $billing['fname'] ?? '' ) . ' ' . ( $billing['lname'] ?? '' ) );
		$link    = $booking->get_due_payment_link();

		$body = '<p>' . sprintf(
			/* translators: %s: customer name. */
			__( 'Hi %s,', 'wp-travel-engine' ),
			'' !== $name ? $name : __( 'there', 'wp-travel-engine' )
		) . '</p>';

		$body .= '<p>' . sprintf(
			/* translators: 1: booking ID, 2: amount still owed. */
			__( 'This is a reminder that booking #%1$d still has %2$s outstanding.', 'wp-travel-engine' ),
			$booking->ID,
			wptravelengine_the_price( $booking->get_total_due_amount(), false, false )
		) . '</p>';

		if ( '' !== $link ) {
			$body .= '<p>' . sprintf(
				/* translators: %s: link the customer settles the balance through. */
				__( 'You can settle it here: %s', 'wp-travel-engine' ),
				'<a href="' . esc_url( $link ) . '">' . esc_html( $link ) . '</a>'
			) . '</p>';
		}

		return apply_filters( 'wptravelengine_payment_reminder_body', $body, $booking );
	}

	/**
	 * Emails the reminder.
	 *
	 * No template of its own, so it goes out as the operator wrote it, wrapped in the store's own
	 * header and footer by `Email`; the dialog prefills the body from `template()`.
	 *
	 * @param BookingModel $booking Booking the reminder is about.
	 * @param string       $message Body to send; the template's own when left empty.
	 * @return true|\WP_Error True once sent.
	 * @since 6.9.0
	 */
	protected function send_reminder( BookingModel $booking, string $message = '' ) {
		$email = (string) ( $booking->get_billing_info()['email'] ?? '' );

		if ( '' === $email || ! is_email( $email ) ) {
			return new \WP_Error(
				'wptravelengine_no_customer_email',
				__( 'This booking carries no customer email to send to.', 'wp-travel-engine' )
			);
		}

		$subject = sprintf(
			/* translators: %d: booking ID. */
			__( 'Payment reminder for booking #%d', 'wp-travel-engine' ),
			$booking->ID
		);

		// The dialog composes it in the editor, so it arrives as markup already.
		$mailer = new Email();
		$mailer->set( 'to', $email )
			->set( 'my_subject', $subject )
			->set( 'content', wp_kses_post( '' !== trim( $message ) ? $message : self::template( $booking ) ) );

		if ( ! $mailer->send() ) {
			return new \WP_Error(
				'wptravelengine_email_failed',
				__( 'The reminder could not be sent.', 'wp-travel-engine' )
			);
		}

		return true;
	}
}
