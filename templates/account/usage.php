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
 * @var string $view_url Address of the pay-as-you-go subscription: set on the card of the subscription list.
 * @var bool $card The card (state, title, month amount, services), not the plain block.
 * @var string $state Status word of the account (card only).
 * @var string $tone Colour group of the status (card only).
 * @var array<int, string> $links Page address of each service (resource id => address).
 */

defined( 'ABSPATH' ) || exit;

use BeltoftServices\Usage\Estimate;
?>
<?php if ( ! empty( $card ) ) : ?>
<section class="beltoft-services-usage beltoft-services-usage--card">
	<div class="beltoft-services-servers beltoft-services-servers--area beltoft-services-servers--subs">
		<?php if ( ! empty( $view_url ) ) : ?>
		<a class="beltoft-services-srv" href="<?php echo esc_url( $view_url ); ?>" aria-label="<?php echo esc_attr( sprintf( '%1$s, %2$s', __( 'Pay as you go', 'beltoft-services' ), $state ) ); ?>">
		<?php else : ?>
		<div class="beltoft-services-srv">
		<?php endif; ?>
			<span class="beltoft-services-srv__id">
				<span class="beltoft-services-state is-<?php echo esc_attr( $tone ); ?>"><?php echo esc_html( $state ); ?></span>
				<span class="beltoft-services-srv__name"><?php esc_html_e( 'Pay as you go', 'beltoft-services' ); ?></span>
			</span>
			<span class="beltoft-services-srv__state">
				<?php if ( ! empty( $estimate['resources'] ) ) : ?>
					<span class="beltoft-services-srv__figure"><?php echo esc_html( Estimate::money( Estimate::shown_total( $estimate ), $estimate['currency'] ) ); ?></span>
					<span class="beltoft-services-srv__sub"><?php echo esc_html( Estimate::incl() ? __( 'so far this month, incl. VAT (estimate)', 'beltoft-services' ) : __( 'so far this month, excl. VAT (estimate)', 'beltoft-services' ) ); ?></span>
				<?php endif; ?>
			</span>
			<?php if ( ! empty( $view_url ) ) : ?>
				<span class="beltoft-services-srv__go" aria-hidden="true">&rsaquo;</span>
			</a>
			<?php else : ?>
			</div>
			<?php endif; ?>
		<div class="beltoft-services-usage__body">
			<?php
			// A currency sign can end in a full stop (kr.): the sentence adds its own.
			$beltoft_services_usage_money = rtrim( Estimate::money( Estimate::shown_total( $estimate ), $estimate['currency'] ), '.' );
			$beltoft_services_usage_next  = $estimate['next'] > 0 ? wp_date( (string) get_option( 'date_format' ), $estimate['next'] ) : '';
			$beltoft_services_usage_full  = empty( $view_url );
			?>
			<?php if ( empty( $estimate['resources'] ) ) : ?>
				<p class="beltoft-services-usage__note"><?php esc_html_e( 'No usage so far this month.', 'beltoft-services' ); ?></p>
			<?php elseif ( $beltoft_services_usage_full ) : ?>
				<table class="beltoft-services-usage__table">
					<thead>
						<tr>
							<th scope="col"><?php esc_html_e( 'Service', 'beltoft-services' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Hours', 'beltoft-services' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Amount', 'beltoft-services' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $estimate['resources'] as $beltoft_services_usage_id => $beltoft_services_usage_resource ) : ?>
							<?php foreach ( $beltoft_services_usage_resource['lines'] as $beltoft_services_usage_line ) : ?>
								<?php $beltoft_services_usage_name = (string) ( $beltoft_services_usage_line['subject'] ?? $beltoft_services_usage_resource['label'] ); ?>
								<tr>
									<td>
										<?php if ( ! empty( $links[ $beltoft_services_usage_id ] ) ) : ?>
											<a href="<?php echo esc_url( $links[ $beltoft_services_usage_id ] ); ?>"><?php echo esc_html( $beltoft_services_usage_name ); ?></a>
										<?php else : ?>
											<?php echo esc_html( $beltoft_services_usage_name ); ?>
										<?php endif; ?>
										<?php if ( $beltoft_services_usage_line['capped'] ) : ?>
											<span class="beltoft-services-usage__cap"><?php esc_html_e( 'capped', 'beltoft-services' ); ?></span>
										<?php endif; ?>
									</td>
									<td><?php echo esc_html( (string) $beltoft_services_usage_line['hours'] ); ?></td>
									<td><?php echo esc_html( Estimate::money( Estimate::shown_line( (float) $beltoft_services_usage_line['charge'] ), $estimate['currency'] ) ); ?></td>
								</tr>
							<?php endforeach; ?>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php else : ?>
				<p class="beltoft-services-usage__note">
					<?php
					if ( '' !== $beltoft_services_usage_next ) {
						/* translators: 1: amount so far this month, 2: date of the next usage invoice. */
						echo esc_html( sprintf( __( 'This month\'s usage: %1$s. Next invoice %2$s.', 'beltoft-services' ), $beltoft_services_usage_money, $beltoft_services_usage_next ) );
					} else {
						/* translators: %s: amount so far this month. */
						echo esc_html( sprintf( __( 'This month\'s usage: %s.', 'beltoft-services' ), $beltoft_services_usage_money ) );
					}
					?>
				</p>
			<?php endif; ?>
			<?php
			$beltoft_services_usage_parts = array();
			if ( ! empty( $credit ) && $credit > 0 ) {
				/* translators: %s: amount. */
				$beltoft_services_usage_parts[] = sprintf( __( 'Account credit %s is used first.', 'beltoft-services' ), Estimate::money( $credit / 100, $estimate['currency'] ) );
			}
			if ( $beltoft_services_usage_full && $estimate['next'] > 0 ) {
				/* translators: %s: date */
				$beltoft_services_usage_parts[] = sprintf( __( 'Next usage invoice %s.', 'beltoft-services' ), $beltoft_services_usage_next );
			}
			?>
			<?php if ( array() !== $beltoft_services_usage_parts ) : ?>
				<p class="beltoft-services-usage__note"><?php echo esc_html( implode( ' ', $beltoft_services_usage_parts ) ); ?></p>
			<?php endif; ?>
			<?php if ( ! $beltoft_services_usage_full ) : ?>
				<p class="beltoft-services-usage__note"><a href="<?php echo esc_url( $view_url ); ?>"><?php esc_html_e( 'See usage', 'beltoft-services' ); ?> &rsaquo;</a></p>
			<?php endif; ?>
		</div>
	</div>
</section>
<?php return; endif; ?>
<section class="beltoft-services-usage">
	<div class="beltoft-services-ov__head">
		<h2><?php esc_html_e( 'Usage this month', 'beltoft-services' ); ?></h2>
	</div>
	<?php if ( empty( $estimate['resources'] ) ) : ?>
		<p class="beltoft-services-usage__note"><?php esc_html_e( 'No usage so far this month.', 'beltoft-services' ); ?></p>
	<?php else : ?>
	<div class="beltoft-services-credit__balance">
		<span class="beltoft-services-credit__label"><?php echo esc_html( Estimate::incl() ? __( 'Total so far, incl. VAT (estimate)', 'beltoft-services' ) : __( 'Total so far, excl. VAT (estimate)', 'beltoft-services' ) ); ?></span>
		<span class="beltoft-services-credit__amount"><?php echo esc_html( Estimate::money( Estimate::shown_total( $estimate ), $estimate['currency'] ) ); ?></span>
	</div>
	<table class="beltoft-services-usage__table">
		<thead>
			<tr>
				<th scope="col"><?php esc_html_e( 'Service', 'beltoft-services' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Hours', 'beltoft-services' ); ?></th>
				<th scope="col"><?php echo esc_html( Estimate::incl() ? __( 'Amount incl. VAT', 'beltoft-services' ) : __( 'Amount', 'beltoft-services' ) ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php foreach ( $estimate['resources'] as $beltoft_services_usage_id => $beltoft_services_usage_resource ) : ?>
				<?php foreach ( $beltoft_services_usage_resource['lines'] as $beltoft_services_usage_line ) : ?>
					<tr>
						<td>
							<?php $beltoft_services_usage_name = (string) ( $beltoft_services_usage_line['subject'] ?? $beltoft_services_usage_resource['label'] ); ?>
							<?php if ( ! empty( $links[ $beltoft_services_usage_id ] ) ) : ?>
								<a href="<?php echo esc_url( $links[ $beltoft_services_usage_id ] ); ?>"><?php echo esc_html( $beltoft_services_usage_name ); ?></a>
							<?php else : ?>
								<?php echo esc_html( $beltoft_services_usage_name ); ?>
							<?php endif; ?>
							<?php if ( $beltoft_services_usage_line['capped'] ) : ?>
								<span class="beltoft-services-usage__cap"><?php esc_html_e( 'capped', 'beltoft-services' ); ?></span>
							<?php endif; ?>
						</td>
						<td><?php echo esc_html( (string) $beltoft_services_usage_line['hours'] ); ?></td>
						<td><?php echo esc_html( Estimate::money( Estimate::shown_line( (float) $beltoft_services_usage_line['charge'] ), $estimate['currency'] ) ); ?></td>
					</tr>
				<?php endforeach; ?>
			<?php endforeach; ?>
		</tbody>
	</table>
	<?php endif; ?>
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
