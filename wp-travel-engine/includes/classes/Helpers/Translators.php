<?php
/**
 * Translation helper class.
 *
 * @since 6.5.1
 * @package WPTravelEngine
 */

namespace WPTravelEngine\Helpers;

class Translators {

	public function __construct() {
		add_filter(
			'wpml_pre_save_pro_translation',
			function ( $postarr, $job ) {

				if ( WP_TRAVEL_ENGINE_POST_TYPE !== ( $postarr['post_type'] ?? false ) ) {
					return $postarr;
				}

				return static::wpml_pre_save_pro_translation( $postarr, $job );
			},
			10,
			2
		);

		/**
		 * This filter is used to modify the attributes and extradata of the translation units for WPML Advanced Translation Editor.
		 *
		 * @since 6.6.9
		 */
		add_filter( 'wpml_tm_adjust_translation_job', array( __CLASS__, 'wpml_ate_translation_adjust' ) );

		// Set the language after booking created if TranslatePress is active.
		add_action( 'wptravelengine_after_booking_created', array( __CLASS__, 'set_language_after_booking_created' ) );
	}

	/**
	 * Set the language after booking created.
	 *
	 * @param int $booking_id The booking ID.
	 * @return void
	 * @since 6.7.9
	 */
	public static function set_language_after_booking_created( int $booking_id ) {
		$language = static::get_translatepress_language();
		if ( $language ) {
			update_post_meta( $booking_id, 'wp_travel_engine_booking_language', $language );
		}
	}

	public static function wpml_pre_save_pro_translation( $postarr, $job ) {

		$original_trip_id = $job->original_doc_id;
		$post_metas       = get_post_meta( $original_trip_id );

		$meta_input = $postarr['meta_input'] ?? array();
		foreach ( $post_metas as $key => $value ) {
			if ( preg_match( '#^_wpml_#', $key ) ) {
				continue;
			}

			$meta_input[ $key ] = maybe_unserialize( $value[0] );
		}

		$postarr['meta_input'] = $meta_input;

		return $postarr;
	}

	/**
	 * @return array[]|false|mixed|null
	 */
	public static function get_current_language() {
		if ( function_exists( 'pll_current_language' ) ) {
			$language = pll_current_language();

			return $language ? array( $language => array() ) : false;
		}

		return apply_filters( 'wpml_active_languages', null, array() );
	}

	/**
	 * Checks if WPML multilingual is active.
	 *
	 * @return bool True if WPML multilingual mode is active, false otherwise.
	 * @since 6.6.6
	 */
	public static function is_wpml_multilingual_active(): bool {
		return defined( 'WPML_ST_VERSION' ) && apply_filters( 'wpml_current_language', null ) !== apply_filters( 'wpml_default_language', null );
	}

	/**
	 * Checks if a translation plugin (WPML String Translation, and later Polylang or others)
	 * is active, regardless of the current language (unlike is_wpml_multilingual_active(),
	 * which is false while on the default language).
	 *
	 * @return bool
	 * @since 6.8.6
	 */
	public static function is_translation_active(): bool {
		return defined( 'WPML_ST_VERSION' );
	}

	/**
	 * Checks if the given element is in the site's default language (or no translation
	 * plugin is active / element language is unknown).
	 *
	 * When $element_id/$element_type are omitted, falls back to comparing the ambient
	 * current language against the default language (same check used by wpml_translate_string()).
	 *
	 * @param int|null    $element_id   Post/term ID.
	 * @param string|null $element_type WPML element type, e.g. 'post_trip', 'post_page'.
	 * @return bool
	 * @since 6.8.6
	 */
	public static function is_default_language( ?int $element_id = null, ?string $element_type = null ): bool {
		if ( ! static::is_translation_active() ) {
			return true;
		} elseif ( null === $element_id || null === $element_type ) {
			return ! static::is_wpml_multilingual_active();
		}

		$element_language = static::get_element_language( $element_id, $element_type );

		if ( null === $element_language ) {
			return true;
		}

		return $element_language === apply_filters( 'wpml_default_language', null );
	}

	/**
	 * Summary of set_wpml_language.
	 *
	 * @param string|null $lang
	 * @return void
	 * @since 6.6.6
	 */
	public static function set_wpml_language( $lang = null ): void {
		do_action( 'wpml_switch_language', $lang ?? apply_filters( 'wpml_current_language', null ) );
	}

	/**
	 * This function is used to save the translations to the wpml string.
	 *
	 * @param array|string $translations Translations to save.
	 * @param string       $base_context Name of the wpml string.
	 * @since 6.6.6
	 */
	public static function save_wpml_translation( $translations, $base_context, ?string $lang = null ): void {
		if ( ! function_exists( 'icl_update_string_translation' ) ) {
			return;
		}

		$lang = $lang ?? apply_filters( 'wpml_current_language', null );

		if ( is_string( $translations ) ) {
			icl_update_string_translation( $base_context, $lang, $translations, 10 );
			return;
		}

		foreach ( $translations as $key => $translation ) {
			if ( is_array( $translation ) ) {
				$current_context = $base_context . '[' . $key . ']';
				static::save_wpml_translation( $translation, $current_context, $lang );
			} else {
				icl_update_string_translation( $base_context . $key, $lang, $translation, 10 );
			}
		}
	}

	/**
	 * Gets the actual WPML-assigned language code of a specific element (post/term/etc.),
	 * independent of the ambient "current language" request/admin state.
	 *
	 * @param int    $element_id   Post/term ID.
	 * @param string $element_type WPML element type, e.g. 'post_trip', 'post_page'.
	 * @return string|null Language code, or null if WPML is inactive / language unknown.
	 * @since 6.8.6
	 */
	public static function get_element_language( int $element_id, string $element_type ): ?string {
		return apply_filters(
			'wpml_element_language_code',
			null,
			array(
				'element_id'   => $element_id,
				'element_type' => $element_type,
			)
		);
	}

	/**
	 * Resolves any-language element ID to its original-language ID (WPML), so addons that key
	 * data (capacity rules, inventory, cross-trip linking) off one canonical ID never split
	 * between per-language duplicate posts. Falls back to wpml_element_trid (WPML convention:
	 * trid = original post ID) when the original has no explicit icl_translations row.
	 *
	 * @param int    $element_id   Post ID, any language.
	 * @param string $element_type WPML element type, e.g. 'post_trip', 'post_wte-services'.
	 * @return int Original-language element ID, or $element_id unchanged if not determinable / WPML inactive.
	 * @since 6.8.6
	 */
	public static function get_original_id( int $element_id, string $element_type ): int {
		$default_lang = apply_filters( 'wpml_default_language', null );
		if ( ! $default_lang ) {
			return $element_id;
		}

		$post_type = preg_replace( '/^post_/', '', $element_type );
		$orig_id   = (int) apply_filters( 'wpml_object_id', $element_id, $post_type, false, $default_lang );

		if ( ! $orig_id ) {
			$orig_id = (int) apply_filters( 'wpml_element_trid', 0, $element_id, $element_type );
		}

		return $orig_id ?: $element_id;
	}

	/**
	 * Gets every other-language translation ID of an element (WPML), excluding the element itself.
	 *
	 * @param int    $element_id   Post ID, any language.
	 * @param string $element_type WPML element type, e.g. 'post_trip', 'post_wte-services'.
	 * @return int[] Translated element IDs, empty when WPML is inactive or no translations exist.
	 * @since 6.8.6
	 */
	public static function get_translations( int $element_id, string $element_type ): array {
		$trid = apply_filters( 'wpml_element_trid', null, $element_id, $element_type );
		if ( ! $trid ) {
			return array();
		}

		$translations = apply_filters( 'wpml_get_element_translations', array(), $trid, $element_type );

		$ids = array();
		foreach ( (array) $translations as $translation ) {
			$translated_id = (int) ( $translation->element_id ?? 0 );
			if ( $translated_id && $translated_id !== $element_id ) {
				$ids[] = $translated_id;
			}
		}

		return $ids;
	}

	/**
	 * Pushes structural post-meta data from the original-language element to every translation's
	 * own copy of that meta key — for data that's mostly structural (IDs/config shared across
	 * languages) but may carry per-language content (e.g. free-text descriptions) that a
	 * translation has already customized and must not be clobbered.
	 *
	 * @param int           $element_id   Element ID being saved. Only original-language saves propagate.
	 * @param string        $element_type WPML element type, e.g. 'post_trip'.
	 * @param string        $meta_key     Post meta key to sync.
	 * @param mixed         $new_value    The value just saved on $element_id.
	 * @param callable|null $merge        function( array $translated_meta, mixed $new_value ): mixed.
	 *                                    Receives the translation's own current meta value (empty array
	 *                                    if unset) and the new structural value; returns what to save on
	 *                                    the translation. Omit to overwrite the translation's meta outright.
	 * @return void
	 * @since 6.8.6
	 */
	public static function sync_meta_to_translations( int $element_id, string $element_type, string $meta_key, $new_value, ?callable $merge = null ): void {
		if ( ! static::is_default_language( $element_id, $element_type ) ) {
			return;
		}

		foreach ( static::get_translations( $element_id, $element_type ) as $translated_id ) {
			$value = $new_value;

			if ( $merge ) {
				$translated_meta = get_post_meta( $translated_id, $meta_key, true );
				$translated_meta = is_array( $translated_meta ) ? $translated_meta : array();
				$value           = $merge( $translated_meta, $new_value );
			}

			update_post_meta( $translated_id, $meta_key, $value );
		}
	}

	/**
	 * Registers a value as a WPML-translatable string under the given domain/name.
	 *
	 * @param string      $name            Unique string name/context, e.g. "package_title_{$id}".
	 * @param string      $value           Source (default language) value.
	 * @param string      $domain          Text domain the string is registered under.
	 * @param string|null $source_language Language code the $value is written in. Null defaults to the site's default language.
	 * @return void
	 * @since 6.8.6
	 */
	public static function wpml_register_string( string $name, string $value, string $domain = 'wp-travel-engine', ?string $source_language = null ): void {
		$source_language ??= apply_filters( 'wpml_default_language', null );

		do_action( 'wpml_register_single_string', $domain, $name, $value, false, $source_language );
	}

	/**
	 * Gets the translated value of a string registered via wpml_register_string(), for the
	 * current language. Returns $value unchanged if no translation exists or WPML is inactive.
	 *
	 * @param string      $value        Source (default language) value.
	 * @param string      $name         Unique string name/context used at registration.
	 * @param string      $domain       Text domain the string was registered under.
	 * @param int|null    $element_id   Post/term ID the string belongs to.
	 * @param string|null $element_type WPML element type, e.g. 'post_trip-packages'.
	 * @return string
	 * @since 6.8.6
	 */
	public static function wpml_translate_string( string $value, string $name, string $domain = 'wp-travel-engine', ?int $element_id = null, ?string $element_type = null ): string {
		if ( static::is_default_language( $element_id, $element_type ) ) {
			return $value;
		}

		$language_code = null !== $element_id && null !== $element_type
			? static::get_element_language( $element_id, $element_type )
			: apply_filters( 'wpml_current_language', null );

		$string_id = apply_filters(
			'wpml_string_id',
			null,
			array(
				'context' => $domain,
				'name'    => $name,
			)
		);

		if ( empty( $string_id ) || ! function_exists( 'icl_get_string_by_id' ) ) {
			return $value;
		}

		$translation = icl_get_string_by_id( $string_id, $language_code );

		return false === $translation || '' === $translation ? $value : $translation;
	}

	/**
	 * Saves a runtime-keyed string for translation: if $language is the site's default
	 * language (or WPML is inactive / $language is unknown), (re)registers the source string;
	 * otherwise writes $value as that specific language's translation.
	 *
	 * @param string      $name         Unique string name/context, e.g. "package_title_{$id}".
	 * @param string      $value        Value being saved, in $language.
	 * @param string|null $language     Language code the value is being saved in. Null is treated as default language.
	 * @param string      $domain       Text domain the string is registered under.
	 * @param string|null $source_value The element's actual default-language value, used to register the source string
	 *                                  if it doesn't exist yet. Ignored when $language is the default language.
	 * @return void
	 * @since 6.8.6
	 */
	public static function wpml_sync_string( string $name, string $value, ?string $language = null, string $domain = 'wp-travel-engine', ?string $source_value = null ): void {
		$default_language = apply_filters( 'wpml_default_language', null );
		$is_default       = null === $language || $language === $default_language;

		if ( $is_default ) {
			static::wpml_register_string( $name, $value, $domain, $default_language );
			return;
		}

		$string_id = apply_filters(
			'wpml_string_id',
			null,
			array(
				'context' => $domain,
				'name'    => $name,
			)
		);

		if ( empty( $string_id ) ) {
			// No source string yet; register the element's real default-language value (not the translation) as the source.
			static::wpml_register_string( $name, $source_value ?? '', $domain, $default_language );
		}

		static::save_wpml_translation( $value, $name, $language );
	}

	/**
	 * Auto register admin strings.
	 *
	 * @return void
	 * @since 6.6.6
	 */
	public static function register_wpml_admin_strings() {
		if ( function_exists( 'wpml_st_parse_config' ) ) {
			$config_file = WP_TRAVEL_ENGINE_BASE_PATH . '/wpml-config.xml';

			if ( file_exists( $config_file ) ) {
				$config_hash = md5( serialize( $config_file ) );
				delete_transient( 'wpml_admin_text_import:parse_config:' . $config_hash );
				wpml_st_parse_config( $config_file );
			}
		}
	}

	/**
	 * This function is used to modify the attributes and extradata of the translation units for WPML Advanced Translation Editor.
	 *
	 * @since 6.6.9
	 * @param array $translation_units Translation units.
	 * @return array Translation units.
	 */
	public static function wpml_ate_translation_adjust( $translation_units ) {
		foreach ( $translation_units as $key => $value ) {
			if ( ! isset( $value['attributes']['id'] ) || strpos( $value['attributes']['id'], 'field-wp_travel_engine_setting' ) === false ) {
				continue;
			}
			$translation_units[ $key ]['attributes']['resname'] = '';
			$translation_units[ $key ]['extradata']['unit']     = '';
		}
		return $translation_units;
	}

	/**
	 * Checks if TranslatePress is active.
	 *
	 * @return bool True if TranslatePress is active, false otherwise.
	 * @since 6.7.9
	 */
	public static function is_translatepress_active(): bool {
		return function_exists( 'trp_translate' ) && class_exists( 'TRP_Translate_Press' );
	}

	/**
	 * Get available languages for translation with their display labels.
	 * Currently supports TranslatePress. Can be extended for other plugins via filter.
	 *
	 * @return array List of ['code' => string, 'label' => string] pairs.
	 * @since 6.7.9
	 */
	public static function get_available_languages( $plugin = 'translatepress' ): array {
		$languages = array();
		if ( 'translatepress' === $plugin && static::is_translatepress_active() ) {
			$trp = \TRP_Translate_Press::get_trp_instance();
			if ( $trp ) {
				$settings  = $trp->get_component( 'settings' )->get_settings();
				$codes     = $settings['translation-languages'] ?? array();
				$trp_langs = $trp->get_component( 'languages' );

				$trp_languages = $trp_langs->get_language_names( $codes );
				foreach ( $trp_languages as $code => $name ) {
					$languages[] = array(
						'code'  => $code,
						'label' => $name,
					);
				}
			}
		}

		return apply_filters( 'wptravelengine_available_languages', $languages );
	}

	/**
	 * Get default language code.
	 * Currently supports TranslatePress. Can be extended for other plugins via filter.
	 *
	 * @return string Default language code (e.g., 'en_US').
	 * @since 6.7.9
	 */
	public static function get_default_language( $plugin = 'translatepress' ): string {
		$language = get_locale();

		if ( 'translatepress' === $plugin && static::is_translatepress_active() ) {
			$trp = \TRP_Translate_Press::get_trp_instance();
			if ( $trp ) {
				$settings = $trp->get_component( 'settings' )->get_settings();
				$language = $settings['default-language'] ?? get_locale();
			}
		}

		return apply_filters( 'wptravelengine_default_language', $language );
	}

	/**
	 * Get the current TranslatePress language.
	 *
	 * @return string|null Current language code or null if TranslatePress is not active.
	 * @since 6.7.9
	 */
	public static function get_translatepress_language(): ?string {
		if ( ! static::is_translatepress_active() || ! function_exists( 'trp_get_locale' ) ) {
			return null;
		}

		return trp_get_locale() ?? null;
	}

	/**
	 * Set the TranslatePress language.
	 *
	 * @param string $language_code Language code.
	 * @return void
	 * @since 6.7.9
	 */
	public static function set_translatepress_language( $language_code = null ): void {
		if ( ! static::is_translatepress_active() || ! $language_code || ! function_exists( 'trp_switch_language' ) ) {
			return;
		}

		trp_switch_language( $language_code );
	}
}
