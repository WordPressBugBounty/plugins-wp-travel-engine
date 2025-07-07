<?php
/**
 * Trip Card Aside
 */

?>
<div class="wpte-trip-price-wrapper">
    <div class="wpte-trip-duration">
        <?php echo esc_html__('Duration', 'wp-travel-engine'); ?>
        <?php 
            if ( false !== $trip_duration ) {
                ?>
                <span class="wpte-trip-duration-value">
                    <?php wptravelengine_get_template( 'components/content-trip-card-duration.php', array( 'is_booking_detail' => true ) );?>
                </span>
                <?php
            }
        ?>
    </div>
    <?php
    if ( ! empty( $trip_instance->get_price() ) ) {
        wptravelengine_get_template( 'trip-card/components/card-price.php' );
    }
    ?>
</div>
<div class="wpte-button-group">
    <a href="<?php echo esc_url( get_the_permalink() ); ?>" class="wpte-button">
        <?php echo esc_html__('View More', 'wp-travel-engine'); ?>
    </a>
    <?php do_action( 'wp_travel_engine_download_pdf_button' ); ?>
</div>
<?php

$fsds = apply_filters( 'trip_card_fixed_departure_dates', $trip_id );

wptravelengine_get_template( 'trip-card/components/card-fsd-details.php', compact( 'fsds' ) );