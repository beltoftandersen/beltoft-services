<?php
/**
 * The extra disk of a new server, for the MCP tools: which sizes can be ordered, and what they cost.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Mcp;

use BeltoftServices\Product;
use BeltoftServices\Proxmox\Disk\DiskField;
use BeltoftServices\Settings;
use BeltoftServices\Usage\Rates;

defined( 'ABSPATH' ) || exit;

/**
 * The sizes are those the checkout's extra disk box offers a pay as you go server (DiskField::sizes_for( true ): the pay as you
 * go twin of each monthly size, price 0 at checkout, billed by the hour). Room on a node is not asked: capacity never
 * stops an order.
 */
final class ServerDisk {

	/**
	 * The pay as you go disk sizes of a plan, by extra GB.
	 *
	 * @return array<int, \WC_Product_Variation> Sorted by size.
	 */
	public static function steps(): array {
		$out = array();

		foreach ( DiskField::sizes_for( true ) as $variation ) {
			$gb = (int) ( Product::plan( $variation->get_parent_id(), $variation->get_id() )['disk_gb'] ?? 0 );

			if ( $gb < 1 || isset( $out[ $gb ] ) || null === Rates::for_usage( $variation ) ) {
				continue;
			}

			$out[ $gb ] = $variation;
		}

		ksort( $out );

		return $out;
	}

	/**
	 * Checks a size for a plan.
	 *
	 * @param array<string, mixed> $plan Plan from Offers.
	 * @param int                  $gb   Extra disk in GB.
	 * @return \WC_Product_Variation|string The disk variation, or the refusal.
	 */
	public static function pick( array $plan, int $gb ) {
		$steps = self::steps();

		if ( isset( $steps[ $gb ] ) ) {
			return $steps[ $gb ];
		}

		if ( array() === $steps ) {
			return __( 'No extra disk can be ordered with this plan here right now. Order the server without it; a disk can be added in the dashboard.', 'beltoft-services' );
		}

		return sprintf(
			/* translators: %s: the extra disk sizes in GB that are offered, such as "50, 100" */
			__( 'That extra disk size is not offered with this plan. The sizes are, in GB: %s.', 'beltoft-services' ),
			implode( ', ', array_keys( $steps ) )
		);
	}

	/**
	 * What a disk adds: its hourly price and its max per month.
	 *
	 * @param \WC_Product_Variation $disk Pay as you go disk variation.
	 * @return array{gb: int, cap: float, hourly: float}
	 */
	public static function parts( \WC_Product_Variation $disk ): array {
		$rates = (array) Rates::for_usage( $disk );

		return array(
			'gb'     => (int) ( Product::plan( $disk->get_parent_id(), $disk->get_id() )['disk_gb'] ?? 0 ),
			'cap'    => (float) ( $rates['cap'] ?? 0 ),
			'hourly' => (float) ( $rates['hourly'] ?? 0 ),
		);
	}

	/**
	 * The most extra disks that a customer can have: the server's setting, and one is ordered with a new server.
	 */
	public static function max_extra(): int {
		return max( 1, (int) Settings::get( 'disk_max_extra' ) );
	}

	/**
	 * The disk options of a server plan, for catalog and list_server_plans.
	 *
	 * @param array<string, mixed> $plan Plan from Offers.
	 * @return array<string, mixed>
	 */
	public static function options( array $plan ): array {
		$sizes = array();

		foreach ( self::steps() as $gb => $variation ) {
			$sizes[] = array(
				'extra_gb'     => (int) $gb,
				'variation_id' => $variation->get_id(),
				'adds'         => Pricing::variation_price( $variation ),
			);
		}

		return array(
			'extra_disk'              => $sizes,
			'max_extra_disks_at_order' => 1,
			'max_extra_disks_per_server' => self::max_extra(),
			'disk_note'               => self::note(),
		);
	}

	/**
	 * What the dashboard offers for the disks of a server (DiskPanels::server): said as it is.
	 */
	public static function note(): string {
		return sprintf(
			/* translators: %d: most extra disks a server can have */
			__( 'One extra disk can be ordered with a new server, and it is billed by the hour with the server and capped. In the dashboard, the server page lists the extra disk sizes that can be added to a ready server, up to %d extra disks per server, and an extra disk can be removed there. An extra disk can be made larger there to the next size up, never smaller.', 'beltoft-services' ),
			self::max_extra()
		);
	}
}
