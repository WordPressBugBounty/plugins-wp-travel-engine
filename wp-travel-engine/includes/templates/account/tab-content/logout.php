<?php
	/**
	 * Tab Logout.
	 *
	 * @deprecated 6.9.0 No longer loaded by WP Travel Engine, so a theme override of it has no effect. It will be removed in a future release.
	 */
?>
<div class="log-out">
	<div class="title">
		<h3><?php esc_html_e( 'Log Out?', 'wp-travel-engine' ); ?></h3>
		<span>
			<?php esc_html_e( 'Are you sure want to log out?', 'wp-travel-engine' ); ?>
			<a href="<?php echo esc_url( wp_logout_url( wp_travel_engine_get_page_permalink_by_id( wp_travel_engine_get_dashboard_page_id() ) ) ); ?>"><?php esc_html_e( 'Log Out', 'wp-travel-engine' ); ?></a>
		</span>
	</div>
</div>
<?php
