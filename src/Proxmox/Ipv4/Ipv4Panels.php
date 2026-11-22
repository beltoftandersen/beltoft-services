<?php
/**
 * What the dashboard shows of the IPv4 add-on: the block "IPv4 addresses" of a server page and the line of a database page.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Proxmox\Ipv4;

use BeltoftServices\Proxmox\Pve\Clusters;
use BeltoftServices\Proxmox\Vm\Panels;
use BeltoftServices\Proxmox\Vm\VmRecords;
use BeltoftServices\Resource;

defined( 'ABSPATH' ) || exit;

final class Ipv4Panels {

	/**
	 * Hooks: the server page asks the filter `beltoft_services_server_ipv4` (Panels::ipv4_panel()).
	 */
	public static function register(): void {
		add_filter( 'beltoft_services_server_ipv4', array( __CLASS__, 'for_server' ), 10, 2 );
		add_action( 'beltoft_services_account_assets', array( __CLASS__, 'assets' ), 20, 2 );
	}

	public const HANDLE = 'beltoft-services-ipv4-guest';

	/**
	 * The words the script of the "Apply again" button prints.
	 *
	 * @return array<string, string>
	 */
	public static function strings(): array {
		return array(
			'working' => __( 'Working on it. This takes a minute.', 'beltoft-services' ),
			'failed'  => __( 'This did not finish. We have been told and will look into it.', 'beltoft-services' ),
			'error'   => __( 'Something went wrong. Please try again.', 'beltoft-services' ),
		);
	}

	/**
	 * Script of the "Apply again" button: only on the page of a server that has extra addresses.
	 *
	 * @param mixed $parent   Handle of the generic account script.
	 * @param mixed $endpoint The endpoint being shown.
	 */
	public static function assets( $parent, $endpoint = null ): void {
		if ( ! is_string( $parent ) || ( is_string( $endpoint ) && \BeltoftServices\Account\ServerLinks::slug() !== $endpoint ) ) {
			return;
		}

		$asked   = \BeltoftServices\Account\ServerLinks::requested();
		$license = $asked['id'] > 0 ? \BeltoftLicenses\Licenses::find( $asked['id'] ) : null;
		$r       = is_array( $license ) && (int) ( $license['user_id'] ?? 0 ) === get_current_user_id() && get_current_user_id() > 0 ? Resource::for_license( (int) $license['id'] ) : null;

		if ( ! $r || ! Ipv4Guest::wanted_at_build( $r ) ) {
			return;
		}

		wp_enqueue_script( self::HANDLE, plugins_url( 'assets/ipv4-guest.js', BELTOFT_SERVICES_FILE ), array( $parent, 'beltoft-services-status', \BeltoftServices\Status::API_HANDLE ), \BeltoftServices\Assets::version( 'assets/ipv4-guest.js' ), true );
		wp_localize_script( self::HANDLE, 'beltoftServicesIpv4Guest', array( 'strings' => self::strings() ) );
	}

	/**
	 * Filter beltoft_services_server_ipv4: the block of a server.
	 *
	 * @param mixed $panel  Earlier answer.
	 * @param mixed $server Server.
	 * @return mixed
	 */
	public static function for_server( $panel, $server = null ) {
		return $server instanceof Resource && null === $panel ? self::view( $server ) : $panel;
	}

	/**
	 * The block of a server or a database, or null when there is nothing to show or sell (an adopted server, a service that
	 * is not ready, and no product to buy while no address is held).
	 *
	 * Keys: `addresses` (role main or extra, address, state, ending date, remove link), `add_url`, `add_price`, `sold_out`,
	 * `busy`, `first_restarts` (adding the first address of a server that has none restarts it), `setup` (for a server with
	 * extra addresses: the lines for the guest's network settings, by tool), `guest` (for a server with extra addresses: whether
	 * they are set up in the server, from Ipv4Guest, and whether "Apply again" is offered; each extra row has `guest` too), `can_add`.
	 *
	 * @param Resource $parent Server or database.
	 * @return array<string, mixed>|null
	 */
	public static function view( Resource $parent ): ?array {
		if ( ! Ipv4Meta::is_parent( $parent ) || 'ready' !== $parent->get( 'state' ) ) {
			return null;
		}

		$product = Ipv4Cart::product_for( $parent );
		$entries = Ipv4Meta::addresses( $parent );
		$db      = Ipv4Meta::is_db( $parent );

		if ( null === $product && array() === $entries ) {
			return null;
		}

		$rows   = array();
		$extras = 0;
		$guest  = Ipv4Guest::state( $parent );

		foreach ( $entries as $entry ) {
			$addon        = $entry['addon'];
			// A pay as you go address has no subscription of its own: it is removed through the usage engine (CustomerDelete), like a pay as you go disk.
			$usage        = 'usage' === (string) ( $addon->license()['source'] ?? '' );
			$subscription = ! $usage && function_exists( 'wcs_get_subscription' ) ? wcs_get_subscription( (int) ( $addon->license()['subscription_id'] ?? 0 ) ) : null;
			$actions      = $subscription instanceof \WC_Subscription && function_exists( 'wcs_get_all_user_actions_for_subscription' ) ? wcs_get_all_user_actions_for_subscription( $subscription, get_current_user_id() ) : array();
			$ending       = $subscription instanceof \WC_Subscription && $subscription->has_status( 'pending-cancel' );
			$extras      += 'extra' === $entry['role'] ? 1 : 0;

			// The first address of a server is its main one: while extras are left it cannot be taken away alone, nor when the server has no IPv6 to keep.
			$locked = 'main' === $entry['role'] && ! $db && ! Ipv4Meta::main_removable( $parent );

			$rows[] = array(
				'address'    => $entry['address'],
				'role'       => $entry['role'],
				'guest'      => 'extra' === $entry['role'] && $entry['live'] && ! $db ? ( in_array( $entry['address'], $guest['applied'], true ) ? 'applied' : 'not_set_up' ) : '',
				'live'       => $entry['live'],
				'ending'     => $ending ? self::end_date( $subscription ) : '',
				'remove_url' => $entry['live'] && ! $ending && ! $locked && '' === Ipv4Server::working( $parent ) ? (string) ( $actions['cancel']['url'] ?? '' ) : '',
				'delete_id'  => $usage && $entry['live'] && null === \BeltoftServices\Usage\CustomerDelete::blocker( $addon ) ? $addon->id() : 0,
				'undo_url'   => $ending ? (string) ( $actions['reactivate']['url'] ?? '' ) : '',
			);
		}

		// A server whose plan gives it an address (or one built with it) has that main address without an add-on: it is listed first and cannot be removed here.
		$own = (string) $parent->get( 'ip' );

		if ( ! $db && false !== filter_var( $own, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) && ! in_array( $own, array_column( $entries, 'address' ), true ) ) {
			array_unshift(
				$rows,
				array(
					'address'    => $own,
					'role'       => 'main',
					'guest'      => '',
					'live'       => true,
					'ending'     => '',
					'remove_url' => '',
					'delete_id'  => 0,
					'undo_url'   => '',
				)
			);
		}

		$busy    = '' !== Ipv4Server::working( $parent ) || array() !== array_filter( $rows, static fn( $row ) => ! $row['live'] );
		$add_url = Ipv4Cart::add_url( $parent );
		$price   = '';

		if ( '' !== $add_url && null !== $product ) {
			$variation = $product instanceof \WC_Product_Variation ? $product : null;
			$price     = null === $variation ? '' : (string) \BeltoftServices\Currency::with_resource( $parent, static fn() => \BeltoftServices\Usage\Rates::is_usage( $variation ) ? \BeltoftServices\Usage\Rates::addon_terms( $variation ) : Panels::price_text( $variation ), '' );
		}

		return array(
			'is_db'          => $db,
			'addresses'      => $rows,
			'busy'           => $busy,
			'add_url'        => $add_url,
			'add_price'      => $price,
			'sold_out'       => null !== $product && '' === $add_url && Ipv4Cart::room( $parent ) && ! Ipv4Cart::in_stock(),
			'first_restarts' => ! Ipv4Meta::has_main( $parent ),
			'setup'          => $extras > 0 && ! $db ? self::setup_lines( $parent ) : array(),
			'guest'          => $extras > 0 && ! $db ? array(
				'resource_id' => $parent->id(),
				'state'       => $guest['state'],
				'persisted'   => $guest['persisted'],
				'nic'         => $guest['nic'],
				'can_apply'   => array() !== Ipv4Guest::offered( $parent ) && '' === Ipv4Server::working( $parent ),
				'busy'        => Ipv4Server::GUEST === Ipv4Server::working( $parent ),
			) : array(),
		);
	}

	/**
	 * The end date of a subscription that is ending, for the customer.
	 *
	 * @param mixed $subscription Subscription.
	 */
	private static function end_date( $subscription ): string {
		$time = $subscription instanceof \WC_Subscription ? (int) $subscription->get_time( 'end', 'site' ) : 0;

		return $time > 0 ? date_i18n( get_option( 'date_format' ), $time ) : '';
	}

	/**
	 * The lines the customer puts in the guest's network settings for the extra addresses, one block per tool. An extra address
	 * is added as /32: the host routes it to the server, and the main address stays the way out.
	 *
	 * @param Resource $server Server.
	 * @return array<string, string> Tool => text.
	 */
	public static function setup_lines( Resource $server ): array {
		$extras = Ipv4Meta::extra_addresses( $server );

		if ( array() === $extras ) {
			return array();
		}

		$record  = VmRecords::for_resource( $server->id() );
		$cluster = null === $record ? null : Clusters::get( (string) $record['cluster'] );
		$main    = (string) $server->get( 'ip' );
		$prefix  = null === $cluster ? 32 : (int) $cluster['prefix'];

		$state   = Ipv4Guest::state( $server );
		$nic     = '' !== $state['nic'] ? $state['nic'] : 'eth0';
		$profile = '' !== $state['profile'] ? str_replace( '"', '', $state['profile'] ) : 'Wired connection 1';
		$netplan = "addresses:\n";
		$nm      = array();
		$ifup    = array();

		if ( '' !== $main ) {
			$netplan .= '  - ' . $main . '/' . $prefix . "\n";
		}

		foreach ( $extras as $i => $address ) {
			$netplan .= '  - ' . $address . "/32\n";
			$nm[]     = 'nmcli connection modify "' . $profile . '" +ipv4.addresses ' . $address . '/32';
			$ifup[]   = 'up ip addr add ' . $address . '/32 dev ' . $nic . ' label ' . $nic . ':' . ( $i + 1 );
		}

		return array(
			'netplan'        => rtrim( $netplan ),
			'ifupdown'       => implode( "\n", $ifup ),
			'networkmanager' => implode( "\n", $nm ) . "\nnmcli connection up \"" . $profile . '"',
		);
	}

	/**
	 * The database page's part: the answer DbAccess::view() gives for the address buttons.
	 *
	 * @param Resource $db Database.
	 * @return array{add_url: string, add_price: string, sold_out: bool, remove_url: string, delete_id: int, adding: bool, removing: bool}
	 */
	public static function database( Resource $db ): array {
		$view    = self::view( $db );
		$address = \BeltoftServices\Proxmox\Db\DbAccess::public_address( $db );
		$row     = null === $view ? null : ( $view['addresses'][0] ?? null );

		return array(
			'add_url'    => null === $view || '' !== $address ? '' : $view['add_url'],
			'add_price'  => null === $view || '' !== $address ? '' : $view['add_price'],
			'sold_out'   => '' === $address && null !== $view && $view['sold_out'],
			'remove_url' => null === $row ? '' : (string) $row['remove_url'],
			'delete_id'  => null === $row ? 0 : (int) $row['delete_id'],
			'adding'     => '' === $address && Ipv4Meta::db_wants( $db ),
			'removing'   => Ipv4Server::MAIN_REMOVE === Ipv4Server::working( $db ),
		);
	}
}
