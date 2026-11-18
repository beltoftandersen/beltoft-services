<?php
/**
 * Marker: the checkout asks for an optional SSH public key for this driver's product.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Drivers;

defined( 'ABSPATH' ) || exit;

/**
 * Implemented by the drivers that can use a customer's key (the VM driver and the dedicated server driver). The field, its
 * validation and its storage are Proxmox\Vm\SshKeyField for all of them; the key reaches the resource as meta `ssh_key`.
 */
interface TakesSshKey {
}
