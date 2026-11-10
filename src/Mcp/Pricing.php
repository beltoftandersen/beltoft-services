<?php
/**
 * What the MCP tools say about prices: pay as you go amounts, add-ons, a quote for a configuration, and the account's
 * card and credit.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Mcp;

use BeltoftServices\Account\DbPicker;
use BeltoftServices\Credit\Balance;
use BeltoftServices\Credit\Config;
use BeltoftServices\Product;
use BeltoftServices\Proxmox\Backup\Backups;
use BeltoftServices\Proxmox\DbDisk;
use BeltoftServices\Proxmox\Vm\Offers;
use BeltoftServices\Usage\Account;
use BeltoftServices\Usage\Breakdown;
use BeltoftServices\Usage\Estimate;
use BeltoftServices\Usage\Rates;

defined( 'ABSPATH' ) || exit;

/**
 * Read only. The amounts come from Rates and Breakdown, the same code the checkout uses, so Claude quotes what the
 * website shows. Prices exclude VAT. Monthly billing is not offered to customers (setting offer_monthly), so a plan
 * without a pay as you go price is left out unless the site shows Monthly.
 */
final class Pricing {

	/**
	 * The model in a few plain sentences, for the server instructions and the price tools.
	 */
	public static function note(): string {
		return __( 'Servers and databases are pay as you go: billed by the hour, and a month never costs more than the max per month. An hour that has started counts as a whole hour, and a stopped server is still billed until it is deleted (delete_server, delete_database); billing stops in the hour the service is deleted. The hours are invoiced monthly to the customer\'s saved card, which is required to order (account credit alone is not enough); VAT is added on the invoice, and every price here excludes VAT. Add-ons are billed by the hour like the service and capped the same way: a database can get extra storage (it can be added later in the dashboard and only made larger) and a public IPv4 address, a server can get daily backups and one extra disk. Use price_quote for the total of a plan with add-ons. Account credit, if the customer has some, is applied first on usage invoices. Business email is a monthly subscription, plugins are yearly and domains are yearly per ending.', 'beltoft-services' );
	}

	/**
	 * "€0.0226 per hour, max €16.50 per month, billed monthly for the hours used (excluding VAT)".
	 *
	 * @param float $hourly Per hour.
	 * @param float $cap    Monthly cap.
	 */
	public static function text( float $hourly, float $cap ): string {
		/* translators: 1: "€0.0226 per hour, max €16.50 per month", 2: "Billed monthly for the hours used" in lower case. */
		return sprintf( __( '%1$s, %2$s (excluding VAT)', 'beltoft-services' ), Rates::tile_terms( $hourly, $cap ), lcfirst( Rates::billed_note() ) );
	}

	/**
	 * The pay as you go price of something.
	 *
	 * @param float $hourly Per hour.
	 * @param float $cap    Max per month.
	 * @return array{hourly: float, max_per_month: float, currency: string, text: string, excl_vat: bool}
	 */
	public static function payg( float $hourly, float $cap ): array {
		return array(
			'hourly'        => round( $hourly, 4 ),
			'max_per_month' => round( $cap, 2 ),
			'currency'      => get_woocommerce_currency(),
			'text'          => self::text( $hourly, $cap ),
			'excl_vat'      => true,
		);
	}

	/**
	 * The price of an add-on from its max per month: the hour is the month divided by the hours of a month.
	 *
	 * @param float $cap Max per month.
	 */
	private static function adds( float $cap ): array {
		$hourly = round( $cap / Rates::HOURS, 8 );

		return array(
			'hourly'        => round( $hourly, 4 ),
			'max_per_month' => round( $cap, 2 ),
			'currency'      => get_woocommerce_currency(),
			/* translators: 1: "+€5 per month", 2: "€0.0068 per hour" */
			'text'          => sprintf( __( '%1$s, %2$s, billed with the service and capped (excluding VAT)', 'beltoft-services' ), Rates::plus_per_month( $cap ), Rates::per_hour( $hourly ) ),
			'excl_vat'      => true,
		);
	}

	/**
	 * What switching from one variation to another adds at most in a month.
	 *
	 * @param \WC_Product_Variation $from From.
	 * @param \WC_Product_Variation $to   To.
	 */
	public static function difference( \WC_Product_Variation $from, \WC_Product_Variation $to ): float {
		if ( Rates::is_usage( $to ) ) {
			return Rates::monthly_difference( $from, $to );
		}

		return Rates::cap( (float) wc_get_price_excluding_tax( $to ) - (float) wc_get_price_excluding_tax( $from ), Rates::premium( $to->get_parent_id() ) );
	}

	// -- Plans ------------------------------------------------------------------------------------

	/**
	 * The server plans that can be bought: pay as you go, with backups as an add-on.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function server_plans(): array {
		$out = array();

		foreach ( Offers::plans() as $plan ) {
			if ( empty( $plan['usage'] ) && ! Rates::monthly_offered() ) {
				continue;
			}

			$usage = (array) ( $plan['usage'] ?? array() );
			$row   = array(
				'variation_id' => (int) $plan['variation_id'],
				'name'         => (string) $plan['name'],
				'cores'        => (int) $plan['cores'],
				'memory_mb'    => (int) $plan['memory_mb'],
				'disk_gb'      => (int) $plan['disk_gb'],
				'in_stock'     => (bool) $plan['in_stock'],
			);

			if ( array() !== $usage ) {
				$row['price'] = self::payg( (float) $usage['hourly'], (float) $usage['cap'] );
			}

			if ( Rates::monthly_offered() ) {
				$row['monthly'] = array( 'price' => (float) $plan['price'], 'period' => (string) $plan['period'], 'excl_vat' => true );
			}

			$row['backups'] = self::server_backups( $plan );
			$row['options'] = ServerDisk::options( $plan );
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

		if ( empty( $plan['backups'] ) || (int) ( $usage['backups_variation_id'] ?? 0 ) < 1 ) {
			return array( 'available' => false );
		}

		$parts = self::parts_of( (int) $usage['backups_variation_id'] );

		return array(
			'available'         => true,
			'days_kept'         => (int) $plan['backups']['days'],
			'adds'              => null === $parts ? null : self::adds( (float) $parts['backups'] ),
			'total_with_backups' => self::payg( (float) $usage['backups_hourly'], (float) $usage['backups_cap'] ),
		);
	}

	/**
	 * The database plans that can be bought, each with the choices made at order time: the public IPv4 address and
	 * the extra storage steps.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function database_plans(): array {
		$out = array();

		foreach ( DbPicker::plans() as $plan ) {
			if ( empty( $plan['usage'] ) && ! Rates::monthly_offered() ) {
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

			if ( ! empty( $plan['usage'] ) ) {
				$row['price'] = self::payg( (float) $plan['usage']['hourly'], (float) $plan['usage']['cap'] );
			}

			if ( Rates::monthly_offered() ) {
				$row['monthly'] = array( 'price' => (float) $plan['price'], 'period' => (string) $plan['period'], 'excl_vat' => true );
			}

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
		$ip   = $bare instanceof \WC_Product_Variation ? Backups::sibling_by( $bare, 'public_ip', true ) : null;
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

		if ( $ip && is_array( $base ) ) {
			$with = self::database_parts( $plan_id, true, 0 );

			if ( is_array( $with ) && $with['ip'] > 0 ) {
				$out['public_ipv4'] = self::adds( (float) $with['ip'] );
			}
		}

		foreach ( $bare instanceof \WC_Product_Variation ? array_keys( DbDisk::ladder( $bare ) ) : array() as $gb ) {
			if ( $gb < 1 ) {
				continue;
			}

			$parts = self::database_parts( $plan_id, false, (int) $gb );

			if ( is_array( $parts ) && $parts['storage'] > 0 ) {
				$out['extra_storage'][] = array( 'extra_gb' => (int) $gb, 'data_disk_gb' => (int) $gb + $disk, 'adds' => self::adds( (float) $parts['storage'] ) );
			}
		}

		return $out;
	}

	/**
	 * The Breakdown parts of a database plan with the choices.
	 *
	 * @param int  $plan_id Plan id.
	 * @param bool $ip      With the public IPv4 address.
	 * @param int  $gb      Extra storage in GB.
	 * @return array<string, mixed>|string The parts, or the refusal.
	 */
	private static function database_parts( int $plan_id, bool $ip, int $gb ) {
		$target = wc_get_product( $plan_id );

		if ( ! $target instanceof \WC_Product_Variation ) {
			return __( 'This plan is not offered.', 'beltoft-services' );
		}

		if ( $ip ) {
			$target = Backups::sibling_by( $target, 'public_ip', true );

			if ( null === $target ) {
				return __( 'This plan has no public IPv4 address option.', 'beltoft-services' );
			}
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

		$usage = Rates::sibling( $target, true );
		$parts = $usage ? Breakdown::parts( $usage ) : null;

		return $parts ?? __( 'This plan has no pay as you go price.', 'beltoft-services' );
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

	// -- The quote --------------------------------------------------------------------------------

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
			if ( $ip || $gb > 0 ) {
				return $fail( __( 'A public IPv4 address and extra storage are for databases. A server plan can have daily backups and an extra disk (extra_disk_gb).', 'beltoft-services' ) );
			}

			$disk = null;

			if ( $disk_gb > 0 ) {
				$disk = ServerDisk::pick( $server, $disk_gb );

				if ( is_string( $disk ) ) {
					return $fail( $disk );
				}
			}

			$usage = (array) ( $server['usage'] ?? array() );
			$vid   = $backups ? (int) ( $usage['backups_variation_id'] ?? 0 ) : (int) ( $usage['variation_id'] ?? 0 );

			if ( $vid < 1 ) {
				return $fail( $backups ? __( 'This plan has no daily backups.', 'beltoft-services' ) : __( 'This plan has no pay as you go price.', 'beltoft-services' ) );
			}

			$parts = self::parts_of( $vid );
			$name  = (string) $server['name'];
			$kind  = 'server';

			if ( null !== $parts && $disk instanceof \WC_Product_Variation ) {
				// The disk is a cart line of its own at checkout; its hourly price and cap are added to the server's.
				$extra            = ServerDisk::parts( $disk );
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

			$got = self::database_parts( $id, $ip, $gb );

			if ( is_string( $got ) ) {
				return $fail( $got );
			}

			$parts = $got;
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

	// -- Add-ons of one service --------------------------------------------------------------------

	/**
	 * An add-on that is a variation of its own (an extra disk, a snapshot pack): what it costs at most a month, and an hour.
	 *
	 * @param \WC_Product_Variation $v Variation.
	 * @return array<string, mixed>
	 */
	public static function variation_price( \WC_Product_Variation $v ): array {
		$monthly = Rates::is_usage( $v ) ? Rates::monthly( $v ) : (float) wc_get_price_excluding_tax( $v );

		return self::adds( Rates::cap( (float) $monthly, Rates::premium( $v->get_parent_id() ) ) );
	}

	/**
	 * What one backups switch adds, for the add-ons of a service.
	 *
	 * @param \WC_Product_Variation $from Current variation.
	 * @param \WC_Product_Variation $to   Variation with the add-on.
	 * @return array<string, mixed>
	 */
	public static function addon_between( \WC_Product_Variation $from, \WC_Product_Variation $to ): array {
		return self::adds( max( 0.0, self::difference( $from, $to ) ) );
	}

	// -- The account -------------------------------------------------------------------------------

	/**
	 * Whether a card is saved, the credit and the usage so far. The card check reads the usage account only (no call to
	 * the payment provider), so it is fast; it says what the account can be charged for, not that the card is valid today.
	 *
	 * @param int $user_id Customer.
	 * @return array<string, mixed>
	 */
	public static function account( int $user_id ): array {
		$currency = get_woocommerce_currency();
		$account  = Account::for_user( $user_id, Account::LIVE );
		$out      = array(
			'card_saved'  => null !== $account && Account::chargeable( $account ),
			'card_note'   => 'A saved card is required to order servers and databases. The check reads the usage account, not the payment provider.',
			'credit'      => array( 'enabled' => Config::enabled() ),
			'vat_note'    => 'VAT is added on the usage invoice. Prices elsewhere exclude VAT.',
		);

		if ( Config::enabled() ) {
			$cents                    = Balance::get( $user_id );
			$out['credit']['balance'] = round( $cents / 100, 2 );
			$out['credit']['text']    = Rates::money( $cents / 100, 2, $currency );
			$out['credit']['note']    = 'Credit is applied first on usage invoices as "Paid from account credit"; the rest goes to the card. Top-ups carry no VAT. Credit alone is not enough to order.';
		}

		$estimate = Estimate::for_user( $user_id );

		$out['usage_this_month'] = $estimate ? array(
			'so_far' => Estimate::money( (float) $estimate['total'], (string) $estimate['currency'] ),
			'note'   => 'Excluding VAT, an estimate. Each service is capped at its max per month (usage_summary shows which lines reached it).',
		) : null;

		$minimum = \BeltoftServices\Usage\Minimum::amount();

		$out['usage_minimum'] = array(
			'minimum' => $minimum > 0 ? Rates::money( $minimum, 2, $currency ) : null,
			'carried' => $estimate && $estimate['carried'] > 0 ? Estimate::money( (float) $estimate['carried'], (string) $estimate['currency'] ) : null,
			'note'    => 'Including VAT. When the amount to charge the card for a month is under the minimum, it is added to the next month\'s invoice instead.',
		);

		return $out;
	}
}
