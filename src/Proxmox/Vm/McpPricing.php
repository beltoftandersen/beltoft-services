<?php
/**
 * What the MCP tools say about the prices of a server: the plans, the backups, and the quote of a plan with its add-ons.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Proxmox\Vm;

use BeltoftServices\Mcp\Pricing;
use BeltoftServices\Proxmox\Db\DbPicker;
use BeltoftServices\Proxmox\Db\McpPricing as DbPricing;
use BeltoftServices\Proxmox\Disk\McpServerDisk;
use BeltoftServices\Usage\Breakdown;
use BeltoftServices\Usage\Rates;

defined( 'ABSPATH' ) || exit;

/**
 * Read only. The amounts come from Rates and Breakdown, the same code the checkout uses, so Claude quotes what the website
 * shows. Prices exclude VAT. Only pay as you go plans are listed: a plan of a monthly product is not sold here. The quote
 * covers a database plan too (it asks the database's own pricing for its parts).
 */
final class McpPricing {

	/**
	 * The server plans that can be bought: pay as you go, with backups as an add-on.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function server_plans(): array {
		$out = array();

		foreach ( Offers::plans() as $plan ) {
			if ( empty( $plan['usage'] ) ) {
				continue;
			}

			$row   = array(
				'variation_id' => (int) $plan['variation_id'],
				'name'         => (string) $plan['name'],
				'cores'        => (int) $plan['cores'],
				'memory_mb'    => (int) $plan['memory_mb'],
				'disk_gb'      => (int) $plan['disk_gb'],
				'in_stock'     => (bool) $plan['in_stock'],
			);

			$row['price']   = Pricing::payg( (float) $plan['usage']['hourly'], (float) $plan['usage']['cap'] );
			$row['backups'] = self::server_backups( $plan );
			$row['options'] = McpServerDisk::options( $plan );
			$out[]          = $row;
		}

		return $out;
	}

	/**
	 * The daily backups of a server plan: what they add, and the total with them.
	 *
	 * @param array<string, mixed> $plan Plan from Offers.
	 * @return array<string, mixed>
	 */
	public static function server_backups( array $plan ): array {
		$usage = (array) ( $plan['usage'] ?? array() );

		if ( empty( $plan['backups'] ) || array() === $usage ) {
			return array( 'available' => false );
		}

		$parts = self::parts_of( (int) $plan['backups']['variation_id'] );

		return array(
			'available'         => true,
			'days_kept'         => (int) $plan['backups']['days'],
			'adds'              => null === $parts ? null : Pricing::adds( (float) $parts['backups'] ),
			'total_with_backups' => Pricing::payg( (float) $usage['backups_hourly'], (float) $usage['backups_cap'] ),
		);
	}

	/**
	 * Parts of a pay as you go variation by id.
	 *
	 * @param int $variation_id Usage variation id.
	 * @return array<string, mixed>|null
	 */
	private static function parts_of( int $variation_id ): ?array {
		$v = wc_get_product( $variation_id );

		return $v instanceof \WC_Product_Variation ? Breakdown::parts( $v ) : null;
	}

	/**
	 * The price of a plan with add-ons, as the checkout shows it. Nothing is ordered or put in a cart.
	 *
	 * @param array<string, mixed> $args plan, public_ipv4, extra_storage_gb, backups, extra_disk_gb.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function quote( array $args ) {
		$id      = (int) ( $args['plan'] ?? 0 );
		$ip      = ! empty( $args['public_ipv4'] );
		$gb      = max( 0, (int) ( $args['extra_storage_gb'] ?? 0 ) );
		$backups = ! empty( $args['backups'] );
		$disk_gb = max( 0, (int) ( $args['extra_disk_gb'] ?? 0 ) );
		$fail    = static fn( string $m ) => new \WP_Error( 'beltoft_services_tool', $m );
		$server  = Offers::find( $id );
		$name    = '';
		$parts   = null;

		if ( $server ) {
			if ( $ip ) {
				return $fail( DbPricing::ipv4_text() );
			}

			if ( $gb > 0 ) {
				return $fail( __( 'Extra storage is for databases. A server plan can have daily backups and an extra disk (extra_disk_gb).', 'beltoft-services' ) );
			}

			$disk = null;

			if ( $disk_gb > 0 ) {
				$disk = McpServerDisk::pick( $server, $disk_gb );

				if ( is_string( $disk ) ) {
					return $fail( $disk );
				}
			}

			if ( empty( $server['usage'] ) || ( $backups && empty( $server['backups'] ) ) ) {
				return $fail( $backups && ! empty( $server['usage'] ) ? __( 'This plan has no daily backups.', 'beltoft-services' ) : __( 'This plan has no pay as you go price.', 'beltoft-services' ) );
			}

			$parts = self::parts_of( $backups ? (int) $server['backups']['variation_id'] : (int) $server['variation_id'] );
			$name  = (string) $server['name'];
			$kind  = 'server';

			if ( null !== $parts && $disk instanceof \WC_Product_Variation ) {
				// The disk is a cart line of its own at checkout; its hourly price and cap are added to the server's.
				$extra            = McpServerDisk::parts( $disk );
				$parts['disk']    = $extra['cap'];
				$parts['disk_gb'] = $extra['gb'];
				$parts['max']    += $extra['cap'];
				$parts['hourly'] += $extra['hourly'];
			}
		} elseif ( isset( DbPicker::plans()[ $id ] ) ) {
			if ( $disk_gb > 0 ) {
				return $fail( __( 'An extra disk is for servers. A database plan has extra storage (extra_storage_gb).', 'beltoft-services' ) );
			}

			if ( $backups ) {
				return $fail( __( 'Backups of a database are included.', 'beltoft-services' ) );
			}

			$address = $ip ? DbPricing::ipv4_twin() : null;

			if ( is_string( $address ) ) {
				return $fail( $address );
			}

			$got = DbPricing::database_parts( $id, false, $gb );

			if ( is_string( $got ) ) {
				return $fail( $got );
			}

			$parts = $got;

			if ( $address instanceof \WC_Product_Variation ) {
				// The address is a cart line of its own at checkout, billed by the hour with the database: its price and cap are added.
				$rates          = (array) Rates::for_usage( $address );
				$parts['ip']    = (float) $parts['ip'] + (float) ( $rates['cap'] ?? 0 );
				$parts['max']    = (float) $parts['max'] + (float) ( $rates['cap'] ?? 0 );
				$parts['hourly'] = (float) $parts['hourly'] + (float) ( $rates['hourly'] ?? 0 );
			}
			$name  = (string) DbPicker::plans()[ $id ]['name'];
			$kind  = 'database';
		} else {
			return $fail( __( 'That plan is not offered. Use a variation_id from catalog (server_plans or database_plans).', 'beltoft-services' ) );
		}

		if ( null === $parts ) {
			return $fail( __( 'This plan has no pay as you go price.', 'beltoft-services' ) );
		}

		$rows    = Breakdown::rows( $parts, $name );
		$amounts = array_values( array_filter( array( $parts['plan'], $parts['ip'], $parts['storage'], $parts['backups'], $parts['disk'] ?? 0 ), static fn( $a ) => $a > 0 ) );
		$lines   = array();

		foreach ( $rows as $i => $row ) {
			$lines[] = array(
				'label'     => $row[0],
				'per_month' => round( (float) $amounts[ $i ], 2 ),
				'text'      => $row[1],
			);
		}

		return array(
			'kind'             => $kind,
			'plan'             => $name,
			'lines'            => $lines,
			'max_per_month'    => round( (float) $parts['max'], 2 ),
			'max_per_month_text' => Rates::cap_money( (float) $parts['max'] ),
			'hourly'           => round( (float) $parts['hourly'], 4 ),
			/* translators: %s: price per hour such as €0.0740 */
			'hourly_text'      => sprintf( __( 'That is %s per hour, billed for the hours used.', 'beltoft-services' ), Rates::money( (float) $parts['hourly'], 4 ) ),
			'currency'         => get_woocommerce_currency(),
			'excl_vat'         => true,
			'note'             => __( 'Billed by the hour and never more than the max per month. Prices exclude VAT. Nothing was ordered.', 'beltoft-services' ),
		);
	}
}
