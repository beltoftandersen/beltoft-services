<?php
/**
 * A domain bought in the same order as a hosting plan joins the website when it is ready.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Hosting;

use BeltoftLicenses\Licenses;
use BeltoftServices\Domains\HostName;
use BeltoftServices\Emails\Emails;
use BeltoftServices\Resource;

defined( 'ABSPATH' ) || exit;

/**
 * The website starts on its temporary address (nothing is attached at provisioning). The resource meta `site_new_domain`
 * names the domain of the order and `site_new_domain_until` is the day the wait ends (7 days). When the registration or
 * transfer reaches ready, or the hosting account does (whichever comes last), the domain is checked again (the same
 * customer owns both licenses, the account is live) and attached through HostingActions::add_domain, the same way as a
 * domain the customer connects later. Past the deadline, or when it is refused, nothing breaks: the site stays on the
 * temporary address and the account page offers the normal "connect a domain" option.
 *
 * This is a background follow-up of the order, not a customer's change, and it has no loop of its own: the attach is queued as
 * a change of the account through the shared controller (HostingActions::queue(), the same checks as a customer's), and when
 * the account is busy or not ready the domain sweep (DomainSweep, every quarter of an hour) asks again, until the deadline. The
 * state is the resource meta below, which the sweep reads.
 */
final class SiteNewDomain {

	public const META_DOMAIN = 'site_new_domain';
	public const META_UNTIL  = 'site_new_domain_until';
	public const META_DONE   = 'site_new_domain_done';
	public const META_WHY    = 'site_new_domain_why';
	public const META_AT     = 'site_new_domain_at';
	public const META_TOLD   = 'site_new_domain_told';

	/**
	 * Days the account page keeps the "now runs on your domain" notice.
	 */
	public const JOINED_NOTICE_DAYS = 14;

	/**
	 * Hooks.
	 */
	public static function register(): void {
		add_action( 'beltoft_services_state_changed', array( __CLASS__, 'on_state_changed' ), 10, 3 );
	}

	/**
	 * Action beltoft_services_state_changed.
	 *
	 * @param mixed  $r   Resource.
	 * @param string $old Old state.
	 * @param string $new New state.
	 */
	public static function on_state_changed( $r, $old, $new ): void {
		unset( $old );

		if ( 'ready' !== $new || ! $r instanceof Resource ) {
			return;
		}

		$order_id = (int) ( $r->license()['order_id'] ?? 0 );

		if ( $order_id <= 0 ) {
			return;
		}

		foreach ( Licenses::where( 'order_id', $order_id ) as $other ) {
			$one = Resource::for_license( (int) $other['id'] );

			if ( $one && HostingDriver::ID === $one->get( 'driver' ) && '' !== (string) $one->get( self::META_DOMAIN ) ) {
				self::attempt( $one );
			}
		}
	}

	/**
	 * The domain sweep's look at one account: a domain of the order that is waiting is attached when everything is in place.
	 *
	 * @param Resource $r Hosting resource.
	 */
	public static function sweep( Resource $r ): void {
		if ( '' === (string) $r->get( self::META_DOMAIN ) || $r->get( self::META_DONE ) ) {
			return;
		}

		// The wait is over and the domain never became ready: say so once (attempt() only gets as far as the deadline when it is ready).
		if ( time() > (int) $r->get( self::META_UNTIL ) ) {
			$r->set( array( self::META_DONE => 'expired' ) );
			self::tell( $r, 'domain_expired' );

			return;
		}

		self::attempt( $r );
	}

	/**
	 * The domain resource of the same order that holds the name, when it is ready and the same customer's.
	 *
	 * @param Resource $r Hosting resource.
	 */
	private static function domain_ready( Resource $r ): bool {
		$name  = (string) $r->get( self::META_DOMAIN );
		$order = (int) ( $r->license()['order_id'] ?? 0 );
		$user  = (int) ( $r->license()['user_id'] ?? 0 );

		foreach ( Licenses::where( 'order_id', $order ) as $other ) {
			$one = Resource::for_license( (int) $other['id'] );

			if ( $one && 'domain' === $one->get( 'driver' ) && $name === (string) $one->get( 'domain' ) && 'ready' === (string) $one->get( 'state' ) && (int) $other['user_id'] === $user && $user > 0 ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * The domain of the order that is still waiting to join the website, or ''. Used by the thank-you page and the
	 * welcome email to say that the website starts on its temporary address and the domain follows by itself.
	 *
	 * @param Resource $r Hosting resource.
	 */
	public static function pending( Resource $r ): string {
		$name = (string) $r->get( self::META_DOMAIN );

		if ( '' === $name || $r->get( self::META_DONE ) || time() > (int) $r->get( self::META_UNTIL ) ) {
			return '';
		}

		return $name;
	}

	/**
	 * The customer text for a pending domain, or '' when none is pending.
	 *
	 * @param Resource $r Hosting resource.
	 */
	public static function pending_text( Resource $r ): string {
		$name = self::pending( $r );

		if ( '' === $name ) {
			return '';
		}

		$shown = \BeltoftServices\Domains\HostName::display( $name );

		return 'transfer' === self::domain_mode( $r )
			/* translators: %s: domain name. */
			? sprintf( __( 'Your website starts on a temporary address. %s is connected to it automatically when the transfer of the domain is done.', 'beltoft-services' ), $shown )
			/* translators: %s: domain name. */
			: sprintf( __( 'Your website starts on a temporary address. %s is connected to it automatically when the domain registration is done.', 'beltoft-services' ), $shown );
	}

	/**
	 * How the order's domain comes to us: register or transfer ('' when its resource is not found).
	 *
	 * @param Resource $r Hosting resource.
	 */
	private static function domain_mode( Resource $r ): string {
		$name  = (string) $r->get( self::META_DOMAIN );
		$order = (int) ( $r->license()['order_id'] ?? 0 );

		foreach ( $order > 0 ? Licenses::where( 'order_id', $order ) : array() as $other ) {
			$one = Resource::for_license( (int) $other['id'] );

			if ( $one && 'domain' === $one->get( 'driver' ) && $name === (string) $one->get( 'domain' ) ) {
				return (string) $one->get( 'mode' );
			}
		}

		return '';
	}

	/**
	 * Attaches the domain when everything is in place.
	 *
	 * @param Resource $r Hosting resource.
	 */
	public static function attempt( Resource $r ): void {
		$r    = Resource::find( $r->id() ) ?? $r;
		$name = (string) $r->get( self::META_DOMAIN );

		if ( '' === $name || $r->get( self::META_DONE ) || 'ready' !== (string) $r->get( 'state' ) || ! self::domain_ready( $r ) ) {
			return;
		}

		if ( time() > (int) $r->get( self::META_UNTIL ) ) {
			$r->set( array( self::META_DONE => 'expired' ) );
			self::tell( $r, 'domain_expired' );

			return;
		}

		$site = Sites::for_resource( $r->id() )[0] ?? null;
		$out  = null === $site ? new \WP_Error( 'no_site', 'the website does not exist yet' ) : ( new HostingActions( $r ) )->queue( 'add_domain', array( (string) $site['slug'], $name ) );

		// Read fresh: another run of the same event may have finished while this one worked.
		$r = Resource::find( $r->id() ) ?? $r;

		if ( $r->get( self::META_DONE ) ) {
			return;
		}

		$row = SiteDomains::find( $name );

		if ( is_array( $out ) || ( null !== $row && $row['resource_id'] === $r->id() ) ) {
			$r->set( array( self::META_DONE => 'joined', self::META_AT => time() ) );
			self::tell( $r, 'domain_joined' );

			return;
		}

		$code = $out->get_error_code();

		// Busy or not ready yet: the domain sweep asks again until the deadline. Anything else is final.
		if ( in_array( $code, array( 'busy', 'not_ready', 'no_site' ), true ) ) {
			return;
		}

		$r->set( array( self::META_DONE => 'refused', self::META_WHY => $code ) );
		$order = wc_get_order( (int) ( $r->license()['order_id'] ?? 0 ) );

		if ( $order ) {
			/* translators: 1: domain name, 2: reason. */
			$order->add_order_note( sprintf( __( 'The domain %1$s was not connected to the website: %2$s.', 'beltoft-services' ), HostName::display( $name ), self::reason( $code ) ) );
		}
	}

	/**
	 * A plain reason for a refusal code.
	 *
	 * @param string $code Error code of HostingActions.
	 */
	public static function reason( string $code ): string {
		switch ( $code ) {
			case 'addon_required':
				return __( 'your account already has another domain and an extra domain is an add-on', 'beltoft-services' );
			case 'invalid':
				return __( 'the domain cannot be used for hosting', 'beltoft-services' );
			default:
				return __( 'it could not be connected', 'beltoft-services' );
		}
	}

	/**
	 * Did the wait end without the domain joining (the deadline passed, or it was refused)?
	 *
	 * @param Resource $r Hosting resource.
	 */
	public static function not_joined( Resource $r ): bool {
		if ( '' === (string) $r->get( self::META_DOMAIN ) ) {
			return false;
		}

		$done = (string) $r->get( self::META_DONE );

		return in_array( $done, array( 'refused', 'expired' ), true ) || ( '' === $done && time() > (int) $r->get( self::META_UNTIL ) );
	}

	/**
	 * The text for a wait that ended with the domain not connected: why, and what to do.
	 *
	 * @param Resource $r Hosting resource.
	 */
	public static function expired_text( Resource $r ): string {
		/* translators: 1: domain name, 2: days. */
		return sprintf( __( 'The domain %1$s was not ready within %2$d days, so it was not connected. Connect it from this page when the transfer is done.', 'beltoft-services' ), HostName::display( (string) $r->get( self::META_DOMAIN ) ), (int) \BeltoftServices\Hosting\SiteField::WAIT_DAYS );
	}

	/**
	 * The notice for the account page after the domain joined the website (for a while), or ''.
	 *
	 * @param Resource $r Hosting resource.
	 */
	public static function joined_text( Resource $r ): string {
		$at = (int) $r->get( self::META_AT );

		if ( 'joined' !== (string) $r->get( self::META_DONE ) || $at < time() - self::JOINED_NOTICE_DAYS * DAY_IN_SECONDS ) {
			return '';
		}

		/* translators: %s: domain name. */
		return sprintf( __( 'Your website now runs on %s. The WordPress login address is now on the domain, and the https certificate follows within a few minutes.', 'beltoft-services' ), HostName::display( (string) $r->get( self::META_DOMAIN ) ) );
	}

	/**
	 * Tells the customer once, by email, how the wait ended.
	 *
	 * @param Resource $r    Hosting resource.
	 * @param string   $kind domain_joined or domain_expired (kinds of HostingNotice).
	 */
	private static function tell( Resource $r, string $kind ): void {
		if ( $r->get( self::META_TOLD ) ) {
			return;
		}

		$r->set( array( self::META_TOLD => time() ) );
		Emails::send( 'beltoft_services_hosting_notice', $r, array( 'hosting_notice' => $kind, 'site' => (string) $r->get( self::META_DOMAIN ), 'days' => (int) SiteField::WAIT_DAYS ) );
	}

	/**
	 * The hint for the account page when the order's domain was not connected, or an empty string.
	 *
	 * @param Resource $r Hosting resource.
	 */
	public static function hint( Resource $r ): string {
		$name = (string) $r->get( self::META_DOMAIN );

		if ( '' === $name ) {
			return '';
		}

		if ( 'refused' !== (string) $r->get( self::META_DONE ) ) {
			return self::not_joined( $r ) ? self::expired_text( $r ) : '';
		}

		/* translators: 1: domain name, 2: reason. */
		return sprintf( __( 'The domain %1$s from your order was not connected: %2$s. Connect it below or contact support.', 'beltoft-services' ), HostName::display( $name ), self::reason( (string) $r->get( self::META_WHY ) ) );
	}
}
