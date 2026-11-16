<?php
/**
 * Base for the current-customer ("my account") REST controllers. Each subclass resolves
 * its subject from the session, not the request, so a route can't be pointed at another
 * customer's data.
 *
 * @package WPTravelEngine
 * @since 6.9.0
 */

namespace WPTravelEngine\Abstracts;

use WP_Error;
use WP_REST_Request;
use WPTravelEngine\Utilities\ArrayUtility;

abstract class MyDashboard extends RestController {

	/**
	 * Overridden per-subclass rather than the namespace directly, so it can't desync from
	 * the directory a controller lives under.
	 *
	 * @var string
	 */
	const VERSION = 'v1';

	/** @var string */
	const NAMESPACE_ROOT = 'wptravelengine';

	public function __construct() {
		$this->namespace = static::NAMESPACE_ROOT . '/' . static::VERSION;
		$this->rest_base = 'my';
	}

	/**
	 * Every route under `my` only ever exposes the caller's own data.
	 *
	 * @return true|WP_Error
	 */
	public function logged_in_permissions_check() {
		if ( ! is_user_logged_in() ) {
			return new WP_Error(
				'rest_not_logged_in',
				__( 'You must be logged in to view your account.', 'wp-travel-engine' ),
				array( 'status' => rest_authorization_required_code() )
			);
		}

		return true;
	}

	/**
	 * Unregistered params pass through on purpose: add-ons extend the required-field
	 * filters with their own field names, which have to reach the handler to matter.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array Parameters, minus the route's own and REST internals.
	 */
	protected function writable_params( WP_REST_Request $request ): array {
		$params = $request->get_params();

		unset(
			$params['tab_slug'],
			$params['page'],
			$params['per_page'],
			$params['_locale'],
			$params['_envelope'],
			$params['_embed'],
			$params['_fields'],
			$params['_method'],
			$params['_jsonp']
		);

		return $params;
	}

	/**
	 * What is saved, with what the request sent written over it — so PATCH is genuinely
	 * partial. A key the request did not carry keeps its stored value; one it sent empty is
	 * written empty, so a field can still be cleared.
	 *
	 * @param array $fields  Fields from the request.
	 * @param array $current Currently saved values, keyed by handler field name.
	 * @return array Fields to hand the form handler.
	 */
	protected function fill_absent( array $fields, array $current ): array {
		$values = ArrayUtility::make( $current );

		foreach ( $fields as $key => $value ) {
			$values->set( (string) $key, $value );
		}

		return $values->value();
	}

	/**
	 * Turns a handler WP_Error into a 400 carrying every validation message.
	 *
	 * @param WP_Error $error Error from the form handler.
	 *
	 * @return WP_Error
	 */
	protected function as_rest_error( WP_Error $error ): WP_Error {
		return new WP_Error(
			$error->get_error_code(),
			implode( ' ', $error->get_error_messages() ),
			array(
				'status'   => 400,
				'messages' => $error->get_error_messages(),
			)
		);
	}
}
