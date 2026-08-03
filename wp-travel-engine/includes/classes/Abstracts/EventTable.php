<?php
/**
 * Events dispatcher hooks.
 *
 * @package WPTravelEngine/Abstracts
 * @since 6.8.3
 */

namespace WPTravelEngine\Abstracts;

/**
 * Queue persistence, cron dispatch/pruning, and table migration plumbing for `Events`.
 */
abstract class EventTable extends Table {

	/**
	 * Resolved cutoff datetime below which triggered events are pruned.
	 *
	 * @var string
	 */
	protected string $cutoff;

	/**
	 * Constructor.
	 *
	 * Chains up to `Table::__construct()` to resolve `$this->table`, then registers hooks.
	 *
	 * @return void
	 */
	public function __construct() {
		parent::__construct();
		$this->cutoff = gmdate( 'Y-m-d H:i:s', strtotime( '-3 months', time() ) );
		add_action( 'plugins_loaded', array( $this, 'maybe_upgrade_table' ) );
		add_action( 'wp_version_check', array( $this, 'prune_triggered_events' ) );
	}

	/**
	 * Schedule events.
	 */
	public static function schedule(): void {}

	/**
	 * Unprefixed table name shared by this hierarchy.
	 *
	 * @return string
	 */
	protected function table_name(): string {
		return 'wptravelengine_events';
	}

	/**
	 * Create/upgrade the events table and (re-)arm its crons, gated on version options
	 * so this only runs once per relevant upgrade instead of on every `plugins_loaded`.
	 *
	 * @return void
	 * @since 6.8.5 Added a short-lived transient lock so rapid-fire `plugins_loaded` hits
	 *             (e.g. a front-end request racing a wp-cron loopback right after activation,
	 *             before the version options are set) can't pile up repeated `dbDelta()` runs.
	 */
	public function maybe_upgrade_table() {
		if ( get_transient( 'wptravelengine_upgrading_events_table' ) ) {
			return;
		}

		set_transient( 'wptravelengine_upgrading_events_table', 1, MINUTE_IN_SECONDS );

		if ( version_compare( get_option( 'wptravelengine_version' ), '6.6.0', '<' ) ) {
			wptravelengine_create_events_table();
			static::schedule();
		}

		if ( version_compare( get_option( 'wptravelengine_events_schema_version', '1' ), '2', '<' ) ) {
			wptravelengine_create_events_table();
			update_option( 'wptravelengine_events_schema_version', '2' );
		}

		delete_transient( 'wptravelengine_upgrading_events_table' );
	}

	/**
	 * Delete triggered events older than 3 months.
	 *
	 * @return void
	 */
	public function prune_triggered_events() {
		$this->delete_where(
			array(
				array( 'triggered', '=', 1 ),
				array( 'triggered_at', '<=', $this->cutoff ),
			)
		);
	}
}
