<?php
/**
 * Add-ons and Email lite: buying a pack or a domain, removing a pack, switching mail on for a domain.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Hosting\Actions;

use BeltoftServices\Drivers\StepResult;
use BeltoftServices\Hosting\Addons;
use BeltoftServices\Hosting\LiteMail;
use BeltoftServices\Hosting\SiteDomains;
use BeltoftServices\Hosting\SiteMail;

defined( 'ABSPATH' ) || exit;

/**
 * Add-ons and Email lite: buying a pack or a domain, removing a pack, switching mail on for a domain. Reached through the HostingActions facade; results are arrays and a refusal is a WP_Error.
 */
final class AddonActions {

	use Base;

	/**
	 * Buys an add-on: an extra domain or 5 GB of storage for one Email lite mailbox. Nothing is bought until the
	 * customer has confirmed the price; the line is added once the order for the time until the renewal is paid.
	 *
	 * @param string $kind    domain or mail_pack.
	 * @param string $target  The domain name, or the mailbox address.
	 * @param int    $confirm_cents The amount charged now that the customer saw and accepted, in cents; 0 when not confirmed.
	 * @param string $host    For a domain: the name to add once the order is paid through the pay link.
	 * @param string $slug    For a domain: the website of that name.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function buy_addon( string $kind, string $target, int $confirm_cents, string $host = '', string $slug = '' ) {
		return $this->locked( fn() => $this->buy_addon_locked( $kind, $target, $confirm_cents, $host, $slug ) );
	}

	/**
	 * buy_addon with the site lock held.
	 *
	 * @param string $kind    Kind.
	 * @param string $target  Target.
	 * @param int    $confirm_cents Accepted amount in cents.
	 * @param string $host    For a domain: the name to add once the order is paid through the pay link.
	 * @param string $slug    For a domain: the website of that name.
	 * @return array<string, mixed>|\WP_Error
	 */
	private function buy_addon_locked( string $kind, string $target, int $confirm_cents, string $host = '', string $slug = '' ) {
		$ready = $this->ready();

		if ( is_wp_error( $ready ) ) {
			return $ready;
		}

		$target = strtolower( trim( $target ) );

		if ( Addons::KIND_DOMAIN === $kind ) {
			$target = Addons::registrable( $target );

			if ( ! SiteDomains::valid_host( $target ) ) {
				return $this->error( 'invalid', __( 'The domain name is not valid.', 'beltoft-services' ) );
			}

			// The account's first domain, and every name under a domain it has, needs no add-on; a paid one is not paid twice.
			if ( Addons::domain_needing_addon( $this->r, $target ) !== $target || Addons::has( $this->r, Addons::KIND_DOMAIN, $target ) ) {
				return $this->error( 'invalid', __( 'That domain needs no add-on.', 'beltoft-services' ) );
			}
		} elseif ( Addons::KIND_PACK === $kind ) {
			if ( ! $this->mailbox_exists( $target ) ) {
				return $this->error( 'not_found', __( 'That mailbox is not on your Email lite.', 'beltoft-services' ) );
			}
		} else {
			return $this->error( 'invalid', __( 'That add-on does not exist.', 'beltoft-services' ) );
		}

		$quote = Addons::quote( $this->r, $kind );

		if ( null === $quote ) {
			return $this->error( 'failed', __( 'This add-on cannot be bought online right now. Contact support.', 'beltoft-services' ) );
		}

		// Nothing is bought unless the customer accepted this very amount: a price that changed since is shown again.
		if ( $confirm_cents <= 0 || $confirm_cents !== (int) round( $quote['now'] * 100 ) ) {
			return new \WP_Error( 'confirm', Addons::confirm_text( $kind, $quote ), array( 'kind' => $kind, 'target' => $target, 'amount_cents' => (int) round( $quote['now'] * 100 ) ) );
		}

		$connect = array();

		if ( Addons::KIND_DOMAIN === $kind ) {
			$host = strtolower( rtrim( trim( $host ), '.' ) );

			if ( '' !== $host && SiteDomains::valid_host( $host ) && Addons::registrable( $host ) === $target && '' !== $slug ) {
				$connect = array( 'host' => $host, 'slug' => $slug );
			}
		}

		$bought = Addons::buy( $this->r, $kind, $target, null, $connect );

		return is_wp_error( $bought ) ? $this->error( $bought->get_error_code(), $bought->get_error_message() ) : $bought;
	}

	/**
	 * Ends one storage pack of a mailbox: it leaves at the next renewal and the storage stays until then.
	 *
	 * @param string $mailbox Mailbox address.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function remove_pack( string $mailbox ) {
		return $this->locked(
			function () use ( $mailbox ) {
				$ready = $this->ready();

				return is_wp_error( $ready ) ? $ready : ( Addons::end( $this->r, Addons::KIND_PACK, strtolower( trim( $mailbox ) ) ) ? array( 'at_renewal' => true ) : $this->error( 'not_found', __( 'That mailbox has no extra storage to remove.', 'beltoft-services' ) ) );
			}
		);
	}

	/**
	 * Is the address a mailbox of the account's Email lite (the hidden website mailbox is not one)?
	 *
	 * @param string $email Address.
	 */
	private function mailbox_exists( string $email ): bool {
		$child = SiteMail::child_of( $this->r );
		$rows  = $child ? \BeltoftServices\Mail\Mailboxes::all( $child ) : array();

		if ( ! is_array( $rows ) ) {
			return false;
		}

		return in_array( $email, array_map( static fn( $row ) => strtolower( (string) $row['email'] ), $rows ), true );
	}

	/**
	 * Makes Email lite for the account.
	 *
	 * @param string $domain The mail domain.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function enable_mail( string $domain ) {
		return $this->locked( fn() => $this->enable_mail_locked( $domain ) );
	}

	/**
	 * Sets up Email lite with the site lock held (a double click makes one child, not two).
	 *
	 * @param string $domain Mail domain.
	 * @return array<string, mixed>|\WP_Error
	 */
	private function enable_mail_locked( string $domain ) {
		$child = LiteMail::enable( $this->r, $domain );

		if ( is_wp_error( $child ) ) {
			$map   = array(
				'exists'         => array( 'exists', __( 'Your account has Email lite already.', 'beltoft-services' ) ),
				'bad_domain'     => array( 'invalid', __( 'That domain name is not valid.', 'beltoft-services' ) ),
				'no_domain'      => array( 'invalid', __( 'Connect your own domain to a website first. Email lite uses that domain.', 'beltoft-services' ) ),
				'not_own_domain' => array( 'invalid', __( 'Email lite uses the domain connected to your hosting account.', 'beltoft-services' ) ),
				'no_mail'        => array( 'failed', __( 'Your plan has no mailboxes.', 'beltoft-services' ) ),
			);
			$known = $map[ $child->get_error_code() ] ?? array( 'failed', __( 'Email lite could not be set up.', 'beltoft-services' ) );

			return $this->error( $known[0], $known[1] );
		}

		return array( 'mail_resource' => $child->id(), 'domain' => (string) $child->get( \BeltoftServices\Mail\MailDomain::META_DOMAIN ) );
	}

	/**
	 * Points the websites' mail at the Email lite domain (the hidden system mailbox).
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	public function use_email_for_sites() {
		$ready = $this->ready();

		if ( is_wp_error( $ready ) ) {
			return $ready;
		}

		if ( null === SiteMail::child_of( $this->r ) ) {
			return $this->error( 'not_ready', __( 'Set up Email lite first.', 'beltoft-services' ) );
		}

		$done = ( new SiteMail( $this->r ) )->enable_system_mailbox();

		if ( $done instanceof StepResult ) {
			return $this->error( $done->is_failed() ? 'not_ready' : 'busy', $done->is_failed() ? __( 'Email lite is not ready yet. Try again in a moment.', 'beltoft-services' ) : __( 'The mail server is busy. Try again in a moment.', 'beltoft-services' ) );
		}

		return array( 'sender' => (string) ( $done['mail_sender']['address'] ?? '' ) );
	}
}
