<?php
/**
 * The view-model of one domain's page: plain values, built once per request.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Account;

use BeltoftServices\Domains\Cart;
use BeltoftServices\Domains\Clients;
use BeltoftServices\Domains\DomainDriver;
use BeltoftServices\Domains\LiveDns;
use BeltoftServices\Domains\Panels as DomainPanels;
use BeltoftServices\Domains\Records;
use BeltoftServices\Domains\Zone;
use BeltoftServices\Resource;

defined( 'ABSPATH' ) || exit;

/**
 * Everything the page's template prints, from the resource meta and one read of the zone (with the short page
 * timeout). The template does no lookups of its own.
 */
final class DomainPage {

	/**
	 * Hooks.
	 */
	public static function register(): void {
		add_filter( 'beltoft_services_account_page_head', array( __CLASS__, 'head' ), 10, 5 );
	}

	/**
	 * Listener of beltoft_services_account_page_head: the head of a domain's page, the domain name as the title.
	 *
	 * @param mixed $handled  Whether another listener printed the head.
	 * @param mixed $resource Resource.
	 * @param mixed $license  License row.
	 * @param mixed $view     Display values of the license.
	 * @param mixed $page     The page being printed.
	 * @return mixed
	 */
	public static function head( $handled, $resource, $license = array(), $view = array(), $page = array() ) {
		unset( $license, $view );

		if ( $handled || ! $resource instanceof Resource || ! in_array( $resource->get( 'driver' ), array( 'domain', 'dns' ), true ) || ! is_array( $page ) || ! Domains::page() ) {
			return $handled;
		}

		$state = (string) $resource->get( 'state' );
		$group = Panel::group( $state, $resource );
		$vars  = array(
			'title'        => (string) $resource->get( 'domain' ),
			'group'        => $group,
			'badge'        => Panel::label( $state, $resource ),
			'message'      => Panel::message( $state, $resource ),
			'show_message' => 'ready' !== $group,
			'kind'         => DomainRows::kind( $resource ),
			'renews'       => DomainRows::renewal( $resource ),
		);

		( static function ( array $vars ): void {
			// phpcs:ignore WordPress.PHP.DontExtract.extract_extract -- Template variables, a fixed list built above.
			extract( $vars, EXTR_SKIP );
			include Domains::file( 'domain-head.php' );
		} )( $vars );

		return true;
	}

	/**
	 * Builds the values.
	 *
	 * @param Resource             $r    The domain's resource.
	 * @param array<string, mixed> $page The page being printed: license and requested tab.
	 * @return array<string, mixed>
	 */
	public static function build( Resource $r, array $page ): array {
		$domain   = (string) $r->get( 'domain' );
		$dns_only = 'dns' === $r->get( 'driver' );
		$external = ! $dns_only && (bool) $r->get( 'dns_external' );
		$ours     = ! $external && ( $dns_only || 'custom' !== $r->get( 'ns_mode' ) );
		$can      = $r->accepts_actions();
		$off      = $dns_only && (bool) $r->get( 'records_off' );

		if ( $external ) {
			$hosts = 'custom' === $r->get( 'ns_mode' ) ? (array) $r->get( 'nameservers' ) : DomainDriver::stored_nameservers( $r );
		} else {
			$hosts = $ours ? Zone::nameservers() : (array) $r->get( 'nameservers' );
		}

		// One zone read for the page, with a few seconds of patience, not the thirty a background job may take.
		$zone_unavailable = false;
		$rows             = array();

		if ( $ours && ( $can || $off ) ) {
			$client = Clients::dns();

			if ( $client instanceof LiveDns ) {
				$client = $client->with_timeout( DomainPanels::PAGE_TIMEOUT );
			}

			$zone = $client->zone( $domain );

			// Without the zone the editor would show no records: it says so instead.
			$zone_unavailable = is_wp_error( $zone );
			$rows             = is_array( $zone ) ? Records::to_rows( $domain, (array) ( $zone['rrsets'] ?? array() ) ) : array();
		}

		$expires = (string) $r->get( 'expires_at' );
		$time    = '' !== $expires ? strtotime( $expires . ' UTC' ) : false;
		$price   = null;

		if ( $dns_only && $can ) {
			$price = Cart::price_for( array( 'beltoft_services_domain' => array( 'domain' => $domain, 'mode' => 'transfer' ) ) );
		}

		$data = array(
			'r'                => $r,
			'license'          => $page['license'],
			'domain'           => $domain,
			'dns_only'         => $dns_only,
			'external'         => $external,
			'ours'             => $ours,
			'can'              => $can,
			'off'              => $off,
			'hosts'            => array_values( array_map( 'strval', $hosts ) ),
			'locked'           => (bool) $r->get( 'is_locked' ),
			'expires'          => $time ? wp_date( (string) get_option( 'date_format' ), $time ) : '',
			'renews'           => DomainRows::renewal( $r ),
			'kind'             => DomainRows::kind( $r ),
			'delegation'       => (string) $r->get( 'delegation' ),
			'parent_ds'        => true === $r->get( 'parent_ds' ),
			'import_error'     => (string) $r->get( 'import_error' ),
			'zone_unavailable' => $zone_unavailable,
			'rows'             => $rows,
			'transfer_price'   => $price,
		);

		$data['tabs']   = self::tabs( $data );
		$data['active'] = isset( $data['tabs'][ (string) ( $page['tab'] ?? '' ) ] ) ? (string) $page['tab'] : 'overview';

		return $data;
	}

	/**
	 * The tabs of the page, slug => title, only the ones that have something to show.
	 *
	 * @param array<string, mixed> $d Values so far.
	 * @return array<string, string>
	 */
	private static function tabs( array $d ): array {
		$tabs = array( 'overview' => __( 'Overview', 'beltoft-services' ) );

		// The DNS records are ours to show and change only when our name servers answer for the domain.
		if ( $d['ours'] && ( $d['can'] || $d['off'] ) ) {
			$tabs['dns'] = __( 'DNS records', 'beltoft-services' );
		}

		$tabs['nameservers'] = __( 'Name servers', 'beltoft-services' );

		if ( ( $d['can'] && ! $d['dns_only'] ) || null !== $d['transfer_price'] ) {
			$tabs['transfer'] = __( 'Transfer', 'beltoft-services' );
		}

		/**
		 * Filters the tabs of a domain page, slug => title, in the order they are shown. An extension adds its
		 * slug to beltoft_services_domain_page_tabs too and prints its pane on beltoft_services_domain_tab_pane.
		 * The slug danger is kept for the danger zone.
		 *
		 * @param array<string, string> $tabs        Tabs.
		 * @param int                   $resource_id The domain's resource id.
		 */
		$tabs = (array) apply_filters( 'beltoft_services_domain_tabs', $tabs, $d['r']->id() );

		return array_filter( array_map( 'strval', $tabs ), static fn( string $title ): bool => '' !== $title );
	}

	/**
	 * The next thing the customer has to do, as a sentence and the tab that holds it, or null.
	 *
	 * @param array<string, mixed> $d Values.
	 * @return array{text: string, tab: string, link: string}|null
	 */
	public static function next_step( array $d ): ?array {
		if ( $d['off'] ) {
			return array(
				'text' => __( 'DNS hosting is paused. Your records are kept but not answered.', 'beltoft-services' ),
				'tab'  => 'dns',
				'link' => __( 'See the records', 'beltoft-services' ),
			);
		}

		if ( $d['dns_only'] && 'ours' !== $d['delegation'] && $d['can'] ) {
			return array(
				'text' => __( 'This domain does not point at our name servers yet. Copy your DNS records here before you change the name servers at your registrar.', 'beltoft-services' ),
				'tab'  => 'nameservers',
				'link' => __( 'Show the name servers', 'beltoft-services' ),
			);
		}

		return null;
	}
}
