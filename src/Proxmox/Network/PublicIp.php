<?php
/**
 * The public IPv4 add-on of a machine: the shop side that a managed database and a VPS Basic server share.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Proxmox\Network;

use BeltoftServices\Billing\SwitchGuard;
use BeltoftServices\Proxmox\Backup\Backups;
use BeltoftServices\Resource;

defined( 'ABSPATH' ) || exit;

/**
 * The add-on is a pair of variations that differ in the plan field `public_ip` (0 or 1), like Backups. This class is the one
 * owner of what is the same for every machine kind: does a plan want the address, which variation is the other half of the
 * pair, the switch link and its price text, and the check that an address is free before a switch is paid. What differs
 * (how the address reaches the machine) stays with the kind: Db\DbIp for a database, Vm\VpsIp for a server.
 */
final class PublicIp {

	/** The plan field of the pair. */
	public const FIELD = 'public_ip';

	/**
	 * Does the plan have the public IPv4 address?
	 *
	 * @param array<string, mixed> $plan    Plan.
	 * @param int                  $default What a plan without the field means: 0 for a database (the address is an add-on), 1 for a server (older plans always had one).
	 */
	public static function wants( array $plan, int $default = 0 ): bool {
		return (int) ( $plan[ self::FIELD ] ?? $default ) > 0;
	}

	/**
	 * The other variation of the pair, or null (no pair, so no UI).
	 *
	 * @param Resource $r    Resource.
	 * @param bool     $with Whether the sibling has the public IP.
	 */
	public static function sibling( Resource $r, bool $with ): ?\WC_Product_Variation {
		$id        = (int) ( $r->license()['variation_id'] ?? 0 );
		$variation = $id > 0 && function_exists( 'wc_get_product' ) ? wc_get_product( $id ) : null;

		return $variation instanceof \WC_Product_Variation ? Backups::sibling_by( $variation, self::FIELD, $with ) : null;
	}

	/**
	 * The link that switches the subscription to the variation with or without the public IP (the same WooCommerce
	 * Subscriptions switch as for backups). Empty when there is none.
	 *
	 * @param Resource $r    Resource.
	 * @param bool     $with Whether the target has the public IP.
	 */
	public static function switch_url( Resource $r, bool $with ): string {
		$sibling = self::sibling( $r, $with );

		return null === $sibling ? '' : Backups::switch_to( $r, $sibling );
	}

	/**
	 * "+€4.00 / month" of the variation with the public IP, or its removal. Empty when there is none.
	 *
	 * @param Resource $r    Resource.
	 * @param bool     $with Whether the target has the public IP.
	 */
	public static function price_label( Resource $r, bool $with ): string {
		$variation = wc_get_product( (int) ( $r->license()['variation_id'] ?? 0 ) );
		$sibling   = self::sibling( $r, $with );

		return $variation instanceof \WC_Product_Variation && null !== $sibling ? Backups::price_difference( $variation, $sibling ) : '';
	}

	/**
	 * The refusal of a switch to a variation with the address while none can be given (an existing customer's switch is
	 * let through by Stock, so the pool is checked here). Empty when it may go on.
	 *
	 * @param int                  $subscription_id Subscription id.
	 * @param int                  $product_id      Target product.
	 * @param string               $driver_id       Driver of the resources switched.
	 * @param mixed                $item            The line item switched.
	 */
	public static function pool_error( int $subscription_id, int $product_id, string $driver_id, $item ): string {
		foreach ( SwitchGuard::resources( $subscription_id, $item, $driver_id, $product_id ) as $resource ) {
			if ( null === IpPool::for_resource( $resource->id() ) && 0 === IpPool::available_for_db() ) {
				return __( 'No public IP address is available right now. Please try again later.', 'beltoft-services' );
			}
		}

		return '';
	}
}
