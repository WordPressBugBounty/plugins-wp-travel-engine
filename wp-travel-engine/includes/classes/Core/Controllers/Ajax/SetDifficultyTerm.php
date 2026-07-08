<?php
/**
 * Set Difficulty Term Controller.
 *
 * @package WPTravelEngine/Core/Controllers
 * @since 6.0.0
 */

namespace WPTravelEngine\Core\Controllers\Ajax;

use WPTravelEngine\Abstracts\AjaxController;

/**
 * Handles to set difficulty term ajax request.
 */
class SetDifficultyTerm extends AjaxController {

	const NONCE_KEY    = '_nonce';
	const NONCE_ACTION = 'wp_xhr';
	const ACTION       = 'wte_set_difficulty_term_level';
	const ALLOW_NOPRIV = false;

	/**
	 * Process Request.
	 *
	 * @since 5.5.7
	 */
	public function process_request() {
		$post_data = $this->request->get_params();
		$term_id   = absint( $post_data['term_id'] ?? 0 );
		$level     = sanitize_text_field( $post_data['level'] ?? '' );

		$term = get_term( $term_id );
		if ( ! $term || is_wp_error( $term ) ) {
			wp_send_json_error( new \WP_Error( 'invalid_term', __( 'Invalid term.', 'wp-travel-engine' ) ), 400 );
			return;
		}

		// save in options.
		$difficulty_level           = get_option( 'difficulty_level_by_terms', array() );
		$difficulty_level[ $level ] = array(
			'level'   => $level,
			'term_id' => $term_id,
			'label'   => $term->name,
		);
		foreach ( $difficulty_level as $key => $val ) {
			if ( ( $level != $key && $term_id === $val['term_id'] ) || ( '' === $level && $term_id === $val['term_id'] ) ) {
				unset( $difficulty_level[ $key ] );
			}
		}
		if ( array_key_exists( 'Select Level', $difficulty_level ) ) {
			unset( $difficulty_level['Select Level'] );
		}
		update_option( 'difficulty_level_by_terms', $difficulty_level );
		wp_send_json_success( $difficulty_level );
	}

	/**
	 * @inheritDoc
	 * @since 6.8.2
	 */
	protected function authorize_request() {
		parent::authorize_request();

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error(
				new \WP_Error( 'insufficient_permissions', __( 'You do not have permission to perform this action.', 'wp-travel-engine' ) ),
				403
			);
		}

		return true;
	}
}
