<?php
/**
 * WP Travel Engine Trip Code.
 *
 * @since __addonmigration__
 */
namespace WPTravelEngine\Modules;

use WPTravelEngine\Modules\CouponCode\Ajax;
use WPTravelEngine\Modules\CouponCode\Banner;

class CouponCode {
	public function __construct() {
		defined( 'WP_TRAVEL_ENGINE_COUPONS_POST_TYPE' ) || define( 'WP_TRAVEL_ENGINE_COUPONS_POST_TYPE', 'wte-coupon' );

		new Ajax();
		new Banner();

		$this->init_hooks();
	}

	/**
	 * Initialize Hooks.
	 *
	 * @return void
	 */
	private function init_hooks() {
		// Column Header.
		add_filter(
			'manage_edit-wte-coupon_columns',
			function ( $columns ) {
				return array(
					'cb'              => '<input type="checkbox" />',
					'title'           => _x( 'Name', 'column name', 'wp-travel-engine' ),
					'coupon_code'     => _x( 'Code', 'column name', 'wp-travel-engine' ),
					'discount_value'  => _x( 'Discount Value', 'column name', 'wp-travel-engine' ),
					'max_users'       => _x( 'Max Uses', 'column name', 'wp-travel-engine' ),
					'used_so_far'     => _x( 'Usage Count', 'column name', 'wp-travel-engine' ),
					'expiration_date' => _x( 'Expiration Date', 'column name', 'wp-travel-engine' ),
					'coupon_status'   => __( 'Status', 'wp-travel-engine' ),
					'date'            => __( 'Created Date', 'wp-travel-engine' ),
				);
			}
		);

		// Column Rows.
		add_action(
			'manage_wte-coupon_posts_custom_column',
			function ( $column_name, $id ) {
				if ( is_callable( array( $this, "get_column_value_{$column_name}" ) ) ) {
					call_user_func( array( $this, "get_column_value_{$column_name}" ), $id );
				}
			},
			10,
			2
		);

		add_filter(
			'post_updated_messages',
			function ( $messages ) {
				global $post, $post_ID;

				$post_object = get_post_type_object( 'wte-coupon' );

				$messages['wte-coupon'] = array(
					0  => '', // Unused. Messages start at index 1.
					1  => sprintf( __( '%s updated.', 'wp-travel-engine' ), $post_object->labels->singular_name ),
					2  => __( 'Custom field updated.', 'wp-travel-engine' ),
					3  => __( 'Custom field deleted.', 'wp-travel-engine' ),
					4  => sprintf( __( '%s updated.', 'wp-travel-engine' ), $post_object->labels->singular_name ),
					5  => isset( $_GET['revision'] ) ? sprintf( __( '%1$s restored to revision from %2$s', 'wp-travel-engine' ), $post_object->labels->singular_name, wp_post_revision_title( (int) wte_clean( wp_unslash( $_GET['revision'] ) ), false ) ) : false, // phpcs:ignore
					6  => sprintf( __( '%1$s published. <a href="%2$s">View %3$s</a>', 'wp-travel-engine' ), $post_object->labels->singular_name, esc_url( get_permalink( $post_ID ) ), $post_object->labels->singular_name ),
					7  => sprintf( __( '%s saved.', 'wp-travel-engine' ), $post_object->labels->singular_name ),
					8  => sprintf( __( '%1$s submitted. <a target="_blank" href="%2$s">Preview %3$s</a>', 'wp-travel-engine' ), $post_object->labels->singular_name, esc_url( add_query_arg( 'preview', 'true', get_permalink( $post_ID ) ) ), $post_object->labels->singular_name ),
					9  => sprintf( __( '%1$s scheduled for: <strong>%2$s</strong>. <a target="_blank" href="%3$s">Preview %4$s</a>', 'wp-travel-engine' ), $post_object->labels->singular_name, date_i18n( __( 'M j, Y @ G:i', 'wp-travel-engine' ), strtotime( $post->post_date ) ), esc_url( get_permalink( $post_ID ) ), $post_object->labels->singular_name ),
					10 => sprintf( __( '%1$s draft updated. <a target="_blank" href="%2$s">Preview %3$s</a>', 'wp-travel-engine' ), $post_object->labels->singular_name, esc_url( add_query_arg( 'preview', 'true', get_permalink( $post_ID ) ) ), $post_object->labels->singular_name ),
				);
				return $messages;
			}
		);

		add_action(
			'add_meta_boxes',
			function ( $post_type ) {
				if ( WP_TRAVEL_ENGINE_COUPONS_POST_TYPE !== $post_type ) {
					return;
				}
				\add_meta_box( WP_TRAVEL_ENGINE_COUPONS_POST_TYPE . '-details', __( 'Coupon Options', 'wp-travel-engine' ), array( __CLASS__, 'add_coupon_metabox_field_callback' ), WP_TRAVEL_ENGINE_COUPONS_POST_TYPE, 'normal', 'high' );
			},
			10,
			2
		);

		add_action( 'save_post_wte-coupon', array( __CLASS__, 'save_wte_coupon_metabox' ) );

		add_action( 'wp_travel_engine_before_billing_form', array( __CLASS__, 'checkout_coupon_form' ) );
	}

	/**
	 * Checkout Coupon Form.
	 *
	 * @return void
	 */
	public static function checkout_coupon_form() {
		wte_get_template( 'checkout-coupon-form.php', array(), '', __DIR__ . '/CouponCode/views' );
	}

	private static function can_update_coupon( $post_id ) {
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		// If this is just a revision, don't save.
		if ( wp_is_post_revision( $post_id ) ) {
			return;
		}

		return ! ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) && current_user_can( 'edit_post', $post_id ) && ! wp_is_post_revision( $post_id );
	}

	/**
	 * @since 6.8.2 Added max discount, behavior, trip date restriction, min travelers, min spend fields.
	 */
	public static function get_coupon_edit_form_schema() {
		$schema = array(
			'wp_travel_engine_coupon_code' => array(
				'type'              => 'text',
				'sanitize_callback' => 'wp_filter_nohtml_kses',
			),
			'wp_travel_engine_coupon'      => array(
				'type'  => 'object',
				'items' => array(
					'general'     => array(
						'type'  => 'object',
						'items' => array(
							'coupon_type'        => array(
								'type'              => 'text',
								'sanitize_callback' => 'wp_filter_nohtml_kses',
							),
							'coupon_value'       => array(
								'type' => 'number',
							),
							'coupon_start_date'  => array(
								'type'              => 'text',
								'sanitize_callback' => 'wp_filter_nohtml_kses',
							),
							'coupon_expiry_date' => array(
								'type'              => 'text',
								'sanitize_callback' => 'wp_filter_nohtml_kses',
							),
							'trip_date_restriction_enabled' => array(
								'type'              => 'text',
								'sanitize_callback' => 'wp_filter_nohtml_kses',
							),
							'trip_starts_after'  => array(
								'type'              => 'text',
								'sanitize_callback' => 'wp_filter_nohtml_kses',
							),
							'trip_starts_before' => array(
								'type'              => 'text',
								'sanitize_callback' => 'wp_filter_nohtml_kses',
							),
							'show_banner'        => array(
								'type'              => 'text',
								'sanitize_callback' => 'wp_filter_nohtml_kses',
							),
							'banner_message'     => array(
								'type'              => 'text',
								'sanitize_callback' => 'wp_filter_nohtml_kses',
							),
							'banner_bg_color'    => array(
								'type'              => 'text',
								'sanitize_callback' => 'sanitize_hex_color',
							),
							'banner_text_color'  => array(
								'type'              => 'text',
								'sanitize_callback' => 'sanitize_hex_color',
							),
						),
					),
					'restriction' => array(
						'type'  => 'object',
						'items' => array(
							'coupon_limit_number' => array(
								'type' => 'number',
							),
						),
					),
				),
			),
		);

		return $schema;
	}

	/**
	 * Save coupon metabox.
	 *
	 * @since 6.8.2 Save new coupon fields: max discount cap, behavior, trip date restriction window, min travelers, min spend.
	 */
	public static function save_wte_coupon_metabox( $coupon_id ) {
		if ( ! self::can_update_coupon( $coupon_id ) ) {
			return;
		}

		$coupon = get_post( $coupon_id );
		if ( is_null( $coupon ) || WP_TRAVEL_ENGINE_COUPONS_POST_TYPE !== $coupon->post_type ) {
			return;
		}

		if ( ! isset( $_POST['wte_coupon_nonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['wte_coupon_nonce'] ) ), 'wte_save_coupon' ) ) {
			return;
		}

		empty( $_POST['wp_travel_engine_coupon_code'] ) || update_post_meta( $coupon->ID, 'wp_travel_engine_coupon_code', wp_filter_nohtml_kses( wp_unslash( $_POST['wp_travel_engine_coupon_code'] ) ) );

		if ( ! isset( $_POST['wp_travel_engine_coupon'] ) ) {
			return;
		}

		$coupon_data = wte_clean( wp_unslash( $_POST['wp_travel_engine_coupon'] ) );
		$_data       = array();

		if ( isset( $coupon_data['general'] ) && is_array( $coupon_data['general'] ) ) {
			$general = $coupon_data['general'];

			$allowed_types = array( 'fixed', 'percentage' );

			$_data['general'] = array(
				'coupon_type'                   => in_array( $general['coupon_type'] ?? '', $allowed_types, true ) ? $general['coupon_type'] : 'fixed',
				'coupon_value'                  => isset( $general['coupon_value'] ) ? wp_filter_nohtml_kses( $general['coupon_value'] ) : '',
				'coupon_start_date'             => isset( $general['coupon_start_date'] ) ? wp_filter_nohtml_kses( $general['coupon_start_date'] ) : '',
				'coupon_expiry_date'            => isset( $general['coupon_expiry_date'] ) ? wp_filter_nohtml_kses( $general['coupon_expiry_date'] ) : '',
				'trip_date_restriction_enabled' => ( isset( $general['trip_date_restriction_enabled'] ) && in_array( $general['trip_date_restriction_enabled'], array( 'yes', 'on', '1', 1, true ), true ) ) ? 'yes' : 'no',
				'trip_starts_after'             => isset( $general['trip_starts_after'] ) ? wp_filter_nohtml_kses( $general['trip_starts_after'] ) : '',
				'trip_starts_before'            => isset( $general['trip_starts_before'] ) ? wp_filter_nohtml_kses( $general['trip_starts_before'] ) : '',
				'show_banner'                   => ( isset( $general['show_banner'] ) && in_array( $general['show_banner'], array( 'yes', 'on', '1', 1, true ), true ) ) ? 'yes' : 'no',
				'banner_message'                => isset( $general['banner_message'] ) ? wp_filter_nohtml_kses( $general['banner_message'] ) : '',
				'banner_bg_color'               => isset( $general['banner_bg_color'] ) ? ( sanitize_hex_color( $general['banner_bg_color'] ) ?? '' ) : '',
				'banner_text_color'             => isset( $general['banner_text_color'] ) ? ( sanitize_hex_color( $general['banner_text_color'] ) ?? '' ) : '',
			);
		}

		if ( isset( $coupon_data['restriction'] ) && is_array( $coupon_data['restriction'] ) ) {
			$restriction = $coupon_data['restriction'];

			$_data['restriction'] = array(
				'restricted_trips'    => isset( $restriction['restricted_trips'] ) ? array_map( 'absint', (array) $restriction['restricted_trips'] ) : array(),
				'coupon_limit_number' => isset( $restriction['coupon_limit_number'] ) && '' !== $restriction['coupon_limit_number'] ? absint( $restriction['coupon_limit_number'] ) : '',
			);
		}

		update_post_meta( $coupon->ID, 'wp_travel_engine_coupon_metas', $_data );

		// Dedicated indexed key so the banner query avoids a serialized LIKE scan.
		if ( 'yes' === ( $_data['general']['show_banner'] ?? 'no' ) ) {
			update_post_meta( $coupon->ID, 'wp_travel_engine_coupon_show_banner', 'yes' );
		} else {
			delete_post_meta( $coupon->ID, 'wp_travel_engine_coupon_show_banner' );
		}

		delete_transient( Banner::TRANSIENT_KEY );
	}

	/**
	 * @since 6.8.2 Falls back to post creation date when coupon start date is blank; normalises start/end to midnight before comparing.
	 */
	public static function is_coupon_date_valid( $coupon_id ) {
		if ( empty( $coupon_id ) ) {
			return false;
		}

		$coupon_metas       = get_post_meta( $coupon_id, 'wp_travel_engine_coupon_metas', true );
		$general_tab        = isset( $coupon_metas['general'] ) ? $coupon_metas['general'] : array();
		$coupon_expiry_date = isset( $general_tab['coupon_expiry_date'] ) ? $general_tab['coupon_expiry_date'] : '';
		$coupon_start_date  = isset( $general_tab['coupon_start_date'] ) ? $general_tab['coupon_start_date'] : '';

		if ( empty( $coupon_start_date ) ) {
			$post_date = get_post_field( 'post_date', $coupon_id );
			if ( ! empty( $post_date ) ) {
				$coupon_start_date = $post_date;
			}
		}

		// Check Coupon Status.
		$coupon_status = get_post_status( $coupon_id );

		if ( 'publish' !== $coupon_status ) {
			return false;
		}

		$date_now = new \DateTime();
		$date_now->setTime( 0, 0, 0, 0 );

		try {
			$start = new \DateTime( $coupon_start_date );
			$start->setTime( 0, 0, 0, 0 );
		} catch ( \Exception $e ) {
			return false;
		}

		if ( ! empty( $coupon_expiry_date ) ) {
			try {
				$end = new \DateTime( $coupon_expiry_date );
				$end->setTime( 0, 0, 0, 0 );
			} catch ( \Exception $e ) {
				return false;
			}
			return $date_now <= $end && $date_now >= $start;
		}

		return $date_now >= $start;
	}

	public static function coupon_can_be_applied( $coupon_id, $trip_id ) {

		$restricted_trips = self::get_coupon_meta( $coupon_id, 'restriction', 'restricted_trips' );

		return empty( $restricted_trips ) || in_array( $trip_id, $restricted_trips );
	}

	public static function get_usage_count( $coupon_id ) {
		return (int) get_post_meta( $coupon_id, 'wp_travel_engine_coupon_usage_count', true );
	}

	public static function update_usage_count( $coupon_id, $increment_by = 1 ) {
		$count = self::get_usage_count( $coupon_id ) + (int) $increment_by;
		$count = $count < 1 ? 0 : $count;
		update_post_meta( $coupon_id, 'wp_travel_engine_coupon_usage_count', $count );
	}

	public static function get_discount_type( $coupon_id ) {
		$value = self::get_coupon_meta( $coupon_id, 'general', 'coupon_type' );
		return $value ? $value : 'fixed';
	}

	public static function get_discount_value( $coupon_id ) {
		$value = self::get_coupon_meta( $coupon_id, 'general', 'coupon_value' );
		return $value ? (float) $value : 0;
	}

	public static function get_coupon_meta( $coupon_id, $tab, $key ) {

		if ( empty( $coupon_id ) || empty( $key ) || empty( $tab ) ) {
			return false;
		}

		$coupon_metas = get_post_meta( $coupon_id, 'wp_travel_engine_coupon_metas', true );

		return isset( $coupon_metas[ $tab ][ $key ] ) ? $coupon_metas[ $tab ][ $key ] : false;
	}

	public static function get_coupon_status( $coupon_id ) {
		if ( ! $coupon_id || empty( $coupon_id ) ) {
			return false;
		}

		if ( ! self::is_coupon_date_valid( $coupon_id ) ) {
			return 'inactive';
		}

		// Activity by usage count.
		$usage_count = self::get_usage_count( $coupon_id );
		$limit       = self::get_coupon_meta( $coupon_id, 'restriction', 'coupon_limit_number' );

		return '' !== $limit && ( (int) $limit <= $usage_count ) ? 'inactive' : 'active';
	}

	private function get_column_value_coupon_status( $coupon_id ) {
		$coupon_status = self::get_coupon_status( $coupon_id );
		if ( 'active' === $coupon_status ) {
			print(
				'<span class="wp-travel-engine-info-msg">'
				. esc_html__( 'Active', 'wp-travel-engine' )
				. '</span>'
			);
		} else {
			print(
				'<span class="wp-travel-engine-info-msg">'
				. esc_html__( 'Inactive', 'wp-travel-engine' )
				. '</span>'
			);
		}
	}

	private function get_column_value_discount_value( $coupon_id ) {
		$discount_type  = self::get_coupon_meta( $coupon_id, 'general', 'coupon_type' );
		$discount_value = self::get_coupon_meta( $coupon_id, 'general', 'coupon_value' );

		$discount_value = ( 'percentage' === $discount_type ) ? $discount_value . ' (%) ' : wte_get_formated_price( $discount_value );
		printf(
			'<span><strong>%1$s</strong></span>',
			esc_html( $discount_value )
		);
	}

	private function get_column_value_max_users( $coupon_id ) {
		$max_users = self::get_coupon_meta( $coupon_id, 'restriction', 'coupon_limit_number' );
		printf( '<span><strong>%1$s</strong></span>', esc_html( $max_users ) );
	}
	private function get_column_value_used_so_far( $coupon_id ) {
		printf( '<span><strong>%1$s</strong></span>', esc_html( self::get_usage_count( $coupon_id ) ) );
	}
	private function get_column_value_expiration_date( $coupon_id ) {
		printf( '<span><strong>%1$s</strong></span>', esc_html( self::get_coupon_meta( $coupon_id, 'general', 'coupon_expiry_date' ) ) );
	}

	private function get_column_value_coupon_code( $coupon_id ) {
		echo '<span><strong>' . esc_html( get_post_meta( $coupon_id, 'wp_travel_engine_coupon_code', true ) ) . '</strong></span>';
	}

	public static function coupon_id_by_code( $code ) {
		global $wpdb;

		$meta_key = 'wp_travel_engine_coupon_code';

		$sql = $wpdb->prepare(
			"
			SELECT post_id
			FROM $wpdb->postmeta
			WHERE meta_key = %s
			AND meta_value = BINARY %s
		",
			$meta_key,
			$code
		);

		$results = $wpdb->get_results( $sql );

		if ( empty( $results ) ) {
			return false;
		}
		foreach ( $results as $result ) {
			$coupon_post = get_post( $result->post_id );
			if ( 'publish' === $coupon_post->post_status ) {
				return $coupon_post->ID;
			}
		}

		return $results['0']->post_id;
	}

	public static function add_coupon_metabox_field_callback() {
		include_once plugin_dir_path( __FILE__ ) . 'CouponCode/views/coupon-metabox-content.php';
	}
}
