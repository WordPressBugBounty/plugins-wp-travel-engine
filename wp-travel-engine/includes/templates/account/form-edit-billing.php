<?php

/**
 * Edit account form
 *
 * This template can be overridden by copying it to yourtheme/wp-travel/account/form-edit-billing.php.
 *
 * HOWEVER, on occasion WP Travel will need to update template files and you
 * (the theme developer) will need to copy the new files to your theme to
 * maintain compatibility. We try to do this as little as possible, but it does
 * happen. When this occurs the version of the template file will be bumped and
 * the readme will list any important changes.
 *
 * @see     https://wptravelengine.com
 * @author  WP Travel Engine
 * @package WP Travel Engine/includes/templates
 * @version 1.3.7
 */

use WPTravelEngine\Helpers\Functions;

if (! defined('ABSPATH')) {
	exit;
}

// Hook before the billing address form.
do_action('wp_travel_engine_before_edit_billing_form');

$biling_data = get_user_meta($user->ID, 'wp_travel_engine_customer_billing_details', true);

// Set Vars.
$billing_name    = get_user_meta($user->ID, 'first_name', true) . ' ' . get_user_meta($user->ID, 'last_name', true);
$billing_address = isset($biling_data['billing_address']) ? $biling_data['billing_address'] : '';
$billing_city    = isset($biling_data['billing_city']) ? $biling_data['billing_city'] : '';
$billing_company = isset($biling_data['billing_company']) ? $biling_data['billing_company'] : '';
$billing_state   = isset($biling_data['billing_state']) ? $biling_data['billing_state'] : '';
$billing_zip     = isset($biling_data['billing_zip_code']) ? $biling_data['billing_zip_code'] : '';
$billing_country = isset($biling_data['billing_country']) ? $biling_data['billing_country'] : '';
$billing_phone   = isset($biling_data['billing_phone']) ? $biling_data['billing_phone'] : '';
?>
<form method="post" class="wpte-lrf-form">
	<?php do_action('wp_travel_engine_edit_billing_form_start'); ?>

	<div class="wpte__form-row columns-2">
		<div class="wpte__form-col">
			<div class="wpte-form-field lrf-text">
				<label class="lrf-field-label" for="customer_billing_company"><?php esc_html_e('Company Name:', 'wp-travel-engine'); ?></label>
				<input type="text" name="customer_billing_company" id="customer_billing_company"
					value="<?php echo esc_attr($billing_company); ?>" />
			</div>
		</div>
		<div class="wpte__form-col">
			<div class="wpte-form-field lrf-text">
				<label class="lrf-field-label" for="customer_billing_name"><?php esc_html_e('Billing Name:', 'wp-travel-engine'); ?></label>
				<input type="text" name="customer_billing_name" id="customer_billing_name" readonly
					value="<?php echo esc_attr($billing_name); ?>" />
			</div>
		</div>
		<div class="wpte__form-col">
			<div class="wpte-form-field lrf-text">
				<label class="lrf-field-label" for="customer_billing_address"><?php esc_html_e('Address:', 'wp-travel-engine'); ?><span
						class="required">*</span></label>
				<input type="text" name="customer_billing_address" id="customer_billing_address" required="1"
					data-msg="Please enter your address" data-parsley-required-message="Please enter your address"
					value="<?php echo esc_attr($billing_address); ?>" />
			</div>
		</div>
		<div class="wpte__form-col">
			<div class="wpte-form-field lrf-text">
				<label class="lrf-field-label" for="customer_billing_city"><?php esc_html_e('City:', 'wp-travel-engine'); ?></label>
				<input type="text" name="customer_billing_city" id="customer_billing_city" required="1"
					data-msg="Please enter your city" data-parsley-required-message="Please enter your city"
					value="<?php echo esc_attr($billing_city); ?>" />
			</div>
		</div>
		<div class="wpte__form-col">
			<div class="wpte-form-field lrf-text">
				<label class="lrf-field-label" for="customer_billing_state"><?php esc_html_e('State:', 'wp-travel-engine'); ?></label>
				<input type="text" name="customer_billing_state" id="customer_billing_state"
					data-msg="Please enter your state"
					value="<?php echo esc_attr($billing_state); ?>" />
			</div>
		</div>
		<div class="wpte__form-col">
			<div class="wpte-form-field lrf-text">
				<div class="wpte-form-field lrf-text">
					<label class="lrf-field-label" for="customer_country"><?php esc_html_e('Country:', 'wp-travel-engine'); ?><span
							class="required">*</span></label>
					<select id="customer_country" class="selectpicker form-control" name="customer_country" required="1"
						data-msg="Please select a country" data-parsley-required-message="Please select a country">
						<option value=""><?php esc_html_e('Select a Country', 'wp-travel-engine'); ?></option>
						<?php
						$country_list = Functions::get_countries();
						foreach ($country_list as $key => $value) :
						?>
							<option value="<?php echo esc_html($key); ?>" <?php selected($key, $billing_country); ?>>
								<?php echo esc_html($value); ?>
							</option>
						<?php endforeach; ?>
					</select>
				</div>
			</div>
		</div>
		<div class="wpte__form-col">
			<div class="wpte-form-field lrf-number">
				<label class="lrf-field-label"
					for="customer_zip_code"><?php esc_html_e('Zip/Postal Code:', 'wp-travel-engine'); ?></label>
				<input type="number" name="customer_zip_code" id="customer_zip_code"
					data-msg="Please enter zip code"
					value="<?php echo esc_attr($billing_zip); ?>" />
			</div>
		</div>
		<div class="wpte__form-col">
			<div class="wpte-form-field lrf-tel">
				<label class="lrf-field-label" for="customer_phone"><?php esc_html_e('Phone Number:', 'wp-travel-engine'); ?>
					<span class="required">*</span></label>
				<input type="number" name="customer_phone" id="customer_phone" required="1"
					data-msg="Please enter your phone number"
					data-parsley-required-message="Please enter your phone number"
					value="<?php echo esc_attr($billing_phone); ?>" />
			</div>
		</div>
		<div class="wpte__form-col full">
			<div class="wpte-form-field lrf-submit">
				<?php
				do_action('wp_travel_engine_edit_billing_form');
				wp_nonce_field('wp_travel_engine_save_user_meta_billing_address', 'wp_billing_address_security');
				?>
				<input type="submit" class="wpte-lrf-btn" name="wp_travel_engine_save_user_meta_billing_address"
					value="<?php esc_attr_e('Save changes', 'wp-travel-engine'); ?>">
				<input type="hidden" name="action" value="wp_travel_engine_save_user_meta_billing_address" />
			</div>
		</div>
	</div>

	<?php do_action('wp_travel_engine_edit_billing_form_end'); ?>
</form>
<?php
$checkout_fields = wp_travel_engine_get_checkout_form_fields();
do_action('wp_travel_engine_after_edit_billing_form');
