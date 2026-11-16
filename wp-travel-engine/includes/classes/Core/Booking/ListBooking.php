<?php
/**
 * Booking list screen.
 *
 * @package WPTravelEngine/Core/Booking
 * @since 6.9.0
 */

namespace WPTravelEngine\Core\Booking;

use WPTravelEngine\Core\Tax;
use WPTravelEngine\Helpers\Asset;
use WPTravelEngine\Helpers\CartInfoParser;
use WPTravelEngine\Core\Models\Post\Booking as BookingModel;
use WPTravelEngine\Core\Models\Settings\PluginSettings;

/**
 * The Bookings list screen, used by PostTypes\Booking.
 *
 * Static properties rather than constants: traits cannot hold constants before PHP 8.2.
 *
 * @since 6.9.0
 */
trait ListBooking {

	/**
	 * Fragment of a v4.0 cart_info; bookings without it are deprecated.
	 *
	 * @var string
	 */
	private static string $cart_info_version = 's:7:"version";s:3:"4.0";';

	/**
	 * Transient caching the summary card totals.
	 *
	 * @var string
	 */
	private static string $stats_transient = 'wptravelengine_booking_stats';

	/**
	 * Sortable custom columns.
	 *
	 * @var string[]
	 */
	private static array $sort_columns = array( 'traveler', 'trip', 'travelers', 'amount', 'booking_status' );

	/**
	 * Hooks the list screen.
	 *
	 * @return void
	 */
	protected function register_list_hooks(): void {
		add_action( 'restrict_manage_posts', array( $this, 'add_filter_options' ) );
		add_action( 'manage_posts_extra_tablenav', array( $this, 'add_clear_filters_button' ) );
		add_action( 'parse_query', array( $this, 'filter_bookings' ) );
		add_filter( 'disable_months_dropdown', array( $this, 'remove_date_filter' ) );

		add_action( 'pre_get_posts', array( $this, 'exclude_migrated_bookings' ) );
		add_action( 'load-edit.php', array( $this, 'delete_auto_drafts' ) );
		add_filter( 'views_edit-booking', array( $this, 'fix_booking_view_counts' ) );
		add_filter( 'display_post_states', array( $this, 'append_booking_state_badges' ), 10, 2 );

		add_filter( 'the_title', array( $this, 'booking_reference_title' ), 10, 2 );
		add_filter( 'post_row_actions', array( $this, 'remove_row_actions' ), 10, 2 );
		add_filter( 'post_class', array( $this, 'mark_paid_rows' ), 10, 3 );
		add_filter( 'bulk_actions-edit-booking', array( $this, 'remove_bulk_actions' ) );
		add_filter( 'handle_bulk_actions-edit-booking', array( $this, 'handle_bulk_archive' ), 10, 3 );
		add_action( 'in_admin_header', fn() => add_action( 'admin_notices', array( $this, 'bulk_action_notices' ) ), 11111 );
		add_action( 'load-edit.php', array( $this, 'guard_bulk_trash' ) );
		add_action( 'load-post.php', array( $this, 'guard_single_trash' ) );
		add_filter( 'removable_query_args', fn( array $args ): array => array_merge( $args, array( 'wte_archived', 'wte_trash_skipped' ) ) );
		add_filter( 'view_mode_post_types', array( $this, 'remove_view_mode' ) );

		// Priority 20 so fix_booking_view_counts() has corrected the counts first.
		add_filter( 'views_edit-booking', array( $this, 'render_list_header' ), 20 );
		add_action( 'wptravelengine.booking.created', array( __CLASS__, 'clear_stats_cache' ) );
		add_action( 'wptravelengine.booking.updated', array( __CLASS__, 'clear_stats_cache' ) );
		add_action( 'transition_post_status', array( __CLASS__, 'clear_stats_cache_on_transition' ), 10, 3 );

		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_list_scripts' ) );

		// For Sorting.
		add_action( 'pre_get_posts', array( $this, 'sort_bookings' ) );
		add_filter( 'manage_edit-booking_sortable_columns', fn( array $columns ): array => $columns + array_combine( self::$sort_columns, self::$sort_columns ) );
		add_action( 'added_post_meta', array( __CLASS__, 'clear_sort_keys' ), 10, 3 );
		add_action( 'updated_post_meta', array( __CLASS__, 'clear_sort_keys' ), 10, 3 );
	}

	/**
	 * Add filter options.
	 *
	 * @param string $post_type Post type.
	 *
	 * @since 5.7.4 - Booking Export button added.
	 * @modified_since 6.3.5 - Trip Name filter and Booking Status filter added.
	 * @since 6.9.0 Dropped a dead remove_all_actions() call; restrict_manage_posts fires long after notices print.
	 * @since 6.9.0 Trip select carries a data-trips map of ID to title so the add booking wizard can read it.
	 * @since 6.9.0 Booking status placeholder reads "All Booking Status".
	 * @since 6.9.0 Trip filter is a searchable checkbox dropdown posting trip_id[].
	 */
	public function add_filter_options( $post_type ) {
		$current_screen = get_current_screen();
		if ( 'booking' !== $post_type && 'edit-booking' !== $current_screen->id ) {
			return;
		}
		$trips            = wp_travel_engine_get_trips_array();
		$status           = wp_travel_engine_get_booking_status();
		$booking_selected = isset( $_REQUEST['booking_status'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['booking_status'] ) ) : 'all';
		$trip_selected    = array_flip( self::get_requested_trip_ids() );

		$trip_label = __( 'All Trips', 'wp-travel-engine' );
		if ( 1 === count( $trip_selected ) ) {
			$trip_label = $trips[ array_key_first( $trip_selected ) ] ?? $trip_label;
		} elseif ( $trip_selected ) {
			/* translators: %d: number of selected trips. */
			$trip_label = sprintf( __( '%d trips selected', 'wp-travel-engine' ), count( $trip_selected ) );
		}

		// The add booking wizard reads its trips off data-trips, rather than shipping them twice.
		?>
		<div id="trip_id_filter" class="wpte-multiselect" data-trips="<?php echo esc_attr( wp_json_encode( $trips ) ); ?>" data-placeholder="<?php esc_attr_e( 'All Trips', 'wp-travel-engine' ); ?>" data-count-format="<?php /* translators: %d: number of selected trips. */ esc_attr_e( '%d trips selected', 'wp-travel-engine' ); ?>">
			<button type="button" class="wpte-multiselect__toggle" aria-haspopup="true" aria-expanded="false">
				<span class="wpte-multiselect__label"><?php echo esc_html( $trip_label ); ?></span>
			</button>
			<div class="wpte-multiselect__panel" hidden>
				<input type="search" class="wpte-multiselect__search" placeholder="<?php esc_attr_e( 'Search trips...', 'wp-travel-engine' ); ?>" aria-label="<?php esc_attr_e( 'Search trips', 'wp-travel-engine' ); ?>" autocomplete="off">
				<ul class="wpte-multiselect__options">
					<?php foreach ( $trips as $key => $title ) : ?>
						<li>
							<label>
								<input type="checkbox" name="trip_id[]" value="<?php echo esc_attr( $key ); ?>" <?php checked( isset( $trip_selected[ (int) $key ] ) ); ?>>
								<span><?php echo esc_html( $title ); ?></span>
							</label>
						</li>
					<?php endforeach; ?>
				</ul>
				<p class="wpte-multiselect__empty" hidden><?php esc_html_e( 'No trips found.', 'wp-travel-engine' ); ?></p>
				<div class="wpte-multiselect__actions">
					<button type="button" class="wpte-multiselect__clear"><?php esc_html_e( 'Clear', 'wp-travel-engine' ); ?></button>
					<button type="button" class="wpte-multiselect__done"><?php esc_html_e( 'Done', 'wp-travel-engine' ); ?></button>
				</div>
			</div>
		</div>
		<select id="booking_status_filter" name="booking_status">
			<option value="all"><?php esc_html_e( 'All Booking Status', 'wp-travel-engine' ); ?></option>
			<?php foreach ( $status as $key => $value ) : ?>
				<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $booking_selected, $key ); ?>><?php echo esc_html( $value['text'] ); ?></option>
			<?php endforeach; ?>
		</select>
		<?php
	}

	/**
	 * Remove date filter given by WordPress
	 *
	 * @return bool
	 * @since 6.3.5
	 */
	public function remove_date_filter() {
		return 'booking' === ( $_GET['post_type'] ?? '' );
	}

	/**
	 * Return query after filtering bookings.
	 *
	 * @param object $query Query.
	 *
	 * @modified_since 6.4.0 Modified the query for the selected trip name and date range filter option.
	 * @since 6.9.0 Filters by several trips; skips the booking ID lookup when no trip is selected.
	 *
	 * @return object $query
	 */
	public function filter_bookings( $query ) {
		if ( ! is_admin() || ! $query->is_main_query() ) {
			return $query;
		}
		$current_screen = get_current_screen();
		$trip_ids       = self::get_requested_trip_ids();
		$booking_status = 'all';
		if ( isset( $_REQUEST['booking_status'] ) ) {
			$booking_status = sanitize_text_field( wp_unslash( $_REQUEST['booking_status'] ) );
		}
		$date_range = isset( $_REQUEST['wte_booking_range'] ) ? sanitize_text_field( $_REQUEST['wte_booking_range'] ) : '';
		$dates      = explode( ' to ', $date_range );

		$start_date = isset( $dates[0] ) ? $dates[0] : '';
		$end_date   = isset( $dates[1] ) ? $dates[1] : '';

		if ( ( 'edit-booking' !== $current_screen->id ) || ( 'all' === $booking_status && ! $trip_ids && empty( $date_range ) ) ) {
			return $query;
		}

		if ( 'all' !== $booking_status ) {
			$query->set(
				'meta_query',
				array(
					array(
						'key'     => 'wp_travel_engine_booking_status',
						'compare' => '=',
						'value'   => $booking_status,
						'type'    => 'string',
					),
				)
			);
		}

		if ( $trip_ids ) {
			$filter_ids = wptravelengine_get_booking_ids( $trip_ids );
			$query->set( 'post__in', $filter_ids ? $filter_ids : array( 0 ) );
		}

		if ( ! empty( $start_date ) && ! empty( $end_date ) ) {
			$query->set(
				'date_query',
				array(
					array(
						'after'     => $start_date,
						'before'    => $end_date,
						'inclusive' => true,
					),
				)
			);
		} elseif ( ! empty( $start_date ) ) {
			$get_specific_date = explode( '-', $start_date );
			$query->set(
				'date_query',
				array(
					array(
						'year'  => $get_specific_date[0],
						'month' => $get_specific_date[1],
						'day'   => $get_specific_date[2],
					),
				)
			);
		}

		return $query;
	}

	/**
	 * Orders the booking list by a custom column, writing `_wte_sort_*` keys for bookings lacking them.
	 *
	 * @param \WP_Query $query Current query.
	 * @return void
	 * @since 6.9.0
	 */
	public function sort_bookings( \WP_Query $query ): void {
		$column = $query->get( 'orderby' );

		if ( ! is_admin() || ! $query->is_main_query() || $this->post_type !== $query->get( 'post_type' ) || ! in_array( $column, self::$sort_columns, true ) ) {
			return;
		}

		$meta_key = 'booking_status' === $column ? 'wp_travel_engine_booking_status' : "_wte_sort_{$column}";

		$missing = 'booking_status' === $column ? array() : get_posts(
			array(
				'post_type'        => $this->post_type,
				'post_status'      => array_keys( get_post_stati() ),
				'fields'           => 'ids',
				'nopaging'         => true,
				'suppress_filters' => true,
				'meta_query'       => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array(
						'key'     => $meta_key,
						'compare' => 'NOT EXISTS',
					),
				),
			)
		);

		foreach ( $missing as $id ) {
			$booking = BookingModel::make( (int) $id );
			$name    = trim( $booking->get_billing_fname() . ' ' . $booking->get_billing_lname() );

			update_post_meta( $id, '_wte_sort_traveler', '' !== $name ? $name : $booking->get_billing_email() );
			update_post_meta( $id, '_wte_sort_trip', (string) $booking->get_trip_title() );
			update_post_meta( $id, '_wte_sort_travelers', self::travelers_count( $booking ) );
			update_post_meta( $id, '_wte_sort_amount', $booking->get_total_paid_amount() + $booking->get_total_due_amount() );
		}

		$query->set( 'meta_key', $meta_key );
		$query->set( 'orderby', in_array( $column, array( 'travelers', 'amount' ), true ) ? 'meta_value_num' : 'meta_value' );
	}

	/**
	 * Travelers on a booking.
	 *
	 * `cart_info` records travelers_count as 0 on most bookings, so the pax map leads.
	 *
	 * @param BookingModel $booking Booking.
	 * @return int
	 * @since 6.9.0
	 */
	public static function travelers_count( BookingModel $booking ): int {
		$travelers = (int) array_sum( (array) $booking->get_trip_pax() );

		if ( ! $travelers ) {
			$travelers = (int) ( $booking->get_cart_info( 'items' )[0]['travelers_count'] ?? 0 );
		}

		return $travelers;
	}

	/**
	 * Drops a booking's sort keys when a meta they come from changes.
	 *
	 * @param int    $meta_id   Meta ID.
	 * @param int    $object_id Post ID.
	 * @param string $meta_key  Meta key written.
	 * @return void
	 * @since 6.9.0
	 */
	public static function clear_sort_keys( $meta_id, $object_id, $meta_key ): void {
		$money_keys = array( 'total_paid_amount', 'paid_amount', 'total_due_amount', 'due_amount' );

		if ( ! in_array( $meta_key, array_merge( array( 'billing_info', 'wptravelengine_billing_details', 'order_trips', 'cart_info' ), $money_keys ), true ) ) {
			return;
		}

		if ( in_array( $meta_key, $money_keys, true ) ) {
			self::clear_stats_cache();
		}

		foreach ( array( 'traveler', 'trip', 'travelers', 'amount' ) as $column ) {
			delete_post_meta( (int) $object_id, "_wte_sort_{$column}" );
		}
	}

	/**
	 * Add "Deprecated" view tab for bookings without cart_info version 4.0.
	 *
	 * @param array $views View links HTML keyed by status slug.
	 * @return array
	 * @since 6.8.0
	 * @since 6.9.0 Re-marks the All tab current under post_status=all, which core leaves unhighlighted.
	 */
	public function fix_booking_view_counts( array $views ): array {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$is_deprecated = isset( $_GET['wte_deprecated'] );

		if ( $is_deprecated ) {
			foreach ( $views as &$link ) {
				$link = str_replace( array( ' class="current"', ' aria-current="page"' ), '', $link );
			}
			unset( $link );
		} elseif ( isset( $views['all'] ) && 'all' === sanitize_key( $_GET['post_status'] ?? '' ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			// The filter form posts post_status=all, which core highlights no tab for.
			$has_current = false;

			foreach ( $views as $view ) {
				if ( false !== strpos( $view, 'class="current"' ) ) {
					$has_current = true;
					break;
				}
			}

			if ( ! $has_current ) {
				$views['all'] = str_replace(
					'<a href=',
					'<a class="current" aria-current="page" href=',
					$views['all']
				);
			}
		}

		$deprecated_ids = $this->get_deprecated_booking_ids();

		if ( empty( $deprecated_ids ) ) {
			return $views;
		}

		$deprecated_url          = add_query_arg(
			array(
				'post_type'      => 'booking',
				'wte_deprecated' => '1',
			),
			admin_url( 'edit.php' )
		);
		$views['wte_deprecated'] = sprintf(
			'<a href="%s"%s>%s <span class="count">(%s)</span></a>',
			esc_url( $deprecated_url ),
			$is_deprecated ? ' class="current" aria-current="page"' : '',
			esc_html__( 'Deprecated', 'wp-travel-engine' ),
			number_format_i18n( count( $deprecated_ids ) )
		);

		if ( ! empty( $deprecated_ids ) && isset( $views['publish'] ) ) {
			global $wpdb;
			$ids_str              = implode( ',', $deprecated_ids );
			$published_deprecated = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				"SELECT COUNT(*) FROM {$wpdb->posts} WHERE ID IN ({$ids_str}) AND post_status = 'publish'" // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			);

			if ( $published_deprecated > 0 ) {
				preg_match( '/<span class="count">\((\d+)\)<\/span>/', $views['publish'], $matches );
				if ( isset( $matches[1] ) ) {
					$new              = max( 0, (int) $matches[1] - $published_deprecated );
					$views['publish'] = preg_replace(
						'/<span class="count">\(\d+\)<\/span>/',
						'<span class="count">(' . number_format_i18n( $new ) . ')</span>',
						$views['publish']
					);
				}
			}
		}

		return $views;
	}

	/**
	 * Delete all auto-draft booking posts when the booking list screen loads.
	 *
	 * @return void
	 * @since 6.8.0
	 */
	public function delete_auto_drafts(): void {
		if ( ( $_GET['post_type'] ?? '' ) !== $this->post_type ) {
			return;
		}

		$deprecated_ids = $this->get_deprecated_booking_ids();

		$args = array(
			'post_type'      => $this->post_type,
			'post_status'    => 'auto-draft',
			'posts_per_page' => -1,
			'fields'         => 'ids',
		);

		if ( ! empty( $deprecated_ids ) ) {
			$args['post__not_in'] = $deprecated_ids;
		}

		$auto_drafts = get_posts( $args );

		foreach ( $auto_drafts as $post_id ) {
			wp_delete_post( $post_id, true );
		}
	}

	/**
	 * Appends the booked-on date, and pill-style status badges, to the booking title.
	 *
	 * @param string[]      $states Existing post states.
	 * @param \WP_Post|null $post   Current post.
	 * @return string[]
	 * @since 6.8.0
	 * @since 6.8.1 Badges now render in search results regardless of post_status parameter.
	 * @since 6.8.5 Accept nullable $post; core calls get_post_states( null ) from nav-menu setup.
	 * @since 6.9.0 Booked-on date no longer suppressed alongside the badges; `post_status=all` keeps both.
	 * @since 6.9.0 Badges come from get_state_badges(), shared with the booking edit header.
	 */
	public function append_booking_state_badges( array $states, ?\WP_Post $post ): array {
		if ( ! $post instanceof \WP_Post || 'booking' !== $post->post_type ) {
			return $states;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$current_status = sanitize_key( $_GET['post_status'] ?? '' );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$is_filtered = empty( $_GET['s'] ) && $current_status && ! in_array( $current_status, array( 'all', 'publish' ), true );

		$badges = array_map(
			static fn ( array $badge ): string => \sprintf(
				'<span class="%1$s">%2$s%3$s</span>',
				esc_attr( trim( 'wpte-tag ' . $badge['modifier'] ) ),
				$badge['icon'],
				esc_html( $badge['label'] )
			),
			self::get_state_badges( $post, ! $is_filtered )
		);

		$booked_on = sprintf(
			/* translators: %s: date the booking was placed. */
			__( 'Booked %s', 'wp-travel-engine' ),
			get_the_date( 'M j, Y', $post )
		);

		$states['wte_booking_states'] = sprintf(
			'<span class="wte-booking-states"><span class="wte-booked-on">%1$s</span>%2$s</span>',
			esc_html( $booked_on ),
			implode( '', $badges )
		);

		return $states;
	}

	/**
	 * Exclude deprecated bookings from the admin listing, except in search results.
	 *
	 * @param \WP_Query $query Current query.
	 *
	 * @return void
	 * @since 6.8.0
	 * @since 6.8.1 Search requests skip post__not_in so deprecated bookings appear in results.
	 */
	public function exclude_migrated_bookings( \WP_Query $query ): void {
		global $pagenow;

		if ( ! is_admin() || ! $query->is_main_query() || 'edit.php' !== $pagenow ) {
			return;
		}

		if ( ( $_GET['post_type'] ?? '' ) !== 'booking' ) {
			return;
		}

		$deprecated_ids = $this->get_deprecated_booking_ids();

		if ( isset( $_GET['wte_deprecated'] ) ) {
			$query->set( 'post__in', ! empty( $deprecated_ids ) ? $deprecated_ids : array( 0 ) );
			$query->set( 'post_status', 'any' );
			return;
		}

		if ( ! empty( $_GET['s'] ) ) {
			return;
		}

		if ( ! isset( $_GET['post_status'] ) ) {
			return;
		}

		if ( empty( $deprecated_ids ) ) {
			return;
		}

		$existing = (array) $query->get( 'post__not_in' );
		$query->set( 'post__not_in', array_unique( array_merge( $existing, $deprecated_ids ) ) );
	}

	/**
	 * Get IDs of deprecated bookings — those whose cart_info lacks version 4.0.
	 *
	 * @return int[]
	 * @since 6.8.0
	 * @since 6.9.0 Static, so get_state_badges() can be called outside the list table.
	 */
	private static function get_deprecated_booking_ids(): array {
		static $ids = null;
		if ( null !== $ids ) {
			return $ids;
		}

		global $wpdb;
		$like = '%' . $wpdb->esc_like( self::$cart_info_version ) . '%';
		$ids  = array_map(
			'absint',
			$wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->prepare(
					"SELECT p.ID FROM {$wpdb->posts} p
					LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = 'cart_info'
					WHERE p.post_type = 'booking'
					AND p.post_status = 'publish'
					AND (pm.meta_value IS NULL OR pm.meta_value NOT LIKE %s)",
					$like
				)
			)
		);

		return $ids;
	}

	/**
	 * Trip IDs the list is filtered by, from `trip_id[]` or the older single `trip_id`.
	 *
	 * @return int[] Empty when no trip filter applies.
	 */
	private static function get_requested_trip_ids(): array {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only list filter.
		$ids = isset( $_REQUEST['trip_id'] ) ? array_map( 'absint', (array) wp_unslash( $_REQUEST['trip_id'] ) ) : array();

		return array_values( array_unique( array_filter( $ids ) ) );
	}

	/**
	 * Print a Clear link beside the Filter button while a search or filter is applied.
	 *
	 * @param string $which Tablenav position, 'top' or 'bottom'.
	 * @return void
	 */
	public function add_clear_filters_button( $which ) {
		if ( 'top' !== $which || 'booking' !== ( get_current_screen()->post_type ?? '' ) ) {
			return;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only list filters.
		$search         = isset( $_GET['s'] ) ? trim( sanitize_text_field( wp_unslash( $_GET['s'] ) ) ) : '';
		$booking_status = isset( $_GET['booking_status'] ) ? sanitize_text_field( wp_unslash( $_GET['booking_status'] ) ) : 'all';

		if ( '' === $search && ! self::get_requested_trip_ids() && in_array( $booking_status, array( '', 'all' ), true ) ) {
			return;
		}

		$args = array( 'post_type' => 'booking' );
		foreach ( array( 'post_status', 'author' ) as $key ) {
			if ( ! empty( $_GET[ $key ] ) ) {
				$args[ $key ] = sanitize_key( wp_unslash( $_GET[ $key ] ) );
			}
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		?>
		<a href="<?php echo esc_url( add_query_arg( $args, admin_url( 'edit.php' ) ) ); ?>" class="button wpte-clear-filters"><?php esc_html_e( 'Clear All', 'wp-travel-engine' ); ?></a>
		<?php
	}

	/**
	 * State badges for a booking, as shown in the Bookings list and the booking edit header.
	 *
	 * @param \WP_Post $post      Booking post.
	 * @param bool     $with_core Include core's own badges; the list leaves them out of status-filtered views.
	 * @return array<int, array{modifier: string, icon: string, label: string}>
	 */
	public static function get_state_badges( \WP_Post $post, bool $with_core = true ): array {
		$badges  = array();
		$post_id = $post->ID;

		if ( $with_core ) {
			if ( absint( get_post_meta( $post_id, '_migrated_from', true ) ) ) {
				$badges[] = array(
					'modifier' => 'migrated',
					'icon'     => self::BADGE_ICON_MIGRATED,
					'label'    => __( 'Migrated', 'wp-travel-engine' ),
				);
			}

			if ( in_array( $post_id, self::get_deprecated_booking_ids(), true ) ) {
				$badges[] = array(
					'modifier' => '',
					'icon'     => self::BADGE_ICON_DEPRECATED,
					'label'    => __( 'Deprecated', 'wp-travel-engine' ),
				);
			}

			if ( wptravelengine_toggled( get_post_meta( $post_id, '__is_manual', true ) ) ) {
				$badges[] = array(
					'modifier' => 'manual',
					'icon'     => self::BADGE_ICON_MANUAL,
					'label'    => __( 'Manual', 'wp-travel-engine' ),
				);
			} elseif ( wptravelengine_toggled( get_post_meta( $post_id, '_user_edited', true ) ) ) {
				$badges[] = array(
					'modifier' => 'warning',
					'icon'     => self::BADGE_ICON_MODIFIED,
					'label'    => __( 'Modified', 'wp-travel-engine' ),
				);
			}
		}

		/**
		 * Filters a booking's state badges. Runs per list row; `icon` is printed unescaped.
		 *
		 * @since 6.9.0
		 *
		 * @param array<int, array{modifier: string, icon: string, label: string}> $badges Badges, in display order.
		 * @param \WP_Post                                                         $post   Booking post.
		 */
		$badges = (array) apply_filters( 'wptravelengine_booking_state_badges', $badges, $post );

		return array_values(
			array_map(
				static fn ( array $badge ): array => array(
					'modifier' => (string) ( $badge['modifier'] ?? '' ),
					'icon'     => (string) ( $badge['icon'] ?? '' ),
					'label'    => (string) ( $badge['label'] ?? '' ),
				),
				array_filter( $badges, 'is_array' )
			)
		);
	}

	/**
	 * Drops the View Mode radios from Screen Options on the bookings list.
	 *
	 * @param string[] $post_types Post types offering view modes.
	 * @return string[]
	 */
	public function remove_view_mode( $post_types ): array {
		return array_filter(
			(array) $post_types,
			fn( $post_type ) => $this->post_type !== $post_type
		);
	}

	/**
	 * Drops every row action from the booking rows; a booking is trashed through the bulk action.
	 *
	 * @param array<string, string> $actions Row actions.
	 * @param \WP_Post              $post    Row post.
	 * @return array<string, string>
	 */
	public function remove_row_actions( $actions, $post ): array {
		if ( ! $post instanceof \WP_Post || $this->post_type !== $post->post_type ) {
			return $actions;
		}

		if ( wptravelengine_debug_enabled() ) {
			return array_intersect_key( $actions, array_flip( array( 'trash', 'untrash', 'delete' ) ) );
		}

		return array();
	}

	/**
	 * Whether any money has been paid on the booking.
	 *
	 * @param int $booking_id Booking ID.
	 * @return bool
	 */
	public static function has_paid( int $booking_id ): bool {
		$paid = get_post_meta( $booking_id, 'total_paid_amount', true );

		if ( '' === $paid ) {
			$paid = get_post_meta( $booking_id, 'paid_amount', true );
		}

		return (float) $paid > 0;
	}

	/**
	 * Marks a paid booking's list row, so the bulk actions can leave out Move to Trash for it.
	 *
	 * @param string[] $classes Row classes.
	 * @param string[] $css     Classes added by the caller.
	 * @param int      $post_id Row post ID.
	 * @return string[]
	 */
	public function mark_paid_rows( array $classes, $css, $post_id ): array {
		if ( is_admin() && get_post_type( $post_id ) === $this->post_type && self::has_paid( (int) $post_id ) ) {
			$classes[] = 'wpte-paid';
		}

		return $classes;
	}

	/**
	 * Drops paid bookings from a bulk Move to Trash; refusing via `pre_trash_post` would wp_die().
	 *
	 * @return void
	 */
	public function guard_bulk_trash(): void {
		if ( wptravelengine_debug_enabled() ) {
			return;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Core verifies the bulk nonce right after this.
		if ( empty( $_REQUEST['post'] ) || sanitize_key( $_REQUEST['post_type'] ?? '' ) !== $this->post_type ) {
			return;
		}

		$action = sanitize_key( $_REQUEST['action'] ?? '-1' );
		if ( '-1' === $action || '' === $action ) {
			$action = sanitize_key( $_REQUEST['action2'] ?? '' );
		}

		if ( 'trash' !== $action ) {
			return;
		}

		$ids = array_map( 'absint', (array) wp_unslash( $_REQUEST['post'] ) );
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		update_meta_cache( 'post', $ids );

		$unpaid  = array_values( array_filter( $ids, fn( $id ) => ! self::has_paid( $id ) ) );
		$skipped = count( $ids ) - count( $unpaid );

		if ( ! $skipped ) {
			return;
		}

		$_REQUEST['post'] = $unpaid;
		$_GET['post']     = $unpaid;

		add_filter( 'wp_redirect', fn( $location ) => add_query_arg( 'wte_trash_skipped', $skipped, $location ) );
	}

	/**
	 * Refuses a single Move to Trash on a paid booking.
	 *
	 * @return void
	 */
	public function guard_single_trash(): void {
		if ( wptravelengine_debug_enabled() ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( empty( $_GET['post'] ) || 'trash' !== sanitize_key( $_GET['action'] ?? '' ) ) {
			return;
		}

		$post_id = absint( $_GET['post'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( get_post_type( $post_id ) === $this->post_type && self::has_paid( $post_id ) ) {
			wp_die(
				esc_html__( 'This booking has been paid, so it cannot be moved to the Trash. Archive it instead.', 'wp-travel-engine' ),
				'',
				array( 'back_link' => true )
			);
		}
	}

	/**
	 * Drops Edit from the bulk actions and adds Archive.
	 *
	 * @param array<string, string> $actions Bulk actions.
	 * @return array<string, string>
	 */
	public function remove_bulk_actions( $actions ): array {
		unset( $actions['edit'] );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( 'trash' !== sanitize_key( $_GET['post_status'] ?? '' ) ) {
			$actions['wptravelengine_archive'] = __( 'Archive', 'wp-travel-engine' );
		}

		return $actions;
	}

	/**
	 * Sets the selected bookings' status to Archived.
	 *
	 * @param string $redirect_to Redirect URL.
	 * @param string $action      Bulk action being run.
	 * @param int[]  $post_ids    Selected booking IDs.
	 * @return string
	 */
	public function handle_bulk_archive( $redirect_to, $action, $post_ids ): string {
		if ( 'wptravelengine_archive' !== $action ) {
			return $redirect_to;
		}

		$archived = 0;

		foreach ( (array) $post_ids as $post_id ) {
			if ( ! current_user_can( 'edit_post', $post_id ) ) {
				continue;
			}

			try {
				( new BookingModel( (int) $post_id ) )->update_status( 'archived' );
				++$archived;
			} catch ( \Exception $e ) {
				continue;
			}
		}

		if ( $archived ) {
			self::clear_stats_cache();
		}

		return add_query_arg( 'wte_archived', $archived, $redirect_to );
	}

	/**
	 * Reports the outcome of the Archive and Move to Trash bulk actions.
	 *
	 * @return void
	 */
	public function bulk_action_notices(): void {
		if ( 'edit-booking' !== ( get_current_screen()->id ?? '' ) ) {
			return;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['wte_archived'] ) ) {
			$count = absint( $_GET['wte_archived'] );

			printf(
				'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
				esc_html(
					sprintf(
						/* translators: %s: number of bookings archived. */
						_n( '%s booking archived.', '%s bookings archived.', $count, 'wp-travel-engine' ),
						number_format_i18n( $count )
					)
				)
			);
		}

		if ( ! empty( $_GET['wte_trash_skipped'] ) ) {
			$count = absint( $_GET['wte_trash_skipped'] );

			printf(
				'<div class="notice notice-warning is-dismissible"><p>%s</p></div>',
				esc_html(
					sprintf(
						/* translators: %s: number of bookings left out of the Trash. */
						_n(
							'%s booking has been paid and was not moved to the Trash. Archive it instead.',
							'%s bookings have been paid and were not moved to the Trash. Archive them instead.',
							$count,
							'wp-travel-engine'
						),
						number_format_i18n( $count )
					)
				)
			);
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
	}

	/**
	 * Shows the booking reference instead of the post title in the list table.
	 *
	 * @param string $title   Post title.
	 * @param int    $post_id Post ID.
	 * @return string
	 */
	public function booking_reference_title( $title, $post_id = 0 ): string {
		if ( ! is_admin() || ! $post_id || $this->post_type !== get_post_type( $post_id ) ) {
			return $title;
		}

		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		if ( ! $screen || 'edit-booking' !== $screen->id ) {
			return $title;
		}

		return '#' . $post_id;
	}

	/**
	 * Prints the summary cards and the filter toolbar above the list table.
	 *
	 * @param array<string, string> $views Status links, keyed by view slug.
	 * @return array<string, string> Empty, so core renders nothing further.
	 */
	public function render_list_header( array $views ): array {
		if ( ! current_user_can( 'edit_posts' ) ) {
			return $views;
		}

		$this->render_booking_stats();

		echo '<div class="wpte-bookings-toolbar">';
		printf(
			'<h2 class="screen-reader-text">%s</h2>',
			esc_html__( 'Filter bookings list', 'wp-travel-engine' )
		);

		if ( ! empty( $views ) ) {
			echo '<ul class="subsubsub">';
			foreach ( $views as $class => $link ) {
				// $link is anchor markup already escaped by core and its filters.
				printf( '<li class="%1$s">%2$s</li>', esc_attr( $class ), $link ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			}
			echo '</ul>';
		}

		/**
		 * Renders controls on the right of the bookings toolbar.
		 *
		 * @since 6.9.0
		 */
		do_action( 'wptravelengine_bookings_toolbar' );

		echo '</div>';

		return array();
	}

	/**
	 * Prints the booking summary cards.
	 *
	 * @return void
	 */
	protected function render_booking_stats(): void {
		$stats = self::get_stats();

		$cards = array(
			array(
				'modifier' => 'count',
				'label'    => __( 'Total Bookings', 'wp-travel-engine' ),
				'value'    => number_format_i18n( $stats['total'] ),
				'note'     => __( 'Across all trips', 'wp-travel-engine' ),
			),
			array(
				'modifier' => 'paid',
				'label'    => __( 'Fully Paid', 'wp-travel-engine' ),
				'value'    => number_format_i18n( $stats['fully_paid'] ),
				'note'     => __( 'No balance remaining', 'wp-travel-engine' ),
			),
			array(
				'modifier' => 'received',
				'label'    => __( 'Total Amount Received', 'wp-travel-engine' ),
				'value'    => wptravelengine_the_price( $stats['received'], false, false ),
				'note'     => __( 'Collected across all bookings', 'wp-travel-engine' ),
			),
			array(
				'modifier' => 'due',
				'label'    => __( 'Total Balance Due', 'wp-travel-engine' ),
				'value'    => wptravelengine_the_price( $stats['due'], false, false ),
				'note'     => __( 'Outstanding across active bookings', 'wp-travel-engine' ),
			),
		);

		echo '<div class="wpte-booking-stats">';
		foreach ( $cards as $card ) {
			printf(
				'<div class="wpte-booking-stats__card wpte-booking-stats__card--%1$s">
					<span class="wpte-booking-stats__label">%2$s</span>
					<span class="wpte-booking-stats__value">%3$s</span>
					<span class="wpte-booking-stats__note">%4$s</span>
				</div>',
				esc_attr( $card['modifier'] ),
				esc_html( $card['label'] ),
				esc_html( $card['value'] ),
				esc_html( $card['note'] )
			);
		}
		echo '</div>';
	}

	/**
	 * Aggregate booking totals for the summary cards.
	 *
	 * @return array{total:int, fully_paid:int, received:float, due:float}
	 * @since 6.9.0 Archived bookings add no money or fully paid count.
	 */
	public static function get_stats(): array {
		$cached = get_transient( self::$stats_transient );

		if ( is_array( $cached ) ) {
			return $cached;
		}

		global $wpdb;

		$row = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			"SELECT COUNT(*) AS total,
				SUM( CASE WHEN archived.meta_id IS NULL AND COALESCE( NULLIF( tdue.meta_value, '' ), NULLIF( due.meta_value, '' ), 0 ) + 0 <= 0 THEN 1 ELSE 0 END ) AS fully_paid,
				SUM( CASE WHEN archived.meta_id IS NULL THEN COALESCE( NULLIF( tpaid.meta_value, '' ), NULLIF( paid.meta_value, '' ), 0 ) + 0 ELSE 0 END ) AS received,
				SUM( CASE WHEN archived.meta_id IS NULL THEN COALESCE( NULLIF( tdue.meta_value, '' ), NULLIF( due.meta_value, '' ), 0 ) + 0 ELSE 0 END ) AS due
			FROM {$wpdb->posts} p
			LEFT JOIN {$wpdb->postmeta} archived ON archived.post_id = p.ID AND archived.meta_key = 'wp_travel_engine_booking_status' AND archived.meta_value = 'archived'
			LEFT JOIN {$wpdb->postmeta} tpaid ON tpaid.post_id = p.ID AND tpaid.meta_key = 'total_paid_amount'
			LEFT JOIN {$wpdb->postmeta} paid  ON paid.post_id  = p.ID AND paid.meta_key  = 'paid_amount'
			LEFT JOIN {$wpdb->postmeta} tdue  ON tdue.post_id  = p.ID AND tdue.meta_key  = 'total_due_amount'
			LEFT JOIN {$wpdb->postmeta} due   ON due.post_id   = p.ID AND due.meta_key   = 'due_amount'
			WHERE p.post_type = 'booking' AND p.post_status = 'publish'",
			ARRAY_A
		);

		$stats = array(
			'total'      => (int) ( $row['total'] ?? 0 ),
			'fully_paid' => (int) ( $row['fully_paid'] ?? 0 ),
			'received'   => (float) ( $row['received'] ?? 0 ),
			'due'        => (float) ( $row['due'] ?? 0 ),
		);

		set_transient( self::$stats_transient, $stats, HOUR_IN_SECONDS );

		return $stats;
	}

	/**
	 * Drops the cached summary totals.
	 *
	 * @return void
	 */
	public static function clear_stats_cache(): void {
		delete_transient( self::$stats_transient );
	}

	/**
	 * Drops the cached totals when a booking is published, trashed or restored.
	 *
	 * @param string   $new_status New post status.
	 * @param string   $old_status Previous post status.
	 * @param \WP_Post $post       Post being transitioned.
	 * @return void
	 */
	public static function clear_stats_cache_on_transition( string $new_status, string $old_status, \WP_Post $post ): void {
		if ( 'booking' === $post->post_type && $new_status !== $old_status ) {
			self::clear_stats_cache();
		}
	}

	/**
	 * What the add booking wizard is opened with.
	 *
	 * @return array<string, mixed>
	 */
	public static function get_list_data(): array {
		$tax = new Tax();

		// Methods a payment can be recorded against; the wizard records, it does not charge.
		$payment_methods = array();

		foreach ( wp_travel_engine_get_available_payment_gateways() as $slug => $gateway ) {
			$payment_methods[] = array(
				'value' => (string) $slug,
				'label' => (string) ( $gateway['public_label'] ?? $gateway['label'] ?? wptravelengine_humanize_key( $slug ) ),
			);
		}

		// A custom trip has no rate card of its own, so the wizard prices it off every category.
		$pricing_categories = array_map(
			static function ( $term ): array {
				return array(
					'id'    => $term->term_id,
					'label' => $term->name,
				);
			},
			wptravelengine_settings()->get_traveler_categories()
		);

		// Fee add-ons declare their rate on the payment form fields, only while switched on.
		$payment_fees = array();

		foreach ( (array) apply_filters( 'wptravelengine_payments_form_fields', array() ) as $name => $field ) {
			$behaviours = $field['behaviours'] ?? array();

			if ( 'fee' !== ( $behaviours['type'] ?? '' ) ) {
				continue;
			}

			$payment_fees[] = array(
				'name'       => (string) $name,
				'label'      => wptravelengine_decode_entity( $field['field_label'] ?? '' ),
				'percentage' => (float) ( $behaviours['percentage'] ?? 0 ),
				'apply_tax'  => (bool) ( $behaviours['apply_tax'] ?? false ),
			);
		}

		if ( $tax->is_taxable() && ! $tax->is_inclusive() ) {
			$payment_fees[] = array(
				'name'       => 'tax',
				'label'      => $tax->get_tax_label(),
				'percentage' => (float) $tax->get_tax_percentage(),
			);
		}

		$payment_fees = apply_filters( 'wptravelengine_add_booking_payment_fees', $payment_fees );

		return array(
			'links'             => array(
				'rest'        => '/wptravelengine/v2/bookings/',
				'list'        => admin_url( 'edit.php?post_type=booking' ),
				'tripEdit'    => admin_url( 'post.php?action=edit&post=' ),
				'accountMode' => PluginSettings::url( 'dashboard-user-settings' ),
			),
			'labels'            => wptravelengine_get_labels(),
			'nonce'             => wp_create_nonce( 'wp_rest' ),
			'currencyCode'      => wp_travel_engine_get_currency_code(),
			'currencySymbol'    => wp_travel_engine_get_currency_symbol( wp_travel_engine_get_currency_code() ),
			'tripCatalog'       => self::get_trip_catalog(),
			'pricingCategories' => $pricing_categories,
			'paymentMethods'    => apply_filters( 'wptravelengine_booking_payment_methods', $payment_methods ),
			'paymentFees'       => $payment_fees,
			'exclusiveLabel'    => wptravelengine_decode_entity( CartInfoParser::get_exclusive_label( $payment_fees ) ),
			'shouldDoMultiTrip' => (bool) apply_filters( 'wptravelengine_should_do_multi_trip', false ),
			'accountMode'       => wptravelengine_account_creation_mode(),
			// Inclusive tax is already inside the prices, so the wizard treats it as tax off.
			'tax'               => array(
				'enabled'    => $tax->is_taxable() && ! $tax->is_inclusive(),
				'percentage' => $tax->get_tax_percentage(),
				'label'      => $tax->get_tax_label(),
			),
			'activePlugins'     => array(
				'accommodation'    => wptravelengine_is_addon_active( 'accommodation' ),
				'extra_service'    => wptravelengine_is_addon_active( 'extra_service' ),
				'travel_insurance' => wptravelengine_is_addon_active( 'travel_insurance' ),
			),
		);
	}

	/**
	 * The trips the wizard offers; terms and `_s_price` come from caches the query primes.
	 *
	 * @return array<int, array<string, mixed>> Trips, titles first.
	 */
	protected static function get_trip_catalog(): array {
		$statuses = array( 'publish' );

		if ( current_user_can( 'read_private_posts' ) ) {
			$statuses[] = 'private';
		}

		$query = new \WP_Query(
			array(
				'post_type'      => 'trip',
				'post_status'    => $statuses,
				'posts_per_page' => 500,
				'orderby'        => 'title',
				'order'          => 'ASC',
				'no_found_rows'  => true,
			)
		);

		$catalog = array();

		foreach ( $query->posts as $trip ) {
			$destinations = get_the_terms( $trip, 'destination' );
			$destination  = is_array( $destinations ) && ! empty( $destinations )
				? reset( $destinations )
				: null;

			// Stored in hours, whichever unit the trip is edited in.
			$duration = get_post_meta( $trip->ID, '_s_duration', true );

			if ( '' === $duration ) {
				$duration = get_post_meta( $trip->ID, 'wp_travel_engine_setting_trip_duration', true );
			}

			$settings = get_post_meta( $trip->ID, 'wp_travel_engine_setting', true );

			$catalog[] = array(
				'id'       => (int) $trip->ID,
				'title'    => html_entity_decode( get_the_title( $trip ), ENT_QUOTES, 'UTF-8' ),
				'location' => $destination ? $destination->name : '',
				'price'    => (float) get_post_meta( $trip->ID, '_s_price', true ),
				'duration' => (float) $duration,
				'nights'   => (int) ( $settings['trip_duration_nights'] ?? 0 ),
			);
		}

		return $catalog;
	}

	/**
	 * Enqueues the list table script and the add booking wizard.
	 *
	 * @param string $hook_suffix Current admin page.
	 * @return void
	 */
	public function enqueue_list_scripts( string $hook_suffix ): void {
		$screen = get_current_screen();

		if ( 'edit.php' !== $hook_suffix || ! $screen || $this->post_type !== $screen->post_type ) {
			return;
		}

		self::enqueue_app_script( 'wptravelengine-booking-list', 'admin/booking-list.js' );

		if ( wptravelengine_debug_enabled() ) {
			add_filter( 'admin_body_class', static fn( string $classes ): string => $classes . ' wptravelengine-debug-enabled ' );
		}

		$handle = self::enqueue_app_script( 'wptravelengine-add-booking-app', 'admin/add-booking-app.js' );

		wp_enqueue_style( 'wte-fpickr' );
		wp_localize_script( $handle, 'wptravelengineAddBookingData', self::get_list_data() );

		add_action( 'admin_footer', static fn() => print( '<div id="wptravelengine-add-booking-app"></div>' ) );
	}

	/**
	 * Registers and enqueues one of the booking screens' React apps.
	 *
	 * Relies on `wte-global` for `wteL10n`; localizing it again would drop `priceFormat`.
	 *
	 * @param string $handle Script handle.
	 * @param string $file   Path under dist/.
	 * @return string The handle.
	 */
	protected static function enqueue_app_script( string $handle, string $file ): string {
		$script = Asset::register( $handle, $file );

		wp_enqueue_script(
			$handle,
			$script->source,
			array_merge( $script->dependencies, array( 'wte-fpickr', 'wte-global' ) ),
			$script->version,
			true
		);

		wp_set_script_translations(
			$handle,
			'wp-travel-engine',
			plugin_dir_path( WP_TRAVEL_ENGINE_FILE_PATH ) . 'languages/'
		);

		return $handle;
	}
}
