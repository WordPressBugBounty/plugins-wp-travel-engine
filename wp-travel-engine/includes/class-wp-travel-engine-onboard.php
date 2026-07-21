<?php
/**
 * User onboarding process.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use WPTravelEngine\Registers\AjaxRequestRegistry;
use WPTravelEngine\Core\Controllers\Ajax\FetchDemos;
use WPTravelEngine\Core\Controllers\Ajax\OnboardInstalls;
use WPTravelEngine\Core\Controllers\Ajax\OnboardingSave;

/**
 * Onboarding Process to assist user on initial setup on first activation of the plugin.
 */
class WP_TRAVEL_ENGINE_ONBOARDING_PROCESS {

	/**
	 * The ID of this plugin.
	 *
	 * @since    1.0.0
	 * @access   private
	 * @var      string    $plugin_name    The ID of this plugin.
	 */
	private $plugin_name = 'wp-travel-engine';

	/**
	 * The version of this plugin.
	 *
	 * @since    1.0.0
	 * @access   private
	 * @var      string    $version    The current version of this plugin.
	 */
	private $version = WP_TRAVEL_ENGINE_VERSION;

	/**
	 * The version of this plugin.
	 *
	 * @since    1.0.0
	 * @access   private
	 * @var      string    $version    The current page name
	 */
	private $page_name = 'wp-travel-engine-onboard';

	/**
	 * Constructor.
	 *
	 * @since 6.8.3
	 */
	public function __construct() {
		add_action( 'admin_menu', array( $this, 'add_onboarding_admin_menu' ) );
		add_action( 'admin_init', array( $this, 'maybe_render_page' ), 30 );

		$ajax = AjaxRequestRegistry::make();

		$ajax->register( OnboardingSave::class );
		$ajax->register( OnboardInstalls::class );
		$ajax->register( FetchDemos::class );

		if ( ! wptravelengine_toggled( get_option( 'wp_travel_engine_first_time_activation_flag', false ) ) ) {
			add_action( 'admin_init', array( $this, 'redirect_on_activation' ), 5 );
		}
	}

	/**
	 * Add menu for Onboard Process.
	 */
	public function add_onboarding_admin_menu() {
		add_submenu_page(
			null,
			esc_html__( 'WP Travel Engine - User Onboarding', 'wp-travel-engine' ),
			esc_html__( 'WP Travel Engine - User Onboarding', 'wp-travel-engine' ),
			'manage_options',
			$this->page_name,
			'__return_null'
		);
	}

	/**
	 * Redirect to onboarding page after first activation if user is new.
	 *
	 * @since 6.8.3
	 */
	public function redirect_on_activation() {
		if ( ! get_transient( 'wte_onboarding_redirect' ) ) {
			return;
		}
		delete_transient( 'wte_onboarding_redirect' );

		if ( wp_doing_ajax() || is_network_admin() ) {
			return;
		}

		wp_safe_redirect( admin_url( 'admin.php?page=' . $this->page_name ) );
		exit;
	}

	/**
	 * Intercept the onboarding page request before WordPress renders admin chrome.
	 *
	 * @since 6.8.3
	 */
	public function maybe_render_page() {
		if ( ! isset( $_GET['page'] ) || $_GET['page'] !== $this->page_name ) { // phpcs:ignore WordPress.Security.NonceVerification
			return;
		}

		if ( wptravelengine_toggled( get_option( 'wp_travel_engine_first_time_activation_flag', false ) ) ) {
			wp_safe_redirect( admin_url() );
			exit;
		}

		update_option( 'wp_travel_engine_first_time_activation_flag', 'true' );

		$script_url = plugin_dir_url( WP_TRAVEL_ENGINE_FILE_PATH ) . 'dist/admin/onboarding.js';
		$asset_file = plugin_dir_path( WP_TRAVEL_ENGINE_FILE_PATH ) . 'dist/admin/onboarding.asset.php';
		$asset      = file_exists( $asset_file ) ? require $asset_file : array(
			'dependencies' => array(),
			'version'      => WP_TRAVEL_ENGINE_VERSION,
		);

		wp_enqueue_script( 'wptravelengine-onboarding', $script_url, $asset['dependencies'], $asset['version'], true );
		$currencies       = \WPTravelEngine\Helpers\Currencies::list();
		$currency_options = array();
		foreach ( $currencies as $code => $name ) {
			$currency_options[] = array(
				'value' => $code,
				'label' => $code . ' - ' . html_entity_decode( $name, ENT_QUOTES | ENT_HTML5, 'UTF-8' ),
			);
		}

		wp_add_inline_script(
			'wptravelengine-onboarding',
			'var wteL10n = ' . wp_json_encode( WPTravelEngine\Assets::instance()->get_global_localize_data() ) . ';' .
			'var wpteOnboardingData = ' . wp_json_encode(
				array(
					'dashboardUrl'    => admin_url(),
					'createTripUrl'   => admin_url( 'post-new.php?post_type=trip' ),
					'ajaxUrl'         => admin_url( 'admin-ajax.php' ),
					'nonce'           => wp_create_nonce( 'wptravelengine-onboarding' ),
					'currencies'      => $currency_options,
					'currentCurrency' => wptravelengine_settings()->get( 'currency_code', 'USD' ),
				)
			) . ';',
			'before'
		);
		?>
		<!DOCTYPE html>
		<html <?php language_attributes(); ?>>
		<head>
			<meta charset="<?php bloginfo( 'charset' ); ?>">
			<meta name="viewport" content="width=device-width, initial-scale=1">
			<title><?php esc_html_e( 'Setup Wizard', 'wp-travel-engine' ); ?></title>
			<?php wp_scripts()->do_items( false, 0 ); ?>
			<?php wp_print_styles(); ?>
		</head>
		<body>
			<div id="wpte-onboarding"></div>
			<?php wp_scripts()->do_items( false, 1 ); ?>
		</body>
		</html>
		<?php
		exit;
	}

	public static function add_query_arguements( $args ) {
		return esc_url( add_query_arg( $args ) );
	}

	/**
	 * Get view file to display.
	 *
	 * @param string $view View to display.
	 * @return string
	 * TODO: Remove once confirmed unnecessary.
	 */
	public function get_view( $view ) {
		$view_path = plugin_dir_path( WP_TRAVEL_ENGINE_FILE_PATH ) . "includes/onboarding-process/views/{$view}.php";
		return $view_path;
	}

	/**
	 * Add class in body for travel engine pages/posts.
	 *
	 * @param string $classes Body classes.
	 * @return string
	 * TODO: Remove once confirmed unnecessary.
	 */
	public function wpte_onboard_body_class_before_header_callback( $classes ) {
		return $classes;
	}

	/**
	 * Initialize onboarding process class.
	 *
	 * @return void
	 * TODO: Remove once confirmed unnecessary.
	 */
	public function init() {}

	/**
	 * Enqueue onboarding scripts and styles.
	 *
	 * TODO: Remove once confirmed unnecessary.
	 */
	public function enqueue_scripts() {}

	/**
	 * Remove onboarding menu page from admin menu.
	 *
	 * TODO: Remove once confirmed unnecessary.
	 */
	public function remove_menus() {}

	/**
	 * Render the legacy onboarding page view.
	 *
	 * TODO: Remove once confirmed unnecessary.
	 */
	public function wp_travel_engine_onboarding_menu_callback() {}

	/**
	 * Save & continue button callback.
	 *
	 * @return void
	 * TODO: Remove once confirmed unnecessary.
	 */
	public static function wpte_onboard_save_function_callback() {}

	/**
	 * Output payment gateway recommendations during onboarding.
	 *
	 * @since 1.0.0
	 * TODO: Remove once confirmed unnecessary. Also remove the hooks in class-wte-ajax.php.
	 */
	public static function wte_onboard_dynamic_recommendation_callback() {}

	/**
	 * Dynamic flag set to record that the onboarding page has been visited for the first time.
	 *
	 * TODO: Remove once confirmed unnecessary.
	 */
	public function wte_onboard_dynamic_flag_set() {}
}

new WP_TRAVEL_ENGINE_ONBOARDING_PROCESS();
