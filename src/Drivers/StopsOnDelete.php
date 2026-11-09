<?php
/**
 * Optional driver contract: the reconciler does not pause the service before delete().
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Drivers;

defined( 'ABSPATH' ) || exit;

/**
 * Marker for a driver that needs no pause before delete(): either its delete() already stops what
 * it removes (the Proxmox and hosting drivers stop the machine before destroying it), or there is
 * nothing to pause (the domain and DNS drivers). Every other driver is paused by the reconciler
 * before its delete() runs, because a remote service may refuse to delete what is still running.
 */
interface StopsOnDelete {
}
