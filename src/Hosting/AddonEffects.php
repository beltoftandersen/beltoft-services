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

	/** Resource meta: paid domains not on a website yet because a change was under way (host => site slug). See retry(). */
	public const WANT_CONNECT = 'addon_connect_wanted';

	/** Resource meta: registrable domains whose line ended and whose names are still on the account (a list). See retry(). */
	public const WANT_DROP = 'addon_drop_wanted';

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
	 * Registrable domains whose add-on line ended because the domain became the account's included one.
	 *
	 * @param Resource $r Hosting resource.
	 * @return array<int, string>
	 */
	public static function included_domains( Resource $r ): array {
		$out = array();

		foreach ( AddonBilling::lines( $r, Addons::KIND_DOMAIN ) as $item ) {
			if ( 'yes' === (string) $item->get_meta( Addons::I_ENDING ) && 'yes' === (string) $item->get_meta( Addons::I_INCLUDED ) ) {
				$out[] = (string) $item->get_meta( Addons::I_TARGET );
			}
		}

		return $out;
	}

	/**
	 * Registrable domains of the account's names that are on a website (the temporary address left out).
	 *
	 * @param Resource $r Hosting resource.
	 * @return array<int, string>
	 */
	public static function connected_domains( Resource $r ): array {
		$have = array();

		foreach ( SiteDomains::for_resource( $r->id() ) as $row ) {
			if ( 'preview' !== $row['kind'] ) {
				$have[] = self::registrable( $row['host'] );
			}
		}

		return array_values( array_unique( $have ) );
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
	 * @internal Public only so AddonBilling can call it; not an API.
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

		// A change of the account like any other: its checks and local work here, the names to the node in its job.
		$added = ( new HostingActions( $r ) )->queue( 'add_domain', array( $slug, $host ) );

		// A change under way is no failure: the wish is kept with the account and the domain sweep makes it when the account is free.
		if ( self::waits( $added ) ) {
			$fresh           = Resource::find( $r->id() ) ?? $r;
			$wanted          = (array) $fresh->get( self::WANT_CONNECT );
			$wanted[ $host ] = $slug;
			$fresh->set( array( self::WANT_CONNECT => $wanted ) );
			$order->add_order_note( 'The domain ' . $host . ' is paid. Your hosting is being changed, so it is added to the website afterwards.' );

			return;
		}

		if ( is_wp_error( $added ) ) {
			$order->add_order_note( 'The domain ' . $host . ' is paid but could not be added to the website: ' . $added->get_error_message() );
			AddonBilling::alert( 'Paid domain was not connected', sprintf( "Order #%1\$d paid for the extra domain %2\$s, but it could not be added to the website: %3\$s.\n\nAdd it by hand or ask the customer to add it from the hosting page.", $order->get_id(), $host, $added->get_error_message() ) );
		}
	}

	/**
	 * Whether a refusal of a change is only a wait: a change under way (busy) or an account that is not ready at this moment (not_ready).
	 * The wish stays and the domain sweep tries again; any other refusal is final.
	 *
	 * @param mixed $answer Answer of HostingActions::queue().
	 */
	private static function waits( $answer ): bool {
		return is_wp_error( $answer ) && in_array( $answer->get_error_code(), array( 'busy', 'not_ready' ), true );
	}

	/**
	 * Removes the names of a registrable domain from the account (its line has just ended).
	 *
	 * @internal Public only so AddonBilling can call it; not an API.
	 * @param Resource $r   Hosting resource.
	 * @param string   $reg Registrable domain.
	 * @return bool False when a name could not be removed.
	 */
	public static function drop_domain( Resource $r, string $reg ): bool {
		// One change takes every name of the domain (queued through the shared controller; the names reach the node in its job).
		foreach ( SiteDomains::for_resource( $r->id() ) as $row ) {
			if ( 'preview' !== $row['kind'] && self::registrable( $row['host'] ) === $reg ) {
				$dropped = ( new HostingActions( $r ) )->queue( 'remove_domain', array( $row['host'], true ) );

				// A change under way is no failure: the domain is kept as to be dropped and the domain sweep does it when the account is free.
				if ( self::waits( $dropped ) ) {
					$fresh  = Resource::find( $r->id() ) ?? $r;
					$wanted = array_values( array_unique( array_merge( array_filter( (array) $fresh->get( self::WANT_DROP ), 'is_string' ), array( $reg ) ) ) );

					return $fresh->set( array( self::WANT_DROP => $wanted ) );
				}

				return ! is_wp_error( $dropped );
			}
		}

		return true;
	}

	/**
	 * What the account still owes its paid domains, looked at again from the stored wishes (the domain sweep calls this for each
	 * ready account): a paid domain that could not be added while a change ran is added now, and one whose line has ended and that
	 * could not be dropped is dropped now. A wish that a change under way holds up stays for the next look.
	 *
	 * @param Resource $r Hosting resource.
	 */
	public static function retry( Resource $r ): void {
		$fresh = Resource::find( $r->id() ) ?? $r;
		$add   = (array) $fresh->get( self::WANT_CONNECT );
		$drop  = array_values( array_filter( (array) $fresh->get( self::WANT_DROP ), 'is_string' ) );

		if ( array() === $add && array() === $drop ) {
			return;
		}

		foreach ( $add as $host => $slug ) {
			if ( null !== SiteDomains::find( (string) $host ) ) {
				unset( $add[ $host ] );
				continue;
			}

			$done = ( new HostingActions( $fresh ) )->queue( 'add_domain', array( (string) $slug, (string) $host ) );

			if ( self::waits( $done ) ) {
				continue;
			}

			unset( $add[ $host ] );

			if ( is_wp_error( $done ) ) {
				AddonBilling::alert( 'Paid domain was not connected', sprintf( "The paid extra domain %1\$s of hosting account #%2\$d could not be added to the website: %3\$s.\n\nAdd it by hand or ask the customer to add it from the hosting page.", (string) $host, $r->id(), $done->get_error_message() ) );
			}

			break; // One change at a time: the next wish waits for the next look.
		}

		foreach ( $drop as $i => $reg ) {
			$rows = array_values( array_filter( SiteDomains::for_resource( $r->id() ), static fn( $row ) => 'preview' !== $row['kind'] && self::registrable( $row['host'] ) === $reg ) );
			$paid = array_filter( AddonBilling::lines( $r, Addons::KIND_DOMAIN, $reg ), static fn( $item ) => '' === (string) $item->get_meta( Addons::I_ENDING ) );

			// The domain is bought again, or it is gone: nothing to take back.
			if ( array() === $rows || array() !== $paid ) {
				unset( $drop[ $i ] );
				continue;
			}

			$done = ( new HostingActions( $fresh ) )->queue( 'remove_domain', array( $rows[0]['host'], true ) );

			if ( self::waits( $done ) ) {
				continue;
			}

			if ( is_wp_error( $done ) ) {
				unset( $drop[ $i ] );
				AddonBilling::alert( 'Add-on change needs a look', sprintf( "The extra domain %1\$s of hosting account #%2\$d ended, but its names could not be taken off the website: %3\$s.\n\nTake them off by hand.", $reg, $r->id(), $done->get_error_message() ) );
			}

			break;
		}

		$fresh->set( array( self::WANT_CONNECT => $add, self::WANT_DROP => array_values( $drop ) ) );
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
