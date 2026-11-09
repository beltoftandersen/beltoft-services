<?php
/**
 * Extra storage of single Email lite mailboxes.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Hosting;

use BeltoftServices\Mail\MailClients;
use BeltoftServices\Mail\MailDriver;
use BeltoftServices\Mail\MailGuard;
use BeltoftServices\Resource;

defined( 'ABSPATH' ) || exit;

/**
 * Sets the size of each mailbox to the plan's size plus one pack for each pack it has, and gives the domain room for
 * the biggest mailbox and the total. Idempotent: it reads what the mail server has and changes only what differs, so
 * it raises storage when a pack is paid and lowers it again once the line has left at a renewal. The packs are kept
 * on the Email lite resource (`mail_packs`) so a plan change of the mail driver can put them back.
 */
final class MailPacks {

	/**
	 * Applies the packs.
	 *
	 * @param Resource           $child Email lite resource.
	 * @param array<string, int> $packs Mailbox address => packs.
	 * @return bool False when the mail server could not be reached (nothing is stored, the call can be repeated).
	 */
	public static function apply( Resource $child, array $packs ): bool {
		$packs  = array_filter( array_map( 'intval', $packs ) );
		$domain = MailGuard::domain( $child );

		if ( '' === $domain ) {
			return false;
		}

		$limits = MailGuard::limits( $child );
		$client = MailClients::for();
		$boxes  = $client->list_mailboxes( $domain );

		if ( is_wp_error( $boxes ) ) {
			return false;
		}

		$system = MailGuard::system_address( $child );
		$raise  = array();
		$lower  = array();
		$extra  = 0;
		$biggest = 0;

		foreach ( $boxes as $box ) {
			$email = strtolower( (string) ( $box['username'] ?? '' ) );

			if ( '' === $email || $email === $system ) {
				continue;
			}

			$n       = min( Addons::MAX_PACKS, (int) ( $packs[ $email ] ?? 0 ) );
			$want    = $limits['quota_mb'] + $n * Addons::PACK_MB;
			$now     = (int) round( (int) ( $box['quota'] ?? 0 ) / 1048576 );
			$extra  += $n * Addons::PACK_MB;
			$biggest = max( $biggest, $n );

			if ( $want > $now ) {
				$raise[ $email ] = $want;
			} elseif ( $want < $now ) {
				$lower[ $email ] = $want;
			}
		}

		// Lower the mailboxes before the domain, raise the domain before the mailboxes.
		foreach ( $lower as $email => $mb ) {
			if ( is_wp_error( $client->edit_mailbox( $email, array( 'quota' => (string) $mb ) ) ) ) {
				return false;
			}
		}

		$attrs             = MailDriver::domain_attrs( $limits, '' !== $system ? 1 : 0 );
		$attrs['maxquota'] = (string) ( $limits['quota_mb'] + $biggest * Addons::PACK_MB );
		$attrs['quota']    = (string) ( (int) $attrs['quota'] + $extra );

		if ( is_wp_error( $client->edit_domain( $domain, $attrs ) ) ) {
			return false;
		}

		foreach ( $raise as $email => $mb ) {
			if ( is_wp_error( $client->edit_mailbox( $email, array( 'quota' => (string) $mb ) ) ) ) {
				return false;
			}
		}

		$child->set( array( 'mail_packs' => $packs ) );

		return true;
	}

	/**
	 * Puts the stored packs back after the mail driver changed the domain's limits.
	 *
	 * @param Resource $child Email lite resource.
	 */
	public static function reapply( Resource $child ): void {
		$packs = $child->get( 'mail_packs' );

		if ( is_array( $packs ) && array() !== $packs ) {
			self::apply( $child, $packs );
		}
	}
}
