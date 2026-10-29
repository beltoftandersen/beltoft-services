<?php
/**
 * My Account → Domains: the list of domains, or one domain's page.
 *
 * Override by copying to yourtheme/beltoft-services/account/domains.php. Reached through the
 * licenses template call of Beltoft Licenses (see BeltoftServices\Account\Domains).
 *
 * @package BeltoftServices
 *
 * @var array<int, array<string, mixed>> $licenses Display values of the licenses on the tab.
 * @var string                           $empty    Text for an empty tab (optional).
 */

defined( 'ABSPATH' ) || exit;

\BeltoftServices\Account\Domains::render( $licenses, isset( $empty ) ? (string) $empty : '' );
