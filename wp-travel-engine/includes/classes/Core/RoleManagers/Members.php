<?php
/**
 * Members plugin capability integration.
 *
 * @package WPTravelEngine/Core/RoleManagers
 * @since 6.8.7
 */
namespace WPTravelEngine\Core\RoleManagers;

use WPTravelEngine\Core\Capabilities;

/**
 * Class Members
 */
class Members {

	/**
	 * Hooks into the Members plugin.
	 *
	 * @return void
	 */
	public static function register(): void {
		$self = new self();

		add_action( 'members_register_cap_groups', array( $self, 'register_cap_group' ) );
		add_action( 'members_register_caps', array( $self, 'register_caps' ) );
	}

	/**
	 * Registers the shared group; unregisters Members' auto per-post-type
	 * groups (registered on the same action at priority 5, before this) so
	 * our post types don't get a second, unlabeled tab.
	 *
	 * @return void
	 */
	public function register_cap_group(): void {
		members_register_cap_group(
			Capabilities::CAP_GROUP,
			array(
				'label' => __( 'WP Travel Engine', 'wp-travel-engine' ),
				'icon'  => 'dashicons-airplane',
			)
		);

		foreach ( Capabilities::POST_TYPE_GROUPS as $group ) {
			members_unregister_cap_group( "type-{$group['post_type']}" );
		}
	}

	/**
	 * Registers every WTE capability slug into the shared group.
	 *
	 * @return void
	 */
	public function register_caps(): void {
		foreach ( Capabilities::POST_TYPE_GROUPS as $group ) {
			members_register_cap(
				$group['view'],
				array(
					'label' => sprintf( '%s: View', $group['label'] ),
					'group' => Capabilities::CAP_GROUP,
				)
			);
			members_register_cap(
				$group['manage'],
				array(
					'label' => sprintf( '%s: Manage', $group['label'] ),
					'group' => Capabilities::CAP_GROUP,
				)
			);
		}

		foreach ( Capabilities::DIRECT_CAPS as $cap => $label ) {
			members_register_cap(
				$cap,
				array(
					'label' => $label,
					'group' => Capabilities::CAP_GROUP,
				)
			);
		}
	}
}
