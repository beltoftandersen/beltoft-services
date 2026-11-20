<?php
/**
 * The last step of a webhotel's process line: the domain of the order joins the website, or the customer points their own domain.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Hosting;

use BeltoftServices\Domains\HostName;
use BeltoftServices\Drivers\ProvisionSteps;
use BeltoftServices\Drivers\Registry;
use BeltoftServices\Resource;

defined( 'ABSPATH' ) || exit;

/**
 * No domain logic of its own: it reads what SiteNewDomain (a domain of the same order, META_DONE joined, expired or refused) and
 * SiteDomains (an own domain, external, waiting or active) already keep, and adds one step to the row of the order's services
 * (filter beltoft_services_thankyou_row), so the order received page, the overview's process lines and both order progress routes
 * show it. Progress::build() and its golden file are untouched, so the hosting page does not change.
 *
 * Steps: "Connect domain" (a domain bought or transferred in the order) is todo while the account is built, current while the domain's
 * own line is not ready, done when it joined, skipped (neutral, not red) when the wait ended without it. "Point your domain here"
 * (an own domain on external DNS) is current until its records are found (state active), then done.
 */
final class SiteDomainStep {

	public const CONNECT = 'connect_domain';
	public const POINT   = 'point_domain';

	/**
	 * Hooks. After HostingSettings (30), which sets the row's message.
	 */
	public static function register(): void {
		add_filter( 'beltoft_services_thankyou_row', array( __CLASS__, 'row' ), 35, 4 );
	}

	/**
	 * Adds the step to a hosting row.
	 *
	 * @param mixed $row     The row so far.
	 * @param mixed $r       Resource.
	 * @param mixed $license Licence row (unused).
	 * @param mixed $group   Group of the row.
	 * @return mixed
	 */
	public static function row( $row, $r, $license = null, $group = '' ) {
		unset( $license );

		if ( ! is_array( $row ) || ! $r instanceof Resource || HostingDriver::ID !== (string) $r->get( 'driver' ) || 'failed' === $group || ! isset( $row['progress'] ) || ! is_array( $row['progress'] ) ) {
			return $row;
		}

		$ready = 'ready' === $group;
		$url   = (string) ( $row['url'] ?? '' );
		$step  = self::step( $r, $ready, $url );

		if ( null === $step ) {
			return $row;
		}

		$steps = (array) ( $row['progress']['steps'] ?? array() );

		// A ready account has no build steps any more: they are all done, and the new step follows them.
		if ( ! $steps && $ready ) {
			$found = Registry::get( HostingDriver::ID );

			foreach ( $found instanceof ProvisionSteps ? $found->step_labels() : array() as $key => $label ) {
				$steps[] = array( 'key' => (string) $key, 'label' => (string) $label, 'status' => 'done', 'status_label' => \BeltoftServices\Progress::status_label( 'done' ) );
			}
		}

		$steps[] = array(
			'key'          => $step['key'],
			'label'        => $step['label'],
			'status'       => $step['status'],
			'status_label' => \BeltoftServices\Progress::status_label( $step['status'] ),
		);

		$row['progress']['steps'] = $steps;

		// A step that waits on the domain keeps the line running (and polled) until the domain has joined or the wait has ended.
		if ( $ready && 'current' === $step['status'] ) {
			$row['progress']['phase']  = 'running';
			$row['progress']['active'] = true;
			$row['progress']['text']   = __( 'Waiting for the domain.', 'beltoft-services' );
			$row['progress']['leave']  = '';
		}

		if ( $ready && in_array( $step['status'], array( 'skipped', 'current' ), true ) && '' !== $step['link'] ) {
			$row['progress']['note'] = $step['text'];
			$row['progress']['link'] = array( 'url' => $step['link'], 'label' => $step['link_label'] );
		}

		return $row;
	}

	/**
	 * The step of an account, or null when it has no domain to wait for.
	 *
	 * @param Resource $r     Hosting resource.
	 * @param bool     $ready Whether the account itself is ready.
	 * @param string   $url   The account's page in My Account.
	 * @return array{key: string, label: string, status: string, text: string, link: string, link_label: string}|null
	 */
	private static function step( Resource $r, bool $ready, string $url ): ?array {
		$name = (string) $r->get( SiteNewDomain::META_DOMAIN );

		if ( '' !== $name ) {
			$done = (string) $r->get( SiteNewDomain::META_DONE );
			$base = array( 'key' => self::CONNECT, 'label' => __( 'Connect domain', 'beltoft-services' ), 'link' => $url, 'link_label' => __( 'Connect it from your hosting page', 'beltoft-services' ) );

			if ( 'joined' === $done ) {
				return $base + array( 'status' => 'done', 'text' => '' );
			}

			if ( SiteNewDomain::not_joined( $r ) ) {
				return $base + array( 'status' => 'skipped', 'text' => SiteNewDomain::hint( $r ) );
			}

			// A pending domain joins by itself: no link to connect it by hand (array_merge, a union would keep the base link).
			return array_merge(
				$base,
				array(
					'status' => $ready ? 'current' : 'todo',
					'text'   => SiteNewDomain::pending_text( $r ),
					'link'   => '',
				)
			);
		}

		$site   = $r->get( 'site' );
		$domain = is_array( $site ) ? (string) ( $site['domain'] ?? '' ) : '';

		if ( '' === $domain ) {
			return null;
		}

		foreach ( SiteDomains::for_resource( $r->id() ) as $row ) {
			if ( 'external' !== $row['kind'] || $row['host'] !== $domain ) {
				continue;
			}

			$active = 'active' === $row['state'];

			return array(
				'key'        => self::POINT,
				'label'      => __( 'Point your domain here', 'beltoft-services' ),
				'status'     => $active ? 'done' : ( $ready ? 'current' : 'todo' ),
				/* translators: %s: domain name. */
				'text'       => $active ? '' : sprintf( __( 'Point %s to your hosting with the DNS records on your hosting page. This step is done when we find them.', 'beltoft-services' ), HostName::display( $domain ) ),
				'link'       => $url,
				'link_label' => __( 'See the DNS records', 'beltoft-services' ),
			);
		}

		return null;
	}
}
