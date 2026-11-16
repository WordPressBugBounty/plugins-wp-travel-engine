<?php
/**
 * Thank You — main detail column (regions D1 trip-head, D2 traveler accordion, E notes, F billing).
 *
 * @package WPTravelEngine
 *
 * @var array  $traveller_details
 * @var array  $traveler_categories
 * @var array  $booking_details
 * @var array  $emergency_details
 * @var string $trip_start_date
 * @var string $trip_end_date
 * @var string $trip_title
 * @var string $trip_permalink
 * @var int    $trip_reference
 * @var string $trip_duration_label
 * @var string $trip_start_time
 * @var string $payment_status
 * @var string $additional_note
 */

use WPTravelEngine\Helpers\Countries;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- template-scoped locals passed via compact() / loop vars.

$countries_list      = Countries::list();
$trip_title          = $trip_title ?? '';
$trip_permalink      = $trip_permalink ?? '';
$trip_reference      = $trip_reference ?? '';
$trip_code           = $trip_code ?? '';
$trip_duration_label = $trip_duration_label ?? '';
$trip_start_time     = $trip_start_time ?? '';
$package_name        = $package_name ?? '';
$payment_status      = $payment_status ?? '';
$is_completed        = $is_completed ?? false;
$traveller_count     = is_array( $traveller_details ?? null ) ? count( $traveller_details ) : 0;
$traveler_categories = is_array( $traveler_categories ?? null ) ? $traveler_categories : array();

/**
 * Resolve a single traveler's display name from its resolved fields.
 * Falls back to the "Traveler N" label when no name field is filled.
 *
 * @param array $traveller_detail Resolved field map.
 * @return string
 */
$wpte_traveller_name = static function ( $traveller_detail ) {
	$parts = array();
	foreach ( array( 'fname', 'lname' ) as $key ) {
		if ( ! empty( $traveller_detail[ $key ]['value'] ) && ! is_array( $traveller_detail[ $key ]['value'] ) ) {
			$parts[] = $traveller_detail[ $key ]['value'];
		}
	}
	return trim( implode( ' ', $parts ) );
};

/**
 * Whether a traveler has at least one filled field.
 *
 * @param array $traveller_detail Resolved field map.
 * @return bool
 */
$wpte_traveller_has_details = static function ( $traveller_detail ) {
	foreach ( $traveller_detail as $field ) {
		if ( ! empty( $field['value'] ) ) {
			return true;
		}
	}
	return false;
};
?>
<div class="wpte-thankyou__booking-details">
	<?php do_action( 'wptravelengine_thankyou_before_booking_details' ); ?>

	<?php
	// Region C: action-needed notice — count travelers with incomplete details, track first index.
	$wpte_missing_count = 0;
	$wpte_missing_index = null;
	if ( is_array( $traveller_details ?? null ) ) {
		foreach ( $traveller_details as $wpte_idx => $wpte_td ) {
			if ( ! $wpte_traveller_has_details( $wpte_td ) ) {
				++$wpte_missing_count;
				if ( null === $wpte_missing_index ) {
					$wpte_missing_index = $wpte_idx;
				}
			}
		}
	}
	// "Add details now" links to this booking's dashboard detail page — the dashboard page itself handles showing a login form when the visitor is logged out.
	$wpte_booking_detail_url = '';
	if ( $wpte_missing_count > 0 ) {
		$wpte_booking_detail_url = wptravelengine_dashboard_url(
			array(
				'action'     => 'booking-details',
				'booking_id' => $trip_reference,
			)
		);
	}
	?>
	<?php if ( $wpte_missing_count > 0 ) : ?>
		<div class="wpte-thankyou__notice wpte-thankyou__notice--warning">
			<svg class="wpte-thankyou__notice-icon" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M10 2.5L18 16H2L10 2.5z" /><path d="M10 8v3.5M10 13.8h.01" /></svg>
			<div>
				<strong>
					<?php
					/* translators: %d: number of travelers missing details. */
					echo esc_html( sprintf( _n( '%d traveler still needs details.', '%d travelers still need details.', $wpte_missing_count, 'wp-travel-engine' ), $wpte_missing_count ) );
					?>
				</strong>
				<?php if ( 1 === $wpte_missing_count && null !== $wpte_missing_index ) : ?>
					<?php
					/* translators: %d: traveler number (1-based). */
					echo esc_html( sprintf( __( "Add Traveler %d's information before departure so your operator can finalize permits.", 'wp-travel-engine' ), $wpte_missing_index + 1 ) );
					?>
				<?php else : ?>
					<?php echo esc_html__( 'Add their information before departure so your operator can finalize permits.', 'wp-travel-engine' ); ?>
				<?php endif; ?>
				<a href="<?php echo esc_url( ! empty( $wpte_booking_detail_url ) ? $wpte_booking_detail_url : '#travelers' ); ?>"><?php echo esc_html__( 'Add details now', 'wp-travel-engine' ); ?></a>
			</div>
		</div>
	<?php endif; ?>

	<!-- Region D1 + D2: trip details card -->
	<div class="wpte-card">
		<div class="wpte-trip-head">
			<div class="wpte-trip-head__counter">1</div>
			<div class="wpte-trip-head__info">
				<?php if ( ! empty( $trip_title ) ) : ?>
					<?php if ( ! empty( $trip_permalink ) ) : ?>
						<a class="wpte-trip-head__name" href="<?php echo esc_url( $trip_permalink ); ?>"><?php echo esc_html( $trip_title ); ?></a>
					<?php else : ?>
						<span class="wpte-trip-head__name"><?php echo esc_html( $trip_title ); ?></span>
					<?php endif; ?>
				<?php endif; ?>
				<?php if ( ! empty( $package_name ) || ! empty( $trip_code ) || $traveller_count > 0 ) : ?>
					<div class="wpte-trip-head__badges">
						<?php if ( ! empty( $package_name ) ) : ?>
							<span class="wpte-thankyou__trip-badge"><?php echo esc_html( $package_name ); ?></span>
						<?php endif; ?>

						<?php if ( ! empty( $trip_code ) ) : ?>
							<span class="wpte-thankyou__trip-badge wpte-thankyou__trip-badge--muted"><?php echo esc_html( $trip_code ); ?></span>
						<?php endif; ?>

						<?php if ( $traveller_count > 0 ) : ?>
							<?php if ( ! empty( $package_name ) || ! empty( $trip_code ) ) : ?>
								<span class="wpte-trip-head__meta-sep" aria-hidden="true">·</span>
							<?php endif; ?>
							<span class="wpte-trip-head__meta-item">
								<svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M17 18v-1.5a3.5 3.5 0 0 0-3.5-3.5h-7A3.5 3.5 0 0 0 3 16.5V18" /><circle cx="10" cy="6.5" r="3.5" /></svg>
								<span>
									<?php
									/* translators: %d: number of travelers. */
									echo esc_html( sprintf( _n( '%d traveler', '%d travelers', $traveller_count, 'wp-travel-engine' ), $traveller_count ) );
									?>
								</span>
							</span>
						<?php endif; ?>
					</div>
				<?php endif; ?>
			</div>
			<?php if ( ! empty( $payment_status ) ) : ?>
				<span class="wpte-thankyou__paid-badge<?php echo $is_completed ? '' : ' wpte-thankyou__paid-badge--pending'; ?>">
					<?php if ( $is_completed ) : ?>
						<svg width="12" height="12" viewBox="0 0 14 14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="2 7 5.5 10.5 12 3.5" /></svg>
					<?php endif; ?>
					<?php echo esc_html( $payment_status ); ?>
				</span>
			<?php endif; ?>
		</div>

		<?php if ( ! empty( $trip_start_date ) || ! empty( $trip_duration_label ) || ! empty( $trip_start_time ) ) : ?>
			<div class="wpte-thankyou__trip-info-boxes">
				<?php if ( ! empty( $trip_start_date ) ) : ?>
					<div class="wpte-thankyou__trip-info-box">
						<span class="wpte-thankyou__trip-info-icon" aria-hidden="true"><svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M17.5 8.33333H2.5M17.5 10.4167V7.33333C17.5 5.9332 17.5 5.23314 17.2275 4.69836C16.9878 4.22795 16.6054 3.8455 16.135 3.60582C15.6002 3.33333 14.9001 3.33333 13.5 3.33333H6.5C5.09987 3.33333 4.3998 3.33333 3.86502 3.60582C3.39462 3.8455 3.01217 4.22795 2.77248 4.69836C2.5 5.23314 2.5 5.9332 2.5 7.33333V14.3333C2.5 15.7335 2.5 16.4335 2.77248 16.9683C3.01217 17.4387 3.39462 17.8212 3.86502 18.0608C4.3998 18.3333 5.09987 18.3333 6.5 18.3333H10M13.3333 1.66667V5M6.66667 1.66667V5M12.0833 15.8333L13.75 17.5L17.5 13.75" /></svg></span>
						<div>
							<div class="wpte-thankyou__trip-info-label"><?php echo esc_html__( 'Start – End Date', 'wp-travel-engine' ); ?></div>
							<div class="wpte-thankyou__trip-info-value"><?php echo esc_html( $trip_start_date ); ?><?php echo ! empty( $trip_end_date ) ? ' – ' . esc_html( $trip_end_date ) : ''; ?></div>
						</div>
					</div>
				<?php endif; ?>
				<?php if ( ! empty( $trip_duration_label ) ) : ?>
					<div class="wpte-thankyou__trip-info-box">
						<span class="wpte-thankyou__trip-info-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M5 2h14M5 22h14M6 2v4a6 5.5 0 0 0 12 0V2M6 22v-4a6 5.5 0 0 1 12 0v4" /></svg></span>
						<div>
							<div class="wpte-thankyou__trip-info-label"><?php echo esc_html__( 'Duration', 'wp-travel-engine' ); ?></div>
							<div class="wpte-thankyou__trip-info-value"><?php echo esc_html( $trip_duration_label ); ?></div>
						</div>
					</div>
				<?php endif; ?>
				<?php if ( ! empty( $trip_start_time ) ) : ?>
					<div class="wpte-thankyou__trip-info-box">
						<span class="wpte-thankyou__trip-info-icon" aria-hidden="true"><svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M18.3334 9.99996C18.3334 14.6023 14.6025 18.3333 10.0001 18.3333C5.39771 18.3333 1.66675 14.6023 1.66675 9.99996M18.3334 9.99996C18.3334 5.39759 14.6025 1.66663 10.0001 1.66663M18.3334 9.99996H16.2501M1.66675 9.99996C1.66675 5.39759 5.39771 1.66663 10.0001 1.66663M1.66675 9.99996H3.75008M10.0001 1.66663V3.74996M15.8988 4.16663L11.25 8.74996M15.8988 15.8986L15.7289 15.7287C15.1524 15.1522 14.8641 14.864 14.5277 14.6578C14.2295 14.4751 13.9043 14.3404 13.5642 14.2587C13.1806 14.1666 12.7729 14.1666 11.9576 14.1666L8.04254 14.1667C7.22725 14.1667 6.8196 14.1667 6.43597 14.2588C6.09585 14.3404 5.77071 14.4751 5.47247 14.6579C5.13608 14.864 4.84783 15.1523 4.27133 15.7288L4.10144 15.8986M4.10144 4.16663L5.54848 5.61367M11.6667 9.99996C11.6667 10.9204 10.9206 11.6666 10.0001 11.6666C9.07961 11.6666 8.33341 10.9204 8.33341 9.99996C8.33341 9.07948 9.07961 8.33329 10.0001 8.33329C10.9206 8.33329 11.6667 9.07948 11.6667 9.99996Z" /></svg></span>
						<div>
							<div class="wpte-thankyou__trip-info-label"><?php echo esc_html__( 'Starts On', 'wp-travel-engine' ); ?></div>
							<div class="wpte-thankyou__trip-info-value"><?php echo esc_html( $trip_start_time ); ?></div>
						</div>
					</div>
				<?php endif; ?>
			</div>
		<?php endif; ?>

		<?php
		// Hide section only when every traveler is blank.
		$wpte_all_travelers_blank = $traveller_count > 0 && $wpte_missing_count === $traveller_count;
		?>
		<?php if ( is_array( $traveller_details ?? null ) && ! empty( $traveller_details ) && ! $wpte_all_travelers_blank ) : ?>
			<div class="wpte-thankyou__trip-section" id="travelers">
				<div class="wpte-thankyou__section-label"><?php echo esc_html__( 'Travelers', 'wp-travel-engine' ); ?></div>
				<div class="wpte-thankyou__traveler-list">
					<?php
					foreach ( $traveller_details as $index => $traveller_detail ) :
						$is_open       = ( 0 === $index );
						$is_lead       = ( 0 === $index );
						$has_details   = $wpte_traveller_has_details( $traveller_detail );
						$resolved_name = $wpte_traveller_name( $traveller_detail );
						$row_title     = '' !== $resolved_name
							? $resolved_name
							/* translators: %d: traveler number. */
							: sprintf( __( 'Traveler %d', 'wp-travel-engine' ), $index + 1 );
						?>
						<div class="wpte-thankyou__traveler-wrap<?php echo $is_open ? ' is-open' : ''; ?>">
							<button type="button" class="wpte-thankyou__traveler-row" aria-expanded="<?php echo $is_open ? 'true' : 'false'; ?>">
								<span class="wpte-thankyou__traveler-num"><?php echo esc_html( $index + 1 ); ?></span>
								<span class="wpte-thankyou__traveler-name">
									<?php echo esc_html( $row_title ); ?>
									<?php if ( $is_lead ) : ?>
										<span class="wpte-thankyou__lead-tag"><?php echo esc_html__( 'LEAD', 'wp-travel-engine' ); ?></span>
									<?php endif; ?>
									<?php if ( ! empty( $traveler_categories[ $index ] ) ) : ?>
										<span class="wpte-thankyou__cat-pill"><?php echo esc_html( $traveler_categories[ $index ] ); ?></span>
									<?php endif; ?>
									<?php if ( ! $has_details ) : ?>
										<span class="wpte-thankyou__missing-badge"><?php echo esc_html__( 'Details Missing', 'wp-travel-engine' ); ?></span>
									<?php endif; ?>
								</span>
								<span class="wpte-thankyou__traveler-toggle">
									<span
										class="wpte-thankyou__traveler-toggle-label"
										data-label-open="<?php echo esc_attr__( 'Collapse', 'wp-travel-engine' ); ?>"
										data-label-closed="<?php echo esc_attr__( 'Details', 'wp-travel-engine' ); ?>"
									><?php echo $is_open ? esc_html__( 'Collapse', 'wp-travel-engine' ) : esc_html__( 'Details', 'wp-travel-engine' ); ?></span>
									<svg viewBox="0 0 12 12" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"><polyline points="2 4 6 8 10 4" /></svg>
								</span>
							</button>
							<div class="wpte-thankyou__traveler-detail">
								<?php if ( $has_details ) : ?>
									<?php
									foreach ( $traveller_detail as $field ) :
										if ( empty( $field['value'] ) ) {
											continue;
										}
										if ( 'country_dropdown' === $field['type'] && isset( $countries_list[ $field['value'] ] ) ) {
											$field['value'] = $countries_list[ $field['value'] ];
										}
										?>
										<div class="wpte-thankyou__detail-row">
											<span class="wpte-thankyou__detail-label"><?php echo esc_html( $field['field_label'] ); ?></span>
											<span class="wpte-thankyou__detail-value"><?php echo is_array( $field['value'] ) ? esc_html( implode( ', ', $field['value'] ) ) : esc_html( $field['value'] ); ?></span>
										</div>
									<?php endforeach; ?>
								<?php else : ?>
									<div class="wpte-thankyou__missing-notice">
										<?php echo esc_html__( 'Traveler details haven\'t been added yet. Add them from your dashboard to finalize this booking.', 'wp-travel-engine' ); ?>
									</div>
								<?php endif; ?>
							</div>
						</div>
					<?php endforeach; ?>
				</div>
			</div>
		<?php endif; ?>

		<?php if ( ! empty( $line_item_groups ) ) : ?>
			<?php foreach ( $line_item_groups as $wpte_group ) : ?>
				<div class="wpte-thankyou__trip-section">
					<div class="wpte-thankyou__section-head">
						<div class="wpte-thankyou__section-label"><?php echo esc_html( $wpte_group['caption'] ); ?></div>
					</div>
					<div class="wpte-thankyou__item-rows">
						<?php foreach ( $wpte_group['items'] as $wpte_line ) : ?>
							<div class="wpte-thankyou__item-row">
								<div class="wpte-thankyou__item-info">
									<div class="wpte-thankyou__item-name"><span class="wpte-thankyou__item-dot"></span><?php echo esc_html( $wpte_line['name'] ); ?></div>
									<div class="wpte-thankyou__item-sub"><?php echo esc_html( $wpte_line['qty'] ); ?> &times; <?php echo wp_kses_post( wptravelengine_the_price( $wpte_line['price'], false, false ) ); ?></div>
								</div>
								<div class="wpte-thankyou__item-price"><?php echo wp_kses_post( wptravelengine_the_price( $wpte_line['total'], false, false ) ); ?></div>
							</div>
						<?php endforeach; ?>
					</div>
				</div>
			<?php endforeach; ?>
		<?php endif; ?>
	</div>

	<?php if ( is_array( $emergency_details ?? null ) && ! empty( $emergency_details ) ) : ?>
		<?php foreach ( $emergency_details as $index => $emergency_detail ) : ?>
			<div class="wpte-card">
				<div class="wpte-card__header">
					<h2 class="wpte-card__title">
						<span class="wpte-card__title-icon">
							<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72c.127.96.361 1.903.7 2.81a2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45c.907.339 1.85.573 2.81.7A2 2 0 0 1 22 16.92z" /></svg>
						</span>
						<?php
						/* translators: %s: emergency contact number (blank when only one contact). */
						echo esc_html( trim( sprintf( __( 'Emergency Details %s', 'wp-travel-engine' ), count( $emergency_details ) > 1 ? $index + 1 : '' ) ) );
						?>
					</h2>
				</div>
				<div class="wpte-card__body">
					<div class="wpte-thankyou__ic-rows">
						<?php
						foreach ( $emergency_detail as $field ) :
							if ( empty( $field['value'] ) ) {
								continue;
							}
							if ( 'country_dropdown' === $field['type'] && isset( $countries_list[ $field['value'] ] ) ) {
								$field['value'] = $countries_list[ $field['value'] ];
							}
							?>
							<div class="wpte-thankyou__ic-row">
								<span class="wpte-thankyou__ic-label"><?php echo esc_html( $field['field_label'] ); ?></span>
								<span class="wpte-thankyou__ic-value"><?php echo is_array( $field['value'] ) ? esc_html( implode( ', ', $field['value'] ) ) : esc_html( $field['value'] ); ?></span>
							</div>
						<?php endforeach; ?>
					</div>
				</div>
			</div>
		<?php endforeach; ?>
	<?php endif; ?>

	<!-- Region E: special requests -->
	<?php if ( ! empty( $additional_note ) ) : ?>
		<div class="wpte-card">
			<div class="wpte-card__header">
				<h2 class="wpte-card__title">
					<span class="wpte-card__title-icon">
						<svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M5.83333 7.08333H10M5.83333 10H12.5M5.83333 15V16.9463C5.83333 17.3903 5.83333 17.6123 5.92436 17.7263C6.00352 17.8255 6.12356 17.8832 6.25045 17.8831C6.39636 17.8829 6.56973 17.7442 6.91646 17.4668L8.90434 15.8765C9.31043 15.5517 9.51347 15.3892 9.73957 15.2737C9.94017 15.1712 10.1537 15.0963 10.3743 15.051C10.6231 15 10.8831 15 11.4031 15H13.5C14.9001 15 15.6002 15 16.135 14.7275C16.6054 14.4878 16.9878 14.1054 17.2275 13.635C17.5 13.1002 17.5 12.4001 17.5 11V6.5C17.5 5.09987 17.5 4.3998 17.2275 3.86502C16.9878 3.39462 16.6054 3.01217 16.135 2.77248C15.6002 2.5 14.9001 2.5 13.5 2.5H6.5C5.09987 2.5 4.3998 2.5 3.86502 2.77248C3.39462 3.01217 3.01217 3.39462 2.77248 3.86502C2.5 4.3998 2.5 5.09987 2.5 6.5V11.6667C2.5 12.4416 2.5 12.8291 2.58519 13.147C2.81635 14.0098 3.49022 14.6836 4.35295 14.9148C4.67087 15 5.05836 15 5.83333 15Z" /></svg>
					</span>
					<?php echo esc_html__( 'Special Requests / Comments', 'wp-travel-engine' ); ?>
				</h2>
			</div>
			<div class="wpte-card__body">
				<div class="wpte-thankyou__note"><?php echo wp_kses_post( $additional_note ); ?></div>
			</div>
		</div>
	<?php endif; ?>

	<!-- Region F: billing details -->
	<?php if ( is_array( $booking_details ?? null ) && ! empty( $booking_details ) ) : ?>
		<?php foreach ( $booking_details as $booking_detail ) : ?>
			<div class="wpte-card">
				<div class="wpte-card__header">
					<h2 class="wpte-card__title">
						<span class="wpte-card__title-icon">
							<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2.26953V6.40007C14 6.96012 14 7.24015 14.109 7.45406C14.2049 7.64222 14.3578 7.7952 14.546 7.89108C14.7599 8.00007 15.0399 8.00007 15.6 8.00007H19.7305M14 17H8M16 13H8M20 9.98822V17.2C20 18.8802 20 19.7202 19.673 20.362C19.3854 20.9265 18.9265 21.3854 18.362 21.673C17.7202 22 16.8802 22 15.2 22H8.8C7.11984 22 6.27976 22 5.63803 21.673C5.07354 21.3854 4.6146 20.9265 4.32698 20.362C4 19.7202 4 18.8802 4 17.2V6.8C4 5.11984 4 4.27976 4.32698 3.63803C4.6146 3.07354 5.07354 2.6146 5.63803 2.32698C6.27976 2 7.11984 2 8.8 2H12.0118C12.7455 2 13.1124 2 13.4577 2.08289C13.7638 2.15638 14.0564 2.27759 14.3249 2.44208C14.6276 2.6276 14.887 2.88703 15.4059 3.40589L18.5941 6.59411C19.113 7.11297 19.3724 7.3724 19.5579 7.67515C19.7224 7.94356 19.8436 8.2362 19.9171 8.5423C20 8.88757 20 9.25445 20 9.98822Z" /></svg>
						</span>
						<?php echo esc_html__( 'Billing Details', 'wp-travel-engine' ); ?>
					</h2>
				</div>
				<div class="wpte-card__body">
					<div class="wpte-thankyou__ic-rows">
						<?php
						foreach ( $booking_detail as $field ) :
							if ( empty( $field['value'] ) ) {
								continue;
							}
							if ( 'country_dropdown' === $field['type'] && isset( $countries_list[ $field['value'] ] ) ) {
								$field['value'] = $countries_list[ $field['value'] ];
							}
							?>
							<div class="wpte-thankyou__ic-row">
								<span class="wpte-thankyou__ic-label"><?php echo esc_html( $field['field_label'] ); ?></span>
								<span class="wpte-thankyou__ic-value"><?php echo is_array( $field['value'] ) ? esc_html( implode( ', ', $field['value'] ) ) : esc_html( $field['value'] ); ?></span>
							</div>
						<?php endforeach; ?>
					</div>
				</div>
			</div>
		<?php endforeach; ?>
	<?php endif; ?>

	<?php do_action( 'wptravelengine_thankyou_after_booking_details' ); ?>

	<?php
	// CTA banner — dashboard + trips-archive links (rendered only when their pages resolve).
	$wpte_dashboard_url = wptravelengine_dashboard_url();
	$wpte_trips_url     = get_post_type_archive_link( 'trip' );
	?>
	<?php if ( ! empty( $wpte_dashboard_url ) || ! empty( $wpte_trips_url ) ) : ?>
		<div class="wpte-thankyou__cta">
			<?php if ( ! empty( $wpte_dashboard_url ) ) : ?>
				<a class="wpte-thankyou__btn wpte-thankyou__btn--primary" href="<?php echo esc_url( $wpte_dashboard_url ); ?>">
					<svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="2" width="12" height="12" rx="2" /><line x1="5" y1="5" x2="11" y2="5" /><line x1="5" y1="8" x2="11" y2="8" /><line x1="5" y1="11" x2="8" y2="11" /></svg>
					<?php echo esc_html__( 'Go to Dashboard', 'wp-travel-engine' ); ?>
				</a>
			<?php endif; ?>
			<?php if ( ! empty( $wpte_trips_url ) ) : ?>
				<a class="wpte-thankyou__btn wpte-thankyou__btn--secondary" href="<?php echo esc_url( $wpte_trips_url ); ?>">
					<?php echo esc_html__( 'Browse More Trips', 'wp-travel-engine' ); ?>
				</a>
			<?php endif; ?>
		</div>
	<?php endif; ?>
</div>
<?php
// phpcs:enable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound

