<?php
/**
 * Plan-wide counts: one mail plan can hold several domains.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Mail;

use BeltoftServices\Children;
use BeltoftServices\Product;
use BeltoftServices\Resource;

defined( 'ABSPATH' ) || exit;

/**
 * The plan root is the mail resource that was bought. Extra domains are child mail resources of the root (like Email
 * lite). The limits belong to the root and count over the root and its live children together.
 */
final class MailPlan {

	/**
	 * The plan root of a resource: its parent when it is a mail child, else itself.
	 *
	 * @param Resource $r Mail resource.
	 */
	public static function root( Resource $r ): Resource {
		$parent = Children::parent_of( $r );

		return $parent && MailDriver::ID === $parent->get( 'driver' ) ? $parent : $r;
	}

	/**
	 * The root, then its live mail children (deleted and failed ones are left out).
	 *
	 * @param Resource $root Plan root.
	 * @return Resource[]
	 */
	public static function members( Resource $root ): array {
		$root = Resource::find( $root->id() ) ?? $root; // The links are read fresh.
		$out  = array( $root );

		foreach ( Children::of( $root ) as $child ) {
			if ( MailDriver::ID === $child->get( 'driver' ) && ! in_array( (string) $child->get( 'state' ), array( 'deleted', 'failed' ), true ) ) {
				$out[] = $child;
			}
		}

		return $out;
	}

	/**
	 * The plan's limits (those of the root).
	 *
	 * @param Resource $r Any member.
	 * @return array{mailboxes:int,quota_mb:int,aliases:int,domains:int}
	 */
	public static function limits( Resource $r ): array {
		return MailGuard::limits( self::root( $r ) );
	}

	/**
	 * Is the domain of the member on the mail server yet? One still being proved or set up holds nothing there.
	 *
	 * @param Resource $member Member.
	 */
	private static function on_server( Resource $member ): bool {
		return '' !== MailGuard::domain( $member ) && in_array( 'domain', (array) $member->get( 'steps' ), true );
	}

	/**
	 * Mailboxes of one member as the mail server has them, the system mailbox left out.
	 *
	 * @param Resource $member Member.
	 * @return int|\WP_Error
	 */
	public static function mailboxes_of( Resource $member ) {
		if ( ! self::on_server( $member ) ) {
			return 0;
		}

		$have = MailClients::for()->list_mailboxes( MailGuard::domain( $member ) );

		if ( is_wp_error( $have ) ) {
			return $have;
		}

		$system = MailGuard::system_address( $member );

		return count( array_filter( $have, static fn( $row ) => '' === $system || strtolower( (string) ( $row['username'] ?? '' ) ) !== $system ) );
	}

	/**
	 * Live mailboxes over the whole plan, or the first error of the mail server.
	 *
	 * @param Resource $root Plan root.
	 * @return int|\WP_Error
	 */
	public static function measure_mailboxes( Resource $root ) {
		$total = 0;

		foreach ( self::members( $root ) as $member ) {
			if ( ! self::on_server( $member ) ) {
				continue;
			}

			$boxes = self::mailboxes_of( $member );

			if ( is_wp_error( $boxes ) ) {
				return $boxes;
			}

			$total += $boxes;
		}

		return $total;
	}

	/**
	 * Live aliases over the whole plan, or the first error.
	 *
	 * @param Resource $root Plan root.
	 * @return int|\WP_Error
	 */
	public static function measure_aliases( Resource $root ) {
		$total = 0;

		foreach ( self::members( $root ) as $member ) {
			if ( ! self::on_server( $member ) ) {
				continue;
			}

			$aliases = Aliases::all( $member );

			if ( is_wp_error( $aliases ) ) {
				return $aliases;
			}

			$total += count( $aliases );
		}

		return $total;
	}

	/**
	 * Live counts over the whole plan, or the first error of the mail server.
	 *
	 * @param Resource $root Plan root.
	 * @return array{mailboxes:int,aliases:int,domains:int}|\WP_Error
	 */
	public static function measure( Resource $root ) {
		$boxes = self::measure_mailboxes( $root );

		if ( is_wp_error( $boxes ) ) {
			return $boxes;
		}

		$aliases = self::measure_aliases( $root );

		if ( is_wp_error( $aliases ) ) {
			return $aliases;
		}

		return array( 'mailboxes' => $boxes, 'aliases' => $aliases, 'domains' => count( self::counted( $root ) ) );
	}

	/**
	 * Live counts over the plan. When the mail server cannot be asked, the remembered mailbox counts are used.
	 *
	 * @param Resource $root Plan root.
	 * @return array{mailboxes:int,aliases:int,domains:int}
	 */
	public static function used( Resource $root ): array {
		$live = self::measure( $root );

		if ( ! is_wp_error( $live ) ) {
			return $live;
		}

		return self::cached( $root );
	}

	/**
	 * Remembered counts (no mail server call): mailboxes as last seen per member, aliases not remembered.
	 *
	 * @param Resource $root Plan root.
	 * @return array{mailboxes:int,aliases:int,domains:int}
	 */
	public static function cached( Resource $root ): array {
		$members = self::counted( $root );
		$boxes   = 0;

		foreach ( $members as $member ) {
			$boxes += (int) $member->get( 'mail_boxes' );
		}

		return array( 'mailboxes' => $boxes, 'aliases' => 0, 'domains' => count( $members ) );
	}

	/**
	 * Runs a callback under the lock of the plan root and, for a child, under the lock of the child too, so two domains
	 * of one plan cannot pass the limit together. Lock order everywhere in the mail code: the plan root first, then a
	 * child (removing a domain and the plan job work the same way), so two runs never wait for each other.
	 *
	 * @param Resource $r        Resource.
	 * @param callable $callback Returns whether it stored something.
	 * @return bool|null The callback's result, or null when a lock stayed busy.
	 */
	public static function locked( Resource $r, callable $callback ): ?bool {
		$root = self::root( $r );

		if ( $root->id() === $r->id() ) {
			return $r->locked( $callback );
		}

		$busy = false;
		$ran  = $root->locked(
			static function () use ( $r, $callback, &$busy ): bool {
				$inner = $r->locked( $callback );
				$busy  = null === $inner;

				return (bool) $inner;
			}
		);

		return $busy ? null : $ran;
	}

	/**
	 * The domains that count against the plan: the members, less those whose removal was asked for (they are gone for the
	 * customer at once; the job that closes them runs a moment later).
	 *
	 * @param Resource $root Plan root.
	 * @return Resource[]
	 */
	public static function counted( Resource $root ): array {
		return array_values(
			array_filter(
				self::members( $root ),
				static fn( Resource $m ): bool => $m->id() === $root->id() || 'deleted' !== (string) $m->get( 'desired' )
			)
		);
	}

	/**
	 * The switch link to the nearest larger plan of the same product (more mailboxes, nothing smaller), for the customer
	 * who is logged in. Empty when Subscriptions is not active, the plan has no subscription or no larger plan exists.
	 *
	 * @param Resource $root Plan root.
	 */
	public static function larger_plan_url( Resource $root ): string {
		$license      = $root->license();
		$variation_id = (int) ( $license['variation_id'] ?? 0 );
		$variation    = $variation_id > 0 && function_exists( 'wc_get_product' ) ? wc_get_product( $variation_id ) : null;

		if ( ! $variation instanceof \WC_Product_Variation || ! class_exists( '\WC_Subscriptions_Switcher' ) ) {
			return '';
		}

		$parent = wc_get_product( $variation->get_parent_id() );
		$have   = MailGuard::limits( $root );
		$best   = null;

		foreach ( $parent instanceof \WC_Product_Variable ? $parent->get_children() : array() as $id ) {
			$offer = wc_get_product( (int) $id );

			if ( ! $offer instanceof \WC_Product_Variation || $offer->get_id() === $variation_id ) {
				continue;
			}

			$limits = MailDriver::limits_from( Product::plan( $parent->get_id(), $offer->get_id() ) );

			if ( $limits['mailboxes'] > $have['mailboxes'] && $limits['aliases'] >= $have['aliases'] && $limits['domains'] >= $have['domains'] && $limits['quota_mb'] >= $have['quota_mb'] && ( null === $best || $limits['mailboxes'] < $best['mailboxes'] ) ) {
				$best = array( 'mailboxes' => $limits['mailboxes'], 'variation' => $offer );
			}
		}

		return null === $best ? '' : \BeltoftServices\SwitchLink::to( $root, $best['variation'] );
	}
}
