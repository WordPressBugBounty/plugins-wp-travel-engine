<?php
/**
 * Site-wide coupon promotional banner.
 *
 * @since 6.8.2
 */

namespace WPTravelEngine\Modules\CouponCode;

use WPTravelEngine\Modules\CouponCode;

class Banner {

	const COOKIE_PREFIX = 'wte_coupon_banner_dismissed_';

	const TRANSIENT_KEY = 'wte_banner_coupon_ids';

	/**
	 * Whether banners have already been output this request.
	 *
	 * @since 6.8.2
	 * @var bool
	 */
	protected bool $rendered = false;

	public function __construct() {
		add_action( 'wp_body_open', array( $this, 'render' ) );
		// Fallback for themes that do not fire wp_body_open; JS relocates the banner to the top of <body>.
		add_action( 'wp_footer', array( $this, 'render_fallback' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_styles' ) );
	}

	/**
	 * Enqueue site-wide coupon banner stylesheet.
	 *
	 * @since 6.8.2
	 */
	public function enqueue_styles(): void {
		if ( is_admin() ) {
			return;
		}

		$rel_path = 'dist/public/coupon-banner.css';
		$abs_path = plugin_dir_path( \WP_TRAVEL_ENGINE_FILE_PATH ) . $rel_path;
		if ( ! file_exists( $abs_path ) ) {
			return;
		}

		wp_enqueue_style(
			'wptravelengine-coupon-banner',
			plugin_dir_url( \WP_TRAVEL_ENGINE_FILE_PATH ) . $rel_path,
			array(),
			filemtime( $abs_path )
		);
	}

	/**
	 * Active coupons that have the banner enabled.
	 *
	 * @since 6.8.2 Query a dedicated indexed meta key and cache the candidate IDs;
	 *             active/show_banner state is still re-checked live per request.
	 *
	 * @return int[]
	 */
	public function get_banner_coupon_ids(): array {
		$candidate_ids = get_transient( self::TRANSIENT_KEY );
		if ( false === $candidate_ids ) {
			$query = new \WP_Query(
				array(
					'post_type'      => defined( 'WP_TRAVEL_ENGINE_COUPONS_POST_TYPE' ) ? WP_TRAVEL_ENGINE_COUPONS_POST_TYPE : 'wte-coupon',
					'post_status'    => 'publish',
					'posts_per_page' => 20,
					'fields'         => 'ids',
					'orderby'        => 'date',
					'order'          => 'DESC',
					'no_found_rows'  => true,
					'meta_query'     => array(
						array(
							'key'     => 'wp_travel_engine_coupon_show_banner',
							'value'   => 'yes',
							'compare' => '=',
						),
					),
				)
			);

			$candidate_ids = array_map( 'intval', $query->posts );
			set_transient( self::TRANSIENT_KEY, $candidate_ids, 5 * MINUTE_IN_SECONDS );
		}

		$ids = array();
		foreach ( $candidate_ids as $coupon_id ) {
			if ( 'active' !== CouponCode::get_coupon_status( $coupon_id ) ) {
				continue;
			}
			if ( 'yes' !== CouponCode::get_coupon_meta( $coupon_id, 'general', 'show_banner' ) ) {
				continue;
			}
			$ids[] = (int) $coupon_id;
		}

		/**
		 * Filter the coupon IDs eligible for the site-wide banner.
		 *
		 * @since 6.8.2
		 *
		 * @param int[] $ids
		 */
		return apply_filters( 'wptravelengine_coupon_banner_ids', $ids );
	}

	/**
	 * Render banners via wp_footer for themes lacking wp_body_open.
	 *
	 * Skips when wp_body_open already rendered them. Output is relocated to the
	 * top of <body> on the client so placement matches the wp_body_open path.
	 *
	 * @since 6.8.2
	 */
	public function render_fallback() {
		if ( $this->rendered ) {
			return;
		}
		$this->render( true );
	}

	/**
	 * @since 6.8.2 Added $is_footer to support the wp_footer fallback render.
	 *
	 * @param bool $is_footer Whether output is on wp_footer (requires client-side relocation).
	 */
	public function render( bool $is_footer = false ) {
		if ( $this->rendered || is_admin() ) {
			return;
		}

		$ids = $this->get_banner_coupon_ids();
		if ( empty( $ids ) ) {
			return;
		}

		$banners = array();
		foreach ( $ids as $coupon_id ) {
			if ( ! empty( $_COOKIE[ self::COOKIE_PREFIX . $coupon_id ] ) ) {
				continue;
			}
			$message = $this->build_message( $coupon_id );
			if ( '' === $message ) {
				continue;
			}
			$banners[ $coupon_id ] = $message;
		}

		if ( empty( $banners ) ) {
			return;
		}

		$this->rendered = true;

		foreach ( $banners as $coupon_id => $message ) {
			$bg      = CouponCode::get_coupon_meta( $coupon_id, 'general', 'banner_bg_color' );
			$text    = CouponCode::get_coupon_meta( $coupon_id, 'general', 'banner_text_color' );
			$style   = '';
			$classes = 'wte-coupon-banner';
			if ( ! empty( $bg ) ) {
				$style   .= 'background:' . esc_attr( $bg ) . ';';
				$classes .= ' wte-coupon-banner--custom-bg';
			}
			if ( ! empty( $text ) ) {
				$style .= 'color:' . esc_attr( $text ) . ';';
			}
			printf(
				'<div class="%7$s" data-coupon-id="%1$d" role="region" aria-label="%2$s"%6$s>'
					. '<div class="wte-coupon-banner__inner">'
					. '<span class="wte-coupon-banner__icon" aria-hidden="true">%3$s</span>'
					. '<span class="wte-coupon-banner__text">%4$s</span>'
					. '</div>'
					. '<button type="button" class="wte-coupon-banner__close" aria-label="%5$s">&times;</button>'
					. '</div>',
				(int) $coupon_id,
				esc_attr__( 'Promotional offer', 'wp-travel-engine' ),
				$this->bell_icon(),
				wp_kses_post( $message ),
				esc_attr__( 'Dismiss banner', 'wp-travel-engine' ),
				$style ? ' style="' . $style . '"' : '',
				esc_attr( $classes )
			);
		}

		$this->print_dismiss_script();

		if ( $is_footer ) {
			$this->print_relocate_script();
		}
	}

	protected function build_message( int $coupon_id ): string {
		$code = get_post_meta( $coupon_id, 'wp_travel_engine_coupon_code', true );
		if ( empty( $code ) ) {
			return '';
		}

		$template = CouponCode::get_coupon_meta( $coupon_id, 'general', 'banner_message' );
		if ( empty( $template ) ) {
			return '';
		}

		$type  = CouponCode::get_discount_type( $coupon_id );
		$value = (float) CouponCode::get_discount_value( $coupon_id );

		if ( 'percentage' === $type ) {
			$value_str = ( floor( $value ) == $value ? (int) $value : $value ) . '%';
		} else {
			$value_str = function_exists( 'wte_get_formated_price' ) ? wte_get_formated_price( $value ) : (string) $value;
		}

		$message = strtr(
			$template,
			array(
				'{code}'  => $code,
				'{value}' => $value_str,
			)
		);

		/**
		 * Filter the rendered banner message.
		 *
		 * @since 6.8.2
		 *
		 * @param string $message   Rendered message.
		 * @param int    $coupon_id Coupon ID.
		 */
		return (string) apply_filters( 'wptravelengine_coupon_banner_message', $message, $coupon_id );
	}

	protected function bell_icon(): string {
		return '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9"/><path d="M10.3 21a1.94 1.94 0 0 0 3.4 0"/></svg>';
	}

	protected function print_dismiss_script(): void {
		static $printed = false;
		if ( $printed ) {
			return;
		}
		$printed = true;
		?>
<script>
(function(){
	document.addEventListener('click', function(e){
		var btn = e.target.closest('.wte-coupon-banner__close');
		if (!btn) return;
		var banner = btn.closest('.wte-coupon-banner');
		if (!banner) return;
		var id = banner.getAttribute('data-coupon-id');
		document.cookie = '<?php echo esc_js( self::COOKIE_PREFIX ); ?>' + id + '=1; path=/; max-age=' + (60*60*24*30) + '; SameSite=Lax';
		banner.parentNode.removeChild(banner);
	});
})();
</script>
		<?php
	}

	/**
	 * Relocate footer-rendered banners to the top of <body>.
	 *
	 * @since 6.8.2
	 */
	protected function print_relocate_script(): void {
		?>
<script>
(function(){
	var banners = document.querySelectorAll('.wte-coupon-banner');
	for (var i = banners.length - 1; i >= 0; i--) {
		document.body.insertBefore(banners[i], document.body.firstChild);
	}
})();
</script>
		<?php
	}
}
