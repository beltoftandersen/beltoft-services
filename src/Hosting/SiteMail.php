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
use BeltoftServices\Mail\MailHold;
use BeltoftServices\Mail\MailMeta;
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
 * - Without Email lite there is no sender: a website cannot send mail (no shared mailbox, no per-account mailbox on the
 *   preview zone). The account panel and the ready email say so and offer to set up Email lite.
 *
 * Not in this version: the 80 percent admin alert and the customer email when the cap is reached (they need the
 * mail server's usage numbers), and moving the fallback from one sender to the other when Email lite is deleted.
 */
final class SiteMail {

	use Runs;

	/** Local part of the system mailbox. */
	public const SYSTEM_LOCAL = 'wordpress';

	/** Start of the hidden local part used when the customer already has a wordpress@<domain> mailbox (wp-<account>). */
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

		$domain = MailGuard::domain( $child );
		$local  = (string) $this->r->get( 'smtp_local' );

		// A mailbox the customer already has at wordpress@<domain> is theirs: it is never taken over, never given our password.
		// Website mail then uses a different hidden local part (wp-<account>), which is chosen once and kept.
		if ( '' === $local ) {
			$existing = MailClients::for()->get_mailbox( self::SYSTEM_LOCAL . '@' . $domain );

			if ( is_wp_error( $existing ) ) {
				return StepResult::retry( 300, 'mail: ' . $this->scrub( $existing->get_error_message(), 160 ) );
			}

			$local = null === $existing ? self::SYSTEM_LOCAL : self::FALLBACK_PREFIX . Config::account( $this->r->id() );

			if ( ! $this->r->set( array( 'smtp_local' => $local ) ) ) {
				return StepResult::retry( 0, 'mail: the mailbox name could not be stored' );
			}
		}

		$address = $local . '@' . $domain;
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
		if ( strtolower( (string) $child->get( MailMeta::SYSTEM ) ) !== $address && ! $child->set( array( MailMeta::SYSTEM => $address ) ) ) {
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
			? $client->add_mailbox( array_merge( $attrs, array( 'local_part' => $local, 'domain' => $domain, 'name' => __( 'Website mail', 'beltoft-services' ), 'quota' => (string) min( self::SYSTEM_QUOTA_MB, $limits['quota_mb'] ), 'active' => '1' ) ) )
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

		// The system mailbox follows the rule of its domain: no sending while SPF and DKIM are not found there.
		$held = MailHold::on_new_mailbox( Resource::find( $child->id() ) ?? $child, $client, $address );

		if ( is_wp_error( $held ) ) {
			return StepResult::retry( 300, 'mail: ' . $this->scrub( $held->get_error_message(), 160 ) );
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
	 * @param string $mode     system.
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
