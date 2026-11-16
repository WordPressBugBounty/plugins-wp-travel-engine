<?php
/**
 * User model.
 *
 * @package WPTravelEngine/Abstracts
 * @since 6.9.0
 */

namespace WPTravelEngine\Abstracts;

use InvalidArgumentException;
use WP_User;

/**
 * A model bound to a WordPress user, for records kept in user meta.
 *
 * The counterpart of `PostModel`; a subclass names the meta key it owns.
 *
 * @since 6.9.0
 */
abstract class User {

	/**
	 * The user this model reads.
	 *
	 * @var WP_User
	 */
	public WP_User $user;

	/**
	 * Meta read so far, keyed by meta key.
	 *
	 * @var array<string, mixed>
	 */
	protected array $data = array();

	/**
	 * @param WP_User|int $user User, or a user ID.
	 * @throws InvalidArgumentException When no such user exists.
	 */
	public function __construct( $user ) {
		if ( is_numeric( $user ) ) {
			$user = get_user_by( 'id', (int) $user );
		}

		if ( ! $user instanceof WP_User || ! $user->exists() ) {
			throw new InvalidArgumentException( 'Invalid user' );
		}

		$this->user = $user;
	}

	/**
	 * @return int
	 */
	public function get_id(): int {
		return (int) $this->user->ID;
	}

	/**
	 * @return WP_User The user itself, for the account fields no meta holds.
	 */
	public function get_user(): WP_User {
		return $this->user;
	}

	/**
	 * A single meta value, read once per request.
	 *
	 * @param string $meta_key Meta key.
	 * @return mixed Stored value, or '' when the user has none.
	 */
	public function get_meta( string $meta_key ) {
		if ( ! array_key_exists( $meta_key, $this->data ) ) {
			$this->data[ $meta_key ] = get_user_meta( $this->get_id(), $meta_key, true );
		}

		return $this->data[ $meta_key ];
	}

	/**
	 * Writes a meta value, keeping this model's read cache in step.
	 *
	 * @param string $meta_key   Meta key.
	 * @param mixed  $meta_value Value to store.
	 * @return $this
	 */
	public function update_meta( string $meta_key, $meta_value ) {
		update_user_meta( $this->get_id(), $meta_key, $meta_value );

		$this->data[ $meta_key ] = $meta_value;

		return $this;
	}
}
