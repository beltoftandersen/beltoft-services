<?php
/**
 * My Account → Overview: every section in order.
 *
 * Override by copying to yourtheme/beltoft-services/account/overview/overview.php.
 *
 * @package BeltoftServices
 *
 * @var array<string, string> $parts Printed sections by key (attention, servers, domains, licenses, next_payment, activity, welcome). A section with nothing to show is ''.
 */

defined( 'ABSPATH' ) || exit;

// Two columns that stack on their own, so a short box is not stretched to the height of a long one beside it.
// On a phone it is one column, in the order of the numbers (the columns step aside: display contents).
$beltoft_services_columns = array(
	array( 'servers' => 1, 'licenses' => 3, 'apps' => 4, 'next_payment' => 5 ),
	array( 'domains' => 2, 'activity' => 6 ),
);
$beltoft_services_filled = array();

foreach ( $beltoft_services_columns as $beltoft_services_i => $beltoft_services_keys ) {
	$beltoft_services_filled[ $beltoft_services_i ] = array_filter( $beltoft_services_keys, static fn( $order, $key ) => '' !== (string) ( $parts[ $key ] ?? '' ), ARRAY_FILTER_USE_BOTH );
}

// One column with nothing in it: everything goes in the other, full width, no empty half.
if ( array() === $beltoft_services_filled[0] || array() === $beltoft_services_filled[1] ) {
	$beltoft_services_filled = array( $beltoft_services_filled[0] + $beltoft_services_filled[1] );
	asort( $beltoft_services_filled[0] );
}
?>
<div class="beltoft-services-overview">
	<?php
	// The sections are escaped where they are built (templates/account/overview/*.php).
	echo $parts['attention']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	echo $parts['welcome']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	?>
	<?php if ( array_filter( $beltoft_services_filled ) ) : ?>
		<div class="beltoft-services-ov__cols<?php echo 1 === count( $beltoft_services_filled ) ? ' is-single' : ''; ?>">
			<?php foreach ( $beltoft_services_filled as $beltoft_services_keys ) : ?>
				<div class="beltoft-services-ov__col">
					<?php foreach ( $beltoft_services_keys as $beltoft_services_key => $beltoft_services_order ) : ?>
						<div class="beltoft-services-ov__slot" style="order:<?php echo (int) $beltoft_services_order; ?>">
							<?php echo $parts[ $beltoft_services_key ]; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						</div>
					<?php endforeach; ?>
				</div>
			<?php endforeach; ?>
		</div>
	<?php endif; ?>
</div>
