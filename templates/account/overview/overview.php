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

$beltoft_services_pairs = array(
	array( 'next_payment', 'activity' ),
);
?>
<div class="beltoft-services-overview">
	<?php
	// The sections are escaped where they are built (templates/account/overview/*.php).
	echo $parts['attention']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	echo $parts['welcome']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	echo $parts['servers']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	echo $parts['domains']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	echo $parts['licenses']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

	foreach ( $beltoft_services_pairs as $beltoft_services_pair ) :
		$beltoft_services_left  = $parts[ $beltoft_services_pair[0] ];
		$beltoft_services_right = $parts[ $beltoft_services_pair[1] ];

		if ( '' === $beltoft_services_left . $beltoft_services_right ) {
			continue;
		}
		?>
		<div class="beltoft-services-ov__two<?php echo '' === $beltoft_services_left || '' === $beltoft_services_right ? ' is-single' : ''; ?>">
			<?php
			echo $beltoft_services_left; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo $beltoft_services_right; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			?>
		</div>
	<?php endforeach; ?>
</div>
