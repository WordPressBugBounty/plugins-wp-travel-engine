<?php
/**
 * Interface for wptravelengine custom database table queries.
 *
 * @package WPTravelEngine/Interfaces
 * @since 6.8.3
 */

namespace WPTravelEngine\Interfaces;

interface Queryable {

	/**
	 * Insert a row.
	 *
	 * @param array $data Column => value pairs.
	 *
	 * @return int|false Inserted row ID, or false on failure.
	 */
	public function insert( array $data );

	/**
	 * Find a row by primary key.
	 *
	 * @param int $id Primary key value.
	 *
	 * @return array|null Row as an associative array, or null if not found.
	 */
	public function find( int $id ): ?array;

	/**
	 * Update a row by primary key.
	 *
	 * @param int   $id Primary key value.
	 * @param array $data Column => value pairs to update.
	 *
	 * @return bool
	 */
	public function update( int $id, array $data ): bool;

	/**
	 * Delete a row by primary key.
	 *
	 * @param int $id Primary key value.
	 *
	 * @return bool
	 */
	public function delete( int $id ): bool;
}
