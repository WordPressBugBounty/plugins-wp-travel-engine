<?php
/**
 * Displays a translation-related admin notice on the WP Travel Engine settings page.
 *
 * Shows a plugin-specific compatibility message when a known translation plugin
 * (WPML, TranslatePress, Polylang, Loco Translate) is active, or a language notice
 * when the site language is non-English and no translation plugin is detected.
 *
 * @package WPTravelEngine
 * @since 6.8.7
 */

namespace WPTravelEngine\Notices;

use WPTravelEngine\Abstracts\Notice;
use WPTravelEngine\Helpers\Translators;

class LabelsTranslationNotice extends Notice {

	/**
	 * @inheritDoc
	 */
	protected function dismiss_action(): string {
		return 'wptravelengine_dismiss_translation_notice';
	}

	/**
	 * @inheritDoc
	 */
	protected function meta_key(): string {
		return 'wpte_dismissed_translation_notices';
	}

	/**
	 * Only render on the WTE settings page, and only for users who can manage it.
	 *
	 * @inheritDoc
	 */
	protected function can_view(): bool {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return wptravelengine_curr_user_can( 'manage_wte_settings' );
	}

	/**
	 * Bolds the message text in addition to the base dismissible-notice markup.
	 *
	 * @inheritDoc
	 */
	protected function render( string $key, string $message ): void {
		?>
		<div
			class="<?php echo esc_attr( $this->notice_classes() ); ?>"
			data-notice-key="<?php echo esc_attr( $key ); ?>"
			data-dismiss-action="<?php echo esc_attr( $this->dismiss_action() ); ?>"
			data-nonce="<?php echo esc_attr( wp_create_nonce( $this->dismiss_action() ) ); ?>"
		>
			<p style="font-weight: 600;"><?php echo wp_kses_post( $message ); ?></p>
		</div>
		<?php
	}

	/**
	 * Returns all applicable notice messages based on active translation plugins or site language.
	 *
	 * Each active translation plugin produces its own notice. The language notice
	 * is only shown when no translation plugin is detected.
	 *
	 * @inheritDoc
	 */
	protected function get_notices(): array {
		$messages = array();

		if ( defined( 'ICL_SITEPRESS_VERSION' ) ) {
			$messages['wpml'] = sprintf(
				/* translators: %s: URL to WPML guide */
				__( 'WPML is compatible with WP Travel Engine. To learn more on how translation works, read our <a href="%s" target="_blank" rel="noopener noreferrer">guide</a>.', 'wp-travel-engine' ),
				'https://docs.wptravelengine.com/article/build-a-multilingual-travel-website/'
			);
		}

		if ( Translators::is_translatepress_active() ) {
			$messages['translatepress'] = sprintf(
				/* translators: %s: URL to TranslatePress guide */
				__( 'TranslatePress is compatible with WP Travel Engine. To learn more on how translation works, read our <a href="%s" target="_blank" rel="noopener noreferrer">guide</a>.', 'wp-travel-engine' ),
				'https://docs.wptravelengine.com/article/how-to-use-translatepress-with-wp-travel-engine/'
			);
		}

		if ( function_exists( 'pll_current_language' ) ) {
			$messages['polylang'] = __( 'Polylang is compatible with WP Travel Engine.', 'wp-travel-engine' );
		}

		if ( function_exists( 'loco_plugin_file' ) ) {
			$messages['loco'] = sprintf(
				/* translators: %s: URL to Loco Translate guide */
				__( 'Loco Translate is compatible with WP Travel Engine. To learn more on how translation works, read our <a href="%s" target="_blank" rel="noopener noreferrer">guide</a>.', 'wp-travel-engine' ),
				'https://docs.wptravelengine.com/article/how-to-translate-themes-and-plugins-using-loco-translate/'
			);
		}

		if ( empty( $messages ) ) {
			$locale = get_locale();
			if ( strpos( $locale, 'en_' ) !== 0 ) {
				$language_name = function_exists( 'locale_get_display_language' )
					? locale_get_display_language( $locale, 'en' )
					: $locale;

				$messages['language'] = sprintf(
					/* translators: 1: site language name, 2: URL to translation guide */
					__( 'We noticed your site language is %1$s. We have created a translation guide to make it easier for you. <a href="%2$s" target="_blank" rel="noopener noreferrer">Learn how to translate</a>.', 'wp-travel-engine' ),
					'<strong>' . esc_html( $language_name ) . '</strong>',
					'https://docs.wptravelengine.com/article/how-to-translate-themes-and-plugins-using-loco-translate/'
				);
			}
		}

		return $messages;
	}
}
