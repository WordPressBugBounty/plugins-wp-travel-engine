<?php
/**
 * Saves the traveler's billing, emergency contacts and insurance details.
 *
 * @package WPTravelEngine/Core/Booking
 * @since 6.9.0
 */

namespace WPTravelEngine\Core\Booking\Save;

use WPTravelEngine\Utilities\ArrayUtility;
use WPTravelEngine\Core\Models\Post\Customer;
use WPTravelEngine\Core\Models\Post\Booking as BookingModel;

/**
 * Traveler-detail writers for SaveBooking, which holds the state they read.
 *
 * @since 6.9.0
 */
trait Traveler {

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
	 * Files the booking's form fields gain and lose in this save.
	 *
	 * @return \WPTravelEngine\Builders\FormFields\FileFieldChanges
	 */
	abstract public function files(): \WPTravelEngine\Builders\FormFields\FileFieldChanges;

	/**
	 * A section's file fields, keyed by stored key.
	 *
	 * @param string $section `billing`, `emergency` or `travelers`.
	 * @return array<string, \WPTravelEngine\Builders\FormFields\FileField>
	 */
	abstract public function file_fields( string $section ): array;

	/**
	 * Process billing details.
	 *
	 * @return void
	 * @since 6.7.0
	 * @since 6.8.0 Moved from PostTypes\Booking; parameters replaced by class properties.
	 * @since 6.9.0 Links or creates the customer only when the operator saves & Records the change against the booking's activity log.
	 * @since 6.9.0 Keeps, adds and removes the files billing file fields hold.
	 */
	protected function set_billing(): void {
		$submitted = ArrayUtility::to_array( $this->request->get_param( 'billing' ) );

		if ( ! $submitted ) {
			return;
		}

		$stored    = $this->canonical( 'billing', ArrayUtility::to_array( $this->booking->get_billing_info() ) );
		$submitted = $this->files()->merge( 'billing', $submitted, $stored, $this->file_fields( 'billing' ) );

		$billing_details = array_merge( $stored, $submitted );

		$billing_email = $submitted['email'] ?? '';

		if ( $this->is_admin && ! empty( $billing_email ) && is_email( $billing_email ) ) {
			$customer_id    = Customer::is_exists( $billing_email );
			$customer_model = null;

			if ( $customer_id ) {
				try {
					$customer_model = new Customer( $customer_id );
				} catch ( \Exception $e ) {
					$customer_model = null;
				}
			} else {
				$customer_model = Customer::create_post(
					array(
						'post_status' => 'publish',
						'post_type'   => 'customer',
						'post_title'  => sanitize_email( $billing_email ),
					)
				);
			}

			if ( $customer_model instanceof Customer ) {
				$known = $customer_model->get_customer_details();

				$customer_model->set_customer_details(
					array_filter(
						array(
							'fname' => sanitize_text_field( (string) ( $billing_details['fname'] ?? '' ) ),
							'lname' => sanitize_text_field( (string) ( $billing_details['lname'] ?? '' ) ),
							'email' => sanitize_email( $billing_email ),
						),
						static fn ( $value, $key ) => '' !== $value && '' === (string) ( $known[ $key ] ?? '' ),
						ARRAY_FILTER_USE_BOTH
					)
				);

				$customer_model->save();

				if ( ! email_exists( $billing_email ) ) {
					/**
					 * Filters whether to force user account creation when saving a booking from the admin.
					 *
					 * Defaults to true unless the account creation mode is "Do not create an account"
					 * (WTE → Settings → User Dashboard).
					 *
					 * @param bool     $force          Whether to force account creation.
					 * @param Customer $customer_model Customer model instance.
					 * @param BookingModel $booking    Booking model instance.
					 *
					 * @since 6.8.0
					 * @since 6.9.0 Defaults to false when the account creation mode is "none".
					 */
					$force = apply_filters( 'wptravelengine_admin_booking_force_register_user', 'none' !== wptravelengine_account_creation_mode(), $customer_model, $this->booking );
					$customer_model->maybe_register_as_user( $force );
				}

				do_action( 'wptravelengine_after_customer_created', $customer_model->ID );

				$customer_model->update_customer_bookings( $this->booking->ID );
				$customer_model->update_customer_meta( $this->booking->ID );
				$customer_model->save();
			}
		}

		$this->booking->set_billing_info( $billing_details );

		$this->record_activity(
			'billing_updated',
			'travelers',
			'' !== (string) $billing_email
				? sprintf(
					/* translators: %s: billing email address. */
					__( 'Updated the billing details, billed to %s.', 'wp-travel-engine' ),
					$billing_email
				)
				: __( 'Updated the billing details.', 'wp-travel-engine' )
		);
	}

	/**
	 * Process emergency contacts.
	 *
	 * @return void
	 * @since 6.7.0
	 * @since 6.8.0 Moved from PostTypes\Booking; parameters replaced by class properties.
	 * @since 6.9.0 Accepts a row-shaped collection as well as the legacy column-major one.
	 * @since 6.9.0 Records the change against the booking's activity log.
	 * @since 6.9.0 Keeps, adds and removes the files emergency contact file fields hold.
	 */
	protected function set_emergency_contacts(): void {
		$contact = (array) ( $this->request->get_param( 'emergency_contacts' ) ?? array() );
		$stored  = $this->canonical( 'emergency', (array) ( $this->booking->get_emergency_contacts()[0] ?? array() ) );

		// The contact is replaced whole, so a removed one takes its files with it.
		if ( empty( $contact ) ) {
			$this->files()->forget( $stored, $this->file_fields( 'emergency' ) );
		} else {
			$contact = $this->files()->merge( 'emergency', $contact, $stored, $this->file_fields( 'emergency' ) );
		}

		// The model stores a list, so the one contact goes in as the only row.
		$this->booking->set_emergency_contact_details( empty( $contact ) ? array() : array( $contact ) );

		$this->record_activity(
			'traveler',
			'travelers',
			empty( $contact )
				? __( 'Removed the emergency contact.', 'wp-travel-engine' )
				: __( 'Updated the emergency contact.', 'wp-travel-engine' )
		);
	}

	/**
	 * Process travel insurance details.
	 *
	 * @return void
	 * @since 6.7.0
	 * @since 6.8.0 Renamed from save_travel_insurance_meta() and moved to SaveBooking.
	 */
	protected function process_insurance_details(): void {
		if ( $travel_insurance = $this->request->get_param( 'travel_insurance_meta' ) ) {
			$this->booking->set_meta( 'wptravelengine_travel_insurance', $travel_insurance );
		}
	}
}
