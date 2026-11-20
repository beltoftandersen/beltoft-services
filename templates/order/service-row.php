<?php
/**
 * One service of an order: its name, state, progress, message and the link to its page. Used by the order received page
 * (order/next.php) and by the order section of the overview (account/overview/new-order.php, $flow true: the process line
 * with its track). A theme can override this file as beltoft-services/order/service-row.php.
 *
 * @package BeltoftServices
 *
 * @var array<string, mixed> $beltoft_row The service (BeltoftServices\ThankYou::row()).
 * @var bool                 $flow        Whether this is a process line of the overview.
 */

defined( 'ABSPATH' ) || exit;

$beltoft_flow = ! empty( $flow );
?>
<li class="beltoft-service<?php echo $beltoft_flow ? ' bs-flow' : ''; ?> is-<?php echo esc_attr( $beltoft_row['group'] ); ?>" data-service="<?php echo esc_attr( (string) $beltoft_row['id'] ); ?>">
		<p class="beltoft-service__head">
			<span class="beltoft-service__name"><?php echo esc_html( $beltoft_row['name'] ); ?></span>
			<span class="beltoft-service__state" data-service-state><?php echo esc_html( $beltoft_row['label'] ); ?></span>
		</p>
		<?php if ( $beltoft_flow ) : ?>
			<div class="bs-flow__track" role="progressbar" aria-label="<?php echo esc_attr( $beltoft_row['name'] ); ?>" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?php echo esc_attr( (string) BeltoftServices\Progress::percent( $beltoft_row['progress'], (bool) $beltoft_row['ready'] ) ); ?>" data-flow-track><i style="width:<?php echo esc_attr( (string) BeltoftServices\Progress::percent( $beltoft_row['progress'], (bool) $beltoft_row['ready'] ) ); ?>%"></i></div>
		<?php endif; ?>
		<?php BeltoftServices\Progress::render( $beltoft_row['progress'] ); ?>
		<p class="beltoft-service__message" data-service-message<?php echo '' === $beltoft_row['message'] ? ' hidden' : ''; ?>><?php echo esc_html( $beltoft_row['message'] ); ?></p>
		<p class="beltoft-service__open" data-service-open<?php echo $beltoft_row['ready'] ? '' : ' hidden'; ?>>
			<a href="<?php echo esc_url( $beltoft_row['url'] ); ?>"><?php echo esc_html( BeltoftServices\ThankYou::open_label( $beltoft_row['kind'] ) ); ?></a>
		</p>
		<?php if ( ! empty( $beltoft_row['snapshot'] ) && 'failed' !== $beltoft_row['group'] ) : ?>
			<p class="beltoft-service__note">
				<?php
				/* translators: %d: snapshot slots added to the server. */
				echo esc_html( sprintf( __( 'Snapshot slots +%d. Find them in My account > VPS > your server > Snapshots.', 'beltoft-services' ), (int) $beltoft_row['snapshot']['slots'] ) );
				?>
			</p>
		<?php endif; ?>
		<?php if ( ! empty( $beltoft_row['disk'] ) && 'failed' !== $beltoft_row['group'] ) : ?>
			<p class="beltoft-service__note"><?php esc_html_e( 'Find your disk in My account > VPS > your server > Disks.', 'beltoft-services' ); ?></p>
			<p class="beltoft-service__links">
				<?php if ( '' !== $beltoft_row['disk']['server_url'] ) : ?>
					<a href="<?php echo esc_url( $beltoft_row['disk']['server_url'] ); ?>"><?php esc_html_e( 'Open your server', 'beltoft-services' ); ?></a>
				<?php endif; ?>
				<a href="<?php echo esc_url( $beltoft_row['disk']['guide_url'] ); ?>"><?php esc_html_e( 'Disk setup guide', 'beltoft-services' ); ?></a>
			</p>
		<?php endif; ?>
</li>
