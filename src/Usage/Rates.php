<?php
/**
 * Pay-as-you-go prices: the hourly rate and the monthly cap.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Usage;

use BeltoftServices\Product;
use BeltoftServices\Apps\AppLicenceDriver;
use BeltoftServices\Proxmox\Backup\Backups;
use BeltoftServices\Proxmox\Db\DbDriver;
use BeltoftServices\Proxmox\Disk\DiskDriver;
use BeltoftServices\Proxmox\Vm\ProxmoxDriver;
use BeltoftServices\Proxmox\Snapshot\SnapshotDriver;
use BeltoftServices\Resource;

defined( 'ABSPATH' ) || exit;

/**
 * A pay-as-you-go variation carries the meta `_beltoft_services_billing` = usage and a price of 0 at
 * checkout; it is the sibling of a monthly variation (the same parent, the same plan, the same attributes
 * except "Billing"). The hourly rate is that monthly variation's price (excluding tax) x (1 + premium) / 730,
 * the cap per resource, item and month is the monthly price x (1 + premium). The premium is a product
 * setting (`_beltoft_services_usage_premium`, percent, 10 when empty).
 */
final class Rates {

	public const META_BILLING = '_beltoft_services_billing';
	public const META_PREMIUM = '_beltoft_services_usage_premium';
	public const META_MONTHLY = '_beltoft_services_usage_monthly';

	public const DEFAULT_PREMIUM = 10.0;

	/**
	 * Hours in a month for the rate (365 x 24 / 12).
	 */
	public const HOURS = 730;

	/**
	 * Is this variation (or product) sold pay as you go?
	 *
	 * @param \WC_Product|int|false $product Product or id.
	 */
	public static function is_usage( $product ): bool {
		$product = $product instanceof \WC_Product ? $product : wc_get_product( (int) $product );

		return $product instanceof \WC_Product && 'usage' === (string) $product->get_meta( self::META_BILLING );
	}

	/**
	 * The premium in percent for a product (or variation).
	 *
	 * @param int $product_id Product id.
	 */
	public static function premium( int $product_id ): float {
		$parent = 'product_variation' === get_post_type( $product_id ) ? (int) wp_get_post_parent_id( $product_id ) : $product_id;
		$value  = (string) get_post_meta( $parent, self::META_PREMIUM, true );

		return is_numeric( $value ) ? max( 0.0, (float) $value ) : self::DEFAULT_PREMIUM;
	}

	/**
	 * Price per hour for a monthly price.
	 *
	 * @param float $monthly Monthly price.
	 * @param float $premium Percent.
	 */
	public static function hourly( float $monthly, float $premium ): float {
		return round( $monthly * ( 1 + $premium / 100 ) / self::HOURS, 8 );
	}

	/**
	 * The most one resource's item costs in a month.
	 *
	 * @param float $monthly Monthly price.
	 * @param float $premium Percent.
	 */
	public static function cap( float $monthly, float $premium ): float {
		return round( $monthly * ( 1 + $premium / 100 ), 2 );
	}

	/**
	 * An amount for people as plain text, the way the shop shows prices ("€16.50", "€0.0226"): wc_price without its
	 * markup. Spaces in it are non-breaking, so an amount never wraps inside.
	 *
	 * @param float  $amount   Amount.
	 * @param int    $decimals Decimals.
	 * @param string $currency Currency code, '' for the shop's.
	 */
	public static function money( float $amount, int $decimals = 2, string $currency = '' ): string {
		$args = array( 'decimals' => $decimals );

		if ( '' !== $currency ) {
			$args['currency'] = $currency;
		}

		$text = html_entity_decode( wp_strip_all_tags( wc_price( $amount, $args ) ), ENT_QUOTES, 'UTF-8' );

		return str_replace( ' ', "\xC2\xA0", trim( $text ) );
	}

	/**
	 * A monthly cap for people: a whole amount has no decimals ("€55"), any other has two ("€16.50").
	 *
	 * @param float $cap Monthly cap.
	 */
	public static function cap_money( float $cap ): string {
		return self::money( $cap, abs( $cap - round( $cap ) ) < 0.005 ? 0 : 2 );
	}

	/**
	 * "€45 / month": a monthly amount in whole euros (see cap_money()) in the order summary.
	 *
	 * @param float $amount Monthly amount.
	 */
	public static function per_month_line( float $amount ): string {
		/* translators: %s: monthly amount such as €45 */
		return sprintf( __( '%s / month', 'beltoft-services' ), self::cap_money( $amount ) );
	}

	/**
	 * "+€5 / month": an add-on's monthly amount in the order summary.
	 *
	 * @param float $amount Monthly amount.
	 */
	public static function plus_per_month_line( float $amount ): string {
		/* translators: %s: monthly amount such as €5 */
		return sprintf( __( '+%s / month', 'beltoft-services' ), self::cap_money( $amount ) );
	}

	/**
	 * "+€5 per month": an add-on's monthly amount in a label.
	 *
	 * @param float $amount Monthly amount.
	 */
	public static function plus_per_month( float $amount ): string {
		/* translators: %s: monthly amount such as €5 */
		return sprintf( __( '+%s per month', 'beltoft-services' ), self::cap_money( $amount ) );
	}

	/**
	 * The most an add-on costs in a month: the difference between two pay-as-you-go variations, from their monthly
	 * siblings and the product's premium (the same figures as the hourly rate and the cap).
	 *
	 * @param \WC_Product_Variation $from From.
	 * @param \WC_Product_Variation $to   To.
	 */
	public static function monthly_difference( \WC_Product_Variation $from, \WC_Product_Variation $to ): float {
		$was = self::monthly( $from );
		$now = self::monthly( $to );

		return null === $was || null === $now ? 0.0 : self::cap( $now - $was, self::premium( $to->get_parent_id() ) );
	}

	/**
	 * "+€5 per month" for an add-on that is a pay-as-you-go variation, or null when the target is not pay as you go
	 * (the monthly label keeps its wording).
	 *
	 * @param \WC_Product_Variation $from From.
	 * @param \WC_Product_Variation $to   To.
	 */
	public static function addon_per_month( \WC_Product_Variation $from, \WC_Product_Variation $to ): ?string {
		return self::is_usage( $to ) ? self::plus_per_month( self::monthly_difference( $from, $to ) ) : null;
	}

	/**
	 * The pay-as-you-go offer next to a monthly plan: the usage sibling that can be bought, and what it costs
	 * (hourly and the monthly cap, excluding tax). Null for a pay-as-you-go variation, a plan with no usage
	 * sibling, one that cannot be bought, or a monthly price of 0.
	 *
	 * @param \WC_Product_Variation $monthly Monthly variation.
	 * @param bool                  $buyable Whether the usage sibling must be purchasable (false: for showing prices, a sold-out plan keeps its price).
	 * @return array{variation_id: int, monthly: float, hourly: float, cap: float, premium: float}|null
	 */
	public static function for_plan( \WC_Product_Variation $monthly, bool $buyable = true ): ?array {
		if ( self::is_usage( $monthly ) ) {
			return null;
		}

		$usage = self::sibling( $monthly, true );
		$price = (float) wc_get_price_excluding_tax( $monthly );

		if ( ! $usage || ( $buyable && ! $usage->is_purchasable() ) || $price <= 0 ) {
			return null;
		}

		$premium = self::premium( $monthly->get_parent_id() );

		return array(
			'variation_id' => $usage->get_id(),
			'monthly'      => $price,
			'hourly'       => self::hourly( $price, $premium ),
			'cap'          => self::cap( $price, $premium ),
			'premium'      => $premium,
		);
	}

	/**
	 * "Or €0.0226/hour, max €16.50/month, pay as you go" for a monthly variation, or '' when there is no offer.
	 * The slash is followed by a word joiner, so a line never breaks inside "€0.0226/hour".
	 *
	 * @param \WC_Product_Variation $monthly Monthly variation.
	 */
	public static function line( \WC_Product_Variation $monthly ): string {
		$rates = self::for_plan( $monthly );

		if ( null === $rates ) {
			return '';
		}

		/* translators: 1: hourly price such as €0.0226, 2: monthly cap such as €16.50 */
		return str_replace( '/', "/\u{2060}", sprintf( __( 'Or %1$s/hour, max %2$s/month, pay as you go', 'beltoft-services' ), self::money( $rates['hourly'], 4 ), self::cap_money( $rates['cap'] ) ) );
	}

	/**
	 * The terms of pay as you go, next to the choice: "€0.0226 per hour, max €16.50 per month excluding VAT".
	 *
	 * @param float $hourly Price per hour.
	 * @param float $cap    Monthly cap.
	 */
	public static function terms( float $hourly, float $cap ): string {
		/* translators: %s: the terms, such as "€0.0226 per hour, max €16.50 per month" */
		return sprintf( __( '%s excluding VAT', 'beltoft-services' ), self::tile_terms( $hourly, $cap ) );
	}

	/**
	 * The rate and the cap of a pay-as-you-go variation (excluding tax): its monthly sibling's price and the
	 * product's premium. Null when the variation is not pay as you go or has no monthly price.
	 *
	 * @param mixed $usage Product of a line.
	 * @return array{hourly: float, cap: float}|null
	 */
	public static function for_usage( $usage ): ?array {
		if ( ! $usage instanceof \WC_Product_Variation || ! self::is_usage( $usage ) ) {
			return null;
		}

		$monthly = self::monthly( $usage );

		if ( null === $monthly || $monthly <= 0 ) {
			return null;
		}

		$premium = self::premium( $usage->get_parent_id() );

		return array( 'hourly' => self::hourly( $monthly, $premium ), 'cap' => self::cap( $monthly, $premium ) );
	}

	/**
	 * The one sentence that says how pay as you go is billed.
	 */
	public static function billed_note(): string {
		return __( 'Billed monthly for the hours used', 'beltoft-services' );
	}

	/**
	 * Is the Monthly option shown to customers (setting)? Off, new servers are sold pay as you go only.
	 */
	public static function monthly_offered(): bool {
		return (bool) \BeltoftServices\Settings::get( 'offer_monthly' );
	}

	/**
	 * "€0.0829 per hour".
	 *
	 * @param float $hourly Price per hour.
	 */
	public static function per_hour( float $hourly ): string {
		/* translators: %s: price per hour such as €0.0829 */
		return sprintf( __( '%s per hour', 'beltoft-services' ), self::money( $hourly, 4 ) );
	}

	/**
	 * "max €55.00 per month".
	 *
	 * @param float $cap Monthly cap.
	 */
	public static function max_per_month( float $cap ): string {
		/* translators: %s: monthly cap such as €55.00 */
		return sprintf( __( 'max %s per month', 'beltoft-services' ), self::cap_money( $cap ) );
	}

	/**
	 * The two billing tiles' first line for pay as you go: "€0.0829 per hour, max €55.00 per month".
	 *
	 * @param float $hourly Price per hour.
	 * @param float $cap    Monthly cap.
	 */
	public static function tile_terms( float $hourly, float $cap ): string {
		/* translators: 1: "€0.0829 per hour", 2: "max €55.00 per month" */
		return sprintf( __( '%1$s, %2$s', 'beltoft-services' ), self::per_hour( $hourly ), self::max_per_month( $cap ) );
	}

	/**
	 * The monthly tile's line: "€55.00 per month, paid in advance".
	 *
	 * @param float $monthly Monthly price.
	 */
	public static function tile_monthly( float $monthly ): string {
		/* translators: %s: monthly price such as €55.00 */
		return sprintf( __( '%s per month, paid in advance', 'beltoft-services' ), self::money( $monthly ) );
	}

	/**
	 * The name of the Billing attribute key of a variation, or null.
	 *
	 * @param array<string, mixed> $attributes Variation attributes.
	 */
	public static function billing_key( array $attributes ): ?string {
		foreach ( array_keys( $attributes ) as $key ) {
			if ( 1 === preg_match( '/^(attribute_)?(pa_)?billing$/D', (string) $key ) ) {
				return (string) $key;
			}
		}

		return null;
	}

	/**
	 * The other billing model of the same plan: the variation of the same parent that differs only in the
	 * Billing attribute (as Backups::sibling() finds the other backups state) and has the same plan.
	 *
	 * @param \WC_Product_Variation $v     Variation.
	 * @param bool                  $usage Whether the sibling is pay as you go.
	 */
	public static function sibling( \WC_Product_Variation $v, bool $usage ): ?\WC_Product_Variation {
		$parent = wc_get_product( $v->get_parent_id() );

		if ( ! $parent instanceof \WC_Product_Variable || self::is_usage( $v ) === $usage ) {
			return null;
		}

		$mine = $v->get_attributes();
		$own  = Product::plan( $parent->get_id(), $v->get_id() );

		foreach ( $parent->get_children() as $id ) {
			$other = wc_get_product( $id );

			if ( ! $other instanceof \WC_Product_Variation || $other->get_id() === $v->get_id() || 'publish' !== $other->get_status() || self::is_usage( $other ) !== $usage ) {
				continue;
			}

			$theirs = $other->get_attributes();
			$billing = self::billing_key( $mine ) ?? self::billing_key( $theirs );

			foreach ( array_unique( array_merge( array_keys( $mine ), array_keys( $theirs ) ) ) as $key ) {
				if ( $key !== $billing && (string) ( $mine[ $key ] ?? '' ) !== (string) ( $theirs[ $key ] ?? '' ) ) {
					continue 2;
				}
			}

			if ( Product::plan( $parent->get_id(), $other->get_id() ) === $own ) {
				return $other;
			}
		}

		return null;
	}

	/**
	 * The monthly price (excluding tax) a pay-as-you-go variation is priced from: its monthly sibling's
	 * price, or else the variation's own meta `_beltoft_services_usage_monthly`. Null when neither exists.
	 *
	 * @param \WC_Product_Variation $v Pay-as-you-go variation.
	 */
	public static function monthly( \WC_Product_Variation $v ): ?float {
		$sibling = self::sibling( $v, false );

		if ( $sibling && '' !== (string) $sibling->get_price() ) {
			return (float) wc_get_price_excluding_tax( $sibling );
		}

		$meta = (string) $v->get_meta( self::META_MONTHLY );

		return is_numeric( $meta ) ? (float) $meta : null;
	}

	/**
	 * What a pay-as-you-go resource is billed for each hour: a list of items with their quantity, unit and
	 * rate per unit and hour.
	 *
	 * - a database: one `database` item at the plan's variation price (public IP and extra storage included) / 730;
	 * - a server (VM): one `server` item and, when its plan has backups and a variation without
	 *   them exists, a `backups` item priced at the difference;
	 * - an extra disk: `disk`, in GB;
	 * - a snapshot pack: `snapshots`, one pack;
	 * - the licence of a paid app on the server: `app`, one licence, at the tier's monthly price (its monthly sibling's)
	 *   x (1 + premium) / 730, capped like the others at that price x (1 + premium) a month.
	 *
	 * @param Resource $r Resource.
	 * @return array<int, array{item: string, quantity: float, unit: string, rate: float}>
	 */
	public static function items( Resource $r ): array {
		$license = $r->license();
		$vid     = (int) ( $license['variation_id'] ?? 0 );
		$v       = $vid > 0 ? wc_get_product( $vid ) : false;

		if ( ! $v instanceof \WC_Product_Variation ) {
			return array();
		}

		$monthly = self::monthly( $v );

		if ( null === $monthly ) {
			return array();
		}

		$premium = self::premium( $v->get_parent_id() );
		$driver  = $r->get( 'driver' );
		$out     = array();

		if ( DbDriver::ID === $driver ) {
			// One item: the variation's price already holds the public IP and the extra storage the plan was bought with, so
			// the hourly rate is that price / 730 and the cap that price (the add-ons are variation attributes, not products).
			$out[] = array( 'item' => 'database', 'quantity' => 1.0, 'unit' => 'hour', 'rate' => self::hourly( $monthly, $premium ) );
		} elseif ( ProxmoxDriver::ID === $driver ) {
			$server = $monthly;
			$extra  = 0.0;

			if ( Backups::days( $r ) > 0 ) {
				$without = Backups::sibling( $v, false );
				$base    = $without ? self::monthly( $without ) : null;

				if ( null !== $base && $monthly > $base ) {
					$server = $base;
					$extra  = $monthly - $base;
				}
			}

			$out[] = array( 'item' => 'server', 'quantity' => 1.0, 'unit' => 'hour', 'rate' => self::hourly( $server, $premium ) );

			if ( $extra > 0 && Backups::enabled( $r ) ) {
				$out[] = array( 'item' => 'backups', 'quantity' => 1.0, 'unit' => 'hour', 'rate' => self::hourly( $extra, $premium ) );
			}
		} elseif ( DiskDriver::ID === $driver ) {
			$gb = (float) ( ( (array) $r->get( 'plan' ) )['disk_gb'] ?? 0 );

			if ( $gb > 0 ) {
				$out[] = array( 'item' => 'disk', 'quantity' => $gb, 'unit' => 'GB', 'rate' => self::hourly( $monthly / $gb, $premium ) );
			}
		} elseif ( SnapshotDriver::ID === $driver ) {
			$out[] = array( 'item' => 'snapshots', 'quantity' => 1.0, 'unit' => 'pack', 'rate' => self::hourly( $monthly, $premium ) );
		} elseif ( AppLicenceDriver::ID === $driver ) {
			$out[] = array( 'item' => 'app', 'quantity' => 1.0, 'unit' => 'hour', 'rate' => self::hourly( $monthly, $premium ) );
		}

		return $out;
	}
}
