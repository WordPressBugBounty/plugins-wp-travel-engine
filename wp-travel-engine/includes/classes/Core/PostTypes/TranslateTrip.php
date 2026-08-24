<?php
/**
 * Syncs trip packages and their translated strings across WPML translations of a trip.
 *
 * @package WPTravelEngine/Core/PostTypes
 * @since 6.8.6
 */

namespace WPTravelEngine\Core\PostTypes;

use WPTravelEngine\Core\Models\Post;
use WPTravelEngine\Core\Models\Post\TripPackage;
use WPTravelEngine\Helpers\Translators;

/**
 * Class TranslateTrip
 * Keeps a trip's `packages_ids` meta in sync across its WPML translations, so a package
 * created, deleted, or cloned on the default-language trip is reflected on every translation.
 * Also hooks the `wptravelengine_trip_package_title`/`_description` filters to serve WPML
 * String Translation content for a package's title/description.
 *
 * @since 6.8.6
 */
class TranslateTrip extends Trip {

	/**
	 * Registers the sync hooks.
	 */
	public function __construct() {
		add_action( 'wptravelengine_trip_package_created', array( $this, 'on_package_created' ), 10, 2 );
		add_action( 'wptravelengine_trip_package_deleted', array( $this, 'on_package_deleted' ), 10, 2 );

		add_filter( 'wptravelengine_trip_package_title', array( $this, 'translate_package_title' ), 10, 2 );
		add_filter( 'wptravelengine_trip_package_description', array( $this, 'translate_package_description' ), 10, 2 );

		// Covers direct get_the_title()/the_title() calls on a package post, which bypass TripPackage::get_title().
		add_filter( 'the_title', array( $this, 'translate_package_post_title' ), 10, 2 );
	}

	/**
	 * Translates a `trip-packages` post's title for any caller using get_the_title()/the_title()
	 * directly (instead of going through TripPackage::get_title()).
	 *
	 * @param string     $title   The post title.
	 * @param int|string $post_id The post ID. Some callers fire `the_title` manually with a
	 *                            string ID, so this isn't strictly typed as `int`.
	 * @return string
	 * @since 6.8.7 Accept `$post_id` as int|string to avoid a TypeError when a caller fires
	 *             `the_title` manually with a string ID; guard falsy/non-package IDs.
	 */
	public function translate_package_post_title( string $title, $post_id ): string {
		$post_id = (int) $post_id;

		if ( ( $post_id && 'trip-packages' !== get_post_type( $post_id ) ) || ! Translators::is_translation_active() ) {
			return $title;
		}

		return Translators::wpml_translate_string( $title, "package_title_{$post_id}", 'wp-travel-engine' );
	}

	/**
	 * Translates the package title via WPML String Translation.
	 *
	 * @param string      $title   The package title.
	 * @param TripPackage $package The package.
	 * @return string
	 */
	public function translate_package_title( string $title, TripPackage $package ): string {
		return Translators::wpml_translate_string( $title, "package_title_{$package->ID}", 'wp-travel-engine', $package->get_trip()->ID, 'post_trip' );
	}

	/**
	 * Translates the package description via WPML String Translation.
	 *
	 * @param string      $description The package description.
	 * @param TripPackage $package     The package.
	 * @return string
	 */
	public function translate_package_description( string $description, TripPackage $package ): string {
		return Translators::wpml_translate_string( $description, "package_description_{$package->ID}", 'wp-travel-engine', $package->get_trip()->ID, 'post_trip' );
	}

	/**
	 * Appends the newly created (or cloned) package to every translation's `packages_ids`, if not already there.
	 *
	 * @param Post\Trip $trip       The trip the package was added to.
	 * @param int       $package_id The newly created package's ID.
	 * @return void
	 */
	public function on_package_created( Post\Trip $trip, int $package_id ): void {
		foreach ( $this->get_translation_ids( $trip->ID ) as $translation_id ) {
			$translated_trip = new Post\Trip( $translation_id );
			$package_ids     = (array) $translated_trip->get_meta( 'packages_ids' );

			if ( in_array( $package_id, $package_ids, false ) ) {
				continue;
			}

			$package_ids[] = $package_id;
			$translated_trip->set_meta( 'packages_ids', $package_ids )->save();
		}
	}

	/**
	 * Removes the deleted package from every translation's `packages_ids`, if present.
	 *
	 * @param Post\Trip $trip       The trip the package was removed from.
	 * @param int       $package_id The deleted package's ID.
	 * @return void
	 */
	public function on_package_deleted( Post\Trip $trip, int $package_id ): void {
		foreach ( $this->get_translation_ids( $trip->ID ) as $translation_id ) {
			$translated_trip = new Post\Trip( $translation_id );
			$package_ids     = (array) $translated_trip->get_meta( 'packages_ids' );

			if ( ! in_array( $package_id, $package_ids, false ) ) {
				continue;
			}

			$remaining_packages = array_diff( $package_ids, array( $package_id ) );
			$translated_trip->set_meta( 'packages_ids', empty( $remaining_packages ) ? '' : array_values( $remaining_packages ) )->save();
		}
	}

	/**
	 * Gets the post IDs of every other-language translation of the given trip.
	 *
	 * @param int $trip_id The default/source trip's post ID.
	 * @return int[]
	 */
	protected function get_translation_ids( int $trip_id ): array {
		if ( ! Translators::is_translation_active() ) {
			return array();
		}

		// trid = WPML's "translation ID": the group ID shared by a post and all its language versions.
		$trid = apply_filters( 'wpml_element_trid', null, $trip_id, 'post_trip' );

		if ( ! $trid ) {
			return array();
		}

		$translations = (array) apply_filters( 'wpml_get_element_translations', null, $trid, 'post_trip' );

		return array_values(
			array_filter(
				array_map( fn ( $translation ) => (int) $translation->element_id, $translations ),
				fn ( $element_id ) => $element_id && $element_id !== $trip_id
			)
		);
	}
}
