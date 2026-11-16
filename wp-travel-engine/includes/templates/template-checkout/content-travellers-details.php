<?php
/**
 * Additional travellers — rendered inside the shared "Traveler Details" box.
 *
 * @var WPTravelEngine\Builders\FormFields\FormField $travellers_form_fields
 * @var bool $show_title
 * @since 6.3.0
 * @since 6.9.0 Renders only the traveller sections (no separate box); nested in the lead box.
 */

if ( 'hide' === ( $args['attributes']['travellers'] ?? '' ) ) {
	return;
}
$wptravelengine_settings = get_option( 'wp_travel_engine_settings', array() );
$travellers_details_type = $wptravelengine_settings['travellers_details_type'] ?? 'all';
$number_of_travellers    = $travellers_form_fields[0]->number_of_travellers ?? 1;

// Early return if only lead traveller details needed or when there is only one traveller.
if ( 'only_lead' === $travellers_details_type || $number_of_travellers <= 1 ) {
	return;
}

if ( isset( $travellers_form_fields ) && isset( $travellers_form_fields[0]->fields ) && empty( $travellers_form_fields[0]->fields ) ) {
	return;
}

foreach ( $travellers_form_fields as $travellers_form_field ) {
	$travellers_form_field->render();
}
