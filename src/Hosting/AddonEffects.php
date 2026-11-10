<?php
/**
 * What a paid hosting add-on changes on the account: domains and mailbox storage.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Hosting;

use BeltoftServices\Domains\PublicSuffix;
use BeltoftServices\Resource;


defined( 'ABSPATH' ) || exit;

/**
 * The effect side of the add-ons: which names need a paid domain, which paid domains are not on a website yet, joining a
 * paid domain to its website, taking a name back when its line ends, and the Email lite storage that the pack lines give.
 * The money side is AddonBilling. Addons is the facade that keeps the public names, constants and hooks.
 */
final class AddonEffects {

	/**
	 * The registrable domain of a host name (the name itself when the ending is unknown).
	 *
	 * @param string $host Host.
	 */
	public static function registrable( string $host ): string {
		$host = strtolower( rtrim( trim( $host ), '.' ) );

		$labels = explode( '.', $host );

		// An ending the public suffix list does not know: the last two labels.
		return PublicSuffix::registrable( $host ) ?? ( count( $labels ) > 2 ? implode( '.', array_slice( $labels, -2 ) ) : $host );
	}

	/**
	 * The registrable domain a new name would add to the account when it is not the account's first one, else null
	 * (the first domain and everything under a domain the account has are free).
	 *
	 * @param Resource $r    Hosting resource.
	 * @param string   $host New host name.
	 */
	public static function domain_needing_addon( Resource $r, string $host ): ?string {
		$reg  = self::registrable( $host );
		$have = array();

		foreach ( SiteDomains::for_resource( $r->id() ) as $row ) {
			if ( 'preview' !== $row['kind'] ) {
				$have[] = self::registrable( $row['host'] );
			}
		}

		return array() === $have || in_array( $reg, $have, true ) ? null : $reg;
	}

	/**
	 * Registrable domains whose add-on line ends at the next renewal.
	 *
	 * @param Resource $r Hosting resource.
	 * @return array<int, string>
	 */
	public static function ending_domains( Resource $r ): array {
		$out = array();

		foreach ( AddonBilling::lines( $r, Addons::KIND_DOMAIN ) as $item ) {
			if ( 'yes' === (string) $item->get_meta( Addons::I_ENDING ) ) {
				$out[] = (string) $item->get_meta( Addons::I_TARGET );
			}
		}

		return $out;
	}

	/**
	 * Paid extra domains that are not on a website yet (the customer has not added them, or they are being connected).
	 *
	 * @param Resource $r Hosting resource.
	 * @return array<int, string> Registrable domains.
	 */
	public static function unconnected_domains( Resource $r ): array {
		$have = array();

		foreach ( SiteDomains::for_resource( $r->id() ) as $row ) {
			if ( 'preview' !== $row['kind'] ) {
				$have[] = self::registrable( $row['host'] );
			}
		}

		$out = array();

		foreach ( AddonBilling::lines( $r, Addons::KIND_DOMAIN ) as $item ) {
			$target = (string) $item->get_meta( Addons::I_TARGET );

			if ( '' === (string) $item->get_meta( Addons::I_ENDING ) && ! in_array( $target, $have, true ) ) {
				$out[] = $target;
			}
		}

		return $out;
	}

	/**
	 * Adds the domain a paid add-on order was for to its website, or says that it could not be (the line is paid and stays).
	 *
	 * @param \WC_Order            $order Paid order.
	 * @param Resource             $r     Hosting resource.
	 * @param array<string, mixed> $meta  Order meta of the add-on.
	 */
	public static function connect( \WC_Order $order, Resource $r, array $meta ): void {
		$host = (string) ( $meta['host'] ?? '' );
		$slug = (string) ( $meta['slug'] ?? '' );

		if ( '' === $host || '' === $slug ) {
			return;
		}

		$added = ( new HostingActions( $r ) )->add_domain( $slug, $host );

		if ( is_wp_error( $added ) ) {
			$order->add_order_note( 'The domain ' . $host . ' is paid but could not be added to the website: ' . $added->get_error_message() );
			AddonBilling::alert( 'Paid domain was not connected', sprintf( "Order #%1\$d paid for the extra domain %2\$s, but it could not be added to the website: %3\$s.\n\nAdd it by hand or ask the customer to add it from the hosting page.", $order->get_id(), $host, $added->get_error_message() ) );
		}
	}

	/**
	 * Removes the names of a registrable domain from the account (its line has just ended).
	 *
	 * @param Resource $r   Hosting resource.
	 * @param string   $reg Registrable domain.
	 * @return bool False when a name could not be removed.
	 */
	public static function drop_domain( Resource $r, string $reg ): bool {
		$actions = new HostingActions( $r );
		$ok      = true;

		foreach ( SiteDomains::for_resource( $r->id() ) as $row ) {
			if ( 'preview' !== $row['kind'] && self::registrable( $row['host'] ) === $reg && is_wp_error( $actions->remove_domain( $row['host'], true ) ) ) {
				$ok = false;
			}
		}

		return $ok;
	}

	/**
	 * Brings what the add-ons give in line with the lines now: the Email lite storage of each mailbox.
	 *
	 * @param Resource $r Hosting resource.
	 * @return bool False when the mail server could not be changed.
	 */
	public static function apply( Resource $r ): bool {
		$child = SiteMail::child_of( $r );

		return $child ? \BeltoftServices\Mail\MailPacks::apply( $child, self::packs( $r ) ) : true;
	}

	/**
	 * Packs of each Email lite mailbox, lines that are ending included (they still hold the storage).
	 *
	 * @param Resource $r Hosting resource.
	 * @return array<string, int> Mailbox address => packs.
	 */
	public static function packs( Resource $r ): array {
		$out = array();

		foreach ( AddonBilling::lines( $r, Addons::KIND_PACK ) as $item ) {
			$to         = (string) $item->get_meta( Addons::I_TARGET );
			$out[ $to ] = ( $out[ $to ] ?? 0 ) + 1;
		}

		return $out;
	}
}
