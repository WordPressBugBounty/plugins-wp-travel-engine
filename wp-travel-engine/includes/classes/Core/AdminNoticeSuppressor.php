<?php
/**
 * Central admin notice suppression.
 *
 * @package WPTravelEngine/Core
 * @since 6.9.0
 */

namespace WPTravelEngine\Core;

use WP_Screen;
use WPTravelEngine\Traits\Singleton;

/**
 * Hides third-party admin notices on WP Travel Engine screens, matched by a filterable rule set.
 *
 * Notices registered at priority 11111 or later, or re-added on `wptravelengine_admin_notices_suppressed`,
 * survive a rule with `keep_from`; add-ons that want theirs shown use one of the two.
 */
class AdminNoticeSuppressor {

	use Singleton;

	/**
	 * Notice hooks cleared when a rule does not name its own.
	 */
	private const DEFAULT_HOOKS = array( 'admin_notices', 'all_admin_notices' );

	/**
	 * Hooks in on `in_admin_header`, right before the notices print and after they are all registered.
	 */
	protected function __construct() {
		add_action( 'in_admin_header', array( $this, 'suppress_notices' ), 5 );
	}

	/**
	 * Clears the notice hooks named by the first rule matching this screen.
	 *
	 * @return void
	 */
	public function suppress_notices(): void {
		$screen = get_current_screen();

		if ( ! $screen instanceof WP_Screen ) {
			return;
		}

		/**
		 * Rules for where notices are hidden. The first rule that matches wins.
		 *
		 * - `screen`    Screen ID, or list of IDs, to match.
		 * - `query`     `$_GET` key => value, or list of values, all of which must match.
		 * - `hooks`     Notice actions to clear; defaults to `admin_notices` and `all_admin_notices`.
		 * - `keep_from` Priority kept from. Without it every notice is cleared and nothing re-registers.
		 *
		 * `screen` and `query` combine with AND; a rule without either matches every screen.
		 *
		 * @param array<int, array<string, mixed>> $rules  Suppression rules.
		 * @param WP_Screen                        $screen Current screen.
		 * @since 6.9.0
		 */
		$rules = apply_filters( 'wptravelengine_suppressed_notice_screens', $this->default_rules(), $screen );

		foreach ( (array) $rules as $rule ) {
			if ( ! \is_array( $rule ) || ! $this->matches( $rule, $screen ) ) {
				continue;
			}

			foreach ( (array) ( $rule['hooks'] ?? self::DEFAULT_HOOKS ) as $hook ) {
				$this->clear( $hook, (int) ( $rule['keep_from'] ?? 0 ) );
			}

			if ( empty( $rule['keep_from'] ) ) {
				return;
			}

			/**
			 * Fires after the notices are cleared; a notice that must still show registers here.
			 *
			 * @param WP_Screen $screen Current screen.
			 * @since 6.9.0
			 */
			do_action( 'wptravelengine_admin_notices_suppressed', $screen );

			return;
		}
	}

	/**
	 * Rules applied when nothing filters them.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	protected function default_rules(): array {
		return array(
			// Single booking: keeps Pro's notices (11111) and the screen's own.
			array(
				'screen'    => 'booking',
				'hooks'     => array( 'admin_notices' ),
				'keep_from' => 11111,
			),
			// Pages of the plugin's post types (`?post_type=…`): keeps Pro's notices (11111) and the plugin's own.
			array(
				'query'     => array( 'post_type' => array( 'booking', 'trip', 'enquiry', 'customer', 'wte-coupon' ) ),
				'hooks'     => array( 'admin_notices' ),
				'keep_from' => 11111,
			),
		);
	}

	/**
	 * Clears a notice hook below a priority.
	 *
	 * @param string $hook      Notice action to clear.
	 * @param int    $keep_from Priority kept from; 0 clears everything.
	 * @return void
	 */
	protected function clear( string $hook, int $keep_from ): void {
		global $wp_filter;

		if ( ! $keep_from || ! isset( $wp_filter[ $hook ] ) ) {
			remove_all_actions( $hook );

			return;
		}

		foreach ( array_keys( $wp_filter[ $hook ]->callbacks ) as $priority ) {
			if ( $priority < $keep_from ) {
				remove_all_actions( $hook, $priority );
			}
		}
	}

	/**
	 * Whether a rule applies to the current request.
	 *
	 * @param array<string, mixed> $rule   Rule to test.
	 * @param WP_Screen            $screen Current screen.
	 * @return bool
	 */
	protected function matches( array $rule, WP_Screen $screen ): bool {
		if ( isset( $rule['screen'] ) && ! \in_array( $screen->id, (array) $rule['screen'], true ) ) {
			return false;
		}

		foreach ( (array) ( $rule['query'] ?? array() ) as $key => $expected ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			if ( ! isset( $_GET[ $key ] ) ) {
				return false;
			}

			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$value = sanitize_text_field( wp_unslash( $_GET[ $key ] ) );

			if ( ! \in_array( $value, array_map( 'strval', (array) $expected ), true ) ) {
				return false;
			}
		}

		return true;
	}
}
