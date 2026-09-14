<?php
/**
 * Base REST controller for payment gateway credential verification.
 *
 * @package WP Travel Engine
 * @since 6.8.8
 */

namespace WPTravelEngine\PaymentGateways;

use WP_Error;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Backs the PAYMENT_CREDENTIALS settings field's "Verify Connection" actions.
 *
 * A gateway extends this, declares its route namespace and its checks, and gets the
 * response envelope the settings field expects: { success, message }.
 *
 * Credentials are posted from the settings form, not read from saved options, so a
 * merchant can verify before saving. Gateways must never verify from the browser
 * directly against their provider — secret keys belong server-side only.
 *
 * Example:
 *
 *     class Verify_Connection extends CredentialsVerification {
 *         protected function route_namespace(): string {
 *             return 'wte-stripe/v1';
 *         }
 *         protected function checks(): array {
 *             return array( 'verify-keys' => 'verify_keys' );
 *         }
 *         public function verify_keys( WP_REST_Request $request ) {
 *             // ... return $this->success( '...' ) or $this->failed( '...' )
 *         }
 *     }
 *
 * @since 6.8.8
 */
abstract class CredentialsVerification {

	/**
	 * REST namespace for this gateway's checks, e.g. 'wte-stripe/v1'.
	 *
	 * @return string
	 */
	abstract protected function route_namespace(): string;

	/**
	 * Checks this gateway exposes, as route => callback method on this class.
	 *
	 * The route is what the PHP settings field passes as the group's `verify.endpoint`,
	 * prefixed with the namespace: '/wte-stripe/v1/verify-keys'.
	 *
	 * @return array<string, string>
	 */
	abstract protected function checks(): array;

	/**
	 * Register hooks.
	 */
	public function hooks() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Register a route per declared check.
	 */
	public function register_routes() {
		foreach ( $this->checks() as $route => $callback ) {
			if ( ! method_exists( $this, $callback ) ) {
				_doing_it_wrong(
					__METHOD__,
					sprintf(
						/* translators: 1: gateway class name, 2: missing method name. */
						esc_html__( '%1$s declares the check "%2$s" but does not define that method.', 'wp-travel-engine' ),
						esc_html( static::class ),
						esc_html( $callback )
					),
					'next'
				);
				continue;
			}

			register_rest_route(
				$this->route_namespace(),
				'/' . ltrim( $route, '/' ),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, $callback ),
					'permission_callback' => array( $this, 'permission_check' ),
				)
			);
		}
	}

	/**
	 * Only settings-capable users may verify, since checks send live credentials upstream.
	 *
	 * @return bool|WP_Error
	 */
	public function permission_check() {
		if ( wptravelengine_curr_user_can( 'manage_wte_settings' ) ) {
			return true;
		}

		return new WP_Error(
			'wptravelengine_rest_forbidden',
			__( 'You are not allowed to verify payment credentials.', 'wp-travel-engine' ),
			array( 'status' => rest_authorization_required_code() )
		);
	}

	/**
	 * A passing check.
	 *
	 * @param string $message Shown to the merchant.
	 * @param array  $data Optional extras the settings field acts on: 'fills' => [ response key
	 *                     => form field name ], to write returned values back into the form.
	 *
	 * @return WP_REST_Response
	 */
	protected function success( string $message, array $data = array() ): WP_REST_Response {
		return new WP_REST_Response(
			array_merge(
				array(
					'success' => true,
					'message' => $message,
				),
				$data
			)
		);
	}

	/**
	 * A failing check.
	 *
	 * @param string $message Shown to the merchant. Keep it to one line.
	 * @param array  $data Optional extras the settings field acts on.
	 *
	 * @return WP_REST_Response
	 */
	protected function failed( string $message, array $data = array() ): WP_REST_Response {
		return new WP_REST_Response(
			array_merge(
				array(
					'success' => false,
					'message' => $message,
				),
				$data
			)
		);
	}
}
