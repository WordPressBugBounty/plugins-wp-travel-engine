<?php
/**
 * File upload field.
 *
 * @package WPTravelEngine/Builders/FormFields
 * @since 6.9.0
 */

namespace WPTravelEngine\Builders\FormFields;

use WP_Error;

/**
 * A file field: what it accepts, and the files it holds.
 *
 * Any field system describes a file field with the same keys - `multiple`, `max_file_size`
 * (MB) and `accepted_files` (`.jpg,.pdf`) - so a form editor field, a trip's or a setting's
 * all get the same rules. The field stores attachment IDs; each attachment's parent is the
 * post that owns it, be it a booking, an enquiry or anything else.
 *
 * New files go under `uploads/wptravelengine-private/` with random names and are read back
 * through the `wptravelengine/v2/files/{id}` route, which checks who is asking. Files stored
 * before that keep their public URLs.
 *
 * @since 6.9.0
 */
class FileField {

	/**
	 * Field types that hold files: the form editor writes `file`, the form builders report `files`.
	 *
	 * @var string[]
	 */
	const TYPES = array( 'file', 'files' );

	/**
	 * Accepted extensions when a field names none.
	 *
	 * @var string
	 */
	const DEFAULT_ACCEPT = '.jpg,.jpeg,.png,.doc,.docx,.pdf';

	/**
	 * Size limit in megabytes when a field names none.
	 *
	 * @var int
	 */
	const DEFAULT_MAX_MB = 5;

	/**
	 * Folder under uploads that new files go to.
	 *
	 * @var string
	 */
	const PRIVATE_DIR = 'wptravelengine-private';

	/**
	 * Attachment meta holding the name the file was uploaded under; on disk it is random.
	 *
	 * @var string
	 */
	const NAME_META = '_wptravelengine_file_name';

	/**
	 * Whether the field holds more than one file.
	 *
	 * @var bool
	 */
	public bool $multiple;

	/**
	 * Largest file accepted, in bytes.
	 *
	 * @var int
	 */
	public int $max_bytes;

	/**
	 * Accepted types, as the mime map `wp_handle_upload()` takes.
	 *
	 * @var array<string, string>
	 */
	public array $mimes;

	/**
	 * Field label, for messages.
	 *
	 * @var string
	 */
	public string $label;

	/**
	 * Whether the field must hold a file.
	 *
	 * @var bool
	 */
	public bool $required;

	/**
	 * Constructor; use `from_definition()`.
	 *
	 * @param bool                  $multiple  Holds more than one file.
	 * @param int                   $max_bytes Largest file in bytes.
	 * @param array<string, string> $mimes     Accepted types.
	 * @param string                $label     Field label.
	 * @param bool                  $required  Must hold a file.
	 */
	public function __construct( bool $multiple, int $max_bytes, array $mimes, string $label = '', bool $required = false ) {
		$this->multiple  = $multiple;
		$this->max_bytes = $max_bytes;
		$this->mimes     = $mimes;
		$this->label     = $label;
		$this->required  = $required;
	}

	/**
	 * A file field from its definition.
	 *
	 * @param array<string, mixed> $definition `multiple`, `max_file_size`, `accepted_files`, `label` or `field_label`, `required`.
	 * @return self
	 */
	public static function from_definition( array $definition ): self {
		$megabytes = (float) ( $definition['max_file_size'] ?? 0 );

		return new self(
			wptravelengine_toggled( $definition['multiple'] ?? false ),
			(int) round( ( $megabytes > 0 ? $megabytes : self::DEFAULT_MAX_MB ) * MB_IN_BYTES ),
			self::mimes( (string) ( $definition['accepted_files'] ?? self::DEFAULT_ACCEPT ) ),
			(string) ( $definition['label'] ?? $definition['field_label'] ?? '' ),
			wptravelengine_toggled( $definition['required'] ?? $definition['validations']['required'] ?? false )
		);
	}

	/**
	 * Whether a field type holds files.
	 *
	 * @param string $type Field type.
	 * @return bool
	 */
	public static function is_type( string $type ): bool {
		return in_array( $type, self::TYPES, true );
	}

	/**
	 * Why one upload breaks this field's rules, if it does.
	 *
	 * @param array<string, mixed> $file One upload, as `$_FILES` holds it.
	 * @return true|WP_Error
	 */
	public function check( array $file ) {
		$name = sanitize_file_name( (string) ( $file['name'] ?? '' ) );

		if ( UPLOAD_ERR_OK !== (int) ( $file['error'] ?? UPLOAD_ERR_NO_FILE ) ) {
			return new WP_Error(
				'rest_upload_failed',
				/* translators: %s: file name. */
				sprintf( __( '%s could not be uploaded.', 'wp-travel-engine' ), $name ),
				array( 'status' => 400 )
			);
		}

		if ( (int) ( $file['size'] ?? 0 ) > $this->max_bytes ) {
			return new WP_Error(
				'rest_file_too_big',
				/* translators: 1: file name, 2: the largest size allowed, e.g. "5 MB". */
				sprintf( __( '%1$s is larger than %2$s.', 'wp-travel-engine' ), $name, size_format( $this->max_bytes ) ),
				array( 'status' => 400 )
			);
		}

		if ( ! wp_check_filetype( $name, $this->mimes )['ext'] ) {
			return new WP_Error(
				'rest_file_type',
				/* translators: %s: file name. */
				sprintf( __( '%s is not a file type this field accepts.', 'wp-travel-engine' ), $name ),
				array( 'status' => 400 )
			);
		}

		return true;
	}

	/**
	 * Stores one upload, already checked, as a private attachment of its owner.
	 *
	 * @param array<string, mixed> $file     One upload, as `$_FILES` holds it.
	 * @param int                  $owner_id Post the file belongs to.
	 * @return int Attachment ID, or 0 when WordPress refused the file.
	 */
	public function store( array $file, int $owner_id ): int {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$original  = sanitize_file_name( (string) $file['name'] );
		$extension = strtolower( pathinfo( $original, PATHINFO_EXTENSION ) );

		// Unguessable on disk: the folder's deny rule is Apache's alone, so on Nginx this is what holds.
		$file['name'] = 'wte-' . bin2hex( random_bytes( 16 ) ) . ( '' !== $extension ? ".{$extension}" : '' );

		self::protect_folder();

		$into_private = static function ( array $dirs ): array {
			$subdir = '/' . self::PRIVATE_DIR . $dirs['subdir'];

			$dirs['path']   = $dirs['basedir'] . $subdir;
			$dirs['url']    = $dirs['baseurl'] . $subdir;
			$dirs['subdir'] = $subdir;

			return $dirs;
		};

		add_filter( 'upload_dir', $into_private );

		try {
			$handled = wp_handle_upload(
				$file,
				array(
					'test_form' => false,
					'mimes'     => $this->mimes,
				)
			);
		} finally {
			remove_filter( 'upload_dir', $into_private );
		}

		if ( ! $handled || isset( $handled['error'] ) ) {
			return 0;
		}

		$id = wp_insert_attachment(
			array(
				'post_mime_type' => (string) ( $handled['type'] ?? '' ),
				'post_title'     => sanitize_text_field( pathinfo( $original, PATHINFO_FILENAME ) ),
				'post_content'   => '',
				'post_status'    => 'private',
			),
			$handled['file'],
			$owner_id
		);

		if ( ! $id || is_wp_error( $id ) ) {
			wp_delete_file( $handled['file'] );
			return 0;
		}

		update_post_meta( $id, self::NAME_META, $original );
		wp_update_attachment_metadata( $id, wp_generate_attachment_metadata( $id, $handled['file'] ) );

		return (int) $id;
	}

	/**
	 * Deletes a file its owner no longer holds.
	 *
	 * Only the owner's own attachments go; a legacy value pointing at shared media is left alone.
	 *
	 * @param int $id       Attachment ID.
	 * @param int $owner_id Post the file belonged to.
	 * @return bool Whether it was deleted.
	 */
	public static function delete( int $id, int $owner_id ): bool {
		if ( ! $owner_id || (int) wp_get_post_parent_id( $id ) !== $owner_id ) {
			return false;
		}

		return (bool) wp_delete_attachment( $id, true );
	}

	/**
	 * Attachment IDs from a stored or submitted value.
	 *
	 * Takes `"1,2"`, `[1, 2]`, the URLs older records stored in place of IDs, and the
	 * `{ id, url }` files a response carries, so a row read can be written back unchanged.
	 *
	 * @param mixed $value Field value.
	 * @return int[]
	 */
	public static function ids( $value ): array {
		$ids = array();

		foreach ( self::items( $value ) as $item ) {
			$id = is_numeric( $item ) ? absint( $item ) : attachment_url_to_postid( $item );

			if ( $id ) {
				$ids[] = $id;
			}
		}

		return array_values( array_unique( $ids ) );
	}

	/**
	 * A value's files, described for display.
	 *
	 * A legacy URL with no attachment behind it is kept as a bare link, with an `id` of 0.
	 *
	 * @param mixed $value Field value.
	 * @return array<int, array{id: int, name: string, mime: string, url: string}>
	 */
	public static function resolve( $value ): array {
		$files = array();

		foreach ( self::items( $value ) as $item ) {
			$id  = is_numeric( $item ) ? absint( $item ) : attachment_url_to_postid( $item );
			$url = $id ? self::url( $id ) : '';

			if ( ! $url ) {
				if ( ! is_numeric( $item ) && wp_http_validate_url( $item ) ) {
					$files[] = array(
						'id'   => 0,
						'name' => wp_basename( (string) wp_parse_url( $item, PHP_URL_PATH ) ),
						'mime' => '',
						'url'  => esc_url_raw( $item ),
					);
				}
				continue;
			}

			$files[] = array(
				'id'   => $id,
				'name' => self::display_name( $id ),
				'mime' => (string) get_post_mime_type( $id ),
				'url'  => $url,
			);
		}

		return $files;
	}

	/**
	 * Where a file is read from: the download route for a protected file, its own URL otherwise.
	 *
	 * The route answers to the logged-in user, so the link carries a REST nonce; an `<img>`
	 * cannot send one as a header.
	 *
	 * @param int $id Attachment ID.
	 * @return string Empty when the attachment has no file.
	 */
	public static function url( int $id ): string {
		if ( ! self::is_private( $id ) ) {
			return (string) wp_get_attachment_url( $id );
		}

		return add_query_arg( '_wpnonce', wp_create_nonce( 'wp_rest' ), rest_url( "wptravelengine/v2/files/{$id}" ) );
	}

	/**
	 * Whether an attachment was stored in the protected folder.
	 *
	 * @param int $id Attachment ID.
	 * @return bool
	 */
	public static function is_private( int $id ): bool {
		return 0 === strpos( (string) get_post_meta( $id, '_wp_attached_file', true ), self::PRIVATE_DIR . '/' );
	}

	/**
	 * The name a file was uploaded under, or its file name for one stored before names were kept.
	 *
	 * @param int $id Attachment ID.
	 * @return string
	 */
	public static function display_name( int $id ): string {
		$name = (string) get_post_meta( $id, self::NAME_META, true );

		return '' !== $name ? $name : wp_basename( (string) get_attached_file( $id ) );
	}

	/**
	 * Gives the protected folder its deny rule and an empty index, once.
	 *
	 * Apache alone reads the rule; on Nginx the random file names are what keep files private,
	 * so a folder that cannot be written is logged rather than allowed to stop the upload.
	 *
	 * @return void
	 */
	protected static function protect_folder(): void {
		$base = wp_get_upload_dir()['basedir'] . '/' . self::PRIVATE_DIR;

		if ( file_exists( "{$base}/.htaccess" ) ) {
			return;
		}

		$files = array(
			// Apache 2.4, then 2.2. Denying every request also stops the folder being listed.
			'.htaccess' => "<IfModule mod_authz_core.c>\n\tRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n\tDeny from all\n</IfModule>\n",
			'index.php' => "<?php\n// Silence is golden.\n",
		);

		foreach ( $files as $name => $contents ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- a fixed file; WP_Filesystem may ask for credentials mid-request.
			if ( ! wp_mkdir_p( $base ) || false === file_put_contents( "{$base}/{$name}", $contents ) ) {
				error_log( "WP Travel Engine: could not write {$base}/{$name}, so direct requests to the private upload folder are not denied." ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			}
		}
	}

	/**
	 * Accepted extensions as a mime map, within what WordPress allows.
	 *
	 * @param string $accept Comma-separated extensions, e.g. `.jpg,.pdf`.
	 * @return array<string, string>
	 */
	protected static function mimes( string $accept ): array {
		$allowed    = get_allowed_mime_types();
		$extensions = array_filter( array_map( static fn ( $ext ) => strtolower( ltrim( trim( $ext ), '.' ) ), explode( ',', $accept ) ) );

		if ( ! $extensions ) {
			return $allowed;
		}

		$mimes = array();

		foreach ( $allowed as $pattern => $mime ) {
			if ( array_intersect( explode( '|', $pattern ), $extensions ) ) {
				$mimes[ $pattern ] = $mime;
			}
		}

		return $mimes;
	}

	/**
	 * A value's entries as trimmed strings; a file described as `{ id, url }` gives its ID, or its URL when it has none.
	 *
	 * @param mixed $value Field value.
	 * @return string[]
	 */
	protected static function items( $value ): array {
		if ( is_string( $value ) ) {
			$value = explode( ',', $value );
		}

		$items = array();

		foreach ( (array) $value as $item ) {
			if ( is_array( $item ) ) {
				$item = empty( $item['id'] ) ? ( $item['url'] ?? '' ) : $item['id'];
			}

			$item = is_scalar( $item ) ? trim( (string) $item ) : '';

			if ( '' !== $item ) {
				$items[] = $item;
			}
		}

		return $items;
	}
}
