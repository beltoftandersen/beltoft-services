<?php
/**
 * What an app on the customer's own domain tells the DNS watch.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Apps;

use BeltoftServices\Account\ServiceArea;
use BeltoftServices\Domains\DnsQuery;
use BeltoftServices\Domains\DnsWatchFeature;
use BeltoftServices\Domains\HostName;
use BeltoftServices\Proxmox\Vm\ProxmoxDriver;
use BeltoftServices\Resource;

defined( 'ABSPATH' ) || exit;

/**
 * The app's part of the shared DNS watch (Domains\DnsWatch). One needed record: the A record of the name, which must point to this
 * server and nowhere else, with no AAAA record next to it (the server has no IPv6 address for it). Before the install the watch holds
 * it (the wait box, Check now, the install queued when the name is found). After the install it looks once a day and warns the
 * customer when the name no longer points to the server. It never stops, changes or reinstalls anything.
 *
 * Nothing moves: the status word live is read as ok (status_out) and written back as live (status_in), and app_domain_dns_reason and
 * app_domain_dns_seen keep being written for the box. New keys appear when the watch first writes them: app_domain_dns_rows,
 * app_domain_dns_mode and the shared `_rechecked`, `_lost_*` marks.
 */
final class AppDnsWatch implements DnsWatchFeature {

	public const ID = 'app';

	public function id(): string {
		return self::ID;
	}

	public function label(): string {
		return 'App';
	}

	public function noun(): string {
		return 'app domain DNS';
	}

	public function kind(): string {
		return 'vm';
	}

	public function prefix(): string {
		return 'app_domain_dns';
	}

	public function rows_key(): string {
		return 'app_domain_dns_rows';
	}

	public function mode_key(): string {
		return 'app_domain_dns_mode';
	}

	public function hook( string $job ): string {
		return 'look' === $job ? AppOwnDomain::HOOK : 'beltoft_services_dns_watch_' . $job . '_' . self::ID;
	}

	public function shows_status( Resource $r ): bool {
		// Not while the install waits for the name: the progress box says it there.
		return AppOwnDomain::lost_or_live( $r );
	}

	public function owns( Resource $r ): bool {
		return ProxmoxDriver::ID === $r->get( 'driver' ) && '' !== (string) $r->get( 'app_domain_own' );
	}

	public function active( Resource $r ): bool {
		if ( 'ready' !== (string) $r->get( 'state' ) ) {
			return false;
		}

		// A name that never pointed to the server (the app was installed on the address) is not looked at again.
		return ! ( 'timeout' === (string) $r->get( 'app_domain_dns' ) && '' === (string) $r->get( 'app_domain' ) );
	}

	public function domain( Resource $r ): string {
		return (string) $r->get( 'app_domain_own' );
	}

	public function zone_user( Resource $r ): int {
		// Never zero for a zone of ours: the watch of an own domain writes no DNS, whatever the customer's zones are.
		unset( $r );

		return 0;
	}

	public function on_our_servers( Resource $r ): bool {
		unset( $r );

		return false;
	}

	public function needed(): array {
		return array( 'a' );
	}

	public function required( string $key ): bool {
		return 'a' === $key;
	}

	public function rows( Resource $r ): array {
		$stored = (array) $r->get( $this->rows_key() );
		$old    = is_array( $stored[0] ?? null ) ? $stored[0] : array();
		$dns    = (string) $r->get( 'app_domain_dns' );
		$ready  = 'ready' === (string) $r->get( 'state' );

		return array(
			array(
				'key'      => 'a',
				'name'     => (string) $r->get( 'app_domain_own' ),
				'type'     => 'A',
				'ttl'      => 3600,
				'priority' => 0,
				// The address is the server's now, and none is shown or looked for before the server is ready.
				'value'    => $ready ? AppOwnDomain::address( $r ) : '',
				'status'   => (string) ( $old['status'] ?? ( 'live' === $dns ? 'ok' : 'missing' ) ),
				'reason'   => (string) ( $old['reason'] ?? '' ),
				'seen'     => array_values( array_filter( (array) ( $old['seen'] ?? $r->get( 'app_domain_dns_seen' ) ), 'is_string' ) ),
			),
		);
	}

	public function status_in( string $status ): string {
		return 'ok' === $status ? 'live' : $status;
	}

	public function status_out( string $status ): string {
		return 'live' === $status ? 'ok' : $status;
	}

	public function before_look( Resource $r ): bool {
		if ( 'ready' !== (string) $r->get( 'state' ) ) {
			return false;
		}

		// The clock of the wait starts when the server is ready; start() writes it and queues the first look.
		if ( 0 === (int) $r->get( 'app_domain_dns_since' ) ) {
			AppOwnDomain::start( $r );

			return false;
		}

		return true;
	}

	public function mails( string $event, Resource $r ): bool {
		unset( $event );

		// While the install waits for the name nothing is mailed: the customer has the box, as before the watch.
		return '' !== (string) $r->get( 'app_domain' );
	}

	public function read_row( array $row, string $domain ): ?array {
		$ip = (string) ( $row['value'] ?? '' );

		if ( '' === $ip ) {
			return null;
		}

		$a    = DnsQuery::addresses( $domain, 'A' );
		$aaaa = DnsQuery::addresses( $domain, 'AAAA' );

		if ( is_wp_error( $a ) ) {
			// A lookup that fails learns nothing about a name that was found; for one still awaited it is shown as such.
			if ( 'ok' === ( $row['status'] ?? '' ) ) {
				return null;
			}

			$row['status'] = 'missing';
			$row['reason'] = 'unreachable';

			return $row;
		}

		$verdict = AppOwnDomain::verdict( $ip, $a, is_wp_error( $aaaa ) ? array() : $aaaa );

		$row['status'] = 'live' === $verdict ? 'ok' : 'missing';
		$row['reason'] = 'live' === $verdict ? '' : $verdict;
		$row['seen']   = array_values( array_unique( array_filter( $a, static fn( $x ): bool => false !== filter_var( $x, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) ) );

		return $row;
	}

	public function publish( int $user_id, string $domain, array $row ) {
		unset( $user_id, $domain, $row );

		return new \WP_Error( 'not_ours', 'The DNS of an own domain is never written.' );
	}

	public function missing_rows( Resource $r, bool $needed_only = false ): array {
		unset( $needed_only );

		$out = array();

		foreach ( $this->rows( $r ) as $row ) {
			if ( 'ok' !== $row['status'] && '' !== $row['value'] ) {
				$out[] = array(
					'key'      => $row['key'],
					'name'     => $row['name'],
					'type'     => $row['type'],
					'value'    => $row['value'],
					'priority' => 0,
					'status'   => $row['status'],
				);
			}
		}

		return $out;
	}

	public function partial_state(): string {
		return '';
	}

	public function partial_word(): string {
		return '';
	}

	public function partial_applies( Resource $r, array $ok_keys ): bool {
		unset( $r, $ok_keys );

		return false;
	}

	public function held( Resource $r ): bool {
		unset( $r );

		return false;
	}

	public function copy( string $event, Resource $r ): array {
		$license = $r->license();
		$app     = AppProgress::name( $r );
		$effects = array(
			'lost'    => __( 'Visitors may not reach your app at this name, and its HTTPS certificate may not renew. The app and its data are kept.', 'beltoft-services' ),
			'back'    => __( 'Your app is reached at this name again.', 'beltoft-services' ),
			'timeout' => __( 'The app and its data are kept.', 'beltoft-services' ),
		);

		return array(
			/* translators: 1: app name such as WordPress, 2: domain name. */
			'dns_subject'   => sprintf( __( '%1$s on %2$s', 'beltoft-services' ), '' !== $app ? $app : __( 'Your app', 'beltoft-services' ), HostName::display( $this->domain( $r ) ) ),
			'dns_effect'    => $effects[ $event ] ?? '',
			'dns_where'     => __( 'the App tab', 'beltoft-services' ),
			'dns_link'      => $license ? ServiceArea::url_for_license( $license, array( 'app' ) ) : '',
			'dns_open_text' => __( 'Open the App tab', 'beltoft-services' ),
		);
	}

	public function alert_lines( string $event, Resource $r, array $ctx = array() ): array {
		unset( $ctx );

		switch ( $event ) {
			case 'lost':
				return array( 'The name no longer points to the server only. The app keeps running; nothing is stopped or reinstalled. The customer was emailed.' );
			case 'no_dns':
				return array( 'The name still does not point to the server. The app and its data are kept; nothing is stopped or removed by this.' );
		}

		return array();
	}

	public function on_event( string $event, Resource $r, array $ctx = array() ): void {
		unset( $ctx );

		if ( 'changed' === $event ) {
			AppOwnDomain::sync_view( $r, $this->rows( $r )[0] );
		}

		if ( 'found' === $event ) {
			AppOwnDomain::found( $r );
		}

		if ( 'timeout' === $event ) {
			AppOwnDomain::timed_out( $r );
		}
	}
}
