<?php
/**
 * Thrown in place of wp_die() while a checkout runs in the Sandbox.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Mcp;

defined( 'ABSPATH' ) || exit;

/**
 * WooCommerce ends a checkout with wp_send_json(), which ends the request. In the Sandbox that end is this throwable. It is an Error, not an Exception, because process_checkout() catches every Exception.
 */
final class SandboxEnd extends \Error {}
