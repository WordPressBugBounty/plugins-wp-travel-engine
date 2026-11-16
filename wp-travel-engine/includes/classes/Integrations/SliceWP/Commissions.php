<?php
/**
 * Default `wptravelengine_booking_commissions` source, read straight from SliceWP.
 *
 * @package WPTravelEngine/Integrations/SliceWP
 * @since 6.9.0
 */

namespace WPTravelEngine\Integrations\SliceWP;

use WPTravelEngine\Core\Models\Post\Booking as BookingModel;
use WPTravelEngine\Helpers\Functions;

/**
 * Booking edit screen commissions, read from and written to SliceWP.
 *
 * Hooked at priority 20 so the SliceWP addon, once it hooks the same filter/action, pre-empts it.
 */
class Commissions {

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function register_hooks(): void {
		$instance = new self();
		add_filter( 'wptravelengine_booking_commissions', array( $instance, 'from_slicewp' ), 20, 2 );
		add_action( 'wptravelengine_admin_button_action', array( $instance, 'update_commission_amount' ), 20 );
	}

	/**
	 * Commission rows for a booking, unless an earlier callback already supplied them.
	 *
	 * @param array<int, array<string, mixed>> $commissions Commission rows gathered so far.
	 * @param BookingModel                     $booking     Booking the rows belong to.
	 * @return array<int, array<string, mixed>> Commission rows.
	 */
	public function from_slicewp( array $commissions, BookingModel $booking ): array {
		if ( ! empty( $commissions ) ) {
			return $commissions;
		}

		if ( ! function_exists( 'slicewp_get_commissions' ) || ! function_exists( 'slicewp_get_affiliate' ) ) {
			return $commissions;
		}

		$rows = slicewp_get_commissions(
			array(
				'number'    => -1,
				'reference' => $booking->ID,
				'origin'    => 'wptravelengine',
				'order'     => 'ASC',
			)
		);

		foreach ( $rows as $row ) {
			$affiliate     = slicewp_get_affiliate( $row->get( 'affiliate_id' ) );
			$currency      = (string) $row->get( 'currency' );
			$commission_id = $row->get( 'id' );

			$commissions[] = array(
				'id'             => $commission_id,
				'amount'         => (float) $row->get( 'amount' ),
				'currency'       => $currency,
				'currencySymbol' => Functions::currency_symbol_by_code( $currency ),
				'affiliateEmail' => $affiliate ? (string) $affiliate->get( 'payment_email' ) : '',
				'editable'       => function_exists( 'slicewp_update_commission' ),
				'detailsUrl'     => add_query_arg(
					array(
						'page'          => 'slicewp-commissions',
						'subpage'       => 'edit-commission',
						'commission_id' => $commission_id,
					),
					admin_url( 'admin.php' )
				),
			);
		}

		return $commissions;
	}

	/**
	 * Saves an edited commission amount to SliceWP.
	 *
	 * @param \WP_REST_Request $request Posted `admin-ajax` request.
	 * @return void
	 */
	public function update_commission_amount( \WP_REST_Request $request ): void {
		if ( 'wptravelengine_update_commission_amount' !== $request->get_param( 'buttonName' ) ) {
			return;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Unauthorized.', 'wp-travel-engine' ) ) );
			return;
		}

		if ( ! function_exists( 'slicewp_update_commission' ) ) {
			wp_send_json_error( array( 'message' => __( 'SliceWP is not active.', 'wp-travel-engine' ) ) );
			return;
		}

		$commission_id = (int) $request->get_param( 'commission_id' );
		$amount        = (float) $request->get_param( 'amount' );

		if ( slicewp_update_commission( $commission_id, array( 'amount' => $amount ) ) ) {
			wp_send_json_success( array( 'message' => __( 'Commission amount updated.', 'wp-travel-engine' ) ) );
		} else {
			wp_send_json_error( array( 'message' => __( 'Could not update the commission amount.', 'wp-travel-engine' ) ) );
		}
	}
}
