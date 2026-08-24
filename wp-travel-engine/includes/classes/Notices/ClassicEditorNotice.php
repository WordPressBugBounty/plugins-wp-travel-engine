<?php
/**
 * Classic Editor compatibility notice.
 *
 * @package WPTravelEngine
 * @since 6.8.6
 */
namespace WPTravelEngine\Notices;

use WP_Post;
use WPTravelEngine\Abstracts\Notice;

class ClassicEditorNotice extends Notice {

	/** Dismiss key for the single notice this class renders. */
	const DISMISS_KEY = 'classic-editor';

	const TYPE = 'error';

	public function __construct() {
		if ( ! is_admin() || ! $this->is_classic_editor_active() ) {
			return;
		}

		parent::__construct();

		add_action( 'edit_form_after_title', array( $this, 'display_inline' ) );
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
	 * @inheritDoc
	 */
	protected function dismiss_action(): string {
		return 'wptravelengine_dismiss_classic_editor_notice';
	}

	/**
	 * @inheritDoc
	 */
	protected function meta_key(): string {
		return 'wptravelengine_classic_editor_notice_dismissed';
	}

	/**
	 * @inheritDoc
	 */
	protected function get_notices(): array {
		return array( self::DISMISS_KEY => $this->message() );
	}

	/**
	 * Dismissible notice rendered on admin screens other than the trip editor.
	 *
	 * @inheritDoc
	 */
	protected function can_view(): bool {
		$screen = get_current_screen();

		return ! $screen || 'trip' !== $screen->id;
	}

	/**
	 * Inline notice rendered inside the trip editor, below the title. Always shown
	 * there regardless of dismissal — it's about this specific screen's conflict risk.
	 *
	 * @return void
	 */
	public function display_inline( $post = null ): void {
		if ( ! $post instanceof WP_Post || 'trip' !== $post->post_type ) {
			return;
		}

		?>
		<div class="notice notice-error inline">
			<p><?php echo wp_kses_post( $this->message() ); ?></p>
		</div>
		<?php
	}

	/**
	 * @return string
	 */
	private function message(): string {
		$deactivate_url = wp_nonce_url(
			admin_url( 'plugins.php?action=deactivate&plugin=classic-editor%2Fclassic-editor.php' ),
			'deactivate-plugin_classic-editor/classic-editor.php'
		);

		return sprintf(
			'%s <a href="%s">%s</a>',
			esc_html__( 'Classic Editor Plugin causes conflicts with WP Travel Engine. Please deactivate Classic Editor to avoid issues.', 'wp-travel-engine' ),
			esc_url( $deactivate_url ),
			esc_html__( 'Deactivate Classic Editor Plugin', 'wp-travel-engine' )
		);
	}
}
