<?php
/**
 * Checkout form template content.
 *
 * @since 6.3.0
 */

do_action( 'wptravelengine_checkout_before_form' );

// Lead + additional travellers render together inside one "Traveler Details" box
// (the lead template calls checkout_template_parts_travellers-details internally).
do_action( 'checkout_template_parts_lead-travellers-details' );

do_action( 'checkout_template_parts_emergency-details' );

do_action( 'checkout_template_parts_billing-details' );

do_action( 'checkout_template_parts_checkout-note' );

do_action( 'checkout_template_parts_payments' );

do_action( 'wptravelengine_checkout_after_form' );
