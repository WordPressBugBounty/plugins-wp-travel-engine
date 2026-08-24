<?php
/**
 * Abstract Email Tags.
 *
 * Base class for all email tag resolvers.
 * Inherits {sitename}, {site_admin_email}, {ip_address} from TemplateTags.
 * Subclasses implement the get_*() methods referenced by build_callbacks() with their own data source;
 * override CALLBACKS_FILTER to hook the resolved registry.
 *
 * @since 6.7.9
 * @since 6.8.7 build_callbacks() moved here (shared registry) from each subclass.
 */

namespace WPTravelEngine\Abstracts;

use WPTravelEngine\Email\TemplateTags;
use WPTravelEngine\Helpers\CartInfoParser;

/**
 * Abstract EmailTags class.
 *
 * @since 6.7.9
 */
abstract class EmailTags extends TemplateTags {

	/**
	 * Filter name applied to the resolved callback registry in build_callbacks().
	 * Subclasses override to hook their own filter (e.g. DummyTags).
	 *
	 * @since 6.8.7
	 */
	public const CALLBACKS_FILTER = '';

	/**
	 * Maps a contract method name to the subclass's differently named method. Used by __call().
	 *
	 * @var array<string, string>
	 * @since 6.8.7
	 */
	public const METHOD_ALIASES = array();

	/**
	 * Whether this instance is rendering fake preview data rather than a real
	 * booking/payment. Templates use this to skip do_action()/apply_filters()
	 * calls that addons expect to receive real Booking/Payment models — passing
	 * DummyTags' fake stdClass objects through those hooks fatals when an addon
	 * calls a real model method (e.g. $booking->get_id()) on them.
	 *
	 * @return bool
	 * @since 6.8.7
	 */
	public function is_preview(): bool {
		return false;
	}

	/**
	 * The booking this instance is generating tags for.
	 * Real Booking model in Template_Tags, a dummy \stdClass in DummyTags.
	 *
	 * @var object|null
	 * @since 6.8.7
	 */
	public $booking;

	/**
	 * The payment this instance is generating tags for.
	 * Real Payment model in Template_Tags, a dummy \stdClass in DummyTags.
	 *
	 * @var object|null
	 * @since 6.8.7
	 */
	public $payment;

	/**
	 * The trip respective of booking id.
	 *
	 * @var object
	 * @since 6.8.7
	 */
	public $trip;

	/**
	 * Billing info for the booking (fname, lname, email, address, city, country).
	 *
	 * @var array
	 * @since 6.8.7
	 */
	public $billing_info;

	/**
	 * Cart info for the booking (subtotal, total, discounts, totals, line items, etc).
	 *
	 * @var CartInfoParser|array
	 * @since 6.8.7
	 */
	public $cart_info;

	/**
	 * Color palette used across the HTML block tag markup (bank details, payment
	 * details, discount rows, etc). Filterable so themes/addons can rebrand
	 * rendered email markup without touching template files.
	 *
	 * @var array<string, string>
	 * @since 6.8.7
	 */
	protected array $template_colors;

	public function __construct() {
		parent::__construct();

		$this->template_colors = apply_filters(
			'wptravelengine_email_template_colors',
			array(
				'muted'               => '#566267',
				'border'              => '#DCDFEA',
				'success'             => '#12B76A',
				'warning'             => '#F79009',
				'highlight'           => array(
					'bg'    => 'rgba(15, 29, 35, 0.04)',
					'color' => '#0F1D23',
				),
				'secondary_highlight' => array(
					'bg'    => '#147dfe1a',
					'color' => '#0F1D23',
				),
			)
		);
	}

	/**
	 * Look up a color from the template_colors palette. `highlight` and
	 * `secondary_highlight` are `['bg' => ..., 'color' => ...]` arrays; pass
	 * $field to pick which one.
	 *
	 * @param string $key Palette key (e.g. 'muted', 'highlight').
	 * @param string $fallback Value returned when the key isn't in the palette.
	 * @param string $field For array-valued keys, which sub-key to return ('bg' or 'color').
	 * @since 6.8.7
	 * @since 6.8.7 Escapes the return value (always used in an HTML attribute), so templates no longer need to call esc_attr() themselves.
	 * @since 6.8.7 $field param added for nested highlight/secondary_highlight colors.
	 */
	public function color( string $key, string $fallback = '#566267', string $field = 'bg' ): string {
		$value = $this->template_colors[ $key ] ?? null;
		if ( is_array( $value ) ) {
			return esc_attr( $value[ $field ] ?? $fallback );
		}
		return esc_attr( $value ?? $fallback );
	}

	/**
	 * Render an email tag template and return its output.
	 *
	 * @param string $file Template file name, relative to includes/templates/template-emails/tags/.
	 * @param array  $args Extracted into local variables before the template is required.
	 * @return string
	 * @since 6.8.7
	 */
	protected function render_tag_template( string $file, array $args = array() ): string {
		if ( $args ) {
			extract( $args );
		}
		ob_start();
		require WP_TRAVEL_ENGINE_BASE_PATH . '/includes/templates/template-emails/tags/' . $file;
		return ob_get_clean();
	}

	/**
	 * Dispatches a call to an undefined method (e.g. get_name(), invoked by
	 * EmailTags::build_callbacks()) to whichever original method actually
	 * implements it, per METHOD_ALIASES.
	 *
	 * @since 6.8.7
	 */
	public function __call( string $name, array $arguments ) {
		if ( isset( static::METHOD_ALIASES[ $name ] ) ) {
			return $this->{static::METHOD_ALIASES[ $name ]}( ...$arguments );
		}

		throw new \BadMethodCallException( sprintf( 'Call to undefined method %s::%s()', static::class, $name ) );
	}

	/**
	 * Build the shared tag => callable registry.
	 *
	 * Both DummyTags and Template_Tags implement every method referenced here
	 * (per the abstract contract above), so the map itself needs no per-subclass
	 * variation — only CALLBACKS_FILTER differs.
	 *
	 * @return array<string, callable>
	 * @since 6.8.7 Moved here from DummyTags::build_callbacks(); Template_Tags mirrors the same tag => method names.
	 */
	protected function build_callbacks(): array {
		$callbacks = array(
			// Customer / billing tags.
			'{billing_address}'           => array( $this, 'get_billing_address' ),
			'{city}'                      => array( $this, 'get_city' ),
			'{country}'                   => array( $this, 'get_country' ),
			'{customer_first_name}'       => array( $this, 'get_name' ),
			'{customer_last_name}'        => array( $this, 'get_last_name' ),
			'{customer_full_name}'        => array( $this, 'get_fullname' ),
			'{customer_email}'            => array( $this, 'get_user_email' ),

			// Trip tags.
			'{trip_url}'                  => array( $this, 'get_trip_url' ),
			'{booked_trip_name}'          => array( $this, 'get_booked_trip_name' ),
			'{trip_code}'                 => array( $this, 'get_trip_code' ),
			'{trip_start_date}'           => array( $this, 'get_trip_start_date' ),
			'{trip_end_date}'             => array( $this, 'get_trip_end_date' ),
			'{no_of_travellers}'          => array( $this, 'get_traveler_count' ), // @deprecated 6.8.0 remove after addons (automator, waitlist) are updated.
			'{no_of_travelers}'           => array( $this, 'get_traveler_count' ),
			'{tprice}'                    => array( $this, 'get_tprice' ),

			// Booking tags.
			'{booking_id}'                => array( $this, 'get_booking_id' ),
			'{booking_url}'               => array( $this, 'get_booking_url' ),
			'{booking_trips_count}'       => array( $this, 'get_booking_trips_count' ),
			'{trip_booked_date}'          => array( $this, 'get_trip_booked_date' ),

			// Payment / price tags.
			'{payment_id}'                => array( $this, 'get_payment_id' ),
			'{payment_method}'            => array( $this, 'get_payment_method' ),
			'{payment_link}'              => array( $this, 'get_payment_link' ),
			'{subtotal}'                  => array( $this, 'get_subtotal' ),
			'{total}'                     => array( $this, 'get_total' ),
			'{paid_amount}'               => array( $this, 'get_paid_amount' ),
			'{trip_total_price}'          => array( $this, 'get_total' ),
			'{trip_paid_amount}'          => array( $this, 'get_paid_amount' ),
			'{trip_due_amount}'           => array( $this, 'get_due' ),
			'{trip_extra_fee}'            => array( $this, 'get_trip_extra_fee' ),
			'{total_gateway_fee}'         => array( $this, 'get_total_gateway_fee' ),

			// Discount tags.
			'{discount_name}'             => array( $this, 'get_discount_name' ),
			'{discount_amount}'           => array( $this, 'get_discount_amount' ),
			'{discount_sign}'             => array( $this, 'get_discount_sign' ),
			'{discount_value}'            => array( $this, 'get_discount_value' ),

			// Payment gateway detail tags.
			'{bank_details}'              => array( $this, 'get_bank_details' ),
			'{check_payment_instruction}' => array( $this, 'get_check_payment_instruction' ),

			// Complex HTML block tags.
			'{trip_booking_summary}'      => array( $this, 'get_trip_booking_summary' ),
			'{trip_payment_details}'      => array( $this, 'get_trip_payment_details' ),
			'{trip_booking_details}'      => array( $this, 'get_trip_booking_details' ),
			'{traveller_details}'         => array( $this, 'get_traveler_details' ), // @deprecated 6.7.9 typo kept for addon back-compat.
			'{traveler_details}'          => array( $this, 'get_traveler_details' ),
			'{emergency_details}'         => array( $this, 'get_emergency_details' ),
			'{billing_details}'           => array( $this, 'get_billing_details' ),
			'{additional_note}'           => array( $this, 'get_additional_note' ),

			// Deprecated tags.
			'{booking_details}'           => array( $this, 'get_booking_details' ),
			'{name}'                      => array( $this, 'get_name' ),
			'{fullname}'                  => array( $this, 'get_fullname' ),
			'{user_email}'                => array( $this, 'get_user_email' ),
			'{tdate}'                     => array( $this, 'get_trip_start_date' ),
			'{date}'                      => array( $this, 'get_date' ),
			'{traveler}'                  => array( $this, 'get_traveler_count' ),
			'{price}'                     => array( $this, 'get_price' ),
			'{due}'                       => array( $this, 'get_due' ),
			'{total_cost}'                => array( $this, 'get_total_cost' ),
			'{traveler_data}'             => array( $this, 'get_traveler_data' ),
		);

		// Per-booking billing fields (fname, email, city, ...) — keys vary with the
		// checkout form, so they can't be literal array keys above; scan-and-prune
		// in get_email_tags() covers these the same as everything else.
		foreach ( $this->billing_info as $field_name => $field_value ) {
			if ( isset( $callbacks[ '{' . $field_name . '}' ] ) ) {
				continue;
			}
			if ( is_array( $field_value ) ) {
				$field_value = implode( ',', $field_value );
			}
			$callbacks[ '{' . $field_name . '}' ] = static fn() => $field_value;
		}

		return apply_filters( static::CALLBACKS_FILTER, $callbacks, $this );
	}

	/**
	 * Resolve and return the full tag array.
	 *
	 * @param string $content Template body content.
	 * @param string $subject Template subject line.
	 * @return array<string, string>
	 * @since 6.8.7 Dropped `final` so subclasses can layer dynamic tags (e.g. per-booking form fields) or legacy filters on top of the resolved callback map.
	 */
	public function get_email_tags( string $content = '', string $subject = '' ): array {
		$callbacks = $this->build_callbacks();

		$scan = $subject . $content;
		if ( $scan !== '' ) {
			preg_match_all( '/\{(\w+)\}/i', $scan, $matches );
			if ( ! empty( $matches[0] ) ) {
				$callbacks = array_intersect_key( $callbacks, array_flip( $matches[0] ) );
			}
		}

		// $this->tags already holds the 3 site tags; merge resolved callbacks on top.
		return array_merge( $this->tags, array_map( 'call_user_func', $callbacks ) );
	}
}
