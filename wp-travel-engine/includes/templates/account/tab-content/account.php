<?php
/**
 * Account tab content.
 *
 * @deprecated 6.9.0 No longer loaded by WP Travel Engine, so a theme override of it has no effect. It will be removed in a future release.
 */
	// get current user.
	$current_user = $args['current_user'];
?>
<div class="wpte-lrf-block-wrap">
	<div class="wpte-lrf-block">
		<?php
			wte_get_template(
				'account/form-edit-account.php',
				array(
					'user' => $current_user,
				)
			);
			?>
	</div>
</div>
<?php
