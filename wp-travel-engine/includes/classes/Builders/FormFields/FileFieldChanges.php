<?php
/**
 * What one save does to the files its file fields hold.
 *
 * @package WPTravelEngine/Builders/FormFields
 * @since 6.9.0
 */

namespace WPTravelEngine\Builders\FormFields;

use WP_Error;
use WP_REST_Request;

/**
 * Keeps, adds and removes the files of one owner's file fields, within one save.
 *
 * Files arrive with the save they belong to, under `files[<path>][]`, so nothing is stored for
 * a form that is never saved. The path names a field in the owner's own shape, e.g.
 * `billing.upload_proof` or `travelers.1.passport`; the caller says which `FileField` each
 * path is, so this works for any owner and any field source.
 *
 * New attachments are undone by `rollback()`; removed ones are deleted only by `commit()`.
 *
 * @since 6.9.0
 */
class FileFieldChanges {

	/**
	 * Post the files belong to.
	 *
	 * @var int
	 */
	protected int $owner_id;

	/**
	 * Uploads not yet stored, keyed by path, one `$_FILES`-shaped entry each.
	 *
	 * @var array<string, array<int, array<string, mixed>>>
	 */
	protected array $uploads;

	/**
	 * Attachments this save created, for `rollback()`.
	 *
	 * @var int[]
	 */
	protected array $created = array();

	/**
	 * Attachments this save dropped, for `commit()`.
	 *
	 * @var int[]
	 */
	protected array $removed = array();

	/**
	 * Constructor.
	 *
	 * @param int                                             $owner_id Post the files belong to.
	 * @param array<string, array<int, array<string, mixed>>> $uploads  Uploads keyed by path.
	 */
	public function __construct( int $owner_id, array $uploads = array() ) {
		$this->owner_id = $owner_id;
		$this->uploads  = $uploads;
	}

	/**
	 * Changes carrying a REST request's uploads.
	 *
	 * @param WP_REST_Request $request  Save request.
	 * @param int             $owner_id Post the files belong to.
	 * @param string          $key      Top-level name the files were sent under.
	 * @return self
	 */
	public static function from_request( WP_REST_Request $request, int $owner_id, string $key = 'files' ): self {
		return new self( $owner_id, self::uploads( (array) ( $request->get_file_params()[ $key ] ?? array() ) ) );
	}

	/**
	 * One `$_FILES` entry turned from PHP's column-major shape into one row per file, keyed by path.
	 *
	 * @param array<string, array> $raw One entry of `$_FILES`, e.g. `$_FILES['files']`.
	 * @return array<string, array<int, array<string, mixed>>>
	 */
	public static function uploads( array $raw ): array {
		$uploads = array();

		foreach ( (array) ( $raw['name'] ?? array() ) as $path => $names ) {
			foreach ( array_keys( (array) $names ) as $i ) {
				$file = array();

				foreach ( array( 'name', 'type', 'tmp_name', 'error', 'size' ) as $prop ) {
					$value         = $raw[ $prop ][ $path ] ?? null;
					$file[ $prop ] = is_array( $value ) ? ( $value[ $i ] ?? null ) : $value;
				}

				if ( UPLOAD_ERR_NO_FILE !== (int) $file['error'] ) {
					$uploads[ (string) $path ][] = $file;
				}
			}
		}

		return $uploads;
	}

	/**
	 * Checks every upload against its field before anything is written.
	 *
	 * @param callable $field_for Given a path, the `FileField` it names, or null when none.
	 * @return true|WP_Error
	 */
	public function validate( callable $field_for ) {
		foreach ( $this->uploads as $path => $files ) {
			$field = $field_for( (string) $path );

			if ( ! $field instanceof FileField ) {
				return new WP_Error(
					'rest_invalid_file_field',
					/* translators: %s: the form path a file was sent for. */
					sprintf( __( 'There is no file field at %s.', 'wp-travel-engine' ), $path ),
					array( 'status' => 400 )
				);
			}

			if ( ! $field->multiple && count( $files ) > 1 ) {
				return new WP_Error(
					'rest_too_many_files',
					/* translators: %s: field label. */
					sprintf( __( '%s takes only one file.', 'wp-travel-engine' ), $field->label ),
					array( 'status' => 400 )
				);
			}

			foreach ( $files as $file ) {
				$error = $field->check( $file );

				if ( is_wp_error( $error ) ) {
					return $error;
				}
			}
		}

		return true;
	}

	/**
	 * One row's file fields, as kept plus newly uploaded attachment IDs.
	 *
	 * A field is touched only when the row names it or a file was sent for it. Only IDs the row
	 * already held may be kept, so a request cannot attach media that is not the owner's.
	 *
	 * @param string                   $prefix Path of the row, e.g. `billing` or `travelers.1`.
	 * @param array<string, mixed>     $row    Submitted values.
	 * @param array<string, mixed>     $stored The row as the owner holds it.
	 * @param array<string, FileField> $fields The row's file fields, keyed by stored key.
	 * @return array<string, mixed> The row with each touched file field as `int[]`.
	 */
	public function merge( string $prefix, array $row, array $stored, array $fields ): array {
		foreach ( $fields as $key => $field ) {
			$path    = "{$prefix}.{$key}";
			$uploads = $this->uploads[ $path ] ?? array();

			if ( ! array_key_exists( $key, $row ) && ! $uploads ) {
				continue;
			}

			$held = FileField::ids( $stored[ $key ] ?? '' );
			$keep = array_key_exists( $key, $row ) ? array_values( array_intersect( FileField::ids( $row[ $key ] ), $held ) ) : $held;
			$ids  = $keep;

			foreach ( $uploads as $file ) {
				$id = $field->store( $file, $this->owner_id );

				if ( $id ) {
					$this->created[] = $id;
					$ids[]           = $id;
				}
			}

			// Stored once, should a writer reach the same row twice.
			unset( $this->uploads[ $path ] );

			if ( ! $field->multiple ) {
				$ids = array_slice( $ids, -1 );
			}

			$this->removed = array_merge( $this->removed, array_diff( $held, $ids ) );
			$row[ $key ]   = $ids;
		}

		return $row;
	}

	/**
	 * Marks every file a dropped row held for deletion on `commit()`.
	 *
	 * @param array<string, mixed>     $stored The row as the owner holds it.
	 * @param array<string, FileField> $fields The row's file fields, keyed by stored key.
	 * @return void
	 */
	public function forget( array $stored, array $fields ): void {
		foreach ( array_keys( $fields ) as $key ) {
			$this->removed = array_merge( $this->removed, FileField::ids( $stored[ $key ] ?? '' ) );
		}
	}

	/**
	 * Deletes what the save dropped, once the owner no longer names it.
	 *
	 * @return void
	 */
	public function commit(): void {
		foreach ( array_unique( $this->removed ) as $id ) {
			FileField::delete( (int) $id, $this->owner_id );
		}

		$this->removed = array();
		$this->created = array();
	}

	/**
	 * Deletes what the save created, when the save itself failed.
	 *
	 * @return void
	 */
	public function rollback(): void {
		foreach ( $this->created as $id ) {
			wp_delete_attachment( $id, true );
		}

		$this->removed = array();
		$this->created = array();
	}
}
