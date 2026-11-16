<?php
/**
 * This file contains the Array class.
 *
 * @package WPTravelEngine
 * @since 6.0.0
 */

namespace WPTravelEngine\Utilities;

use WPTravelEngine\Traits\Factory;

/**
 * Array class.
 */
class ArrayUtility {
	/**
	 * Callback key standing for every value rather than one named key.
	 *
	 * @var string
	 * @since 6.9.0
	 */
	const ALL = '__all__';

	/**
	 * @var mixed
	 */
	protected $data;

	/**
	 * Create Instance.
	 */
	public static function make( $array ): ArrayUtility {
		$array = is_array( $array ) ? $array : array();

		return new static( $array );
	}

	/**
	 * ArrayUtility constructor.
	 *
	 * @param array $array Array.
	 */
	protected function __construct( array $array ) {
		$this->data = $array;
	}

	/**
	 * Get value from array.
	 *
	 * @param string $key Key.
	 * @param mixed  $default Default.
	 *
	 * @return mixed
	 */
	public function get( string $key, $default = null ) {
		$keys = explode( '.', $key );

		return static::search( $this->data, $keys ) ?? $default;
	}

	/**
	 * Set value in array.
	 *
	 * @param string $key Key.
	 * @param mixed  $value Value.
	 *
	 * @return ArrayUtility
	 */
	public function set( string $key, $value ): ArrayUtility {
		$keys    = explode( '.', $key );
		$lastKey = array_pop( $keys );
		$data    = &$this->data;
		foreach ( $keys as $key ) {
			if ( ! isset( $data[ $key ] ) || ! is_array( $data[ $key ] ) ) {
				$data[ $key ] = array();
			}
			$data = &$data[ $key ];
		}
		$data[ $lastKey ] = $value;

		return $this;
	}

	/**
	 * Remove from array.
	 *
	 * @param string $key The key to be removed in dot-separated form.
	 *
	 * @return ArrayUtility
	 * @since 6.8.0
	 */
	public function remove( string $key ): ArrayUtility {
		$keys    = explode( '.', $key );
		$lastKey = array_pop( $keys );
		$data    = &$this->data;
		foreach ( $keys as $key ) {
			if ( ! isset( $data[ $key ] ) || ! is_array( $data[ $key ] ) ) {
				return $this;
			}
			$data = &$data[ $key ];
		}
		unset( $data[ $lastKey ] );

		return $this;
	}

	/**
	 * Get the value of the array.
	 *
	 * @return mixed
	 */
	public function value() {
		return $this->data;
	}

	/**
	 * Recursive helper function to retrieve nested values from the settings array.
	 *
	 * @param array $data The current level of data to traverse.
	 * @param array $keys The remaining keys in the dot-separated path.
	 *
	 * @return mixed The value at the end of the path, or null if not found.
	 */
	public static function search( array $data, array $keys ) {
		$key = array_shift( $keys );
		if ( ! isset( $data[ $key ] ) ) {
			return null;
		}

		if ( empty( $keys ) ) {
			return $data[ $key ];
		} else {
			if ( ! is_array( $data[ $key ] ) ) {
				return null;
			}

			return static::search( $data[ $key ], $keys );
		}
	}

	/**
	 * Flatten a multi-dimensional associative array with dots.
	 *
	 * @param array  $array The array to flatten.
	 * @param string $prefix The prefix to prepend to the keys.
	 *
	 * @return array The flattened array.
	 * @since 6.2.0
	 */
	public static function flatten( array $array, string $prefix = '' ): array {
		$result = array();
		foreach ( $array as $key => $value ) {
			if ( is_array( $value ) ) {
				$result = array_merge( $result, static::flatten( $value, $prefix . $key . '.' ) );
			} else {
				$result[ $prefix . $key ] = $value;
			}
		}

		return $result;
	}

	/**
	 * @param array    $data
	 * @param null|int $base_index
	 *
	 * @return array
	 * @since 6.4.0
	 */
	public static function normalize( array $data, $base_index = null ): array {
		$result = array();

		// Get the first key to determine the number of elements
		$keys = array_keys( $data );
		if ( empty( $keys ) ) {
			return $result; // Return empty if no data
		}

		if ( ! $base_index ) {
			$base_index = $keys[0];
		}

		if ( count( $data[ $base_index ] ?? array() ) < 1 ) {
			return $result;
		}

		foreach ( array_keys( $data[ $base_index ] ) as $_key ) {
			$temp = array();
			foreach ( $keys as $key ) {
				$temp[ $key ] = $data[ $key ][ $_key ] ?? null; // Use null if index is missing
			}
			$result[] = $temp;
		}

		return $result;
	}

	/**
	 * Returns a value when it is already an array, and an empty array otherwise.
	 *
	 * Unlike an `(array)` cast, a scalar does not become a one-element list.
	 *
	 * @param mixed $value Value to guard.
	 * @return array<mixed> The value, or an empty array.
	 * @since 6.9.0
	 */
	public static function to_array( $value ): array {
		return is_array( $value ) ? $value : array();
	}

	/**
	 * Adds items to the end of a list, optionally capping what the result keeps.
	 *
	 * @param array<int, mixed> $entries List to add to.
	 * @param array<int, mixed> $items   Items to add after it.
	 * @param int|null          $limit   How many entries to keep, or null to keep them all.
	 * @return array<int, mixed> The combined list.
	 * @since 6.9.0
	 */
	public static function append( array $entries, array $items, ?int $limit = null ): array {
		$result = array_merge( array_values( $entries ), array_values( $items ) );

		return null === $limit ? $result : array_slice( $result, 0, max( 0, $limit ) );
	}

	/**
	 * Keys as a map to intersect against, named either as a list of values
	 * ( `array( 'id', 'label' )` ) or as the keys themselves ( `array( 'id' => true )` ).
	 *
	 * @param array<mixed> $keys Keys, in either spelling.
	 * @return array<string|int, mixed> Map keyed by the names.
	 * @since 6.9.0
	 */
	public static function key_mask( array $keys ): array {
		$is_list = array_keys( $keys ) === range( 0, count( $keys ) - 1 );

		return $is_list ? array_flip( $keys ) : $keys;
	}

	/**
	 * The named keys of an array, and nothing else.
	 *
	 * @param array<string, mixed> $array Array to take from.
	 * @param array<mixed>         $keys  Keys to keep.
	 * @return array<string, mixed> The kept entries.
	 * @since 6.9.0
	 */
	public static function only( array $array, array $keys ): array {
		return $keys ? array_intersect_key( $array, static::key_mask( $keys ) ) : array();
	}

	/**
	 * `only()` over a list of rows.
	 *
	 * @param array<mixed> $rows Rows to take from.
	 * @param array<mixed> $keys Keys to keep from each row.
	 * @return array<mixed> The rows, reduced.
	 * @since 6.9.0
	 */
	public static function only_each( array $rows, array $keys ): array {
		if ( ! $keys ) {
			return array();
		}

		$mask = static::key_mask( $keys );
		$kept = array();

		foreach ( $rows as $key => $row ) {
			$kept[ $key ] = array_intersect_key( static::to_array( $row ), $mask );
		}

		return $kept;
	}

	/**
	 * The array with its keys renamed, wherever the map names one.
	 *
	 * @param array<string, mixed>  $array Array to rename within.
	 * @param array<string, string> $map   Current key => what to call it.
	 * @return array<string, mixed> The array, renamed.
	 * @since 6.9.0
	 */
	public static function rename( array $array, array $map ): array {
		if ( ! $map ) {
			return $array;
		}

		$renamed = array();

		foreach ( $array as $key => $value ) {
			$name = $map[ $key ] ?? null;

			$renamed[ is_string( $name ) && '' !== $name ? $name : $key ] = $value;
		}

		return $renamed;
	}

	/**
	 * `rename()` over a list of rows.
	 *
	 * @param array<mixed>          $rows Rows to rename within.
	 * @param array<string, string> $map  Current key => what to call it.
	 * @return array<mixed> The rows, renamed.
	 * @since 6.9.0
	 */
	public static function rename_each( array $rows, array $map ): array {
		if ( ! $map ) {
			return $rows;
		}

		$renamed = array();

		foreach ( $rows as $key => $row ) {
			$renamed[ $key ] = static::rename( static::to_array( $row ), $map );
		}

		return $renamed;
	}

	/**
	 * The array with the named keys put through their own callable.
	 *
	 * `self::ALL` names a callable for every value, which a key's own overrides; anything
	 * unnamed is left as it was. The callable gets the value alone, since `floatval` and the
	 * like refuse a second argument.
	 *
	 * @param array<string, mixed>    $array     Array to convert within.
	 * @param array<string, callable> $callbacks Key => callable, plus `self::ALL` for the rest.
	 * @return array<string, mixed> The array, converted.
	 * @since 6.9.0
	 */
	public static function transform( array $array, array $callbacks ): array {
		$all = $callbacks[ static::ALL ] ?? null;

		if ( ! is_callable( $all ) ) {
			foreach ( $callbacks as $key => $callback ) {
				if ( array_key_exists( $key, $array ) && is_callable( $callback ) ) {
					$array[ $key ] = $callback( $array[ $key ] );
				}
			}

			return $array;
		}

		$transformed = array();

		foreach ( $array as $key => $value ) {
			$callback = $callbacks[ $key ] ?? null;

			$transformed[ $key ] = is_callable( $callback ) ? $callback( $value ) : $all( $value );
		}

		return $transformed;
	}

	/**
	 * `transform()` over a list of rows.
	 *
	 * @param array<mixed>            $rows      Rows to take from.
	 * @param array<string, callable> $callbacks Key => what to put its value through.
	 * @return array<mixed> The rows, converted.
	 * @since 6.9.0
	 */
	public static function transform_each( array $rows, array $callbacks ): array {
		if ( ! $callbacks ) {
			return $rows;
		}

		$transformed = array();

		foreach ( $rows as $key => $row ) {
			$transformed[ $key ] = static::transform( static::to_array( $row ), $callbacks );
		}

		return $transformed;
	}

	/**
	 * The key of the first row matching every key and value given.
	 *
	 * @param array<mixed>         $rows   Rows to search.
	 * @param array<string, mixed> $match  Key => value every match must carry.
	 * @param bool                 $strict False compares values as strings, so a stored `123` matches a `'123'`.
	 * @return int|string|null The matching row's key, or null when none matches.
	 * @since 6.9.0
	 */
	public static function get_key( array $rows, array $match, bool $strict = false ) {
		if ( ! $match ) {
			return null;
		}

		foreach ( $rows as $index => $row ) {
			$row = static::to_array( $row );

			foreach ( $match as $key => $value ) {
				if ( ! array_key_exists( $key, $row ) ) {
					continue 2;
				}

				$same = $strict ? $row[ $key ] === $value : (string) $row[ $key ] === (string) $value;

				if ( ! $same ) {
					continue 2;
				}
			}

			return $index;
		}

		return null;
	}
}
