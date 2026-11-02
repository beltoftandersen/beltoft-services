<?php
/**
 * My Account → Billing: usage this month.
 *
 * Override by copying to yourtheme/beltoft-services/account/usage.php.
 *
 * @package BeltoftServices
 *
 * @var array{total: float, currency: string, next: int, resources: array<int, array{label: string, total: float, lines: array<int, array{item: string, label: string, hours: int, charge: float, capped: bool}>}>} $estimate The estimate (see BeltoftServices\Usage\Estimate).
 * @var int $credit Account credit in cents (0: none).
 */

defined( 'ABSPATH' ) || exit;

use BeltoftServices\Usage\Estimate;
?>
<section class="beltoft-services-usage">
	<div class="beltoft-services-ov__head">
		<h2><?php esc_html_e( 'Usage this month', 'beltoft-services' ); ?></h2>
	</div>
	<div class="beltoft-services-credit__balance">
		<span class="beltoft-services-credit__label"><?php esc_html_e( 'Total so far, excl. VAT (estimate)', 'beltoft-services' ); ?></span>
		<span class="beltoft-services-credit__amount"><?php echo esc_html( Estimate::money( $estimate['total'], $estimate['currency'] ) ); ?></span>
	</div>
	<table class="beltoft-services-usage__table">
		<thead>
			<tr>
				<th scope="col"><?php esc_html_e( 'Service', 'beltoft-services' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Hours', 'beltoft-services' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Amount', 'beltoft-services' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php foreach ( $estimate['resources'] as $beltoft_services_usage_resource ) : ?>
				<?php foreach ( $beltoft_services_usage_resource['lines'] as $beltoft_services_usage_line ) : ?>
					<tr>
						<td>
							<?php echo esc_html( $beltoft_services_usage_line['subject'] ?? $beltoft_services_usage_resource['label'] ); ?>
							<?php if ( $beltoft_services_usage_line['capped'] ) : ?>
								<span class="beltoft-services-usage__cap"><?php esc_html_e( 'capped', 'beltoft-services' ); ?></span>
							<?php endif; ?>
						</td>
						<td><?php echo esc_html( (string) $beltoft_services_usage_line['hours'] ); ?></td>
						<td><?php echo esc_html( Estimate::money( $beltoft_services_usage_line['charge'], $estimate['currency'] ) ); ?></td>
					</tr>
				<?php endforeach; ?>
			<?php endforeach; ?>
		</tbody>
	</table>
	<?php if ( ! empty( $estimate['carried'] ) && $estimate['carried'] > 0 && ! empty( $estimate['minimum'] ) ) : ?>
		<p class="beltoft-services-usage__note">
			<?php
			echo esc_html(
				sprintf(
					/* translators: 1: amount carried, 2: minimum amount. */
					__( 'Carried to next month: %1$s (under the %2$s minimum)', 'beltoft-services' ),
					Estimate::money( (float) $estimate['carried'], $estimate['currency'] ),
					Estimate::money( (float) $estimate['minimum'], $estimate['currency'] )
				)
			);
			?>
		</p>
	<?php endif; ?>
	<?php if ( ! empty( $credit ) && $credit > 0 ) : ?>
		<p class="beltoft-services-usage__note beltoft-services-usage__credit">
			<?php
			echo esc_html(
				sprintf(
					/* translators: %s: amount. */
					__( 'Account credit: %s, used first on your next usage invoice.', 'beltoft-services' ),
					Estimate::money( $credit / 100, $estimate['currency'] )
				)
			);
			?>
		</p>
	<?php endif; ?>
	<?php if ( $estimate['next'] > 0 ) : ?>
		<p class="beltoft-services-usage__note">
			<?php
			echo esc_html(
				sprintf(
					/* translators: %s: date */
					__( 'Your next usage invoice is due on %s.', 'beltoft-services' ),
					wp_date( (string) get_option( 'date_format' ), $estimate['next'] )
				)
			);
			?>
		</p>
	<?php endif; ?>
</section>
