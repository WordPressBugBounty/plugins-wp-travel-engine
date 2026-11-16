<?php
/**
 * WP Travel Engine Files Controller class.
 *
 * @package WPTravelEngine
 * @since 6.9.0
 */

namespace WPTravelEngine\Core\Controllers\RestAPI\V2;

use WP_Error;
use WP_REST_Controller;
use WP_REST_Request;
use WP_REST_Server;
use WPTravelEngine\Builders\FormFields\FileField;

/**
 * Serves the files a file field holds, and lets a save carry new ones.
 *
 * A protected file is read only through `files/{id}`, by whoever may see the post it belongs
 * to. A save that carries files arrives as multipart, its JSON in `payload`; that is unpacked
 * before the route validates it, so every route takes files without code of its own.
 *
 * @since 6.9.0
 */
class Files extends WP_REST_Controller {

	/**
	 * Types shown in the browser; anything else downloads. SVG is left out, since one opened
	 * inline runs its scripts on this site's origin.
	 *
	 * @var string[]
	 */
	const INLINE_MIMES = array( 'image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/avif', 'image/bmp', 'application/pdf' );

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->namespace = 'wptravelengine/v2';
		$this->rest_base = 'files';
	}

	/**
	 * Registers the download route, and the unpacking of multipart saves.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		register_rest_route(
			$this->namespace,
			"/$this->rest_base/(?P<id>[\d]+)",
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_item' ),
				'permission_callback' => array( $this, 'get_item_permissions_check' ),
				'args'                => array(
					'id' => array(
						'type'     => 'integer',
						'required' => true,
					),
				),
			)
		);

		add_filter( 'rest_pre_dispatch', array( self::class, 'unpack_multipart' ), 10, 3 );
	}

	/**
	 * Whether the current user may read a file: by default, whoever may edit the post it belongs to.
	 *
	 * @param WP_REST_Request $request Download request.
	 * @return bool
	 */
	public function get_item_permissions_check( $request ): bool {
		$id       = (int) $request['id'];
		$owner_id = (int) wp_get_post_parent_id( $id );

		if ( 'attachment' !== get_post_type( $id ) || ! $owner_id ) {
			return false;
		}

		/**
		 * Filters whether the current user may read a file a file field holds.
		 *
		 * The owner's operators may by default; an owner whose own visitors may see their files,
		 * e.g. a traveler their booking's, says so here.
		 *
		 * @param bool $allowed  Whether the user may read it.
		 * @param int  $id       Attachment ID.
		 * @param int  $owner_id Post the file belongs to.
		 * @since 6.9.0
		 */
		return (bool) apply_filters( 'wptravelengine_can_view_file', current_user_can( 'edit_post', $owner_id ), $id, $owner_id );
	}

	/**
	 * Streams the file.
	 *
	 * @param WP_REST_Request $request Download request.
	 * @return WP_Error Only when the file is gone; otherwise the response is the file itself.
	 */
	public function get_item( $request ) {
		$id   = (int) $request['id'];
		$path = (string) get_attached_file( $id );

		if ( '' === $path || ! is_readable( $path ) ) {
			return new WP_Error( 'rest_file_missing', __( 'This file is no longer available.', 'wp-travel-engine' ), array( 'status' => 404 ) );
		}

		$mime        = (string) get_post_mime_type( $id );
		$disposition = in_array( $mime, self::INLINE_MIMES, true ) ? 'inline' : 'attachment';

		while ( ob_get_level() ) {
			ob_end_clean();
		}

		header( 'Cache-Control: private, max-age=300' );
		header( 'Referrer-Policy: no-referrer' );
		header( 'Content-Type: ' . ( '' !== $mime ? $mime : 'application/octet-stream' ) );
		header( 'Content-Length: ' . filesize( $path ) );
		header( sprintf( "Content-Disposition: %s; filename*=UTF-8''%s", $disposition, rawurlencode( FileField::display_name( $id ) ) ) );
		header( 'X-Content-Type-Options: nosniff' );

		readfile( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile -- streaming a checked local file.
		exit;
	}

	/**
	 * Unpacks a multipart save into the body params, before the route validates them.
	 *
	 * Hooked on `rest_pre_dispatch`, which WordPress runs ahead of argument validation, so the
	 * route's schema checks the payload exactly as it would a JSON body. Only this plugin's
	 * routes, and only a request that carries files, are touched.
	 *
	 * @param mixed           $result  Response to short-circuit with, or null.
	 * @param mixed           $server  REST server.
	 * @param WP_REST_Request $request Request being dispatched.
	 * @return mixed `$result` unchanged, or a WP_Error for a payload that is not JSON.
	 */
	public static function unpack_multipart( $result, $server, $request ) {
		if ( null !== $result || ! $request instanceof WP_REST_Request || 0 !== strpos( $request->get_route(), '/wptravelengine/' ) || ! $request->get_file_params() ) {
			return $result;
		}

		$payload = $request->get_body_params()['payload'] ?? null;

		if ( ! is_string( $payload ) ) {
			return $result;
		}

		$decoded = json_decode( $payload, true );

		if ( ! is_array( $decoded ) ) {
			return new WP_Error(
				'rest_invalid_json',
				__( 'The request payload is not valid JSON.', 'wp-travel-engine' ),
				array( 'status' => 400 )
			);
		}

		$request->set_body_params( $decoded );

		return $result;
	}
}
