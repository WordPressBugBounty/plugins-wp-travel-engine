<?php
/**
 * Wrapper for WP Session Manager.
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WP Session Manager wraper.
 */
class WTE_Session {
	/**
	 * Holds session data.
	 *
	 * @var array
	 */
	private $session;

	/**
	 * Constructor function.
	 */
	public function __construct() {
		// Let users change the session cookie name.
		if ( ! defined( 'WP_TRAVEL_ENGINE_SESSION_COOKIE' ) ) {
			define( 'WP_TRAVEL_ENGINE_SESSION_COOKIE', 'wordpress_wp_travel_engine_session' );
		}

		if ( empty( $this->session ) ) { // on page load or refresh.
			add_action( 'plugins_loaded', array( $this, 'init' ), - 1 );
		}
	}

	/**
	 * Setup the WP_Session instance
	 *
	 * @access public
	 * @return array|void
	 * @since 1.5
	 * @since 6.8.2 Skip eager creation for visitors without an existing session cookie (#2507).
	 */
	public function init() {
		if ( ! isset( $_COOKIE[ WP_TRAVEL_ENGINE_SESSION_COOKIE ] ) ) {
			return;
		}
		$this->session = WP_Session::get_instance();

		return $this->session;
	}

	/**
	 * Ensure a session exists, creating one if needed. Called before any write operation.
	 *
	 * @since 6.8.2
	 */
	private function ensure_session() {
		if ( empty( $this->session ) ) {
			$this->session = WP_Session::get_instance();
		}
	}

	/**
	 * Get session data.
	 *
	 * @param string $key session data key.
	 *
	 * @return mixed      session data.
	 */
	public function get( string $key ) {
		if ( empty( $this->session ) ) {
			return false;
		}
		$key   = sanitize_key( $key );
		$value = $this->session[ $key ] ?? null;

		if ( null === $value ) {
			return false;
		}

		if ( is_string( $value ) ) {
			$decoded = json_decode( $value, true );
			if ( JSON_ERROR_NONE === json_last_error() && is_array( $decoded ) ) {
				return $decoded;
			}
		}

		return wptravelengine_maybe_unserialize( $value );
	}

	/**
	 * @since 6.3.3
	 */
	public function set_json( $key, $value ) {
		$this->ensure_session();
		$key = sanitize_key( $key );
		if ( is_array( $value ) ) {
			$this->session[ $key ] = wp_json_encode( $value );
		} else {
			$this->session[ $key ] = $value;
		}
	}

	/**
	 * Set data in session.
	 *
	 * @param string $key session data key.
	 * @param mixed  $value session data.
	 *
	 * @return mixed
	 */
	public function set( $key, $value ) {
		$this->ensure_session();
		$key = sanitize_key( $key );
		if ( is_array( $value ) ) {
			$this->session[ $key ] = serialize( $value );
		} else {
			$this->session[ $key ] = $value;
		}

		return $this->session[ $key ];
	}

	/**
	 * delete data in session.
	 *
	 * @param string $key session data key.
	 *
	 * @return boolean
	 */
	public function delete( $key ) {
		if ( empty( $this->session ) ) {
			return true;
		}
		$key = sanitize_key( $key );
		unset( $this->session[ $key ] );

		return ! isset( $this->session[ $key ] );
	}

	/**
	 * Destroy the session: clear DB records and expire the browser cookie.
	 * Called after booking confirmation so subsequent page visits are not blocked from cache.
	 *
	 * @since 6.8.2
	 */
	public function destroy() {
		if ( ! empty( $this->session ) ) {
			$session_id = $this->session->session_id;
			$this->session->reset();
			delete_option( "_wp_session_{$session_id}" );
			delete_option( "_wp_session_expires_{$session_id}" );
			$this->session = null;
		}

		if ( isset( $_COOKIE[ WP_TRAVEL_ENGINE_SESSION_COOKIE ] ) ) {
			setcookie( WP_TRAVEL_ENGINE_SESSION_COOKIE, '', time() - YEAR_IN_SECONDS, COOKIEPATH, COOKIE_DOMAIN );
			unset( $_COOKIE[ WP_TRAVEL_ENGINE_SESSION_COOKIE ] );
		}
	}
}
