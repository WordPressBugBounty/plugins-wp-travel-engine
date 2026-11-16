<?php
/**
 * Checkout Page Template Filters.
 *
 * @since 6.3.0
 */

namespace WPTravelEngine\Filters;

use WPTravelEngine\Abstracts\BookingProcessPageTemplate;
use WPTravelEngine\Builders\FormFields\BillingFormFields;
use WPTravelEngine\Builders\FormFields\EmergencyFormFields;
use WPTravelEngine\Builders\FormFields\PrivacyPolicyFields;
use WPTravelEngine\Builders\FormFields\TravellersFormFields;
use WPTravelEngine\Builders\FormFields\LeadTravellersFormFields;
use WPTravelEngine\Core\Coupons;
use WPTravelEngine\Core\Models\Settings\PluginSettings;
use WPTravelEngine\Pages\Checkout;

/**
 * Checkout Page Template Filters.
 *
 * @since 6.3.0
 */
class CheckoutPageTemplate extends BookingProcessPageTemplate {

	/**
	 * Register Hooks.
	 *
	 * @return void
	 * @since 6.3.0
	 * @since 6.9.0 renamed the cart hook prefix and resolves each label at render time, not registration
	 */
	public function hooks() {
		$line_item_group_keys = array_keys( wptravelengine_get_labels( 'line_item_group' ) );
		foreach ( $line_item_group_keys as $key ) {
			add_action(
				"wptravelengine_before_{$key}_cart_line_items",
				static function () use ( $key ) {
					$val = wptravelengine_get_labels( 'line_item_group' )[ $key ] ?? '';
					if ( ! empty( $val ) ) {
						print( '<div class="wpte-checkout__summary-caption">' . esc_html( $val ) . '</div>' );
					}
				}
			);
		}

		add_action( 'wptravelengine_checkout_form_submit_button', array( $this, 'print_checkout_form_button' ) );

		add_filter(
			'wptravelengine_checkout_paypal_payment_button',
			array( $this, 'print_paypal_checkout_button' ),
			10,
			2
		);

		add_filter(
			'wptravelengine_checkout_stripe_payment_button',
			array( $this, 'print_stripe_payment_button' ),
			10,
			2
		);

		add_filter(
			'wptravelengine_checkout_paypalexpress_enable_button',
			array( $this, 'print_paypalexpress_checkout_button' ),
			10,
			2
		);

		add_filter(
			'wptravelengine_checkout_authorize-net-payment_button',
			array(
				$this,
				'print_authorize_net_payment_button',
			),
			10,
			2
		);

		add_filter(
			'wptravelengine_checkout_payu_money_enable_button',
			array(
				$this,
				'print_payu_money_enable_button',
			),
			10,
			2
		);

		add_action( 'wptravelengine_stripe_payment_payment_cc', array( $this, 'print_stripe_payment_cc' ) );

		$checkout_templates = array(
			'wptravelengine_checkout_payment_modes'      => 'print_payment_modes',
			'wptravelengine_checkout_payment_methods'    => 'print_payment_methods',
			'checkout_template_parts_tour-details'       => 'print_tour_details',
			'checkout_template_parts_cart-summary'       => 'print_cart_summary',
			'checkout_template_parts_payments'           => 'print_payments_methods',
			'checkout_template_parts_lead-travellers-details' => 'print_lead_travellers_details',
			'checkout_template_parts_travellers-details' => 'print_travellers_details',
			'checkout_template_parts_billing-details'    => 'print_billing_details',
			'checkout_template_parts_checkout-note'      => 'print_checkout_note',
			'checkout_template_parts_emergency-details'  => 'print_emergency_details',
			'checkout_template_parts_checkout-form'      => 'print_checkout_form',
		);

		foreach ( $checkout_templates as $template_part => $callback ) {
			add_action(
				$template_part,
				function ( $args ) use ( $callback ) {
					$args = ! is_array( $args ) ? array() : $args;

					call_user_func(
						array( $this, $callback ),
						wp_parse_args(
							$args,
							array(
								'show_title'   => true,
								'content_only' => false,
							)
						)
					);
				}
			);
		}

		do_action( 'wptravelengine_checkout_page_template_filters', $this );
	}

	/**
	 * Print the Checkout Form.
	 *
	 * @return void
	 */
	public function print_checkout_form() {
		wp_dequeue_script( 'Wte-PayPal-Express' );
		global $wte_cart;

		$template_instance = Checkout::instance( $wte_cart );
		$args              = array(
			'billing_form_fields'         => new BillingFormFields(),
			'lead_travellers_form_fields' => new LeadTravellersFormFields(),
			'travellers_form_fields'      => new TravellersFormFields(),
			'emergency_contact_fields'    => new EmergencyFormFields(),
			'note_form_fields'            => wptravelengine_form_field( false )->init( $template_instance->get_note_form_fields() ),
			'privacy_policy_fields'       => new PrivacyPolicyFields(),
		);
		?>
		<form class="wpte-checkout__content" method="POST" id="wptravelengine-checkout__form"
				enctype="multipart/form-data">
			<input type="hidden" name="action" value="wp_travel_engine_new_booking_process_action">
			<?php
			wp_nonce_field( 'wp_travel_engine_new_booking_process_nonce_action', 'wp_travel_engine_new_booking_process_nonce' );
			wptravelengine_get_template( 'template-checkout/content-checkout-form.php', $args );
			?>
		</form>
		<?php
	}

	/**
	 * Print the Lead Travellers Details.
	 *
	 * @param array $args Arguments.
	 * @since 6.4.3
	 * @return void
	 * @since 6.9.0 Passes the first traveler category label to the lead traveler form.
	 */
	public function print_lead_travellers_details( array $args ) {
		global $wte_cart;
		if ( ! isset( $lead_travellers_form_fields ) ) {
			$lead_travellers_form_fields = array();
			foreach ( $wte_cart->getItems( true ) as $cart_item ) {
				$lead_travellers_form_fields[] = new LeadTravellersFormFields(
					array(
						'traveler_category_label' => $cart_item->get_traveler_category_labels()[0] ?? '',
					)
				);
			}
		}
		$args = array_merge( compact( 'lead_travellers_form_fields' ), $args );
		wptravelengine_get_template( 'template-checkout/content-lead-travellers-details.php', $args );
	}

	/**
	 * Print the Travellers Details.
	 *
	 * @return void
	 * @since 6.9.0 Passes the traveler category labels to the traveler forms.
	 */
	public function print_travellers_details( array $args ) {
		global $wte_cart;
		if ( ! isset( $travellers_form_fields ) ) {
			$travellers_form_fields = array();
			foreach ( $wte_cart->getItems( true ) as $cart_item ) {
				$travellers_form_fields[] = new TravellersFormFields(
					array(
						'number_of_travellers'      => array_sum( $cart_item->travelers ?? $cart_item->pax ),
						'number_of_lead_travellers' => 1,
						'traveler_category_labels'  => $cart_item->get_traveler_category_labels(),
					)
				);
			}
		}
		$args = array_merge( compact( 'travellers_form_fields' ), $args );
		wptravelengine_get_template( 'template-checkout/content-travellers-details.php', $args );
	}

	/**
	 * Print the Billing Details.
	 *
	 * @return void
	 */
	public function print_billing_details() {
		global $wte_cart;
		if ( ! isset( $billing_form_fields ) ) {
			$billing_form_fields = new BillingFormFields( array( 'booking_ref' => $wte_cart->get_booking_ref() ) );
		}

		$lead_travellers_form_fields = array();
		if ( isset( $lead_travellers_form_fields ) ) {
			foreach ( $wte_cart->getItems( true ) as $cart_item ) {
				$lead_travellers_form_fields[] = new LeadTravellersFormFields( array() );
			}
		}
		$lead_travellers_fields_count = isset( $lead_travellers_form_fields ) && isset( $lead_travellers_form_fields[0]->fields ) ? count( $lead_travellers_form_fields[0]->fields ) : 0;
		$args                         = compact( 'billing_form_fields', 'lead_travellers_fields_count' );
		wptravelengine_get_template( 'template-checkout/content-billing-details.php', $args );
	}

	/**
	 * Print the Checkout Note.
	 *
	 * @return void
	 */
	public function print_checkout_note( $args ) {
		$args = array_merge(
			array(
				'note_form_fields' => wptravelengine_form_field( false )->init( Checkout::instance()->get_note_form_fields() ),
			),
			$args
		);
		wptravelengine_get_template( 'template-checkout/content-checkout-note.php', $args );
	}

	/**
	 * Print the Emergency Details.
	 *
	 * @return void
	 */
	public function print_emergency_details() {
		if ( ! isset( $emergency_contact_fields ) ) {
			$emergency_contact_fields = new EmergencyFormFields();
		}
		$args = compact( 'emergency_contact_fields' );

		wptravelengine_get_template( 'template-checkout/content-emergency-details.php', $args );
	}

	/**
	 * Print the Payment Methods.
	 *
	 * @return void
	 */
	public function print_payments_methods() {
		global $wte_cart;

		$privacy_policy_fields = new PrivacyPolicyFields();
		$payment_methods       = Checkout::instance( $wte_cart )->get_active_payment_methods();

		$payment_methods = $wte_cart->get_totals()['total'] <= 0 ? array() : $payment_methods;

		wptravelengine_get_template(
			'template-checkout/content-payments.php',
			compact( 'privacy_policy_fields', 'payment_methods' )
		);
	}

	/**
	 * Print the Cart Summary.
	 *
	 * @param array $args Arguments to be passed in cart summary template.
	 *
	 * @return void
	 * @since 6.9.0 Shows the coupon form only when a coupon applies to the cart trips.
	 */
	public function print_cart_summary( $args ) {
		global $wte_cart;
		/**  @var \WPTravelEngine\Pages\Checkout $template_instance */
		$template_instance  = Checkout::instance( $wte_cart );
		$cart_line_items    = $template_instance->get_cart_line_items();
		$deposit_amount     = $template_instance->cart->get_total_partial();
		$due_amount         = $template_instance->cart->get_due_total();
		$is_partial_payment = in_array(
			$template_instance->cart->get_payment_type(),
			array(
				'partial',
				'due',
				'remaining_payment',
			),
			true
		);
		$show_coupon_form   = wptravelengine_settings()->get( 'show_discount' ) === 'yes' && Coupons::is_coupon_available( $wte_cart->get_cart_trip_ids() ) && 'due' !== $wte_cart->get_payment_type() ? 'show' : 'hide';

		$coupons = array();

		foreach ( $wte_cart->get_deductible_items() as $coupon_item ) {
			if ( 'coupon' !== $coupon_item->name ) {
				continue;
			}
			$coupons[] = array(
				'label'  => $coupon_item->label,
				'amount' => $wte_cart->get_totals()['total_coupon'] ?? 0,
			);
		}

		$args = array_merge(
			compact( 'cart_line_items', 'deposit_amount', 'due_amount', 'is_partial_payment', 'coupons' ),
			array( 'show_coupon_form' => $show_coupon_form === 'show' ),
			$args
		);
		wptravelengine_get_template(
			'template-checkout/content-cart-summary.php',
			$args
		);
	}

	/**
	 * Print the Tour Details.
	 *
	 * @return void
	 */
	public function print_tour_details( array $args ) {
		global $wte_cart;
		$tour_details = Checkout::instance( $wte_cart )->get_tour_details();
		wptravelengine_get_template(
			'template-checkout/content-tour-details.php',
			array_merge( compact( 'tour_details' ), $args )
		);
	}

	/**
	 * Print the Payment Methods.
	 *
	 * @return void
	 */
	public function print_payment_methods( array $args ) {
		global $wptravelengine_template_args, $wte_cart;
		if ( ! isset( $wptravelengine_template_args['payment_methods'] ) ) {
			$payment_methods = Checkout::instance( $wte_cart )->get_active_payment_methods();
			$payment_methods = $wte_cart->get_totals()['total'] <= 0 ? array() : $payment_methods;
		} else {
			$payment_methods = $wptravelengine_template_args['payment_methods'];
		}
		if ( count( $payment_methods ) < 1 ) {
			return;
		}
		if ( 'due' === $wte_cart->get_payment_type() ) {
			unset( $payment_methods['booking_only'] );
		}

		/**
		 * @since 6.7.1
		 * @description Filter for the payment methods.
		 * @param array $payment_methods The payment methods.
		 * @param array $args The arguments for the filter.
		 * @return array
		 */
		$payment_methods = apply_filters( 'wptravelengine_filter_checkout_payment_methods', $payment_methods, $args );

		wptravelengine_get_template(
			'template-checkout/content-payment-methods.php',
			array_merge( compact( 'payment_methods', 'wte_cart' ), $args )
		);
	}

	/**
	 * Print the Checkout Form Button.
	 *
	 * @return void
	 * @since 6.9.0 Adds a lock icon and lets button and SVG markup through wp_kses().
	 */
	public function print_checkout_form_button() {
		global $wte_cart;

		$payment_gateway = $wte_cart->payment_gateway ?? 'default';
		ob_start();
		?>
		<button type="submit" class="wpte-checkout__form-submit-button">
			<svg width="16" height="16" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><path d="M17 11V8C17 5.23858 14.7614 3 12 3C9.23858 3 7 5.23858 7 8V11M8.8 21H15.2C16.8802 21 17.7202 21 18.362 20.673C18.9265 20.3854 19.3854 19.9265 19.673 19.362C20 18.7202 20 17.8802 20 16.2V15.8C20 14.1198 20 13.2798 19.673 12.638C19.3854 12.0735 18.9265 11.6146 18.362 11.327C17.7202 11 16.8802 11 15.2 11H8.8C7.11984 11 6.27976 11 5.63803 11.327C5.07354 11.6146 4.6146 12.0735 4.32698 12.638C4 13.2798 4 14.1198 4 15.8V16.2C4 17.8802 4 18.7202 4.32698 19.362C4.6146 19.9265 5.07354 20.3854 5.63803 20.673C6.27976 21 7.11984 21 8.8 21Z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
			<?php echo __( 'Confirm Booking', 'wp-travel-engine' ); ?>
		</button>
		<?php
		$button = apply_filters( "wptravelengine_checkout_{$payment_gateway}_button", ob_get_clean(), $wte_cart );

		$allowed_button_html = array_merge(
			wp_kses_allowed_html( 'post' ),
			array(
				'button' => array(
					'type'              => true,
					'class'             => true,
					'id'                => true,
					'name'              => true,
					'value'             => true,
					'disabled'          => true,
					'aria-label'        => true,
					'data-payment'      => true,
					'data-acceptui-url' => true,
				),
				'svg'    => array(
					'width'        => true,
					'height'       => true,
					'viewbox'      => true,
					'fill'         => true,
					'xmlns'        => true,
					'stroke'       => true,
					'stroke-width' => true,
					'class'        => true,
					'aria-hidden'  => true,
				),
				'path'   => array(
					'd'               => true,
					'stroke'          => true,
					'fill'            => true,
					'stroke-width'    => true,
					'stroke-linecap'  => true,
					'stroke-linejoin' => true,
				),
				'rect'   => array(
					'x'      => true,
					'y'      => true,
					'width'  => true,
					'height' => true,
					'rx'     => true,
				),
				'use'    => array( 'xlink:href' => true ),
			)
		);
		echo wp_kses( $button, $allowed_button_html );
	}

	/**
	 * Print the Payment Options.
	 *
	 * @return void
	 * @since 6.9.0 Shows the due-payment option for an existing booking even when partial payment is off.
	 */
	public function print_payment_modes() {
		global $wte_cart;

		$cart_totals = $wte_cart->get_totals();

		if ( ( ! wp_travel_engine_is_cart_partially_payable() && ! $wte_cart->get_booking_ref() ) || ( $cart_totals['payable_now'] <= 0 ) ) {
			return;
		}

		if ( 'booking_only' === $wte_cart->payment_gateway ) {
			return;
		}

		$instance             = Checkout::instance( $wte_cart );
		$full_payment_enabled = $instance->is_full_payment_enabled();
		$payment_mode         = $instance->get_payment_type() ?? 'partial';
		// Get Booking Ref.
		$booking_ref = $instance->cart->get_booking_ref();
		if ( $booking_ref ) {
			$booking = wptravelengine_get_booking( $booking_ref );
			if ( $booking && $wte_cart->is_curr_cart() && ! $booking->get_last_payment() ) {
				return;
			}
			$payment_mode = 'due';
		}

		switch ( $payment_mode ) {
			case 'due':
			case 'remaining_payment':
				$due_payment_amount = $cart_totals['due_total'];

				wptravelengine_get_template(
					'template-checkout/content-payment-mode-due.php',
					compact( 'payment_mode', 'due_payment_amount' )
				);

				break;
			case 'partial':
			case 'full':
			case 'full_payment':
			default:
				$down_payment_amount = $cart_totals['partial_total'];
				$full_payment_amount = $cart_totals['total'];

				$due_payment_amount = $full_payment_amount - $down_payment_amount;

				wptravelengine_get_template(
					'template-checkout/content-payment-modes.php',
					compact( 'payment_mode', 'full_payment_enabled', 'down_payment_amount', 'full_payment_amount', 'due_payment_amount' )
				);
				break;
		}
	}

	/**
	 * Print the Stripe Payment CC.
	 *
	 * @return void
	 */
	public function print_stripe_payment_cc() {
		?>
		<div class="wpte-checkout__payment-cc-placeholder">
			<div id="stripe-payment-element"></div>
			<div id="stripe-card-errors" role="alert"></div>
		</div>
		<?php
	}

	/**
	 * Print the PayPal Checkout Button.
	 *
	 * @return string
	 * @since 6.9.0 Adds a lock icon to the button.
	 */
	public function print_paypal_checkout_button(): string {
		ob_start();
		?>
		<button type="submit" class="wpte-checkout__form-submit-button">
			<svg width="16" height="16" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><path d="M17 11V8C17 5.23858 14.7614 3 12 3C9.23858 3 7 5.23858 7 8V11M8.8 21H15.2C16.8802 21 17.7202 21 18.362 20.673C18.9265 20.3854 19.3854 19.9265 19.673 19.362C20 18.7202 20 17.8802 20 16.2V15.8C20 14.1198 20 13.2798 19.673 12.638C19.3854 12.0735 18.9265 11.6146 18.362 11.327C17.7202 11 16.8802 11 15.2 11H8.8C7.11984 11 6.27976 11 5.63803 11.327C5.07354 11.6146 4.6146 12.0735 4.32698 12.638C4 13.2798 4 14.1198 4 15.8V16.2C4 17.8802 4 18.7202 4.32698 19.362C4.6146 19.9265 5.07354 20.3854 5.63803 20.673C6.27976 21 7.11984 21 8.8 21Z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
			<?php echo __( 'Pay &amp; Confirm Booking', 'wp-travel-engine' ); ?>
		</button>
		<?php
		return ob_get_clean();
	}

	/**
	 * Print the Stripe Checkout Button.
	 *
	 * @return string
	 * @since 6.7.1 Added support for stripe button label filter.
	 */
	public function print_stripe_payment_button(): string {
		$btn_label = wptravelengine_settings()->get(
			'stripe_btn_label'
		);
		return sprintf(
			'<button id="wte-stripe-payment-button" class="wpte-checkout__form-submit-button">%s</button>',
			empty( $btn_label ) ? __( 'Pay & Confirm Booking', 'wp-travel-engine' ) : $btn_label
		);
	}

	/**
	 * Print the Authorize Net Checkout Button.
	 *
	 * @return string
	 */
	public function print_authorize_net_payment_button(): string {

		ob_start();
		wp_enqueue_script( 'wte-authorizenet-checkout' );

		do_action( 'wte_booking_after_submit_button' );
		$button                  = ob_get_clean();
		$wptravelengine_settings = get_option( 'wp_travel_engine_settings' );
		$is_debug                = isset( $wptravelengine_settings['payment_debug'] ) && 'yes' === $wptravelengine_settings['payment_debug'];
		$acceptui_url            = $is_debug ? 'https://jstest.authorize.net/v3/AcceptUI.js' : 'https://js.authorize.net/v3/AcceptUI.js';
		?>
		<input type="hidden" name="dataValue" id="dataValue" />
		<input type="hidden" name="dataDescriptor" id="dataDescriptor" />
		<?php
		$button = str_replace( 'class="AcceptUI"', 'class="wpte-checkout__form-submit-button AcceptUI wte-authorize-net-payment-button" data-acceptui-url="' . esc_url( $acceptui_url ) . '"', $button );

		return $button;
	}

	/**
	 * Print the PayPal Express Checkout Button.
	 *
	 * @return string
	 */
	public function print_paypalexpress_checkout_button(): string {
		global $wte_cart;

		$amount = $wte_cart->get_total_payable_amount();

		$data = array(
			'currency_code' => PluginSettings::make()->get( 'currency_code' ),
			'amount'        => $amount,
		);

		return sprintf(
			'<div id="wte-paypal-express-payment-button" class="wte-paypal-express-payment-button" data-payment="%s"></div>',
			esc_attr( wp_json_encode( $data ) )
		);
	}

	/**
	 * Print the PayU Money Enable Button.
	 *
	 * @return string
	 */
	public function print_payu_money_enable_button(): string {
		global $wte_cart;

		$amount = $wte_cart->get_total_payable_amount();

		// Prepare data array.
		$data = array(
			'amount' => round( $amount, 2 ),
		);

		// Return the button HTML directly.
		return sprintf(
			'<button type="submit" class="wpte-checkout__form-submit-button" id="wte-payu-money-enable-button" data-payment="%s">%s</button>',
			esc_attr( wp_json_encode( $data ) ),
			esc_html__( 'Pay & Confirm Booking', 'wp-travel-engine' )
		);
	}
}
