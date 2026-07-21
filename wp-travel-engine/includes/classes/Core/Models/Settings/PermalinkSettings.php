<?php
/**
 * Permalink Settings Model.
 *
 * @package WPTravelEngine/Core/Models/Settings
 * @since 6.8.3
 */

namespace WPTravelEngine\Core\Models\Settings;

use WPTravelEngine\Traits\Factory;

/**
 * Wraps the wp_travel_engine_permalinks option with get/set/save methods.
 *
 * @since 6.8.3
 */
class PermalinkSettings extends BaseSetting {

	use Factory;

	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct( 'wp_travel_engine_permalinks', array() );
	}
}
