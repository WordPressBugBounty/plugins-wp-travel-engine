<?php
/**
 * Abstract handler base class.
 *
 * @package WPTravelEngine\Logger\Handlers
 * @since 6.7.6
 */

namespace WPTravelEngine\Logger\Handlers;

/**
 * Abstract handler class.
 *
 * Base class for all log handlers.
 *
 * @since 6.7.6
 */
abstract class Handler {

	/**
	 * Handle a log entry.
	 *
	 * @param string $level   Log level.
	 * @param string $message Log message.
	 * @param array  $context Additional context.
	 * @return void
	 * @since 6.7.6
	 */
	abstract public function handle( string $level, string $message, array $context ): void;

	/**
	 * Format timestamp in ISO 8601 format.
	 *
	 * @param int $timestamp Unix timestamp.
	 * @return string Formatted timestamp.
	 * @since 6.7.6
	 */
	protected function format_timestamp( int $timestamp ): string {
		return gmdate( 'Y-m-d H:i:s', $timestamp );
	}

	/**
	 * Format a log entry.
	 *
	 * @param string $level   Log level.
	 * @param string $message Log message.
	 * @param array  $context Additional context.
	 * @return string Formatted log entry.
	 * @since 6.7.6
	 */
	protected function format_entry( string $level, string $message, array $context ): string {
		$timestamp = $this->format_timestamp( time() );
		$level     = strtoupper( $level );

		$entry = sprintf(
			'%s %s %s',
			$timestamp,
			$level,
			$message
		);

		if ( ! empty( $context ) ) {
			$entry .= ' CONTEXT: ' . wp_json_encode( $context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		}

		return $entry;
	}
}
