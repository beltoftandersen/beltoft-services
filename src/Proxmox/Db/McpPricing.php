<?php
/**
 * What the MCP tools say about the prices of a managed database: the plans with their options, the address and the parts of a quote.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Proxmox\Db;

use BeltoftServices\Mcp\Pricing;
use BeltoftServices\Product;
use BeltoftServices\Proxmox\Ipv4\Ipv4Cart;
use BeltoftServices\Usage\Breakdown;
use BeltoftServices\Usage\Rates;

defined( 'ABSPATH' ) || exit;

/**
 * Read only. The amounts come from Rates and Breakdown, the same code the checkout uses (the generic lines are Mcp\Pricing).
 */
final class McpPricing {

	/**
	 * The database plans that can be bought, each with the choices made at order time: the public IPv4 address and
	 * the extra storage steps.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function database_plans(): array {
		$out = array();

		foreach ( DbPicker::plans() as $plan ) {
			if ( empty( $plan['usage'] ) ) {
				continue;
			}

			$row = array(
				'variation_id' => (int) $plan['variation_id'],
				'name'         => (string) $plan['name'],
				'engine'       => (string) $plan['engine'],
				'version'      => (int) $plan['major'],
				'cores'        => (int) $plan['cores'],
				'memory_mb'    => (int) $plan['memory_mb'],
				'disk_gb'      => (int) $plan['disk_gb'],
				'backup_days'  => (int) $plan['backup_days'],
				'in_stock'     => (bool) $plan['in_stock'],
			);

			$row['price']   = Pricing::payg( (float) $plan['usage']['hourly'], (float) $plan['usage']['cap'] );
			$row['options'] = self::database_options( (int) $plan['variation_id'] );
			$out[]          = $row;
		}

		return $out;
	}

	/**
	 * The options of a database plan at order time.
	 *
	 * @param int $plan_id Plan (variation) id from database_plans.
	 * @return array<string, mixed>
	 */
	private static function database_options( int $plan_id ): array {
		$bare = wc_get_product( $plan_id );
		$disk = $bare instanceof \WC_Product_Variation ? (int) ( Product::plan( $bare->get_parent_id(), $plan_id )['disk_gb'] ?? 0 ) : 0;
		$base = self::database_parts( $plan_id, false, 0 );
		$out  = array(
			'public_ipv4'     => null,
			'extra_storage'   => array(),
			'storage_note'    => sprintf(
				/* translators: 1: largest data disk in GB, 2: step in GB */
				__( 'Extra storage is chosen in steps of %2$d GB, up to a data disk of %1$d GB in all. It can also be added later in the dashboard, only made larger and never smaller, and it is billed by the hour with the database and capped.', 'beltoft-services' ),
				DbDisk::max_gb(),
				DbDisk::step_gb()
			),
			'max_data_disk_gb' => DbDisk::max_gb(),
		);

		foreach ( $bare instanceof \WC_Product_Variation ? array_keys( DbDisk::ladder( $bare ) ) : array() as $gb ) {
			if ( $gb < 1 ) {
				continue;
			}

			$parts = self::database_parts( $plan_id, false, (int) $gb );

			if ( is_array( $parts ) && $parts['storage'] > 0 ) {
				$out['extra_storage'][] = array( 'extra_gb' => (int) $gb, 'data_disk_gb' => (int) $gb + $disk, 'adds' => Pricing::adds( (float) $parts['storage'] ) );
			}
		}

		return $out;
	}

	/**
	 * Why a public IPv4 address cannot be put in an order made here: it is an add-on with its own subscription, bought from the
	 * server or database page in the dashboard.
	 */
	public static function ipv4_text(): string {
		return __( 'An IPv4 address is an add-on with its own subscription. Add it from the server or the database in the dashboard, once the service is ready.', 'beltoft-services' );
	}

	/**
	 * The pay as you go IPv4 address that can be ordered with a new database now, or the refusal.
	 *
	 * @return \WC_Product_Variation|string
	 */
	public static function ipv4_twin() {
		$twin = Ipv4Cart::product( true );

		if ( ! $twin instanceof \WC_Product_Variation || null === Rates::for_usage( $twin ) ) {
			return self::ipv4_text();
		}

		return Ipv4Cart::in_stock() ? $twin : __( 'No IPv4 address is free right now.', 'beltoft-services' );
	}

	/**
	 * The Breakdown parts of a database plan with the choices.
	 *
	 * @param int  $plan_id Plan id.
	 * @param bool $ip      Unused (an address is added to the quote by quote()).
	 * @param int  $gb      Extra storage in GB.
	 * @return array<string, mixed>|string The parts, or the refusal.
	 */
	public static function database_parts( int $plan_id, bool $ip, int $gb ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- kept for the callers.
		$target = wc_get_product( $plan_id );

		if ( ! $target instanceof \WC_Product_Variation ) {
			return __( 'This plan is not offered.', 'beltoft-services' );
		}

		if ( $gb > 0 ) {
			$step = DbDisk::ladder( $target )[ $gb ] ?? null;

			if ( null === $step ) {
				return sprintf(
					/* translators: %s: the extra storage sizes in GB that are offered, such as "50, 100" */
					__( 'That much extra storage is not offered with this plan. The steps are, in GB: %s.', 'beltoft-services' ),
					implode( ', ', array_filter( array_keys( DbDisk::ladder( $target ) ) ) )
				);
			}

			$target = $step;
		}

		$parts = Breakdown::parts( $target );

		return $parts ?? __( 'This plan has no pay as you go price.', 'beltoft-services' );
	}
}
