<?php
/**
 * Onboarding Save Controller.
 *
 * @package WPTravelEngine/Core/Controllers/Ajax
 * @since 6.8.3
 */

namespace WPTravelEngine\Core\Controllers\Ajax;

use WPTravelEngine\Abstracts\AjaxController;
use WPTravelEngine\Core\Models\Settings\PermalinkSettings;
use WPTravelEngine\Core\Models\Settings\PluginSettings;

/**
 * Saves trip permalink settings submitted from the onboarding wizard.
 */
class OnboardingSave extends AjaxController {

	const NONCE_KEY    = 'nonce';
	const NONCE_ACTION = 'wptravelengine-onboarding';
	const ACTION       = 'wptravelengine_save_onboarding_settings';
	const ALLOW_NOPRIV = false;

	/**
	 * Field map: JS key => wp_travel_engine_permalinks option key.
	 */
	private const FIELD_MAP = array(
		'tripBase'            => 'wp_travel_engine_trip_base',
		'tripDestinationBase' => 'wp_travel_engine_destination_base',
		'tripActivityBase'    => 'wp_travel_engine_activity_base',
		'tripTypeBase'        => 'wp_travel_engine_trip_type_base',
		'tripTagBase'         => 'wp_travel_engine_tags_base',
		'tripDifficulty'      => 'wp_travel_engine_difficulty_base',
	);

	/**
	 * Verify nonce and capability.
	 *
	 * @since 6.8.7 Also allow manage_wte_settings, not just manage_options.
	 */
	protected function authorize_request() {
		parent::authorize_request();

		if ( ! wptravelengine_curr_user_can( 'manage_wte_settings' ) ) {
			wp_send_json_error( array( 'message' => __( 'Unauthorized', 'wp-travel-engine' ) ) );
		}

		return true;
	}

	/**
	 * Process request.
	 */
	protected function process_request() {
		$permalink_settings = PermalinkSettings::make();

		foreach ( self::FIELD_MAP as $js_key => $option_key ) {
			$value = $this->request->get_param( $js_key );
			if ( null !== $value ) {
				$permalink_settings->set( $option_key, trim( wte_clean( wp_unslash( $value ) ) ) );
			}
		}

		$permalink_settings->save();
		update_option( 'wptravelengine_queue_flush_rewrite_rules', 'yes' );

		$currency = $this->request->get_param( 'currency' );
		if ( null !== $currency ) {
			$plugin_settings = PluginSettings::make();
			$plugin_settings->set( 'currency_code', wte_clean( wp_unslash( $currency ) ) );
			$plugin_settings->save();
		}

		wp_send_json_success( array( 'message' => __( 'Saved', 'wp-travel-engine' ) ) );
	}
}
