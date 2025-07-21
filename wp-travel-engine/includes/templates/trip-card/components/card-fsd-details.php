<?php
/**
 * @var \WPTravelEngine\Core\Models\Post\Trip $trip_instance
 * @var array $fsds
 * @var boolean $related_query
 * @var boolean $show_related_date_layout
 * @var boolean $show_date_layout
 */

$show_date_layout = $related_query ? $show_related_date_layout : $show_date_layout;

if ( ! $show_date_layout || false === $fsds || empty( $fsds ) || ! $has_date ) {
    return;
}

if ( is_numeric( $fsds ) ) {
    $fsds = [
        wp_date( 'Y-m-d' ),
        wp_date( 'Y-m-d', strtotime( '+1 day' ) ),
        wp_date( 'Y-m-d', strtotime( '+2 day' ) ),
    ];
}

$i = 0;
$content = '';
foreach ( $fsds as $fsd ) :
    if ( 0 >= ( $fsd['seats_left'] ?? '' ) && $i <= 2 ) :
        continue;
    elseif ( $i > 2 ) :
        break;
    endif;
    $content .= '<span class="category-trip-start-date"><span>';
    $content .= wte_get_new_formated_date( $fsd['start_date'] ?? $fsd, get_option( 'date_format' ) );
    if ( empty( $fsd['seats_left'] ) ) {
        $content .= ' <em>('.esc_html__( 'Available', 'wp-travel-engine' ).')</em>';
    } else {
        $content .= ' <em>('. $fsd['seats_left'] .' '._n( 'Seat Available', 'Seats Available', $fsd['seats_left'], 'wp-travel-engine' ).')</em>';
    }
    $content .= '</span></span>';
    $i++;
endforeach;

if ( empty( $content ) ) {
    return;
}

?>

<div class="category-trip-dates">
    <span class="trip-dates-title"><?php echo esc_html__('Next Departures', 'wp-travel-engine'); ?></span>
    <?php echo $content; ?>
</div>
