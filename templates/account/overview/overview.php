<?php
/**
 * My Account → Overview: every section in order.
 *
 * Override by copying to yourtheme/beltoft-services/account/overview/overview.php.
 *
 * @package BeltoftServices
 *
 * @var array<string, string> $parts Printed sections by key (attention, servers, databases, domains, licenses, apps, mail, next_payment, support, activity, welcome). A section with nothing to show is ''.
 */

defined( 'ABSPATH' ) || exit;

// One list in the order of the numbers. On a wide screen the sections flow into two columns that balance by height (CSS columns), so a
// short box is not stretched and no half stays empty; on a phone it is one column in the same order.
$beltoft_services_order = array(
	// Databases share the 1 of servers: equal numbers keep the order they are listed in, so they come right after the servers.
	'servers' => 1, 'databases' => 1, 'hosting' => 1, 'domains' => 2, 'licenses' => 3, 'support' => 3, 'apps' => 4, 'mail' => 4, 'next_payment' => 5, 'activity' => 6,
);
$beltoft_services_filled = array_filter( $beltoft_services_order, static fn( $order, $key ) => '' !== (string) ( $parts[ $key ] ?? '' ), ARRAY_FILTER_USE_BOTH );
asort( $beltoft_services_filled );
?>
<div class="beltoft-services-overview">
	<?php
	// The sections are escaped where they are built (templates/account/overview/*.php).
	echo $parts['new_order'] ?? ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	echo $parts['attention']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	echo $parts['welcome']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	?>
	<?php if ( $beltoft_services_filled ) : ?>
		<div class="beltoft-services-ov__cols<?php echo 1 === count( $beltoft_services_filled ) ? ' is-single' : ''; ?>">
			<div class="beltoft-services-ov__col">
				<?php foreach ( $beltoft_services_filled as $beltoft_services_key => $beltoft_services_number ) : ?>
					<div class="beltoft-services-ov__slot" style="order:<?php echo (int) $beltoft_services_number; ?>">
						<?php echo $parts[ $beltoft_services_key ]; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					</div>
				<?php endforeach; ?>
			</div>
		</div>
	<?php endif; ?>
</div>
