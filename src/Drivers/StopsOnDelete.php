<?php
/**
 * Optional driver contract: delete() stops the service itself.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Drivers;

defined( 'ABSPATH' ) || exit;

/**
 * Marker for a driver whose delete() already stops what it removes (the Proxmox driver stops
 * the VM before destroying it). Every other driver is paused by the reconciler before its
 * delete() runs, because a remote service may refuse to delete what is still running.
 */
interface StopsOnDelete {
}
