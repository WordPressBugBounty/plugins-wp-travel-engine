<?php
/**
 * Emergency contact model.
 *
 * @package WPTravelEngine/Core/Models
 * @since 6.9.0
 */

namespace WPTravelEngine\Core\Models\User;

use InvalidArgumentException;
use WP_User;
use WPTravelEngine\Abstracts\User;

/**
 * The emergency contact a traveler keeps on their account, keyed like the checkout's fields.
 *
 * Prefills the checkout's emergency section; My Profile edits it.
 *
 * @since 6.9.0
 */
class EmergencyContact extends User {

	/**
	 * Meta key the contact is stored under.
	 *
	 * @var string
	 */
	const META_KEY = 'wptravelengine_emergency_contact';

	/**
	 * The contact of a user, or of the current user.
	 *
	 * @param WP_User|int $user User, or user ID; 0 for the current user.
	 * @return EmergencyContact|null Null when no such user exists.
	 */
	public static function get( $user = 0 ): ?self {
		try {
			return new static( $user ? $user : get_current_user_id() );
		} catch ( InvalidArgumentException $e ) {
			return null;
		}
	}

	/**
	 * The stored contact.
	 *
	 * @return array<string, string> Empty when none is saved.
	 */
	public function all(): array {
		$contact = $this->get_meta( static::META_KEY );

		return is_array( $contact ) ? $contact : array();
	}

	/**
	 * Writes values over the stored contact; a key left out keeps what it holds.
	 *
	 * @param array<string, string> $values Values keyed by stored key.
	 * @return $this
	 */
	public function put( array $values ): self {
		return $this->update_meta( static::META_KEY, array_merge( $this->all(), $values ) );
	}
}
