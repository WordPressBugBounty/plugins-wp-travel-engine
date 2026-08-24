<?php

use WPTravelEngine\Booking\Email\Template_Tags;
use WPTravelEngine\Core\Models\Post\Booking;
use WPTravelEngine\Core\Models\Post\Payment;
/**
 * New-cart (v4) payment details block, part of the {trip_payment_details} email tag.
 *
 * Fully argument-driven: no Booking/Payment model calls happen in this file, so it
 * can be rendered from either Template_Tags (real booking) or DummyTags (fake preview
 * data) — the caller precomputes every value below.
 *
 * @var Template_Tags|\WPTravelEngine\Booking\Email\Dummy\DummyTags $this
 * @var string $payment_type                'full'|'partial'|'due'.
 * @var string $payment_gateway
 * @var array  $amounts                     subtotal, deposit, due, tax, total, initial_deposit, remaining_total, gateway_fee.
 * @var array  $_totals                     Raw payments-data totals, used only when $this->is_manual_trigger.
 * @var array  $deductible_items            List of ['label' => string, 'value' => float] — already resolved, no cart_info calls needed.
 * @var array  $cart_info_array             Raw cart info array, forwarded to addon hooks/filters.
 * @var float  $payable_amount              Precomputed payable amount for the booking_only/check_payments/direct_bank_transfer branch.
 * @var bool   $tax_enable
 * @var bool   $is_inclusive_tax
 * @var string $excl_label
 * @var bool   $called_from_booking_details
 */

$success_color   = $this->color( 'success' );
$warning_color   = $this->color( 'warning' );
$highlight_bg    = $this->color( 'highlight' );
$highlight_color = $this->color( 'highlight', '#0F1D23', 'color' );
$primary_bg      = $this->color( 'secondary_highlight' );
$primary_color   = $this->color( 'secondary_highlight', '#0F1D23', 'color' );
?>
<?php if ( ! $called_from_booking_details ) : ?>
	<tr>
		<td colspan="2" style="font-size: 16px;line-height: 1.75;font-weight: bold;padding: 8px 0 4px;"><?php esc_html_e( 'Payment Details:', 'wp-travel-engine' ); ?></td>
	</tr>
	<?php
endif;
if ( $deductible_items ) {
	foreach ( $deductible_items as $line_item ) {
		printf( '<tr style="color: %1$s;"><td>%2$s</td><td style="text-align: right;"><strong>%3$s</strong></td></tr>', $success_color, esc_html( $line_item['label'] ?? '' ), wptravelengine_the_price_with_decimal( $line_item['value'] ?? 0, false ) );
	}
}

// Add new row before total amount calculation on email template.
do_action( 'wptravelengine_email_template_trip_cost_rows', $this->cart_info );
?>
<tr>
	<td colspan="2">
		<span style="display: flex;padding: 8px 16px;background-color: <?php echo $highlight_bg; ?>;color: <?php echo $highlight_color; ?>;border-radius: 4px;margin: 0 -16px; font-size: 0px;">
			<strong style="width: 50%;display: inline-block; font-size: 16px;"><?php esc_html_e( 'Total', 'wp-travel-engine' ); ?></strong>
			<strong style="width: 50%;text-align: right;display: inline-block; font-size: 16px;">
			<?php
			echo wptravelengine_the_price_with_decimal( $amounts['total'], false );
			if ( $tax_enable && $is_inclusive_tax ) {
				$tax_percentage = $this->global_settings->get( 'tax_percentage' );
				printf( '<span class="wpte-inclusive-tax-label">%s</span>', sprintf( __( '(%s%% Incl. tax)', 'wp-travel-engine' ), esc_html( $tax_percentage ) ) );
			}
			?>
			</strong>
		</span>
	</td>
</tr>
<tr>
	<td colspan="2" style="padding: 4px 0;"></td>
</tr>

<?php
	$show_initial_deposit = $payment_type !== 'full' && $amounts['initial_deposit'] > 0 && apply_filters( 'wptravelengine_email_template_initial_deposit_row', true, $this->cart_info );
if ( $show_initial_deposit ) :
	?>
	<tr>
		<td><?php esc_html_e( 'Initial Deposit', 'wp-travel-engine' ); ?></td>
		<td style="text-align: right;"><strong><?php echo wptravelengine_the_price_with_decimal( $amounts['initial_deposit'], false ); ?></strong></td>
	</tr>
	<?php
endif;

if ( $payment_type === 'due' && $amounts['remaining_total'] > 0 ) :
	?>
	<tr>
		<td><?php esc_html_e( 'Remaining Amount', 'wp-travel-engine' ); ?></td>
		<td style="text-align: right;"><strong><?php echo wptravelengine_the_price_with_decimal( $amounts['remaining_total'], false ); ?></strong></td>
	</tr>
	<?php
endif;

if ( $this->is_manual_trigger ) :

	foreach ( $_totals['tax_inclusive'] ?? array() as $tax_inclusive ) :
		?>
		<tr>
			<td><?php echo esc_html( $tax_inclusive['label'] ); ?></td>
			<td style="text-align: right;"><strong><?php echo wptravelengine_the_price_with_decimal( $tax_inclusive['value'], false ); ?></strong></td>
		</tr>
		<?php
	endforeach;

	if ( isset( $_totals['tax'] ) ) :
		?>
		<tr style="color: <?php echo $warning_color; ?>;">
			<td><?php echo esc_html( wptravelengine_get_tax_label() ); ?></td>
			<td style="text-align: right;"><strong><?php echo wptravelengine_the_price_with_decimal( $_totals['tax']['value'], false ); ?></strong></td>
		</tr>
		<?php
	endif;

	foreach ( $_totals['tax_exclusive'] ?? array() as $tax_exclusive ) :
		?>
		<tr>
			<td><?php echo esc_html( $tax_exclusive['label'] ); ?></td>
			<td style="text-align: right;"><strong><?php echo wptravelengine_the_price_with_decimal( $tax_exclusive['value'], false ); ?></strong></td>
		</tr>
		<?php
	endforeach;

else :
	// Hooks for addon.
	do_action( 'wptravelengine_email_template_before_tax_amount', $cart_info_array );

	if ( $amounts['tax'] > 0 && ! $is_inclusive_tax ) :
		?>
			<tr style="color: <?php echo $warning_color; ?>;">
				<td><?php echo esc_html( wptravelengine_get_tax_label() ); ?></td>
				<td style="text-align: right;"><strong><?php echo wptravelengine_the_price_with_decimal( $amounts['tax'], false ); ?></strong></td>
			</tr>
			<?php
	endif;

	do_action( 'wptravelengine_email_template_after_tax_amount', $cart_info_array );
endif;
ob_start();
?>
<?php if ( 'booking_only' === $payment_gateway || 'check_payments' === $payment_gateway || 'direct_bank_transfer' === $payment_gateway ) : ?>
<tr>
	<td colspan="2">
		<span style="display: flex;padding: 8px 16px;background-color: <?php echo $primary_bg; ?>;color: <?php echo $primary_color; ?>;border-radius: 4px;margin: 0 -16px;">
			<strong style="width: 50%;display: inline-block;"><?php esc_html_e( 'Payable Amount', 'wp-travel-engine' ); ?></strong>
			<strong style="width: 50%;text-align: right;display: inline-block;">
			<?php echo wptravelengine_the_price_with_decimal( $payable_amount, false ); ?>
			</strong>
		</span>
	</td>
</tr>
	<?php
else :
	if ( $amounts['gateway_fee'] > 0 ) :
		?>
		<tr>
			<td><?php esc_html_e( 'Gateway Fee', 'wp-travel-engine' ); ?></td>
			<td style="text-align: right;"><strong><?php echo wptravelengine_the_price_with_decimal( $amounts['gateway_fee'], false ); ?></strong></td>
		</tr>
		<?php
	endif;
	?>
	<tr>
	<td colspan="2">
		<span style="display: flex;padding: 8px 16px;background-color: <?php echo $primary_bg; ?>;color: <?php echo $primary_color; ?>;border-radius: 4px;margin: 0 -16px;">
			<strong style="width: 50%;display: inline-block;"><?php esc_html_e( 'Amount Paid', 'wp-travel-engine' ); ?></strong>
			<strong style="width: 50%;text-align: right;display: inline-block;">
			<?php echo wptravelengine_the_price_with_decimal( $amounts['deposit'], false ); ?>
			</strong>
		</span>
	</td>
</tr>
	<?php if ( $amounts['due'] > 0 ) : ?>
	<tr>
		<td><strong><?php esc_html_e( 'Amount Due', 'wp-travel-engine' ); ?></strong> <?php echo ( ! empty( $excl_label ) ? '(excl. ' . $excl_label . ')' : '' ); ?></td>
		<td style="text-align: right;font-size: 16px;"><strong><?php echo wptravelengine_the_price_with_decimal( $amounts['due'], false ); ?></strong></td>
	</tr>
		<?php
	endif;
	if ( $show_initial_deposit && $this->booking->get_refunded_amount() > 0 ) :
		?>
		<tr>
			<td colspan="2" style="font-size: 12px;color: #666;"><?php echo esc_html( Booking::get_refund_fallback_msg() ); ?></td>
		</tr>
		<?php
	endif;
endif;
/**
 * @since 6.7.1
 * @description This is the html for the payable amount on email template that can be overwritten by the filters.
 * @param string $totals_html The html for the payable amount.
 * @param Template_Tags $this The object of the class.
 * @param Booking $this->booking The object of the booking.
 * @param Payment $this->payment The object of the payment model.
 * @return string
 */
$totals_html = ob_get_clean();
$htmls       = apply_filters( 'wptravelengine_email_template_payable_amount_html', $totals_html, $this, $this->booking, $this->payment );
echo $htmls;
?>
<tr>
	<td colspan="2">
		<?php echo $this->get_billing_details(); ?>
	</td>
</tr>
