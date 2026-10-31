<?php
/**
 * What the read tools say about one service, built from the classes the account pages use.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Mcp;

use BeltoftServices\Account\DomainRows;
use BeltoftServices\Account\Domains;
use BeltoftServices\Account\Panel;
use BeltoftServices\Account\Servers;
use BeltoftServices\Drivers\Registry;
use BeltoftServices\Proxmox\Backups;
use BeltoftServices\Proxmox\DbAccess;
use BeltoftServices\Proxmox\DbCard;
use BeltoftServices\Proxmox\DbDriver;
use BeltoftServices\Proxmox\Firewall;
use BeltoftServices\Proxmox\GuestDisk;
use BeltoftServices\Proxmox\LiveUsage;
use BeltoftServices\Proxmox\Operations;
use BeltoftServices\Proxmox\PrivateAttach;
use BeltoftServices\Proxmox\Snapshots;
use BeltoftServices\Resource;

defined( 'ABSPATH' ) || exit;

/**
 * Every value here is picked by name from what those classes return: their views also carry links with nonces,
 * the customer's own address and button state, which must never reach a result.
 */
final class Details {

	/**
	 * Everything service_details says about one service.
	 *
	 * @param Resource $r Service, already checked to be the customer's.
	 * @return array<string, mixed>
	 */
	public static function of( Resource $r ): array {
		$license = $r->license();
		$driver  = Registry::get( (string) $r->get( 'driver' ) );
		$kind    = $driver ? $driver->kind() : '';
		$state   = (string) $r->get( 'state' );
		$offered = $driver ? array_values( array_map( 'strval', $driver->customer_actions( $r ) ) ) : array();

		$out = array(
			'id'                  => $r->id(),
			'license_id'          => (int) ( $license['id'] ?? 0 ),
			'type'                => $kind,
			'name'                => (string) apply_filters( 'beltoft_licenses_account_title', \BeltoftLicenses\Account\View::product_name( $license ), $license ),
			'state'               => $state,
			'status'              => Panel::label( $state, $r ),
			'renews'              => Servers::renewal( $license ),
			'subscription'        => Reads::subscription_summary( (int) ( $license['subscription_id'] ?? 0 ) ),
			'dashboard_offers'    => $offered,
			'dashboard_page'      => self::page( $r, $kind, $license ),
		);

		if ( 'vm' === $kind ) {
			$out += array( 'server' => self::vm( $r ) + self::vm_extras( $r ) );
		} elseif ( DbDriver::ID === $r->get( 'driver' ) ) {
			$out += array( 'database' => self::database( $r ) );
		} elseif ( in_array( $kind, array( 'domain', 'dns' ), true ) ) {
			$out += array( 'domain' => self::domain( $r ) );
		}

		return $out;
	}

	/**
	 * The plain address of the service's page in the dashboard (no login, no nonce), or ''.
	 *
	 * @param Resource             $r       Service.
	 * @param string               $kind    Driver kind.
	 * @param array<string, mixed> $license License.
	 * @param string               $tab     A tab of the page, or ''.
	 */
	public static function page( Resource $r, string $kind, array $license, string $tab = '' ): string {
		if ( 'vm' === $kind ) {
			return Servers::url( $license, $tab );
		}

		if ( in_array( $kind, array( 'domain', 'dns' ), true ) ) {
			return Domains::url( $license, $tab );
		}

		return '';
	}

	/**
	 * A server's live figures and what it is, from stored readings (no cluster call).
	 *
	 * @param Resource $r Server.
	 * @return array<string, mixed>
	 */
	public static function vm( Resource $r ): array {
		$plan   = (array) $r->get( 'plan' );
		$cached = Operations::cached_status( $r->id() );
		$guest  = LiveUsage::cached_memory( $r->id() );
		$disk   = GuestDisk::cached( $r );
		$memory = (int) ( $plan['memory_mb'] ?? 0 ) * 1048576;

		return array(
			'power'          => null === $cached ? '' : (string) $cached['power'],
			'cpu_percent'    => null !== $cached && 'running' === $cached['power'] ? max( 0, min( 100, (int) round( (float) $cached['cpu'] * 100 ) ) ) : null,
			'memory_percent' => null !== $guest ? $guest : ( null !== $cached && 'running' === $cached['power'] && $memory > 0 ? max( 0, min( 100, (int) round( (int) $cached['mem'] / $memory * 100 ) ) ) : null ),
			'disk_percent'   => null !== $disk ? max( 0, min( 100, (int) round( $disk['used'] / $disk['total'] * 100 ) ) ) : null,
			'ip'             => false !== filter_var( (string) $r->get( 'ip' ), FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ? (string) $r->get( 'ip' ) : '',
			'system'         => Servers::os_label( $r ),
			'size'           => sprintf( '%d vCPU, %s GB memory, %d GB disk', (int) ( $plan['cores'] ?? 0 ), (string) round( (int) ( $plan['memory_mb'] ?? 0 ) / 1024, 1 ), (int) ( $plan['disk_gb'] ?? 0 ) ),
			'app'            => '' !== (string) $r->get( 'app_id' ) ? (string) $r->get( 'app_id' ) : '',
		);
	}

	/**
	 * What belongs to a server besides its figures: firewall, private network, snapshots and backups.
	 *
	 * @param Resource $r Server.
	 * @return array<string, mixed>
	 */
	private static function vm_extras( Resource $r ): array {
		$out = array( 'app_state' => (string) $r->get( 'app_state' ) );

		if ( Firewall::editable( $r ) ) {
			$view = Firewall::view( $r );

			$out['firewall'] = array(
				'on'      => $view['on'],
				'rules'   => count( $view['rules'] ),
				'applied' => $view['applied'],
				'state'   => $view['state'],
			);
		}

		if ( PrivateAttach::eligible( $r ) ) {
			$state                  = (string) $r->get( PrivateAttach::STATE );
			$out['private_network'] = '' === $state ? 'detached' : $state;
		}

		$snapshots = Snapshots::panel( $r );

		if ( null !== $snapshots ) {
			$out['snapshots'] = array( 'enabled' => $snapshots['enabled'], 'slots' => $snapshots['slots'], 'used' => $snapshots['used'] );
		}

		$out['backups'] = array(
			'enabled' => Backups::enabled( $r ),
			'days'    => Backups::days( $r ),
			'last_ok' => Backups::listing( $r )['last_ok'],
		);

		return $out;
	}

	/**
	 * A database: how to connect, backups and the access list. No password, no certificate, no link.
	 *
	 * @param Resource $r Database.
	 * @return array<string, mixed>
	 */
	private static function database( Resource $r ): array {
		$card   = DbCard::view( $r );
		$access = $card['access'];

		return array(
			'engine'          => (string) $card['engine'],
			'version'         => (int) $card['version'],
			'state'           => (string) $card['kind'],
			'host'            => (string) $card['host'],
			'port'            => (int) $card['port'],
			'database'        => (string) $card['database'],
			'user'            => (string) $card['user'],
			'tls_fingerprint' => (string) $card['fingerprint'],
			'size'            => (string) $card['size'],
			'cost_so_far'     => (string) $card['cost'],
			'backups'         => array(
				'enabled'   => (bool) $card['backups']['enabled'],
				'days'      => (int) $card['backups']['days'],
				'last_ok'   => (string) $card['backups']['last_ok'],
				'last_dump' => (string) $card['backups']['last_dump'],
			),
			'public_access'   => array(
				'address'  => (string) $access['address'],
				'open_to_everyone' => (bool) $access['world'],
				'state'    => (string) $access['state'],
				'editable' => (bool) $access['editable'],
				'entries'  => array_map( static fn( $e ) => array( 'address' => (string) $e['cidr'], 'label' => (string) $e['label'] ), (array) $access['entries'] ),
				'max'      => (int) $access['max'],
			),
		);
	}

	/**
	 * The access list of a database, the part get_database_access adds to the route's state.
	 *
	 * @param Resource $r Database.
	 * @return array<string, mixed>
	 */
	public static function access( Resource $r ): array {
		$view = DbAccess::view( $r );

		return array(
			'public_address'   => (string) $view['address'],
			'open_to_everyone' => (bool) $view['world'],
			'editable'         => (bool) $view['editable'],
			'max'              => (int) $view['max'],
			'entries'          => array_map( static fn( $e ) => array( 'address' => (string) $e['cidr'], 'label' => (string) $e['label'], 'added' => (string) $e['added'] ), (array) $view['entries'] ),
		);
	}

	/**
	 * A domain or DNS hosting service.
	 *
	 * @param Resource $r Domain or DNS service.
	 * @return array<string, mixed>
	 */
	private static function domain( Resource $r ): array {
		$dns_only = 'dns' === $r->get( 'driver' );

		return array(
			'name'             => (string) $r->get( 'domain' ),
			'what'             => DomainRows::kind( $r ),
			'dns_hosted_here'  => ! (bool) $r->get( 'dns_external' ),
			'name_servers'     => $dns_only ? array() : array_values( array_map( 'strval', (array) $r->get( 'nameservers' ) ) ),
			'name_server_mode' => $dns_only ? '' : (string) $r->get( 'ns_mode' ),
			'transfer_locked'  => $dns_only ? null : (bool) $r->get( 'is_locked' ),
			'expires'          => (string) $r->get( 'expires_at' ),
		);
	}
}
