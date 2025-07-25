<?php
do_action( 'wp_travel_engine_trip_archive_wrap' );
return;

// TODO: Remove this once above is stable
$active_theme = get_option( 'template', '' );
global $post;

$settings                 = get_option( 'wp_travel_engine_settings', array() );
$collapsible_filter_panel = $settings[ 'archive' ][ 'collapsible_filter_panel' ] ?? 'no';

wp_enqueue_style( 'wp-travel-engine' );
wp_enqueue_script( 'wp-travel-engine' );

use WPTravelEngine\Modules\TripSearch;

?>

<div
	class="wp-travel-engine-archive-outer-wrap <?php echo 'yes' === $collapsible_filter_panel ? 'collapsible-filter-panel' : '' ?>">
	<?php
	/**
	 * wp_travel_engine_archive_sidebar hook
	 *
	 * @hooked wte_advanced_search_archive_sidebar
	 */
	do_action( 'wp_travel_engine_archive_sidebar' );
	?>
	<div class="wp-travel-engine-archive-repeater-wrap">
		<?php
		// $orderby   = isset( $_GET['wte_orderby'] ) && ! empty( $_GET['wte_orderby'] ) ? $_GET['wte_orderby'] : '';
		\Wp_Travel_Engine_Archive_Hooks::archive_filters_sub_options();
		?>
		<div class="wte-category-outer-wrap">
			<?php
			$j          = 1;
			$view_mode  = wp_travel_engine_get_archive_view_mode();
			$classes    = apply_filters( 'wte_advanced_search_trip_results_grid_classes', 'wte-col-2 category-grid' );
			$view_class = 'grid' === $view_mode ? $classes : 'category-list';

			echo '<div class="category-main-wrap ' . esc_attr( $view_class ) . '">';
			$query = new \WP_Query( TripSearch::get_query_args() );

			if ( ! $query->have_posts() ) {
				echo apply_filters( 'no_result_found_message', __( 'No results found!', 'wp-travel-engine' ) );
			}

			$user_wishlists = wptravelengine_user_wishlists();

			while ( $query->have_posts() ) {
				$query->the_post();
				$details                     = wte_get_trip_details( get_the_ID() );
				$details[ 'j' ]              = $j;
				$details[ 'user_wishlists' ] = $user_wishlists;

				// wte_get_template( 'content-' . $view_mode . '.php', $details );
				if ( version_compare( '6.0.0', \WP_TRAVEL_ENGINE_VERSION, '<' ) ) {
					wte_get_template( 'content-' . $view_mode . '.php', $details );
				} else {
					$details[ 'view_mode' ] = $view_mode;
					wte_get_template( 'content-view.php', $details );
				}
				$j ++;
			}
			wp_reset_postdata();
			echo '</div>';

			$total_pages = $query->max_num_pages;
			$big         = 999999999; // need an unlikely integer

			if ( $total_pages > 1 ) {
				$current_page = max( 1, get_query_var( 'paged' ) );
				echo '<div class="trip-pagination pagination">';
				echo '<div class="nav-links">';
				echo paginate_links(
					array(
						'base'               => str_replace( $big, '%#%', esc_url( get_pagenum_link( $big ) ) ),
						'format'             => '?paged=%#%',
						'current'            => $current_page,
						'total'              => $total_pages,
						'prev_text'          => __( 'Previous', 'wp-travel-engine' ),
						'next_text'          => __( 'Next', 'wp-travel-engine' ),
						'before_page_number' => '<span class="meta-nav screen-reader-text">' . __( 'Page', 'wp-travel-engine' ) . ' </span>',
					)
				); //phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				echo '</div>';
				echo '</div>';
			}
			?>
		</div>
		<div id="loader" style="display: none">
			<div class="table">
				<div class="table-grid">
					<div class="table-cell">
						<i class="fa fa-spinner fa-spin" aria-hidden="true"></i>
					</div>
				</div>
			</div>
		</div>
		<?php
		$nonce = wp_create_nonce( 'wte_show_ajax_result' );
		?>
		<input type="hidden" name="search-nonce" id="search-nonce" value="<?php echo esc_attr( $nonce ); ?>">
	</div>
</div>

