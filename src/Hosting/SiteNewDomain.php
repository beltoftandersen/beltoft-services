<?php
/**
 * A domain bought in the same order as a hosting plan joins the website when it is ready.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Hosting;

use BeltoftLicenses\Licenses;
use BeltoftServices\Domains\HostName;
use BeltoftServices\Resource;

defined( 'ABSPATH' ) || exit;

/**
 * The website starts on its temporary address (nothing is attached at provisioning). The resource meta `site_new_domain`
 * names the domain of the order and `site_new_domain_until` is the day the wait ends (7 days). When the registration or
 * transfer reaches ready, or the hosting account does (whichever comes last), the domain is checked again (the same
 * customer owns both licenses, the account is live) and attached through HostingActions::add_domain, the same way as a
 * domain the customer connects later. Past the deadline, or when it is refused, nothing breaks: the site stays on the
 * temporary address and the account page offers the normal "connect a domain" option.
 */
final class SiteNewDomain {

	public const META_DOMAIN = 'site_new_domain';
	public const META_UNTIL  = 'site_new_domain_until';
	public const META_DONE   = 'site_new_domain_done';
	public const META_WHY    = 'site_new_domain_why';
	public const RETRY_HOOK  = 'beltoft_services_site_new_domain';

	/**
	 * Hooks.
	 */
	public static function register(): void {
		add_action( 'beltoft_services_state_changed', array( __CLASS__, 'on_state_changed' ), 10, 3 );
		add_action( self::RETRY_HOOK, array( __CLASS__, 'retry' ) );
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
	 * Action of the scheduled retry.
	 *
	 * @param mixed $resource_id Hosting resource id.
	 */
	public static function retry( $resource_id ): void {
		$r = Resource::find( (int) $resource_id );

		if ( $r ) {
			self::attempt( $r );
		}
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

			return;
		}

		$site = Sites::for_resource( $r->id() )[0] ?? null;
		$out  = null === $site ? new \WP_Error( 'no_site', 'the website does not exist yet' ) : ( new HostingActions( $r ) )->add_domain( (string) $site['slug'], $name );

		// Read fresh: another run of the same event may have finished while this one worked.
		$r = Resource::find( $r->id() ) ?? $r;

		if ( $r->get( self::META_DONE ) ) {
			return;
		}

		$row = SiteDomains::find( $name );

		if ( is_array( $out ) || ( null !== $row && $row['resource_id'] === $r->id() ) ) {
			$r->set( array( self::META_DONE => 'joined' ) );

			return;
		}

		$code = $out->get_error_code();

		// Busy or not ready yet: try again until the deadline, one pending retry at a time. Anything else is final.
		if ( in_array( $code, array( 'busy', 'not_ready', 'no_site' ), true ) && function_exists( 'as_schedule_single_action' ) ) {
			if ( time() + 600 <= (int) $r->get( self::META_UNTIL ) && ! ( function_exists( 'as_has_scheduled_action' ) && as_has_scheduled_action( self::RETRY_HOOK, array( $r->id() ), 'beltoft-services' ) ) ) {
				as_schedule_single_action( time() + 600, self::RETRY_HOOK, array( $r->id() ), 'beltoft-services' );
			}

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
	 * The hint for the account page when the order's domain was not connected, or an empty string.
	 *
	 * @param Resource $r Hosting resource.
	 */
	public static function hint( Resource $r ): string {
		$name = (string) $r->get( self::META_DOMAIN );

		if ( '' === $name || 'refused' !== (string) $r->get( self::META_DONE ) ) {
			return '';
		}

		/* translators: 1: domain name, 2: reason. */
		return sprintf( __( 'The domain %1$s from your order was not connected: %2$s. Connect it below or contact support.', 'beltoft-services' ), HostName::display( $name ), self::reason( (string) $r->get( self::META_WHY ) ) );
	}
}
