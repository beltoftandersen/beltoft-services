<?php
/**
 * My Account → Databases or Apps: the list, or one service's page.
 *
 * Override by copying to yourtheme/beltoft-services/account/area.php. Reached through the licenses template call
 * of Beltoft Licenses (see BeltoftServices\Account\ServiceArea).
 *
 * @package BeltoftServices
 *
 * @var array<int, array<string, mixed>> $licenses Display values of the licenses on the tab.
 * @var string                           $empty    Text for an empty tab (optional).
 */

defined( 'ABSPATH' ) || exit;

\BeltoftServices\Account\ServiceArea::render_active( $licenses, isset( $empty ) ? (string) $empty : '' );
