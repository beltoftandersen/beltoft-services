<?php
/**
 * Optional driver contract: the service needs the customer's full billing details.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Drivers;

defined( 'ABSPATH' ) || exit;

/**
 * Marker for a driver that cannot provision from a bare name and email (a domain registrant
 * contact, a VPS customer record). A cart with such a service makes checkout require the
 * billing name, address, country and phone; other carts keep the store's own field settings.
 */
interface NeedsBilling {
}
