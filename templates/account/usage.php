<?php
/**
 * My Account → Billing: usage this month.
 *
 * Override by copying to yourtheme/beltoft-services/account/usage.php.
 *
 * @package BeltoftServices
 *
 * @var array{total: float, currency: string, next: int, resources: array<int, array{label: string, total: float, lines: array<int, array{item: string, label: string, hours: int, charge: float, capped: bool}>}>} $estimate The estimate (see BeltoftServices\Usage\Estimate).
 */

defined( 'ABSPATH' ) || exit;

use BeltoftServices\Usage\Estimate;
?>
<section class="beltoft-services-ov__section beltoft-services-usage">
	<div class="beltoft-services-ov__head">
		<h2><?php esc_html_e( 'Usage this month', 'beltoft-services' ); ?></h2>
	</div>
	<div class="beltoft-services-ov__money">
		<div class="beltoft-services-ov__moneyhead">
			<span class="beltoft-services-ov__label"><?php esc_html_e( 'Total so far, excl. VAT (estimate)', 'beltoft-services' ); ?></span>
			<span class="beltoft-services-ov__big"><?php echo esc_html( Estimate::money( $estimate['total'], $estimate['currency'] ) ); ?></span>
		</div>
		<?php foreach ( $estimate['resources'] as $beltoft_services_usage_resource ) : ?>
			<h3><?php echo esc_html( $beltoft_services_usage_resource['label'] ); ?></h3>
			<dl>
				<?php foreach ( $beltoft_services_usage_resource['lines'] as $beltoft_services_usage_line ) : ?>
					<dt>
						<?php
						echo esc_html(
							sprintf(
								/* translators: 1: item (server, backups), 2: number of hours */
								_n( '%1$s, %2$d hour', '%1$s, %2$d hours', $beltoft_services_usage_line['hours'], 'beltoft-services' ),
								$beltoft_services_usage_line['label'],
								$beltoft_services_usage_line['hours']
							)
						);
						?>
					</dt>
					<dd>
						<?php echo esc_html( Estimate::money( $beltoft_services_usage_line['charge'], $estimate['currency'] ) ); ?>
						<?php if ( $beltoft_services_usage_line['capped'] ) : ?>
							<?php esc_html_e( '(monthly maximum reached)', 'beltoft-services' ); ?>
						<?php endif; ?>
					</dd>
				<?php endforeach; ?>
			</dl>
		<?php endforeach; ?>
		<?php if ( $estimate['next'] > 0 ) : ?>
			<p>
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
	</div>
</section>
