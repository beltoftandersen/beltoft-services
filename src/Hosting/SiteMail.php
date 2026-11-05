<?php
/**
 * Website mail of a hosting account.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Hosting;

use BeltoftServices\Children;
use BeltoftServices\Crypto;
use BeltoftServices\Drivers\StepResult;
use BeltoftServices\Mail\MailClients;
use BeltoftServices\Mail\MailDomain;
use BeltoftServices\Mail\MailDriver;
use BeltoftServices\Mail\MailGuard;
use BeltoftServices\Mail\MailSettings;
use BeltoftServices\Resource;
use BeltoftServices\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Plan 9.2: PHP mail() of the websites is sent through our mail server with an authenticated mailbox, never
 * directly.
 *
 * - With Email lite set up for the account's domain, `wordpress@<domain>` is a hidden system mailbox (not counted in
 *   the plan, not listed to the customer, not changeable by the customer) with a random password kept encrypted in
 *   the account's meta and the plan's daily cap (`site_mail_per_day`) as the mailbox send limit. The per-minute cap
 *   is the domain's override (LiteMail). The agent's `mail.smtp` job writes the settings into the container, so
 *   From is `wordpress@<domain>` and SPF, DKIM and DMARC align.
 * - Without Email lite (and for the preview addresses): the account's OWN fallback mailbox `wp-<account>@<preview zone>`
 *   on the preview zone's mail domain (which the owner makes once on the mail server). It is made through the mail
 *   server's client like the system mailbox: a random password kept encrypted in the account's meta, the plan's daily
 *   cap, never listed to the customer, deleted with the account. No credential is shared between accounts: a customer
 *   who reads the one in their own container reaches only their own mailbox. The site's admin email is the Reply-To,
 *   which the container's mail plugin sets. Aligned with the preview zone, not the customer's domain. Nothing is
 *   made when the preview zone is not a mail domain yet.
 *
 * Not in this version: the 80 percent admin alert and the customer email when the cap is reached (they need the
 * mail server's usage numbers), and moving the fallback from one sender to the other when Email lite is deleted.
 */
final class SiteMail {

	use Runs;

	/** Local part of the system mailbox. */
	public const SYSTEM_LOCAL = 'wordpress';

	/** Start of the local part of an account's fallback sender on the preview zone (wp-<account>). */
	public const FALLBACK_PREFIX = 'wp-';

	/** Size of the system mailbox in MB. */
	public const SYSTEM_QUOTA_MB = 256;

	/**
	 * @param Resource $r Hosting resource.
	 */
	public function __construct( Resource $r ) {
		$this->r = $r;
	}

	/**
	 * The account's own fallback sender address, or '' when there is no preview zone.
	 *
	 * @param int $resource_id Hosting resource id.
	 */
	public static function fallback_address( int $resource_id ): string {
		$zone = Config::preview_zone();

		return '' === $zone ? '' : self::FALLBACK_PREFIX . Config::account( $resource_id ) . '@' . $zone;
	}

	/**
	 * Makes the account's own fallback mailbox on the preview zone and sends the container its settings. Safe to run
	 * again. Without a mail host, a preview zone, or the zone as a mail domain there is nothing to make, which is not an
	 * error (the owner makes the domain once).
	 *
	 * @return array<string, mixed>|StepResult Result meta.
	 */
	public function use_fallback() {
		$address = self::fallback_address( $this->r->id() );
		$host    = MailSettings::host();

		if ( '' === $address || '' === $host || ! Crypto::available() ) {
			return array();
		}

		$early = $this->ensure_connected();

		if ( null !== $early ) {
			return $early;
		}

		$zone   = Config::preview_zone();
		$client = MailClients::for();
		$domain = $client->get_domain( $zone );

		if ( is_wp_error( $domain ) ) {
			return StepResult::retry( 300, 'mail: ' . $this->scrub( $domain->get_error_message(), 160 ) );
		}

		if ( null === $domain ) {
			return array(); // The preview zone is not a mail domain on the mail server.
		}

		$secret = (string) $this->r->get( 'smtp_fb_secret' );

		if ( '' === $secret ) {
			$secret = Crypto::encrypt( wp_generate_password( 32, false ) );

			if ( ! $this->r->set( array( 'smtp_fb_secret' => $secret ) ) ) {
				return StepResult::retry( 0, 'mail: the mailbox password could not be stored' );
			}
		}

		$password = (string) Crypto::decrypt( $secret );

		if ( '' === $password ) {
			return StepResult::fail( 'mail: the stored fallback mailbox password cannot be decrypted' );
		}

		$this->secrets[] = $password;
		$plan            = Plan::typed( (array) $this->r->get( 'plan' ) );
		$box             = $client->get_mailbox( $address );

		if ( is_wp_error( $box ) ) {
			return StepResult::retry( 300, 'mail: ' . $this->scrub( $box->get_error_message(), 160 ) );
		}

		$attrs = array(
			'password'        => $password,
			'password2'       => $password,
			'force_pw_update' => '0',
		);
		$done  = null === $box
			? $client->add_mailbox( array_merge( $attrs, array( 'local_part' => self::FALLBACK_PREFIX . Config::account( $this->r->id() ), 'domain' => $zone, 'name' => 'Website mail', 'quota' => (string) self::SYSTEM_QUOTA_MB, 'active' => '1' ) ) )
			: $client->edit_mailbox( $address, $attrs );

		if ( is_wp_error( $done ) ) {
			return StepResult::retry( 300, 'mail: ' . $this->scrub( $done->get_error_message(), 160 ) );
		}

		if ( (int) $plan['site_mail_per_day'] > 0 ) {
			$limited = $client->set_mailbox_rate_limit( $address, (int) $plan['site_mail_per_day'], 'd' );

			if ( is_wp_error( $limited ) ) {
				return StepResult::retry( 300, 'mail: ' . $this->scrub( $limited->get_error_message(), 160 ) );
			}
		}

		return $this->smtp( 'smtp_fallback', $host, $address, $password, 'fallback' );
	}

	/**
	 * Deletes the account's fallback mailbox (the account is being deleted). Nothing to do when it was never made.
	 *
	 * @return true|StepResult
	 */
	public function delete_fallback() {
		if ( '' === (string) $this->r->get( 'smtp_fb_secret' ) || '' === MailSettings::host() ) {
			return true;
		}

		$address = self::fallback_address( $this->r->id() );

		if ( '' === $address ) {
			return true;
		}

		$client = MailClients::for();
		$box    = $client->get_mailbox( $address );

		if ( is_wp_error( $box ) ) {
			return StepResult::retry( 300, 'delete: ' . $this->scrub( $box->get_error_message(), 160 ) );
		}

		if ( null === $box ) {
			return true;
		}

		$gone = $client->delete_mailbox( $address );

		return is_wp_error( $gone ) ? StepResult::retry( 300, 'delete: ' . $this->scrub( $gone->get_error_message(), 160 ) ) : true;
	}

	/**
	 * Makes the system mailbox of the account's Email lite domain and points the websites at it.
	 *
	 * @return array<string, mixed>|StepResult Result meta (mail_sender), or the step's retry or failure.
	 */
	public function enable_system_mailbox() {
		$child = self::child_of( $this->r );

		if ( null === $child || 'ready' !== $child->get( 'state' ) || 'ready' !== $child->get( 'desired' ) ) {
			return StepResult::fail( 'Email lite is not ready, so website mail cannot use it yet' );
		}

		if ( ! Crypto::available() ) {
			return StepResult::fail( 'BELTOFT_SERVICES_KEY is not configured: the mailbox password cannot be stored safely' );
		}

		$early = $this->ensure_connected();

		if ( null !== $early ) {
			return $early;
		}

		$domain  = MailGuard::domain( $child );
		$address = self::SYSTEM_LOCAL . '@' . $domain;
		$secret  = (string) $this->r->get( 'smtp_secret' );

		if ( '' === $secret ) {
			$secret = Crypto::encrypt( wp_generate_password( 32, false ) );

			if ( ! $this->r->set( array( 'smtp_secret' => $secret ) ) ) {
				return StepResult::retry( 0, 'mail: the mailbox password could not be stored' );
			}
		}

		$password = (string) Crypto::decrypt( $secret );

		if ( '' === $password ) {
			return StepResult::fail( 'mail: the stored mailbox password cannot be decrypted' );
		}

		$this->secrets[] = $password;
		$plan            = Plan::typed( (array) $this->r->get( 'plan' ) );
		$client          = MailClients::for();

		// The domain gets room for one more mailbox before the mailbox is made; the customer's own count is unchanged.
		if ( strtolower( (string) $child->get( 'mail_system' ) ) !== $address && ! $child->set( array( 'mail_system' => $address ) ) ) {
			return StepResult::retry( 0, 'mail: the system mailbox could not be recorded' );
		}

		$limits = MailDriver::limits_from( MailGuard::limits( $child ) );
		$edit   = $client->edit_domain( $domain, MailDriver::domain_attrs( $limits, 1 ) );

		if ( is_wp_error( $edit ) ) {
			return StepResult::retry( 300, 'mail: ' . $this->scrub( $edit->get_error_message(), 160 ) );
		}

		$box = $client->get_mailbox( $address );

		if ( is_wp_error( $box ) ) {
			return StepResult::retry( 300, 'mail: ' . $this->scrub( $box->get_error_message(), 160 ) );
		}

		$attrs = array(
			'password'        => $password,
			'password2'       => $password,
			'force_pw_update' => '0',
		);

		$done = null === $box
			? $client->add_mailbox( array_merge( $attrs, array( 'local_part' => self::SYSTEM_LOCAL, 'domain' => $domain, 'name' => 'Website mail', 'quota' => (string) min( self::SYSTEM_QUOTA_MB, $limits['quota_mb'] ), 'active' => '1' ) ) )
			: $client->edit_mailbox( $address, $attrs );

		if ( is_wp_error( $done ) ) {
			return StepResult::retry( 300, 'mail: ' . $this->scrub( $done->get_error_message(), 160 ) );
		}

		// The plan's daily cap on this mailbox only (0 turns the limit off, as for the other mail limits).
		if ( (int) $plan['site_mail_per_day'] > 0 ) {
			$limited = $client->set_mailbox_rate_limit( $address, (int) $plan['site_mail_per_day'], 'd' );

			if ( is_wp_error( $limited ) ) {
				return StepResult::retry( 300, 'mail: ' . $this->scrub( $limited->get_error_message(), 160 ) );
			}
		}

		return $this->smtp( 'smtp_system', MailSettings::host(), $address, $password, 'system' );
	}

	/**
	 * The mail.smtp job and the meta that remembers which sender the websites use.
	 *
	 * @param string $name     Job name.
	 * @param string $host     Mail server.
	 * @param string $user     Sender and login.
	 * @param string $password Password.
	 * @param string $mode     system or fallback.
	 * @return array<string, mixed>|StepResult
	 */
	private function smtp( string $name, string $host, string $user, string $password, string $mode ) {
		$record = Records::for_resource( $this->r->id() );

		if ( ! $record || null === $record['vmid'] || '' === $host ) {
			return StepResult::retry( 0, 'mail: the account or the mail host is not ready' );
		}

		$result = $this->agent_call(
			$name,
			'mail.smtp',
			array(
				'vmid'     => (int) $record['vmid'],
				'host'     => $host,
				'user'     => $user,
				'password' => $password,
			)
		);

		if ( $result instanceof StepResult ) {
			return $result;
		}

		$meta = array( 'mail_sender' => array( 'mode' => $mode, 'address' => $user ) );

		return $this->r->set( $meta ) ? $meta : StepResult::retry( 0, 'mail: the sender could not be recorded' );
	}

	/**
	 * Connects to the node once.
	 */
	private function ensure_connected(): ?StepResult {
		return null === $this->client ? $this->connect() : null;
	}

	/**
	 * The live Email lite child of an account.
	 *
	 * @param Resource $parent Hosting resource.
	 */
	public static function child_of( Resource $parent ): ?Resource {
		foreach ( Children::of( Resource::find( $parent->id() ) ?? $parent ) as $child ) {
			if ( MailDriver::ID === $child->get( 'driver' ) && 'deleted' !== $child->get( 'state' ) && '' !== (string) $child->get( MailDomain::META_DOMAIN ) ) {
				return $child;
			}
		}

		return null;
	}
}
