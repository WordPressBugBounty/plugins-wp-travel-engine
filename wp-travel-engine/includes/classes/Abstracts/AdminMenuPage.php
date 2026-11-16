<?php
/**
 * Class AdminMenuPage.
 *
 * @package WPTravelEngine\Abstracts
 * @since 6.0.0
 */

namespace WPTravelEngine\Abstracts;

use WPTravelEngine\Traits\Factory;
use WPTravelEngine\Interfaces\AdminPage;

/**
 * Class AdminMenuPage
 * This class represents an admin menu page in the WP Travel Engine plugin.
 *
 * Fixed values come from the `SLUG`, `PARENT_SLUG`, `CAPABILITY` and `POSITION`
 * class constants so a page only needs to redeclare what differs. Titles run
 * through `__()` and therefore cannot be constants — child constructors set them
 * after calling `parent::__construct()`.
 *
 * @since 6.0.0
 * @since 6.9.0 Implements the AdminPage contract; constants + constructor replace the per-page property boilerplate.
 */
abstract class AdminMenuPage implements AdminPage {
	use Factory;

	/**
	 * Page slug, used as the `page` query arg.
	 *
	 * @since 6.9.0
	 */
	public const SLUG = '';

	/**
	 * Slug of the parent menu. Empty string registers a top-level menu.
	 *
	 * @since 6.9.0
	 */
	public const PARENT_SLUG = '';

	/**
	 * Capability required to open the page.
	 *
	 * @since 6.9.0
	 */
	public const CAPABILITY = 'manage_options';

	/**
	 * Menu position.
	 *
	 * @since 6.9.0
	 */
	public const POSITION = 10;

	/**
	 * The parent slug of the admin menu page.
	 *
	 * @var string|null
	 */
	protected ?string $parent_slug = null;

	/**
	 * The page title of the admin menu page.
	 *
	 * @var string
	 */
	protected string $page_title = '';

	/**
	 * The menu title of the admin menu page.
	 *
	 * @var string
	 */
	protected string $menu_title = '';

	/**
	 * The capability of the admin menu page.
	 *
	 * @var string
	 */
	protected string $capability;

	/**
	 * The position of the admin menu page.
	 *
	 * @var int
	 */
	protected int $position;

	/**
	 * Seeds the page from its class constants.
	 *
	 * @since 6.9.0
	 */
	public function __construct() {
		$this->parent_slug = '' !== static::PARENT_SLUG ? static::PARENT_SLUG : null;
		$this->capability  = static::CAPABILITY;
		$this->position    = static::POSITION;
	}

	/**
	 * Renders the page body. Registered as the `add_submenu_page()` callback.
	 *
	 * @return void
	 * @since 6.9.0 Replaces the static `callback()` with an instance method.
	 */
	abstract public function view(): void;

	/**
	 * Get the slug of the admin menu page.
	 *
	 * @return string
	 */
	public function slug(): string {
		return static::SLUG;
	}

	/**
	 * Get the parent slug of the admin menu page.
	 *
	 * @return string|null
	 * @since 6.9.0 Nullable — a top-level page has no parent.
	 */
	public function parent_slug(): ?string {
		return $this->parent_slug;
	}

	/**
	 * Get the page title of the admin menu page.
	 *
	 * @return string
	 */
	public function page_title(): string {
		return $this->page_title;
	}

	/**
	 * Get the menu title of the admin menu page.
	 *
	 * @return string
	 */
	public function menu_title(): string {
		return $this->menu_title;
	}

	/**
	 * Get the capability of the admin menu page.
	 *
	 * @return string
	 */
	public function capability(): string {
		return $this->capability;
	}

	/**
	 * Get the position of the admin menu page.
	 *
	 * @return int
	 */
	public function position(): int {
		return $this->position;
	}

	/**
	 * Should be added to the admin menu.
	 *
	 * @return bool
	 */
	public function add_to_menu(): bool {
		return true;
	}

	/**
	 * Get the arguments of the admin menu page.
	 *
	 * @return array
	 * @since 6.9.0 Reads values through the getters; callback is the instance view() method.
	 */
	public function args(): array {
		return array(
			'parent_slug' => $this->parent_slug(),
			'page_title'  => $this->page_title(),
			'menu_title'  => $this->menu_title(),
			'capability'  => $this->capability(),
			'callback'    => array( $this, 'view' ),
			'position'    => $this->position(),
		);
	}

	/**
	 * Check if the admin menu page is a submenu.
	 *
	 * @return bool
	 */
	public function is_submenu(): bool {
		return ! is_null( $this->parent_slug );
	}

	/**
	 * Get the icon for the admin menu page.
	 *
	 * @return string The icon for the admin menu page.
	 */
	public function get_icon(): string {
		return '';
	}
}
