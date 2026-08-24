<?php
/**
 * Capability taxonomy registrar.
 *
 * @package WPTravelEngine/Core
 * @since 6.8.7
 */

namespace WPTravelEngine\Core;

use WP_Role;
use WPTravelEngine\Traits\Singleton;
use WPTravelEngine\Core\RoleManagers\Members;

/**
 * Capability taxonomy registrar.
 */
class Capabilities {

	use Singleton;

	const POST_TYPE_GROUPS = array(
		'trip'     => array(
			'post_type' => 'trip',
			'view'      => 'read_wte_trip',
			'manage'    => 'manage_wte_trip',
			'label'     => 'Trip',
		),
		'booking'  => array(
			'post_type' => 'booking',
			'view'      => 'read_wte_booking',
			'manage'    => 'manage_wte_booking',
			'label'     => 'Booking',
		),
		'enquiry'  => array(
			'post_type' => 'enquiry',
			'view'      => 'read_wte_enquiry',
			'manage'    => 'manage_wte_enquiry',
			'label'     => 'Enquiry',
		),
		'customer' => array(
			'post_type' => 'customer',
			'view'      => 'read_wte_customer',
			'manage'    => 'manage_wte_customer',
			'label'     => 'Customer',
		),
		'coupon'   => array(
			'post_type' => 'wte-coupon',
			'view'      => 'read_wte_coupon',
			'manage'    => 'manage_wte_coupon',
			'label'     => 'Coupon',
		),
	);

	/** Capability slugs with no post type behind them, keyed to their Members-facing label. */
	const DIRECT_CAPS = array(
		'manage_wte_settings' => 'Settings: Manage',
		'view_wte_analytics'  => 'Analytics: View',
		'view_wte_logs'       => 'Logs: View',
	);

	const CAP_GROUP = 'wptravelengine';

	/**
	 * Hooks the expansion filter.
	 *
	 * @return void
	 */
	protected function __construct() {
		add_filter( 'user_has_cap', array( $this, 'expand_capabilities' ), 10, 3 );
		add_action( 'admin_head', array( $this, 'hide_submenu' ) );

		Members::register();
	}

	/**
	 * Whether the current user holds the `administrator` role.
	 *
	 * @return bool
	 */
	private static function is_administrator(): bool {
		return in_array( 'administrator', (array) wp_get_current_user()->roles, true );
	}

	/**
	 * Clears each group's primitives first, then re-grants them only if the user holds
	 * `view`/`manage` without an explicit deny — so a raw grant stored on any role (legacy,
	 * or re-added by a role manager form) can never bypass a deny.
	 *
	 * Administrators are force-granted every WTE capability and are immune to explicit deny.
	 *
	 * @param bool[]   $allcaps All the capabilities of the user keyed by capability name.
	 * @param string[] $caps    Unused; required by the `user_has_cap` filter signature.
	 * @param array    $args    Unused; required by the `user_has_cap` filter signature.
	 * @return bool[]
	 */
	public function expand_capabilities( array $allcaps, array $caps, array $args ): array { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		$is_administrator = self::is_administrator();

		foreach ( self::POST_TYPE_GROUPS as $group ) {
			if ( $is_administrator ) {
				$allcaps[ $group['view'] ]   = true;
				$allcaps[ $group['manage'] ] = true;
			}

			$post_type_object = get_post_type_object( $group['post_type'] );

			if ( ! $post_type_object ) {
				continue;
			}

			$primitives = (array) $post_type_object->cap;

			foreach ( $primitives as $primitive ) {
				if ( 'read' !== $primitive ) {
					unset( $allcaps[ $primitive ] );
				}
			}

			if ( ! empty( $allcaps[ $group['manage'] ] ) && ! self::has_explicit_deny( $group['manage'] ) ) {
				foreach ( $primitives as $primitive ) {
					$allcaps[ $primitive ] = true;
				}
			} elseif ( ! empty( $allcaps[ $group['view'] ] ) && ! self::has_explicit_deny( $group['view'] ) ) {
				foreach ( array( $primitives['edit_posts'], $primitives['read_private_posts'] ) as $primitive ) {
					$allcaps[ $primitive ] = true;
				}
			}
		}

		$this->grant_menu_access( $allcaps );

		return $allcaps;
	}

	/**
	 * Checks every role individually (not the merged `allcaps`, where a later role can
	 * silently overwrite an earlier role's deny) for a literal `false` on `$cap`.
	 *
	 * Administrators are exempt from explicit deny.
	 *
	 * @param string $cap Capability slug to check.
	 * @return bool
	 */
	private static function has_explicit_deny( string $cap ): bool {
		if ( self::is_administrator() ) {
			return false;
		}

		$user = wp_get_current_user();

		if ( isset( $user->caps[ $cap ] ) && false === $user->caps[ $cap ] ) {
			return true;
		}

		foreach ( (array) $user->roles as $role_slug ) {
			$role = get_role( $role_slug );

			if ( $role instanceof WP_Role && isset( $role->capabilities[ $cap ] ) && false === $role->capabilities[ $cap ] ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Enquiry, Customer, Coupon, and Settings/Analytics/Logs are submenus under Booking's
	 * own menu; WordPress hides that whole parent menu unless the user also holds Booking's
	 * capability. Back-fills it for anyone holding one of those nested caps without a deny.
	 *
	 * @param bool[] $allcaps All the capabilities of the user keyed by capability name.
	 * @return void
	 */
	private function grant_menu_access( array &$allcaps ): void {
		$nested_under_booking_menu = array( 'enquiry', 'customer', 'coupon' );
		$is_administrator          = self::is_administrator();

		$has_access = false;

		foreach ( $nested_under_booking_menu as $key ) {
			$group = self::POST_TYPE_GROUPS[ $key ];

			if ( ( ! empty( $allcaps[ $group['view'] ] ) && ! self::has_explicit_deny( $group['view'] ) )
				|| ( ! empty( $allcaps[ $group['manage'] ] ) && ! self::has_explicit_deny( $group['manage'] ) ) ) {
				$has_access = true;
				break;
			}
		}

		foreach ( array_keys( self::DIRECT_CAPS ) as $cap ) {
			if ( $is_administrator ) {
				$allcaps[ $cap ] = true;
			}

			if ( ! $has_access && ! empty( $allcaps[ $cap ] ) && ! self::has_explicit_deny( $cap ) ) {
				$has_access = true;
				break;
			}
		}

		if ( ! $has_access ) {
			return;
		}

		$booking = get_post_type_object( self::POST_TYPE_GROUPS['booking']['post_type'] );

		if ( $booking ) {
			$allcaps[ $booking->cap->edit_posts ] = true;
		}
	}

	/**
	 * Cosmetic hide only. Booking's "Add New" is already removed at the source via
	 * `Booking::modify_submenu()`; Trip still relies on its class-tagged item here.
	 *
	 * @return void
	 */
	public function hide_submenu(): void {
		if ( ! self::user_can( 'manage_wte_booking' ) ) {
			$selector = '<style>body.post-type-booking .page-title-action';
			if ( ! self::user_can( 'read_wte_booking' ) ) {
				$selector .= ', #menu-posts-booking ul li.wpte-submenu-bookings';
			}
			$selector .= '{ display: none; } </style>';

			echo $selector; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}

		if ( ! self::user_can( 'manage_wte_trip' ) ) {
			$selector = '<style>body.post-type-trip .page-title-action';
			if ( ! self::user_can( 'read_wte_trip' ) ) {
				$selector .= ', #menu-posts-trip ul li.wpte-submenu-trips';
			}
			$selector .= '{ display: none; } </style>';

			echo $selector; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
	}

	/**
	 * Falls back to `manage_options`, unless `$cap` is explicitly denied.
	 *
	 * @param string $cap One of the DIRECT_CAPS slugs.
	 * @return bool
	 */
	public static function user_can( string $cap ): bool {
		if ( self::has_explicit_deny( $cap ) ) {
			return false;
		}

		return current_user_can( $cap ) || current_user_can( 'manage_options' );
	}

	/**
	 * Grants the full taxonomy to Administrator/Editor.
	 *
	 * @return void
	 */
	public static function grant_defaults(): void {
		foreach ( array( 'administrator', 'editor' ) as $role_slug ) {
			$role = get_role( $role_slug );

			if ( ! $role instanceof WP_Role ) {
				continue;
			}

			self::strip_legacy_primitives( $role );

			foreach ( self::POST_TYPE_GROUPS as $group ) {
				$role->add_cap( $group['view'] );
				$role->add_cap( $group['manage'] );
			}

			if ( 'administrator' === $role_slug ) {
				foreach ( array_keys( self::DIRECT_CAPS ) as $cap ) {
					$role->add_cap( $cap );
				}
			}
		}
	}

	/**
	 * Removes every WTE capability slug from `$_role`. Refuses to act on `administrator`
	 * regardless of what's passed — WTE capabilities are never denied to Administrator.
	 *
	 * @param string $_role Role name.
	 *
	 * @return void
	 */
	public static function remove_all( string $_role ): void {
		if ( 'administrator' === $_role ) {
			return;
		}

		$role = get_role( $_role );

		if ( ! $role instanceof WP_Role ) {
			return;
		}

		foreach ( self::POST_TYPE_GROUPS as $group ) {
			$role->remove_cap( $group['view'] );
			$role->remove_cap( $group['manage'] );
		}

		foreach ( array_keys( self::DIRECT_CAPS ) as $cap ) {
			$role->remove_cap( $cap );
		}
	}

	/**
	 * Removes any raw primitives (e.g. `edit_bookings`) stored directly on the role from
	 * older activations, so `expand_capabilities()` stays their only source.
	 *
	 * @param WP_Role $role Role to strip.
	 * @return void
	 */
	private static function strip_legacy_primitives( WP_Role $role ): void {
		foreach ( self::POST_TYPE_GROUPS as $group ) {
			$post_type_object = get_post_type_object( $group['post_type'] );

			if ( ! $post_type_object ) {
				continue;
			}

			foreach ( (array) $post_type_object->cap as $primitive ) {
				if ( ! in_array( $primitive, array( 'read' ), true ) ) {
					$role->remove_cap( $primitive );
				}
			}
		}
	}
}
