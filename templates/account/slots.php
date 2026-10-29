<?php
/**
 * The snapshot slots as squares: used ones filled, free ones empty. Decoration only; the numbers are printed beside it.
 *
 * @package BeltoftServices
 *
 * @var array{used: int, slots: int} $beltoft_services_strip Slots in use and allowed.
 */

defined( 'ABSPATH' ) || exit;

$beltoft_services_cells = max( 0, min( 40, (int) $beltoft_services_strip['slots'] ) );
?>
<span class="beltoft-services-slots" aria-hidden="true"><?php for ( $beltoft_services_i = 0; $beltoft_services_i < $beltoft_services_cells; $beltoft_services_i++ ) : ?><i<?php echo $beltoft_services_i < (int) $beltoft_services_strip['used'] ? ' class="is-used"' : ''; ?>></i><?php endfor; ?></span>
