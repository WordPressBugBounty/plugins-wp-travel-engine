<?php
/**
 * Email Tags.
 *
 * @since 5.5.3
 */

namespace WPTravelEngine\Booking\Email;
use WPTravelEngine\Builders\FormFields\DefaultFormFields;
use WPTravelEngine\Builders\FormFields\FormField;
use WPTravelEngine\Builders\FormFields\TravellerEditFormFields;

use DateTimeZone;

use WPTravelEngine\Helpers\Countries;
use WPTravelEngine\Helpers\CartInfoParser;

class Template_Tags {

	public function __construct( $booking_id, $payment_id ) {
		$this->booking = get_post( $booking_id );
		$this->payment = get_post( $payment_id );

		$this->order_trips = (array) ( $this->booking->order_trips ?? [] );

		$this->billing_info = (array) ( $this->booking->billing_info ?? [] );

		$this->cart_info = (array) ( $this->booking->cart_info ?? [] );

		$this->trip = ! empty( $this->order_trips ) ? (object) ( array_values( $this->order_trips )[ 0 ] ?? [] ) : (object) [];

		$this->billing_details = (array) ( $this->booking->wptravelengine_billing_details ?? [] );

		$this->traveller_details = (array) ( $this->booking->wptravelengine_travelers_details ?? [] );

		$this->emergency_details = (array) ( $this->booking->wptravelengine_emergency_details ?? [] );

		$this->additional_notes = $this->booking->wptravelengine_additional_note ?? '';

		$this->cart_info_parser = new CartInfoParser( (array) $this->cart_info );

	}

	public function get_trip_url() {
		$order_trip = $this->cart_info_parser->get_item();

		if ( empty( ! $order_trip ) ) {
			return '<a href=' . esc_url( get_permalink( $order_trip->get_trip_id() ) ) . '>' . get_the_title( $order_trip->get_trip_title() ) . '</a>';
		}

		return '<a href=' . esc_url( get_permalink( $this->trip->ID ) ) . '>' . esc_html( $this->trip->title ) . '</a>';
	}

	public function get_billing_first_name() {
		if ( isset( $this->billing_info[ 'fname' ] ) ) {
			return $this->billing_info[ 'fname' ];
		}

		return wte_array_get( $this->billing_info, 'booking_first_name', '' );
	}

	public function get_billing_last_name() {
		if ( isset( $this->billing_info[ 'lname' ] ) ) {
			return $this->billing_info[ 'lname' ];
		}

		return wte_array_get( $this->billing_info, 'booking_last_name', '' );
	}

	public function get_billing_fullname() {
		return implode( ' ', array( $this->get_billing_first_name(), $this->get_billing_last_name() ) );
	}

	public function get_billing_email() {
		if ( isset( $this->billing_info[ 'email' ] ) ) {
			return $this->billing_info[ 'email' ];
		}

		return wte_array_get( $this->billing_info, 'booking_email', '' );
	}

	public function get_billing_address() {
		if ( isset( $this->billing_info[ 'address' ] ) ) {
			return $this->billing_info[ 'address' ];
		}

		return wte_array_get( $this->billing_info, 'booking_address', '' );
	}

	public function get_billing_city() {
		if ( isset( $this->billing_info[ 'city' ] ) ) {
			return $this->billing_info[ 'city' ];
		}

		return wte_array_get( $this->billing_info, 'booking_city', '' );
	}

	public function get_billing_country() {

		$countries_list = Countries::list();
		if ( isset( $countries_list[ $this->billing_info[ 'country' ] ] ) ) {
			return $countries_list[ $this->billing_info[ 'country' ] ];
		}
		if ( isset( $this->billing_info[ 'country' ] ) ) {
			return $this->billing_info[ 'country' ];
		}

		return wte_array_get( $this->billing_info, 'booking_country', '' );
	}

	public function get_due_amount() {
		$currency = $this->cart_info->currency ?? $this->cart_info['currency'] ?? 'USD';

		return wte_get_formated_price( $this->booking->due_amount, $currency, '', true );
	}

	public function get_bank_details() {
		if ( $this->payment && 'direct_bank_transfer' === $this->payment->payment_gateway ) {
			$bank_details_labels = array(
				// 'account_name',
				'account_number' => __( 'Account Number', 'wp-travel-engine' ),
				'bank_name'      => __( 'Bank Name', 'wp-travel-engine' ),
				'sort_code'      => __( 'Sort Code', 'wp-travel-engine' ),
				'iban'           => __( 'IBAN', 'wp-travel-engine' ),
				'swift'          => __( 'BIC/Swift', 'wp-travel-engine' ),
			);

			$settings = get_option( 'wp_travel_engine_settings', array() );

			$bank_accounts = wte_array_get( $settings, 'bank_transfer.accounts', array() );
			ob_start();
			echo '<table class="invoice-items">';
			echo '<tr>';
			echo '<td colspan="2">';
			echo '<h3>' . esc_html__( 'Bank Details:', 'wp-travel-engine' ) . '</h3>';
			echo '</td>';
			echo '</tr>';
			echo '<tr>';
			echo '<td colspan="2">';
			echo wp_kses_post( wte_array_get( $settings, 'bank_transfer.instruction', '' ) );
			echo '</td>';
			echo '</tr>';
			foreach ( $bank_accounts as $account ) {
				echo '<tr>';
				echo '<td colspan="2">';
				echo '<h5>' . esc_html( $account[ 'account_name' ] ) . '</h5>';
				echo '</td>';
				echo '</tr>';
				foreach ( $bank_details_labels as $key => $label ) {
					?>
					<tr>
						<td><?php echo esc_html( $label ); ?></td>
						<td class="alignright"><?php echo isset( $account[ $key ] ) ? esc_html( $account[ $key ] ) : ''; ?></td>
					</tr>
					<?php
				}
			}
			echo '</table>';

			return ob_get_clean();
		}

		return '';
	}

	public function get_check_payment_details() {
		if ( $this->payment && 'check_payments' === $this->payment->payment_gateway ) {
			ob_start();
			?>
			<table class="invoice-items">
				<tr>
					<td colspan="2">
						<h3><?php echo esc_html__( 'Check Payment Instructions:', 'wp-travel-engine' ); ?></h3>
					</td>
				</tr>
				<tr>
					<td colspan="2">
						<?php echo wp_kses_post( wte_array_get( get_option( 'wp_travel_engine_settings', array() ), 'check_payment.instruction', '' ) ); ?>
					</td>
				</tr>
			</table>
			<?php
			return ob_get_clean();
		}

		return '';
	}

	public function get_booking_details() {
		$order_trips = $this->order_trips;
		$cart_info   = (array) $this->cart_info;

		$currency = $cart_info[ 'currency' ] ?? 'USD';
		if ( is_array( $order_trips ) ) :
			ob_start();
			$count              = 1;
			$pricing_categories = get_terms(
				array(
					'taxonomy'   => 'trip-packages-categories',
					'hide_empty' => false,
					'orderby'    => 'term_id',
					'fields'     => 'id=>name',
				)
			);
			foreach ( $order_trips as $trip ) :
				$trip = (object) $trip;
				?>
                <table width="100%" cellpadding="0" cellspacing="0">
                    <tr>
                        <td colspan="2"><b><?php echo esc_html( $trip->title ); ?></b></td>
                    </tr>
                    <tr>
                        <td><?php esc_html_e( 'Package Name', 'wp-travel-engine' ); ?></td>
                        <td class="alignright"><?php echo esc_html( $trip->package_name ); ?></td>
                    </tr>
                    <tr>
                        <td><?php esc_html_e( 'Trip Date', 'wp-travel-engine' ); ?></td>
                        <td class="alignright"><?php echo esc_html( $trip->has_time ? wp_date( 'Y-m-d H:i', strtotime( $trip->datetime ), new \DateTimeZone('utc') ) : wp_date( get_option( 'date-format', 'Y-m-d' ), strtotime( $trip->datetime ), new \DateTimeZone('utc') ) ); ?></td>
                    </tr>
					<?php if ( isset( $trip->end_datetime ) ) : ?>
                        <tr>
                            <td><?php esc_html_e( 'Trip End Date', 'wp-travel-engine' ); ?></td>
                            <td class="alignright"><?php echo esc_html( $trip->has_time ? wp_date( 'Y-m-d H:i', strtotime( $trip->end_datetime ), new \DateTimeZone('utc') ) : wp_date( get_option( 'date-format', 'Y-m-d' ), strtotime( $trip->end_datetime ), new DateTimeZone('utc') ) ); ?></td>
                        </tr>

					<?php endif; ?>
					<tr>
						<td><?php esc_html_e( 'Travellers', 'wp-travel-engine' ); ?></td>
						<td class="alignright"><?php echo esc_html( array_sum( $trip->pax ) ); ?></td>
					</tr>
					<tr>
						<td><?php esc_html_e( 'Trip Cost', 'wp-travel-engine' ); ?></td>
					</tr>
					<tr>
						<td>&nbsp;</td>
						<td class="alignright">
							<table width="100%" cellpadding="0" cellspacing="0">
								<?php
								$sum = 0;

								foreach ( $trip->pax as $pricing_category_id => $tcount ) {
									if ( + $tcount < 1 ) {
										continue;
									}
									$pax_cost = + $trip->pax_cost[ $pricing_category_id ] / + $tcount;
									$sum      += + $trip->pax_cost[ $pricing_category_id ];

									$label = isset( $pricing_categories[ $pricing_category_id ] ) ? $pricing_categories[ $pricing_category_id ] : $pricing_category_id;
									?>
									<tr>
										<td class="alignright"><?php echo esc_html( $label ); ?></td>
										<td><?php echo (int) $tcount . ' X ' . wte_esc_price( wte_get_formated_price( $pax_cost, $currency, '', ! 0 ) ) . ' = ' . wte_esc_price( wte_get_formated_price( $trip->pax_cost[ $pricing_category_id ], $currency, '', ! 0 ) ); ?></td>
									</tr>
									<?php
								}
								?>
								<tr>
									<td width="50%"><?php esc_html_e( 'Subtotal', 'wp-travel-engine' ); ?></td>
									<td width="50%"><?php echo wte_esc_price( wte_get_formated_price( + $sum, $currency, '', ! 0 ) ); ?></td>
								</tr>
							</table>
						</td>
					</tr>
					<?php do_action( 'wptravelengine_email_template_before_extra_services', $cart_info ); ?>
					<?php if ( $trip->trip_extras && is_array( $trip->trip_extras ) ) : ?>
						<tr>
							<td colspan="2"><?php esc_html_e( 'Extra Services', 'wp-travel-engine' ); ?></td>
						</tr>
						<tr>
							<td>&nbsp;</td>
							<td class="alignright">
								<table width="100%" cellpadding="0" cellspacing="0">
									<?php
									$sum = 0;
									foreach ( $trip->trip_extras as $index => $tx ) {
										$tx_total = + $tx[ 'qty' ] * + $tx[ 'price' ];
										$sum      += $tx_total;
										?>
										<tr>
											<td><?php echo esc_html( $tx[ 'extra_service' ] ); ?></td>
											<td><?php echo (int) $tx[ 'qty' ] . ' X ' . wte_esc_price( wte_get_formated_price( + $tx[ 'price' ], $currency, '', ! 0 ) ) . ' = ' . wte_esc_price( wte_get_formated_price( + $tx_total, $currency, '', ! 0 ) ); ?></td>
										</tr>
										<?php
									}
									?>
									<tr>
										<td width="50%"><?php esc_html_e( 'Subtotal', 'wp-travel-engine' ); ?></td>
										<td widht="50%"><?php echo wte_esc_price( wte_get_formated_price( + $sum, $currency, '', ! 0 ) ); ?></td>
									</tr>
								</table>
							</td>
						</tr>
					<?php endif; ?>
				</table>
				<?php
				$count ++;
			endforeach;
			echo '<hr/>';
			?>
			<table width="100%">
				<tr>
					<td width="50%">&nbsp;</td>
					<td width="50%">
						<table width="100%">
							<tr>
								<td><?php esc_html_e( 'Subtotal', 'wp-travel-engine' ); ?></td>
								<td class="alignright"><?php echo wte_esc_price( wte_get_formated_price( + $cart_info[ 'subtotal' ], $currency, '', ! 0 ) ); ?></td>
							</tr>
							<tr>
								<td><?php esc_html_e( 'Discount', 'wp-travel-engine' ); ?></td>
								<?php
								$discount_figure = 0;
								if ( ! empty( $cart_info[ 'discounts' ] ) ) {
									$discounts       = $cart_info[ 'discounts' ];
									$discount        = array_shift( $discounts );
									$discount_figure = 'percentage' === $discount[ 'type' ] ? + $cart_info[ 'subtotal' ] * ( + $discount[ 'value' ] / 100 ) : $discount[ 'value' ];
								}
								?>
								<td class="alignright">
									<?php echo wte_esc_price( wte_get_formated_price( + $discount_figure, $currency, '', ! 0 ) ); ?>
								</td>
							</tr>
							<?php do_action( 'wptravelengine_email_template_before_tax_amount', $cart_info ); ?>
							<?php if ( ! empty( $cart_info[ 'tax_amount' ] ) ) { ?>
								<tr>
									<td><?php echo esc_html( wptravelengine_get_tax_label( $cart_info[ 'tax_amount' ] ) ); ?></td>
									<?php
									$tax_figure = 0;
									$tax_amount = wp_travel_engine_get_tax_detail( $cart_info );
									?>
									<td class="alignright">
										<?php echo wte_esc_price( wte_get_formated_price( + $tax_amount[ 'tax_actual' ], $currency, '', ! 0 ) ); ?>
									</td>
								</tr>
							<?php } ?>
							<?php
							// Add new row before total amount calculation on email template.
							do_action( 'wptravelengine_email_template_trip_cost_rows', $cart_info );
							?>
							<tr>
								<td><?php esc_html_e( 'Total', 'wp-travel-engine' ); ?></td>
								<td class="alignright">
									<?php echo wte_esc_price( wte_get_formated_price( $cart_info[ 'total' ], $currency, '', ! 0 ) ); ?>
									<?php
									$global_settings = get_option( 'wp_travel_engine_settings', array() );
									$tax_enable      = isset( $global_settings[ 'tax_enable' ] ) && 'yes' === $global_settings[ 'tax_enable' ];
									if ( $tax_enable == 'yes' && isset( $global_settings[ 'tax_type_option' ] ) && 'inclusive' === $global_settings[ 'tax_type_option' ] ) {
										$tax_percentage = $global_settings[ 'tax_percentage' ];
										printf( '<span class="wpte-inclusive-tax-label">%s</span>', sprintf( __( '(%s%% Incl. tax)', 'wp-travel-engine' ), esc_html( $tax_percentage ) ) );
									}
									?>
								</td>
							</tr>
						</table>
					</td>
				</tr>
			</table>
		<?php
		endif;

		return ob_get_clean();
	}

	public function discount_amount() {
		$cart_info = (object) $this->cart_info;

		if ( isset( $cart_info->discounts ) && is_array( $cart_info->discounts ) ) {
			$discounts = $cart_info->discounts;
			$discount  = array_shift( $discounts );
			if ( ! is_array( $discount ) ) {
				return 0;
			}

			extract( $discount );
			if ( 'percentage' === $type ) {
				return + $cart_info->subtotal * ( + $value / 100 );
			}

			return $value;
		}
	}

	/**
	 * Get traveller email template.
	 */
	public function get_traveller_template( $type, $pno, $personal_options ) {

		ob_start();
		$args = array(
			'data'    => $personal_options,
			'numbers' => $pno,
		);

		// Email Content.
		wte_get_template( "emails/{$type}.php", $args );

		$template = ob_get_clean();

		return $template;
	}

	/**
	 * Gets Booking Payment method by Payment ID.
	 *
	 * @param int $payment_id
	 *
	 * @return string
	 */
	public function get_payment_method( $payment_id ) {
		$payment_method = get_post_meta( $payment_id, 'payment_gateway', true );

		return empty( $payment_method ) ? __( 'N/A', 'wp-travel-engine' ) : $payment_method;
	}

	public function get_email_tags() {

		$trip = $this->trip;

		$currency = $this->cart_info->currency ?? $this->cart_info['currency'] ?? 'USD';

		$traveler_data    = get_post_meta( $this->booking->ID, 'wp_travel_engine_placeorder_setting', true );
		$personal_options = isset( $traveler_data[ 'place_order' ] ) ? $traveler_data[ 'place_order' ] : array();

		$item = $this->trip;
		$pno  = ( isset( $item->pax ) ) ? array_sum( $item->pax ) : 0;
		if ( isset( $personal_options[ 'travelers' ] ) ) :
			$traveller_email_template_content = $this->get_traveller_template( 'traveller-data', $pno, $personal_options );
		else :
			$traveller_email_template_content = '';
		endif;

		$booking_id        = $this->booking->ID;
		$admin_url         = admin_url();
		$booking_url       = $admin_url . 'post.php?post=' . $booking_id . '&action=edit';
		$edit_booking_link = $booking_url;

		return apply_filters(
			'wte_booking_mail_tags',
			array(
				'{trip_url}'                  => $this->get_trip_url(),
				'{name}'                      => $this->get_billing_first_name(),
				'{fullname}'                  => $this->get_billing_fullname(),
				'{user_email}'                => $this->get_billing_email(),
				'{billing_address}'           => $this->get_billing_address(),
				'{city}'                      => $this->get_billing_city(),
				'{country}'                   => $this->get_billing_country(),
				'{tdate}'                     => ( isset( $trip->has_time ) && $trip->has_time && isset( $trip->datetime ) ) ? wp_date( 'Y-m-d H:i', strtotime( $trip->datetime ) ) : wp_date( get_option( 'date-format', 'Y-m-d' ), strtotime( isset( $trip->datetime ) ? $trip->datetime : '' ) ),
				'{traveler}'                  => isset( $trip->pax ) ? array_sum( $trip->pax ) : 0,
				// '{child-traveler}'            => $trip->pax['child'],
				'{tprice}'                    => isset( $trip->cost ) ? wte_get_formated_price( $trip->cost, $currency, '', ! 0 ) : 0,
				'{price}'                     => wte_get_formated_price( $this->payment->payable[ 'amount' ] ?? 0, $this->payment->payable[ 'currency' ] ?? $currency, '', ! 0 ),
				'{total_cost}'                => wte_get_formated_price( $this->cart_info->total ?? $this->cart_info['total'] ?? 0, $currency, '', ! 0 ),
				'{due}'                       => $this->get_due_amount(),
				'{sitename}'                  => get_bloginfo( 'name' ),
				'{booking_url}'               => $edit_booking_link,
				'{ip_address}'                => '',
				'{date}'                      => date( 'Y-m-d H:i:s' ),
				'{booking_id}'                => sprintf( __( 'Booking #%1$s', 'wp-travel-engine' ), $this->booking->ID ),
				'{bank_details}'              => $this->get_bank_details(),
				'{check_payment_instruction}' => $this->get_check_payment_details(),
				'{booking_details}'           => $this->get_booking_details(),
				'{booking_trips_count}'       => isset( $this->booking->order_trips ) ? count( $this->booking->order_trips ) : 1,
				'{payment_id}'                => $this->payment->ID,
				'{subtotal}'                  => wte_get_formated_price( $this->booking->cart_info[ 'subtotal' ], $currency, '', ! 0 ),
				'{total}'                     => wte_get_formated_price( $this->booking->cart_info[ 'total' ], $currency, '', ! 0 ),
				'{paid_amount}'               => wte_get_formated_price( $this->payment->payable[ 'amount' ] ?? 0, $this->payment->payable[ 'currency' ] ?? $currency, '', ! 0 ),
				'{discount_amount}'           => wte_get_formated_price( $this->discount_amount(), $currency, '', ! 0 ),
				'{traveler_data}'             => $traveller_email_template_content,
				'{payment_method}'            => $this->get_payment_method( $this->payment->ID ),
				'{billing_details}'           => $this->get_billing_details(),
				'{additional_note}'           => $this->get_additional_note(),
				'{traveller_details}'         => $this->get_traveller_details(),
				'{emergency_details}'         => $this->get_emergency_details(),
			),
			$this->payment->ID,
			$this->booking->ID
		);
	}

	/**
	 * Get additional note.
	 *
	 * @return string
	 */
	public function get_additional_note() {
		if ( empty( $this->additional_notes ) ) {
			return '';
		}
		ob_start();
		?>
		<table width="100%">
			<tr>
				<td class="title-holder" style="margin: 0;" valign="top">
					<h3 class="alignleft"><?php echo esc_html__( 'Additional Note', 'wp-travel-engine' ); ?></h3>
				</td>
			</tr>
			<tr>
				<td><?php echo esc_html( $this->additional_notes ); ?></td>
			</tr>
		</table>
		<?php
		return ob_get_clean();
	}

	public function get_billing_details() {
		if ( empty( $this->billing_details ) ) {
			return '';
		}
		ob_start();
		?>
		<table width="100%">
			<tr>
				<td class="title-holder" style="margin: 0;" valign="top">
					<h3 class="alignleft"><?php echo esc_html__( 'Billing Details', 'wp-travel-engine' ); ?></h3>
				</td>
			</tr>
			<?php
			foreach ( $this->billing_details as $key => $value ) {
				// Map keys to more readable formats
				$key_map = [
					'fname'   => 'First Name',
					'lname'   => 'Last Name',
					'email'   => 'Email',
					'phone'   => 'Phone',
					'address' => 'Address',
					'city'    => 'City',
					'country' => 'Country',
				];

				if ( array_key_exists( $key, $key_map ) ) {
					$key = $key_map[ $key ];
				}
				if ( is_array( $value ) ) {
					$value = implode( ', ', $value );
				}
				$countries_list = Countries::list();
				if ( isset( $countries_list[ $value ] ) ) {
					$value = $countries_list[ $value ];
				}
				?>
				<tr>
					<td><?php echo esc_html( ucfirst( $key ) ); ?></td>
					<td>
						<?php
						if ( filter_var( $value, FILTER_VALIDATE_URL ) ) : ?>
							<a href="<?php echo esc_url( $value ); ?>"
							   target="_blank"><?php echo esc_html( basename( $value ) ); ?></a>
						<?php else: ?>
							<?php echo esc_html( $value ); ?>
						<?php endif; ?>
					</td>
				</tr>
				<?php
			}
			?>
		</table>
		<?php
		return ob_get_clean();
	}

	public function get_emergency_details() {
		if ( empty( $this->emergency_details ) ) {
			return '';
		}
		ob_start();
		?>
		<table width="100%">
			<tr>
				<td class="title-holder" style="margin: 0;" valign="top">
					<h3 class="alignleft"><?php echo esc_html__( 'Emergency Details', 'wp-travel-engine' ); ?></h3>
				</td>
			</tr>
			<?php
			foreach ( $this->emergency_details as $key => $value ) {
				// Map keys to more readable formats
				$key_map = [
					'title'    => 'Title',
					'fname'    => 'First Name',
					'lname'    => 'Last Name',
					'email'    => 'Email',
					'phone'    => 'Phone',
					'address'  => 'Address',
					'city'     => 'City',
					'country'  => 'Country',
					'relation' => 'Relation',
				];

				if ( array_key_exists( $key, $key_map ) ) {
					$key = $key_map[ $key ];
				}
				if ( isset( $value ) && is_array( $value ) ) {
					$flat_value = array_map( function ( $item ) {
						return is_array( $item ) ? implode( ', ', $item ) : strval( $item ?? '' );
					}, $value );
					$value      = implode( ', ', $flat_value );
				}
				$countries_list = Countries::list();
				if ( isset( $countries_list[ $value ] ) ) {
					$value = $countries_list[ $value ];
				}
				?>
				<tr>
					<td><?php echo esc_html( ucfirst( $key ) ); ?></td>
					<td>
						<?php
						if ( filter_var( $value, FILTER_VALIDATE_URL ) ) : ?>
							<a href="<?php echo esc_url( $value ); ?>"
							   target="_blank"><?php echo esc_html( basename( $value ) ); ?></a>
						<?php else: ?>
							<?php echo esc_html( $value ); ?>
						<?php endif; ?>
					</td>
				</tr>
				<?php
			}
			?>
		</table>
		<?php
		return ob_get_clean();
	}

	public function get_traveller_details() {
		if ( empty( $this->traveller_details ) ) {
			return '';
		}
		ob_start();
		?>
		<table width="100%">
			<tr>
				<td class="title-holder" style="margin: 0;" valign="top">
					<h3 class="alignleft"><?php echo esc_html__( 'Traveller Details', 'wp-travel-engine' ); ?></h3>
				</td>
			</tr>
			<?php
			$traveller_form_fields = DefaultFormFields::traveller();
			foreach ( $this->traveller_details as $traveler => $details ) {
				?>
				<tr>
					<td class="title-holder" style="margin: 0;" valign="top">
						<h3 class="alignleft"><?php echo esc_html( sprintf( 'Traveller %s', $traveler + 1 ) ); ?></h3>
					</td>
				</tr>
				<?php
				foreach ( $traveller_form_fields as $field_id => $field_args ) {
					$field_name = $field_args[ 'name' ];
					if ( preg_match( "#\[([^\[]+)]$#", $field_args[ 'name' ], $matches ) ) {
						$field_name = $matches[ 1 ];
					}

					if ( ! isset( $details[ $field_name ] ) ) {
						continue;
					}

					$key   = $field_args[ 'field_label' ] ?? $field_name;
					$value = $details[ $field_name ];

					if ( is_array( $value ) ) {
						$value = implode( ',', $value );
					}
					$countries_list = Countries::list();
					if ( isset( $countries_list[ $value ] ) ) {
						$value = $countries_list[ $value ];
					}
					?>
					<tr>
						<td><?php echo esc_html( ucfirst( $key ) ); ?></td>
						<td>
							<?php
							if ( filter_var( $value, FILTER_VALIDATE_URL ) ) : ?>
								<a href="<?php echo esc_url( $value ); ?>"
								   target="_blank"><?php echo esc_html( basename( $value ) ); ?></a>
							<?php else: ?>
								<?php echo esc_html( $value ); ?>
							<?php endif; ?>
						</td>
					</tr>
					<?php
				}
			}
			?>
		</table>
		<?php
		return ob_get_clean();
	}
}
