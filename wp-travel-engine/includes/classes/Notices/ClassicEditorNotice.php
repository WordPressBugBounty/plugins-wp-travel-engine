<?php
/**
 * Classic Editor compatibility notice.
 *
 * @package WPTravelEngine
 * @since 6.8.6
 */
namespace WPTravelEngine\Notices;

use WP_Post;

class ClassicEditorNotice {

	const DISMISS_META_KEY = 'wptravelengine_classic_editor_notice_dismissed';
	const DISMISS_ACTION   = 'wptravelengine_dismiss_classic_editor_notice';

	public function __construct() {
		if ( ! is_admin() || ! $this->is_classic_editor_active() ) {
			return;
		}

		add_action( 'admin_notices', array( $this, 'display' ) );
		add_action( 'edit_form_after_title', array( $this, 'display_inline' ) );
		add_action( 'wp_ajax_' . self::DISMISS_ACTION, array( $this, 'handle_dismiss' ) );
	}

	/**
	 * Check if classic editor is active.
	 *
	 * @return bool
	 */
	private function is_classic_editor_active(): bool {
		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		return is_plugin_active( 'classic-editor/classic-editor.php' );
	}

	/**
	 * Dismissible notice rendered on admin screens other than the trip editor.
	 *
	 * @return void
	 */
	public function display(): void {
		$screen = get_current_screen();

		if ( $screen && 'trip' === $screen->id ) {
			return;
		}

		if ( get_user_meta( get_current_user_id(), self::DISMISS_META_KEY, true ) ) {
			return;
		}

		$this->render( 'notice notice-error is-dismissible wptravelengine-classic-editor-notice' );

		$data = array(
			'url'    => admin_url( 'admin-ajax.php' ),
			'action' => self::DISMISS_ACTION,
			'nonce'  => wp_create_nonce( self::DISMISS_ACTION ),
		);

		?>
		<script>
		( function () {
			var config = <?php echo wp_json_encode( $data ); ?>;

			document.addEventListener( 'click', function ( event ) {
				var button = event.target.closest( '.wptravelengine-classic-editor-notice .notice-dismiss' );

				if ( ! button ) {
					return;
				}

				window.fetch( config.url, {
					method: 'POST',
					credentials: 'same-origin',
					body: new URLSearchParams( { action: config.action, nonce: config.nonce } )
				} );
			} );
		} )();
		</script>
		<?php
	}

	/**
	 * Inline notice rendered inside the trip editor, below the title.
	 *
	 * @return void
	 */
	public function display_inline( $post = null ): void {
		if ( ! $post instanceof WP_Post || 'trip' !== $post->post_type ) {
			return;
		}

		$this->render( 'notice notice-error inline' );
	}

	/**
	 * Render notice.
	 *
	 * @return void
	 */
	private function render( string $classes ): void {
		$deactivate_url = wp_nonce_url(
			admin_url( 'plugins.php?action=deactivate&plugin=classic-editor%2Fclassic-editor.php' ),
			'deactivate-plugin_classic-editor/classic-editor.php'
		);
		?>
		<div class="<?php echo esc_attr( $classes ); ?>">
			<p>
				<?php esc_html_e( 'Classic Editor Plugin causes conflicts with WP Travel Engine. Please deactivate Classic Editor to avoid issues.', 'wp-travel-engine' ); ?>
				<a href="<?php echo esc_url( $deactivate_url ); ?>"><?php esc_html_e( 'Deactivate Classic Editor Plugin', 'wp-travel-engine' ); ?></a>
			</p>
		</div>
		<?php
	}

	/**
	 * Handles dismiss.
	 *
	 * @return void
	 */
	public function handle_dismiss(): void {
		check_ajax_referer( self::DISMISS_ACTION, 'nonce' );
		update_user_meta( get_current_user_id(), self::DISMISS_META_KEY, true );
		wp_send_json_success();
	}
}
