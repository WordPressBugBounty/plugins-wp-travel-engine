<?php
/**
 * Onboard Installs Ajax Controller.
 *
 * @package WPTravelEngine/Core/Controllers/Ajax
 * @since 6.8.3
 */

namespace WPTravelEngine\Core\Controllers\Ajax;

use WPTravelEngine\Abstracts\AjaxController;

/**
 * Installs and activates a plugin or theme from the onboarding wizard.
 */
class OnboardInstalls extends AjaxController {

	const NONCE_KEY    = 'nonce';
	const NONCE_ACTION = 'wptravelengine-onboarding';
	const ACTION       = 'wptravelengine_onboard_installs';
	const ALLOW_NOPRIV = false;

	/**
	 * Verify nonce and capability.
	 */
	protected function authorize_request() {
		parent::authorize_request();

		if ( ! current_user_can( 'install_plugins' ) ) {
			wp_send_json_error( array( 'message' => __( 'Unauthorized', 'wp-travel-engine' ) ) );
		}

		return true;
	}

	/**
	 * Process request.
	 */
	protected function process_request() {
		$type = sanitize_key( $this->request->get_param( 'type' ) ?? 'plugin' );
		$slug = sanitize_key( $this->request->get_param( 'slug' ) );

		if ( empty( $slug ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid slug.', 'wp-travel-engine' ) ) );
		}

		if ( 'theme' === $type ) {
			$this->install_theme( $slug );
		} else {
			$this->install_plugin( $slug );
		}
	}

	/**
	 * Install and activate a WordPress.org plugin.
	 *
	 * @param string $slug Plugin slug.
	 */
	private function install_plugin( string $slug ) {
		$plugin_file = $slug . '/' . $slug . '.php';

		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$all_plugins       = get_plugins();
		$installed         = array_key_exists( $plugin_file, $all_plugins );
		$installed_version = $installed ? $all_plugins[ $plugin_file ]['Version'] : null;

		if ( ! function_exists( 'plugins_api' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
		}
		if ( ! class_exists( 'Plugin_Upgrader' ) ) {
			require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		}

		$api = plugins_api(
			'plugin_information',
			array(
				'slug'   => $slug,
				'fields' => array(
					'download_link' => true,
					'version'       => true,
				),
			)
		);

		if ( is_wp_error( $api ) ) {
			wp_send_json_error( array( 'message' => $api->get_error_message() ) );
		}

		$upgrader = new \Plugin_Upgrader( new \WP_Ajax_Upgrader_Skin() );

		if ( ! $installed ) {
			$result = $upgrader->install( $api->download_link );

			if ( is_wp_error( $result ) ) {
				wp_send_json_error( array( 'message' => $result->get_error_message() ) );
			}

			if ( ! $result ) {
				wp_send_json_error( array( 'message' => __( 'Plugin installation failed.', 'wp-travel-engine' ) ) );
			}
		} elseif ( version_compare( $installed_version, $api->version, '<' ) ) {
			$result = $upgrader->upgrade( $plugin_file );

			if ( is_wp_error( $result ) ) {
				wp_send_json_error( array( 'message' => $result->get_error_message() ) );
			}

			if ( ! $result ) {
				wp_send_json_error( array( 'message' => __( 'Plugin update failed.', 'wp-travel-engine' ) ) );
			}
		}

		$activate = activate_plugin( $plugin_file );

		if ( is_wp_error( $activate ) ) {
			wp_send_json_error( array( 'message' => $activate->get_error_message() ) );
		}

		wp_send_json_success(
			array(
				'message'     => __( 'Plugin installed and activated.', 'wp-travel-engine' ),
				'redirectUrl' => admin_url( 'themes.php?page=' . $slug ),
			)
		);
	}

	/**
	 * Install, update if outdated, and activate a WordPress.org theme.
	 *
	 * @param string $slug Theme slug.
	 */
	private function install_theme( string $slug ) {
		if ( ! current_user_can( 'install_themes' ) ) {
			wp_send_json_error( array( 'message' => __( 'Unauthorized', 'wp-travel-engine' ) ) );
		}

		if ( ! function_exists( 'themes_api' ) ) {
			require_once ABSPATH . 'wp-admin/includes/theme.php';
		}
		if ( ! class_exists( 'Theme_Upgrader' ) ) {
			require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		}

		$theme             = wp_get_theme( $slug );
		$installed         = $theme->exists();
		$installed_version = $installed ? $theme->get( 'Version' ) : null;

		$api = themes_api(
			'theme_information',
			array(
				'slug'   => $slug,
				'fields' => array(
					'download_link' => true,
					'version'       => true,
				),
			)
		);

		if ( is_wp_error( $api ) ) {
			wp_send_json_error( array( 'message' => $api->get_error_message() ) );
		}

		$upgrader = new \Theme_Upgrader( new \WP_Ajax_Upgrader_Skin() );

		if ( ! $installed ) {
			$result = $upgrader->install( $api->download_link );

			if ( is_wp_error( $result ) ) {
				wp_send_json_error( array( 'message' => $result->get_error_message() ) );
			}

			if ( ! $result ) {
				wp_send_json_error( array( 'message' => __( 'Theme installation failed.', 'wp-travel-engine' ) ) );
			}
		} elseif ( version_compare( $installed_version, $api->version, '<' ) ) {
			$result = $upgrader->upgrade( $slug );

			if ( is_wp_error( $result ) ) {
				wp_send_json_error( array( 'message' => $result->get_error_message() ) );
			}

			if ( ! $result ) {
				wp_send_json_error( array( 'message' => __( 'Theme update failed.', 'wp-travel-engine' ) ) );
			}
		}

		switch_theme( $slug );

		wp_send_json_success( array( 'message' => __( 'Theme installed and activated.', 'wp-travel-engine' ) ) );
	}
}
