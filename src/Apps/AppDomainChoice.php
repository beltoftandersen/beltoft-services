<?php
/** The domain choice of a form (reinstall, change of domain), checked in one place. @package BeltoftServices */

namespace BeltoftServices\Apps;

use BeltoftServices\Proxmox\Network\IpPool;
use BeltoftServices\Resource;

defined( 'ABSPATH' ) || exit;

/**
 * The posted names are `app_domain_mode` (keep, change, own, none), `app_zone`, `app_sub` and `app_own`. The same words and
 * refusals serve the reinstall form and the change of domain on a running app, so the two cannot drift apart.
 */
final class AppDomainChoice {

	/** The name the app is served on, or is going to be: a published name, else a wanted one, else an own domain waiting. */
	public static function current( Resource $r ): string {
		$published = (string) $r->get( 'app_domain' );
		$wanted    = '' !== $published ? $published : (string) $r->get( 'app_domain_want' );
		return '' !== $wanted ? $wanted : (string) $r->get( 'app_domain_own' );
	}

	/**
	 * The effective domain choice, including a same-name change that is really a keep.
	 *
	 * @param array<string, mixed> $args      Posted arguments.
	 * @param array<string, mixed> $app       App definition, or empty for no app.
	 * @param bool                 $check_dns Look at the zone: a name that has a record is refused.
	 * @return array{mode: string, zone: string, fqdn: string}|string The choice, or the refusal text.
	 */
	public static function resolve( Resource $r, array $args, array $app, bool $check_dns = true ) {
		$current = self::current( $r );
		$raw     = $args['app_domain_mode'] ?? '';
		$mode    = '' === $raw ? ( '' === $current ? 'none' : 'keep' ) : $raw;

		if ( ! $app ) {
			return array( 'mode' => 'none', 'zone' => '', 'fqdn' => '' );
		}
		if ( empty( $app['domain'] ) ) {
			if ( in_array( $raw, array( 'change', 'keep', 'own' ), true ) ) {
				return __( 'This app does not use a domain at install. You can add one later.', 'beltoft-services' );
			}
			return array( 'mode' => 'none', 'zone' => '', 'fqdn' => '' );
		}
		if ( 'none' === $mode ) {
			return array( 'mode' => 'none', 'zone' => '', 'fqdn' => '' );
		}
		if ( 'keep' === $mode ) {
			return '' === $current ? __( 'This server has no domain to keep.', 'beltoft-services' ) : array( 'mode' => 'keep', 'zone' => (string) $r->get( 'app_zone' ), 'fqdn' => $current );
		}
		if ( 'own' === $mode ) {
			$own = AppOwnDomain::choose( $app, $args['app_own'] ?? '' );
			if ( is_string( $own ) ) {
				return $own;
			}
			return $current === $own['fqdn'] ? array( 'mode' => 'keep', 'zone' => (string) $r->get( 'app_zone' ), 'fqdn' => $current ) : array( 'mode' => 'own', 'zone' => '', 'fqdn' => $own['fqdn'] );
		}
		if ( 'change' !== $mode ) {
			return __( 'Choose a domain option.', 'beltoft-services' );
		}

		$ip     = IpPool::for_resource( $r->id() );
		$choice = AppDomains::choose( $app, (string) ( $args['app_zone'] ?? '' ), $args['app_sub'] ?? '', (int) ( $r->license()['user_id'] ?? 0 ), $check_dns, (string) ( $ip['address'] ?? '' ) );
		if ( is_string( $choice ) ) {
			return $choice;
		}
		return $current === $choice['fqdn'] ? array( 'mode' => 'keep', 'zone' => (string) $r->get( 'app_zone' ), 'fqdn' => $current ) : array( 'mode' => 'change', 'zone' => $choice['zone'], 'fqdn' => $choice['fqdn'] );
	}

	/**
	 * Refuses a move away from a name whose old record cannot safely be remembered.
	 *
	 * @param array{mode: string} $choice From resolve().
	 */
	public static function release_refusal( Resource $r, array $choice ): ?string {
		$published = (string) $r->get( 'app_domain' );
		if ( '' === $published || true !== $r->get( 'app_domain_made' ) || '' === (string) $r->get( 'app_zone' ) || 'keep' === $choice['mode'] ) {
			return null;
		}
		$ip = IpPool::for_resource( $r->id() );
		if ( '' === (string) ( $ip['address'] ?? $r->get( 'ip' ) ) ) {
			return __( 'The old domain record cannot be checked right now. Try again later.', 'beltoft-services' );
		}
		$pending = (array) $r->get( 'app_domain_old' );
		return count( $pending ) >= 3 ? __( 'The old domain records are still being checked. Try again later.', 'beltoft-services' ) : null;
	}
}
