<?php
/**
 * Review Comments Template
 *
 * This template can be overridden by copying it to yourtheme/wp-travel-engine/single-trip/trip-tabs/review.php.
 *
 * @package Wp_Travel_Engine
 * @subpackage Wp_Travel_Engine/includes/templates
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}
do_action( 'wte_before_review_content' ); ?>

<div class="post-data">
	<?php
		/**
		 * Hook - Display tab content title, left for themes.
		 */
		do_action( 'wte_review_tab_title' );
	?>
	<div class="content">
		<?php
		if ( ! empty( $title ) ) :
			echo '<h3>' . esc_attr( $title ) . '</h3>';
		endif;
			$obj = new Wte_Trip_Review_Init();
			$obj->show_trip_rating( $id );
			$obj->show_trip_rating_form();
		?>
	</div>
</div>

<?php
do_action( 'wte_after_review_content' );
