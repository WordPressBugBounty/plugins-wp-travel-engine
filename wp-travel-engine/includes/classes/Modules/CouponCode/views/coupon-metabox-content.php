<?php
/**
 * Coupon edit metabox — React app mount.
 *
 * @package WP Travel Engine Coupons
 */

use WPTravelEngine\Helpers\Functions;
use WPTravelEngine\Modules\CouponCode;

global $post;

$coupon_metas     = get_post_meta( $post->ID, 'wp_travel_engine_coupon_metas', true );
$coupon_metas     = is_array( $coupon_metas ) ? $coupon_metas : array();
$general_tab      = isset( $coupon_metas['general'] ) ? $coupon_metas['general'] : array();
$restrictions_tab = isset( $coupon_metas['restriction'] ) ? $coupon_metas['restriction'] : array();
$coupon_code      = get_post_meta( $post->ID, 'wp_travel_engine_coupon_code', true );

try {
	$start_date = ! empty( $general_tab['coupon_start_date'] )
		? ( new \DateTime( $general_tab['coupon_start_date'] ) )->format( 'Y-m-d' )
		: '';
} catch ( \Exception $e ) {
	$start_date = '';
}
try {
	$expiry_date = ! empty( $general_tab['coupon_expiry_date'] )
		? ( new \DateTime( $general_tab['coupon_expiry_date'] ) )->format( 'Y-m-d' )
		: '';
} catch ( \Exception $e ) {
	$expiry_date = '';
}
try {
	$trip_starts_after = ! empty( $general_tab['trip_starts_after'] )
		? ( new \DateTime( $general_tab['trip_starts_after'] ) )->format( 'Y-m-d' )
		: '';
} catch ( \Exception $e ) {
	$trip_starts_after = '';
}
try {
	$trip_starts_before = ! empty( $general_tab['trip_starts_before'] )
		? ( new \DateTime( $general_tab['trip_starts_before'] ) )->format( 'Y-m-d' )
		: '';
} catch ( \Exception $e ) {
	$trip_starts_before = '';
}

$coupon_id         = ! empty( $coupon_code ) ? CouponCode::coupon_id_by_code( $coupon_code ) : false;
$coupon_status_key = $coupon_id ? CouponCode::get_coupon_status( $coupon_id ) : 'inactive';
$is_active         = 'active' === $coupon_status_key;
$status_label      = $is_active ? __( 'Active', 'wp-travel-engine' ) : __( 'Inactive', 'wp-travel-engine' );

$wte_settings  = get_option( 'wp_travel_engine_settings', array() );
$currency_code = ! empty( $wte_settings['currency_code'] ) ? $wte_settings['currency_code'] : 'USD';
$currency      = Functions::currency_symbol_by_code( $currency_code );

$trips_raw  = wp_travel_engine_get_trips_array();
$trips_data = array();
foreach ( $trips_raw as $tid => $ttitle ) {
	$trips_data[] = array(
		'value' => (string) $tid,
		'label' => $ttitle,
	);
}

$restricted_trips = isset( $restrictions_tab['restricted_trips'] ) ? array_map( 'strval', (array) $restrictions_tab['restricted_trips'] ) : array();

$config = array(
	'couponId'    => $coupon_id ? (int) $coupon_id : 0,
	'currency'    => html_entity_decode( $currency ),
	'isActive'    => $is_active,
	'statusLabel' => $coupon_id ? $status_label : '',
	'postTitle'   => $post ? $post->post_title : '',
	'addNewUrl'   => admin_url( 'post-new.php?post_type=' . WP_TRAVEL_ENGINE_COUPONS_POST_TYPE ),
	'listUrl'     => admin_url( 'edit.php?post_type=' . WP_TRAVEL_ENGINE_COUPONS_POST_TYPE ),
	'trips'       => $trips_data,
	'coupon'      => array(
		'code'        => $coupon_code,
		'general'     => array(
			'coupon_type'                   => isset( $general_tab['coupon_type'] ) ? $general_tab['coupon_type'] : 'fixed',
			'coupon_value'                  => isset( $general_tab['coupon_value'] ) ? $general_tab['coupon_value'] : '',
			'coupon_start_date'             => $start_date,
			'coupon_expiry_date'            => $expiry_date,
			'trip_date_restriction_enabled' => ! empty( $general_tab['trip_date_restriction_enabled'] ) && 'yes' === $general_tab['trip_date_restriction_enabled'],
			'trip_starts_after'             => $trip_starts_after,
			'trip_starts_before'            => $trip_starts_before,
			'show_banner'                   => ! empty( $general_tab['show_banner'] ) && 'yes' === $general_tab['show_banner'],
			'banner_message'                => isset( $general_tab['banner_message'] ) ? $general_tab['banner_message'] : '',
			'banner_bg_color'               => isset( $general_tab['banner_bg_color'] ) && $general_tab['banner_bg_color'] ? $general_tab['banner_bg_color'] : '#f97316',
			'banner_text_color'             => isset( $general_tab['banner_text_color'] ) && $general_tab['banner_text_color'] ? $general_tab['banner_text_color'] : '#ffffff',
		),
		'restriction' => array(
			'restricted_trips'    => $restricted_trips,
			'coupon_limit_number' => isset( $restrictions_tab['coupon_limit_number'] ) ? $restrictions_tab['coupon_limit_number'] : '',
		),
	),
);

wp_enqueue_script( 'wptravelengine-coupon-edit' );
wp_add_inline_script(
	'wptravelengine-coupon-edit',
	'var wptravelengineCouponEdit = ' . wp_json_encode( $config ) . ';',
	'before'
);
wp_nonce_field( 'wte_save_coupon', 'wte_coupon_nonce' );
?>
<div id="wptravelengine-coupon-edit-app"></div>
