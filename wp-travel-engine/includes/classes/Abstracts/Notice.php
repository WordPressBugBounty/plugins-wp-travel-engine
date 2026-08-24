<?php
/**
 * Class Notice.
 *
 * @package WPTravelEngine\Abstracts
 * @since 6.8.7
 */

namespace WPTravelEngine\Abstracts;

/**
 * Base class for an `admin_notices` notice. When `DISMISSIBLE` is true (the default),
 * each notice item can be dismissed independently and stays dismissed, persisted
 * per-user via user meta and an ajax callback.
 */
abstract class Notice {

	/**
	 * Whether this notice's items can be dismissed and stay dismissed.
	 */
	const DISMISSIBLE = true;

	/**
	 * Notice type; one of the `TYPE_*` constants above. Determines the `notice-*` CSS class.
	 */
	const TYPE = 'info';

	/**
	 * Whether the shared dismiss script has already been printed on this page load.
	 */
	private static bool $script_printed = false;

	/**
	 * Hooks the notice display and, if dismissible, its dismiss handler.
	 *
	 * @return void
	 */
	public function __construct() {
		add_action( 'admin_notices', array( $this, 'display' ) );

		if ( static::DISMISSIBLE ) {
			add_action( 'wp_ajax_' . $this->dismiss_action(), array( $this, 'handle_dismiss' ) );
		}
	}

	/**
	 * Ajax action name used to persist a dismissal. Must be unique across the plugin.
	 * Only used when `DISMISSIBLE` is true.
	 *
	 * @return string
	 */
	abstract protected function dismiss_action(): string;

	/**
	 * User meta key storing the list of dismiss keys this user already dismissed.
	 * Only used when `DISMISSIBLE` is true.
	 *
	 * @return string
	 */
	abstract protected function meta_key(): string;

	/**
	 * Notice messages keyed by a stable dismiss key. Each key is dismissed independently.
	 *
	 * @return string[]
	 */
	abstract protected function get_notices(): array;

	/**
	 * Whether this notice should render for the current request — capability, current
	 * admin page/screen, or any other condition. Override to gate on one or more of these.
	 *
	 * @return bool
	 */
	protected function can_view(): bool {
		return true;
	}

	/**
	 * Renders every not-yet-dismissed notice, then the shared dismiss script.
	 *
	 * @return void
	 */
	public function display(): void {
		if ( ! $this->can_view() ) {
			return;
		}

		$notices = $this->get_notices();

		if ( static::DISMISSIBLE ) {
			$dismissed = (array) get_user_meta( get_current_user_id(), $this->meta_key(), true );
			$notices   = array_diff_key( $notices, array_flip( $dismissed ) );
		}

		if ( empty( $notices ) ) {
			return;
		}

		foreach ( $notices as $key => $message ) {
			$this->render( $key, $message );
		}

		if ( static::DISMISSIBLE ) {
			$this->render_dismiss_script();
		}
	}

	/**
	 * Builds the `class` attribute for a notice `<div>` from `TYPE` and `DISMISSIBLE`.
	 *
	 * @return string
	 */
	protected function notice_classes(): string {
		return 'notice notice-' . static::TYPE . ( static::DISMISSIBLE ? ' is-dismissible wpte-dismissible-notice' : '' );
	}

	/**
	 * Renders a single notice. Override to change markup.
	 *
	 * Dismiss action and nonce are embedded per-element (not per-script) so the one
	 * shared dismiss script correctly handles every notice on the page, regardless
	 * of how many `Notice` subclasses rendered one.
	 *
	 * @param string $key     Dismiss key for this notice.
	 * @param string $message Notice message (may contain safe HTML; passed through `wp_kses_post()`).
	 * @return void
	 */
	protected function render( string $key, string $message ): void {
		?>
		<div
			class="<?php echo esc_attr( $this->notice_classes() ); ?>"
			data-notice-key="<?php echo esc_attr( $key ); ?>"
			<?php if ( static::DISMISSIBLE ) : ?>
			data-dismiss-action="<?php echo esc_attr( $this->dismiss_action() ); ?>"
			data-nonce="<?php echo esc_attr( wp_create_nonce( $this->dismiss_action() ) ); ?>"
			<?php endif; ?>
		>
			<p><?php echo wp_kses_post( $message ); ?></p>
		</div>
		<?php
	}

	/**
	 * Emits the shared dismiss-handling script once per page load, regardless of how
	 * many `Notice` subclasses render. Uses event delegation on `document` and reads
	 * the action/nonce/key off the clicked notice's own data attributes, so a single
	 * script correctly serves every dismissible notice on the page.
	 *
	 * @return void
	 */
	private function render_dismiss_script(): void {
		if ( self::$script_printed ) {
			return;
		}

		self::$script_printed = true;
		?>
		<script>
		( function () {
			document.addEventListener( 'click', function ( event ) {
				if ( ! event.target.classList.contains( 'notice-dismiss' ) ) {
					return;
				}
				var notice = event.target.closest( '.wpte-dismissible-notice' );
				if ( ! notice ) {
					return;
				}
				var body = new URLSearchParams();
				body.set( 'action', notice.getAttribute( 'data-dismiss-action' ) );
				body.set( '_ajax_nonce', notice.getAttribute( 'data-nonce' ) );
				body.set( 'key', notice.getAttribute( 'data-notice-key' ) );
				fetch( ajaxurl, { method: 'POST', credentials: 'same-origin', body: body } );
			} );
		} )();
		</script>
		<?php
	}

	/**
	 * Persists a dismissal for the posted `key` against the current user.
	 * Only reachable when `DISMISSIBLE` is true (the hook isn't registered otherwise).
	 *
	 * @return void
	 */
	public function handle_dismiss(): void {
		check_ajax_referer( $this->dismiss_action() );

		if ( ! $this->can_view() ) {
			wp_send_json_error( null, 403 );
		}

		$key = isset( $_POST['key'] ) ? sanitize_key( wp_unslash( $_POST['key'] ) ) : '';

		if ( '' === $key ) {
			wp_send_json_error();
		}

		$dismissed = (array) get_user_meta( get_current_user_id(), $this->meta_key(), true );

		if ( ! in_array( $key, $dismissed, true ) ) {
			$dismissed[] = $key;
			update_user_meta( get_current_user_id(), $this->meta_key(), $dismissed );
		}

		wp_send_json_success();
	}
}
