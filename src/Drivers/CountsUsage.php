<?php
/**
 * Optional driver contract: the service reports units that are billed one by one (pay as you go per unit).
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Drivers;

defined( 'ABSPATH' ) || exit;

/**
 * Marker for a driver whose service reports counted units (an order it processed), which the usage engine bills per unit with a
 * unit price or price steps. The product editor offers "Pay as you go per unit" only for a product of such a driver; the hourly
 * choice is for drivers that implement UsageItems. No methods: the units themselves are pulled by the driver's own code.
 */
interface CountsUsage {
}
