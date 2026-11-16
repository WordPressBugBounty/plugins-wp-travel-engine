<?php
/**
 * Interface for Admin Page.
 *
 * @package WPTravelEngine/Interfaces
 * @since 6.4.1
 */

namespace WPTravelEngine\Interfaces;

/**
 * Contract for plugin admin pages registered through `Admin::admin_menu()`.
 *
 * Everything `add_submenu_page()` needs is read through these accessors, and
 * `view()` is the registered page callback. `Abstracts\AdminMenuPage` provides the
 * default implementation — the fixed values come from its `PARENT_SLUG`,
 * `CAPABILITY` and `POSITION` class constants, which pages override as needed.
 *
 * @since 6.4.1
 * @since 6.9.0 Declares the page contract instead of acting as a bare marker.
 */
interface AdminPage {

	/**
	 * Slug of the parent menu, or null for a top-level page.
	 *
	 * @return string|null
	 * @since 6.9.0
	 */
	public function parent_slug(): ?string;

	/**
	 * Title rendered in the browser title bar.
	 *
	 * @return string
	 * @since 6.9.0
	 */
	public function page_title(): string;

	/**
	 * Label rendered in the admin menu.
	 *
	 * @return string
	 * @since 6.9.0
	 */
	public function menu_title(): string;

	/**
	 * Capability required to open the page.
	 *
	 * @return string
	 * @since 6.9.0
	 */
	public function capability(): string;

	/**
	 * Menu position.
	 *
	 * @return int
	 * @since 6.9.0
	 */
	public function position(): int;

	/**
	 * Renders the page body.
	 *
	 * @return void
	 * @since 6.9.0
	 */
	public function view(): void;
}
