<?php
/**
 * Fetch Demos Ajax Controller.
 *
 * Proxies the rishidemos.com demos API so the onboarding carousel
 * can display real starter sites without CORS issues.
 *
 * @package WPTravelEngine/Core/Controllers/Ajax
 * @since 6.8.3
 */

namespace WPTravelEngine\Core\Controllers\Ajax;

use WPTravelEngine\Abstracts\AjaxController;

/**
 * Fetches starter-site demos for the onboarding wizard carousel.
 */
class FetchDemos extends AjaxController {

	const NONCE_KEY    = 'nonce';
	const NONCE_ACTION = 'wptravelengine-onboarding';
	const ACTION       = 'wptravelengine_fetch_demos';
	const ALLOW_NOPRIV = false;

	/**
	 * Base URL for the remote demo server.
	 */
	private const BASE_URL = 'https://rishidemos.com/wp-json/';

	/**
	 * Verify nonce and capability.
	 */
	protected function authorize_request() {
		parent::authorize_request();

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Unauthorized', 'wp-travel-engine' ) ) );
		}

		return true;
	}

	/**
	 * Process request.
	 */
	protected function process_request() {
		$demos = $this->get_demos();

		if ( is_wp_error( $demos ) ) {
			wp_send_json_error( array( 'message' => $demos->get_error_message() ) );
		}

		wp_send_json_success( array( 'demos' => $demos ) );
	}

	/**
	 * Fetch demos — uses DemoServer if available, falls back to direct HTTP.
	 *
	 * @return array|\WP_Error
	 */
	private function get_demos() {
		$cache_key = 'wptravelengine_onboarding_demos';
		$cached    = get_transient( $cache_key );

		if ( false !== $cached ) {
			return $cached;
		}

		$raw = $this->fetch_from_api(
			self::BASE_URL . 'demoimporterplusapi/v1/dipa-demos/',
			array(
				'per_page' => 20,
				'page'     => 1,
			)
		);

		if ( is_wp_error( $raw ) ) {
			return $raw;
		}

		$items = $raw['data'] ?? $raw;

		if ( ! is_array( $items ) ) {
			return new \WP_Error( 'invalid_response', __( 'Invalid response from demo server.', 'wp-travel-engine' ) );
		}

		$demos = array_values(
			array_map(
				function ( $demo ) {
					$builder = $demo['site_page_builder'] ?? '';
					$label   = ucfirst( $builder );

					return array(
						'id'      => (int) ( $demo['id'] ?? 0 ),
						'title'   => sanitize_text_field( $demo['site_title'] ?? '' ),
						'image'   => esc_url_raw( $demo['site_featured_image'] ?? '' ),
						'builder' => $label,
						'type'    => sanitize_key( $demo['site_type'] ?? 'free' ),
						'url'     => esc_url_raw( $demo['site_url'] ?? '' ),
					);
				},
				$items
			)
		);

		set_transient( $cache_key, $demos, HOUR_IN_SECONDS );

		return $demos;
	}

	/**
	 * Make a remote GET request to the demo API.
	 *
	 * @param string $url  Base URL.
	 * @param array  $args Query args.
	 *
	 * @return array|\WP_Error Decoded JSON or WP_Error.
	 */
	private function fetch_from_api( string $url, array $args = array() ) {
		$request_url = add_query_arg( $args, $url );

		$response = wp_remote_get(
			$request_url,
			array(
				'timeout' => 30,
				'headers' => array( 'Accept' => 'application/json' ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( 200 !== (int) $code ) {
			return new \WP_Error( 'api_error', sprintf( __( 'Demo server returned HTTP %d.', 'wp-travel-engine' ), $code ) );
		}

		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );

		if ( null === $data ) {
			return new \WP_Error( 'json_error', __( 'Could not decode demo server response.', 'wp-travel-engine' ) );
		}

		return $data;
	}
}
