<?php
/**
 * My Account → Servers: the list of servers, or one server's page.
 *
 * Override by copying to yourtheme/beltoft-services/account/servers.php. Reached through the
 * licenses template call of Beltoft Licenses (see BeltoftServices\Account\Servers).
 *
 * @package BeltoftServices
 *
 * @var array<int, array<string, mixed>> $licenses Display values of the licenses on the tab.
 * @var string                           $empty    Text for an empty tab (optional).
 */

defined( 'ABSPATH' ) || exit;

\BeltoftServices\Account\Servers::render( $licenses, isset( $empty ) ? (string) $empty : '' );
