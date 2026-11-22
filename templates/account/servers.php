<?php
/**
 * My Account → Servers: the list of servers, or one server's page.
 *
 * Override by copying to yourtheme/beltoft-services/account/servers.php. Reached through the
 * licenses template call of Beltoft Licenses (see BeltoftServices\Proxmox\Vm\Servers).
 *
 * @package BeltoftServices
 *
 * @var array<int, array<string, mixed>> $licenses Display values of the licenses on the tab.
 * @var string                           $empty    Text for an empty tab (optional).
 */

defined( 'ABSPATH' ) || exit;

\BeltoftServices\Proxmox\Vm\Servers::render( $licenses, isset( $empty ) ? (string) $empty : '' );
