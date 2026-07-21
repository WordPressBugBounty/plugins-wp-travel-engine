<?php
/**
 * Base class for querying wptravelengine custom database tables.
 *
 * @package WPTravelEngine/Abstracts
 * @since 6.8.3
 */

namespace WPTravelEngine\Abstracts;

use WPTravelEngine\Interfaces\Queryable;

/**
 * Extend this for each custom `wptravelengine_*` table and implement `table_name()`.
 */
abstract class Table implements Queryable {

	/**
	 * Operators allowed in `build_where()` conditions.
	 *
	 * @var string[]
	 */
	protected const ALLOWED_OPERATORS = array( '=', '<=', '>=', '<', '>', 'IN' );

	/**
	 * Memoized per-class singleton instances.
	 *
	 * @var array<class-string, static>
	 */
	protected static array $instances = array();

	/**
	 * Fully-prefixed table name.
	 *
	 * @var string
	 */
	protected string $table;

	/**
	 * Primary key column name.
	 *
	 * @var string
	 */
	protected string $primary_key = 'id';

	/**
	 * Constructor.
	 */
	public function __construct() {
		global $wpdb;
		$this->table = $wpdb->prefix . $this->table_name();
	}

	/**
	 * Get the memoized instance for the called class.
	 *
	 * @return static
	 */
	public static function instance(): self {
		return static::$instances[ static::class ] ??= new static();
	}

	/**
	 * Unprefixed table name, e.g. `wptravelengine_events`.
	 *
	 * @return string
	 */
	abstract protected function table_name(): string;

	/**
	 * Guard against non-identifier column names before interpolating them into raw SQL.
	 *
	 * @param string $column Column name.
	 *
	 * @return void
	 * @throws \InvalidArgumentException If `$column` isn't a safe SQL identifier.
	 */
	protected function assert_valid_column( string $column ): void {
		if ( ! preg_match( '/^[a-zA-Z_][a-zA-Z0-9_]*$/', $column ) ) {
			throw new \InvalidArgumentException( sprintf( 'Invalid column name: %s', $column ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- internal exception message, not rendered output.
		}
	}

	/**
	 * Insert a row.
	 *
	 * @param array $data Column => value pairs.
	 *
	 * @return int|false Inserted row ID, or false on failure.
	 */
	public function insert( array $data ) {
		global $wpdb;

		$result = $wpdb->insert( $this->table, $data );

		return $result ? (int) $wpdb->insert_id : false;
	}

	/**
	 * Find a row by primary key.
	 *
	 * @param int $id Primary key value.
	 *
	 * @return array|null
	 */
	public function find( int $id ): ?array {
		global $wpdb;

		// Use %i placeholder for table name (WP 6.2+). $this->primary_key is a hardcoded
		// class property (not user input), so interpolating the column name here is safe.
		$query = "SELECT * FROM %i WHERE `{$this->primary_key}` = %d"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$row = $wpdb->get_row( $wpdb->prepare( $query, $this->table, $id ), ARRAY_A );

		return $row;
	}

	/**
	 * Update a row by primary key.
	 *
	 * @param int   $id Primary key value.
	 * @param array $data Column => value pairs to update.
	 *
	 * @return bool
	 */
	public function update( int $id, array $data ): bool {
		global $wpdb;

		return false !== $wpdb->update( $this->table, $data, array( $this->primary_key => $id ) );
	}

	/**
	 * Delete a row by primary key.
	 *
	 * @param int $id Primary key value.
	 *
	 * @return bool
	 */
	public function delete( int $id ): bool {
		global $wpdb;

		return false !== $wpdb->delete( $this->table, array( $this->primary_key => $id ) );
	}

	/**
	 * Insert a row, or update it if it collides with an existing unique/primary key.
	 *
	 * Pass every column that should reset on collision (e.g. re-arming a `triggered` flag).
	 *
	 * @param array $data Column => value pairs. A `null` value is written as SQL `NULL`.
	 *
	 * @return int|false Row ID, or false on failure.
	 */
	public function upsert( array $data ) {
		global $wpdb;

		$columns       = array();
		$placeholders  = array();
		$update_clause = array();
		$values        = array();

		foreach ( $data as $column => $value ) {
			$this->assert_valid_column( $column );

			$columns[]       = "`{$column}`";
			$update_clause[] = "`{$column}` = VALUES(`{$column}`)";

			if ( null === $value ) {
				$placeholders[] = 'NULL';
				continue;
			}

			$placeholders[] = is_int( $value ) ? '%d' : '%s';
			$values[]       = $value;
		}

		$query = 'INSERT INTO %i (' . implode( ', ', $columns ) . ') VALUES (' . implode( ', ', $placeholders ) . ') ON DUPLICATE KEY UPDATE ' . implode( ', ', $update_clause ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- column names are validated identifiers.

		$sql = $wpdb->prepare( $query, array_merge( array( $this->table ), $values ) );

		$result = $wpdb->query( $sql );

		return $result ? (int) $wpdb->insert_id : false;
	}

	/**
	 * Find rows matching arbitrary conditions.
	 *
	 * @param array $conditions List of `[ column, operator, value ]` triples, AND-joined.
	 *                          `operator` is one of `=`, `<=`, `>=`, `<`, `>`, `IN`.
	 *
	 * @return array List of rows as associative arrays.
	 */
	public function where( array $conditions ): array {
		global $wpdb;

		$values = array();
		$clause = $this->build_where( $conditions, $values );

		// Use %i placeholder for table name (WP 6.2+).
		$query = "SELECT * FROM %i WHERE {$clause}"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $clause uses validated column/operator names; values are placeholders or absint-cast.

		$sql = $wpdb->prepare( $query, array_merge( array( $this->table ), $values ) );

		return $wpdb->get_results( $sql, ARRAY_A ) ?? array();
	}

	/**
	 * Update rows matching arbitrary conditions.
	 *
	 * @param array $conditions List of `[ column, operator, value ]` triples, AND-joined.
	 * @param array $data Column => value pairs to update. A `null` value is written as SQL `NULL`.
	 *
	 * @return int Number of affected rows.
	 */
	public function update_where( array $conditions, array $data ): int {
		global $wpdb;

		$set_clause = array();
		$values     = array();

		foreach ( $data as $column => $value ) {
			$this->assert_valid_column( $column );

			if ( null === $value ) {
				$set_clause[] = "`{$column}` = NULL";
				continue;
			}

			$set_clause[] = "`{$column}` = " . ( is_int( $value ) ? '%d' : '%s' );
			$values[]     = $value;
		}

		$where_clause = $this->build_where( $conditions, $values );

		$query = 'UPDATE %i SET ' . implode( ', ', $set_clause ) . " WHERE {$where_clause}"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- clauses use validated column/operator names; values are placeholders or absint-cast.

		$sql = $wpdb->prepare( $query, array_merge( array( $this->table ), $values ) );

		$result = $wpdb->query( $sql );

		return false === $result ? 0 : (int) $result;
	}

	/**
	 * Delete rows matching arbitrary conditions.
	 *
	 * @param array $conditions List of `[ column, operator, value ]` triples, AND-joined.
	 *
	 * @return int Number of affected rows.
	 */
	public function delete_where( array $conditions ): int {
		global $wpdb;

		$values = array();
		$clause = $this->build_where( $conditions, $values );

		$query = "DELETE FROM %i WHERE {$clause}"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $clause uses validated column/operator names; values are placeholders or absint-cast.

		$sql = $wpdb->prepare( $query, array_merge( array( $this->table ), $values ) );

		$result = $wpdb->query( $sql );

		return false === $result ? 0 : (int) $result;
	}

	/**
	 * Check whether any row matches arbitrary conditions.
	 *
	 * @param array $conditions List of `[ column, operator, value ]` triples, AND-joined.
	 *
	 * @return bool
	 */
	public function exists_where( array $conditions ): bool {
		global $wpdb;

		$values = array();
		$clause = $this->build_where( $conditions, $values );

		$query = "SELECT 1 FROM %i WHERE {$clause} LIMIT 1"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $clause uses validated column/operator names; values are placeholders or absint-cast.

		$sql = $wpdb->prepare( $query, array_merge( array( $this->table ), $values ) );

		return null !== $wpdb->get_var( $sql );
	}

	/**
	 * Build an AND-joined WHERE clause and collect its placeholder values.
	 *
	 * `IN` values are `absint`-cast and inlined directly (can't be placeholder-bound).
	 *
	 * @param array $conditions List of `[ column, operator, value ]` triples.
	 * @param array $values Populated by reference with values for the non-`IN` placeholders.
	 *
	 * @return string
	 * @throws \InvalidArgumentException If a column name or operator isn't allowed.
	 */
	protected function build_where( array $conditions, array &$values ): string {
		$clauses = array();

		foreach ( $conditions as $condition ) {
			list( $column, $operator, $value ) = $condition;

			$this->assert_valid_column( $column );

			if ( ! in_array( $operator, self::ALLOWED_OPERATORS, true ) ) {
				throw new \InvalidArgumentException( sprintf( 'Disallowed operator: %s', $operator ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- internal exception message, not rendered output.
			}

			if ( 'IN' === $operator ) {
				$ids       = implode( ',', array_map( 'absint', (array) $value ) );
				$clauses[] = "`{$column}` IN ({$ids})";
				continue;
			}

			$clauses[] = "`{$column}` {$operator} " . ( is_int( $value ) ? '%d' : '%s' );
			$values[]  = $value;
		}

		return implode( ' AND ', $clauses );
	}
}
