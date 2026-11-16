<?php
/**
 * Optional driver contract: the service cannot be delivered without the customer's phone number.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Drivers;

defined( 'ABSPATH' ) || exit;

/**
 * Marker for a driver whose provisioning hands a contact with a phone number to a third party (the registrar contact
 * of a domain or of an SSL certificate). A site that hides the phone field at checkout asks
 * BillingRequirements::cart_needs_phone() and shows and requires it for a cart with such a service. Without it the
 * order is paid and the service waits for "complete billing details".
 */
interface NeedsPhone {
}
