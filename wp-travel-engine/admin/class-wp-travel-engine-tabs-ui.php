<?php

/**
 * WP Travel Engine - Backend Tabs UI
 *
 * @package WP_Travel_Engine
 */

/**
 * WP Travel Engine Tabs UI
 *
 * @since 3.1.7
 */
class WP_Travel_Engine_Tabs_UI
{

	/**
	 * var - admin tab attributes
	 *
	 * @access private
	 */
	private $tab_attributes;

	/**
	 * var - admin tabs
	 *
	 * @access private
	 */
	private $admin_tabs;

	/**
	 * Initialize admin tabs.
	 *
	 * @param [type] $tab_attributes
	 *
	 * @return void
	 */
	public function init($tab_attributes = array())
	{
		$this->tab_attributes['id']    = isset($tab_attributes['id']) ? $tab_attributes['id'] : '';
		$this->tab_attributes['class'] = isset($tab_attributes['class']) ? $tab_attributes['class'] : '';

		return $this;
	}

	/**
	 *
	 * @TODO: Add @since tag
	 * @return array
	 */
	public function formated_tabs($admin_tabs)
	{
		$tabs = array();
		foreach ($admin_tabs as $key => $tab) {
			$tabs[] = array(
				'id'      => $tab['content_key'],
				'label'   => $tab['tab_label'],
				'heading' => $tab['tab_heading'],
				'description' => $tab['tab_description'] ?? '',
				'icon'    => $tab['icon'] ?? 'info',
				'fields'  => $tab['fields'] ?? array(),
			);
		}

		return $tabs;
	}

	/**
	 * Template for the Tabs UI.
	 *
	 * @param $admin_tabs
	 */
	public function template($admin_tabs)
	{
		global $post;
		if (is_array($admin_tabs) && !empty($admin_tabs)) :
			$tabs = $this->formated_tabs($admin_tabs);
		?>
			<div id="<?php echo esc_attr( $this->tab_attributes['id'] ); ?>" data-app="<?php echo esc_attr(wp_json_encode(compact('tabs'))); ?>"></div>
		<?php
		endif;
	}
}
