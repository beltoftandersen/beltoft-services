<?php
/**
 * The snapshot slots in use as a bar: the filled part is the share in use. Decoration only; the numbers are printed with it.
 *
 * @package BeltoftServices
 *
 * @var array{used: int, slots: int} $beltoft_services_strip Slots in use and allowed.
 */

defined( 'ABSPATH' ) || exit;

$beltoft_services_slots = max( 0, (int) $beltoft_services_strip['slots'] );

// Nothing to measure against.
if ( $beltoft_services_slots < 1 ) {
	return;
}

$beltoft_services_share = (int) round( 100 * min( $beltoft_services_slots, max( 0, (int) $beltoft_services_strip['used'] ) ) / $beltoft_services_slots );
?>
<span class="beltoft-services-usebar<?php echo 100 === $beltoft_services_share ? ' is-full' : ''; ?>" aria-hidden="true"><i style="width: <?php echo (int) $beltoft_services_share; ?>%"></i></span>
