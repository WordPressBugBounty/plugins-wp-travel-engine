<?php

/**
 * Utility class for sesion utilities
 *
 * THIS CLASS SHOULD NEVER BE INSTANTIATED
 */
class WP_Session_Utils {
	/**
	 * Count the total sessions in the database.
	 *
	 * @global wpdb $wpdb
	 *
	 * @return int
	 */
	public static function count_sessions() {
		global $wpdb;

		$query = "SELECT COUNT(*) FROM $wpdb->options WHERE option_name LIKE '_wp_session_expires_%'";

		/**
		 * Filter the query in case tables are non-standard.
		 *
		 * @param string $query Database count query
		 */
		$query = apply_filters( 'wp_session_count_query', $query );

		$sessions = $wpdb->get_var( $query );

		return absint( $sessions );
	}

	/**
	 * Create a new, random session in the database.
	 *
	 * @param null|string $date
	 */
	public static function create_dummy_session( $date = null ) {
		// Generate our date
		if ( null !== $date ) {
			$time = strtotime( $date );

			if ( false === $time ) {
				$date = null;
			} else {
				$expires = date( 'U', strtotime( $date ) );
			}
		}

		// If null was passed, or if the string parsing failed, fall back on a default
		if ( null === $date ) {
			/**
			 * Filter the expiration of the session in the database
			 *
			 * @param int
			 */
			$expires = time() + (int) apply_filters( 'wp_session_expiration', 30 * 60 );
		}

		$session_id = self::generate_id();

		// Store the session
		add_option( "_wp_session_{$session_id}", array(), '', 'no' );
		add_option( "_wp_session_expires_{$session_id}", $expires, '', 'no' );
	}

	/**
	 * @return void
	 * @since 6.4.3
	 */
	public static function clear_leftover_cache_data() {
		global $wpdb;

		$prefix      = '_wp_session_';
		$batch_size  = 1000;
		$loop_count  = 0;
		$max_loops   = 1000;

		do {
			$option_names = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s LIMIT %d",
					$prefix . '%',
					$batch_size
				)
			);

			if ( ! empty( $option_names ) ) {
				$placeholders = implode( ',', array_fill( 0, count( $option_names ), '%s' ) );
				$query        = $wpdb->prepare(
					"DELETE FROM {$wpdb->options} WHERE option_name IN ($placeholders)",
					...$option_names
				);
				$wpdb->query( $query );
			} else {
				update_option( '_wptravelengine_leftover_cached_cleared', 'yes' );
			}

			$loop_count++;
		} while ( ! empty( $option_names ) && $loop_count < $max_loops );
	}

	/**
	 * Delete old sessions from the database.
	 *
	 * @param int $limit Maximum number of sessions to delete.
	 *
	 * @global wpdb $wpdb
	 *
	 * @return int Sessions deleted.
	 */
	public static function delete_old_sessions( $limit = 1000 ) {
		global $wpdb;

		if ( 'yes' !== get_option( '_wptravelengine_leftover_cached_cleared', 'no' ) ) {
			static::clear_leftover_cache_data();
		}

		$limit = absint( $limit );
		$keys  = $wpdb->get_results( "SELECT option_name, option_value FROM $wpdb->options WHERE option_name LIKE '_wp_session_expires_%' ORDER BY option_value ASC LIMIT 0, {$limit}" );

		$now     = time();
		$expired = array();
		$count   = 0;

		foreach ( $keys as $expiration ) {
			$key     = $expiration->option_name;
			$expires = $expiration->option_value;

			if ( $now > $expires ) {
				$session_id = addslashes( substr( $key, 20 ) );

				$expired[] = $key;
				$expired[] = "_wp_session_{$session_id}";

				$count += 1;
			}
		}

		// Delete expired sessions
		if ( ! empty( $expired ) ) {
			$names = implode( "','", $expired );
			$wpdb->query( "DELETE FROM $wpdb->options WHERE option_name IN ('{$names}')" );
		}

		return $count;
	}

	/**
	 * Remove all sessions from the database, regardless of expiration.
	 *
	 * @global wpdb $wpdb
	 *
	 * @return int Sessions deleted
	 */
	public static function delete_all_sessions() {
		global $wpdb;

		$count = $wpdb->query( "DELETE FROM $wpdb->options WHERE option_name LIKE '_wp_session_%'" );

		return (int) ( $count / 2 );
	}

	/**
	 * Generate a new, random session ID.
	 *
	 * @return string
	 */
	public static function generate_id() {
		require_once ABSPATH . 'wp-includes/class-phpass.php';
		$hash = new PasswordHash( 8, false );

		return md5( $hash->get_random_bytes( 32 ) );
	}
}
