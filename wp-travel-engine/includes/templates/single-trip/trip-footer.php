<?php
/**
 * Single Trip Footer
 *
 * This template can be overridden by copying it to yourtheme/wp-travel-engine/single-trip/trip-footer.php.
 *
 * @package Wp_Travel_Engine
 * @subpackage Wp_Travel_Engine/includes/templates
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}
?>

<footer class="entry-footer">
	<?php
	edit_post_link(
		sprintf(/* translators: %s: id of current post */
			__( 'Edit<span class="screen-reader-text"> "%s"</span>', 'wp-travel-engine' ),
			get_the_title()
		),
		'<span class="edit-link">',
		'</span>'
	);
	?>
</footer>
<?php
