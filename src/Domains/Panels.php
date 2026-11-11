<?php
/** Customer and admin domain details. @package BeltoftServices */
namespace BeltoftServices\Domains;

use BeltoftServices\Brand;
use BeltoftServices\Resource;

defined( 'ABSPATH' ) || exit;

final class Panels {
	/** Seconds the account page waits for the DNS server. */
	public const PAGE_TIMEOUT = 5;

	public static function register(): void {
		add_action( 'beltoft_services_account_assets', array( __CLASS__, 'assets' ), 10, 2 );
		add_filter( 'beltoft_services_account_buttons', array( __CLASS__, 'buttons' ), 10, 2 );
		add_filter( 'beltoft_licenses_account_title', array( __CLASS__, 'title' ), 10, 2 );
	}
	public static function assets( $parent, $endpoint = null ): void {
		// The domain script is for the Domains endpoint only (the Servers and Licenses pages have no domain panel).
		if ( is_string( $endpoint ) && \BeltoftServices\Account\Domains::slug() !== $endpoint ) {
			return;
		}

		wp_enqueue_script( 'beltoft-services-domain', plugins_url( 'assets/domain.js', BELTOFT_SERVICES_FILE ), is_string( $parent ) ? array( $parent, \BeltoftServices\Status::HANDLE, \BeltoftServices\Status::API_HANDLE, 'wp-i18n' ) : array( \BeltoftServices\Status::HANDLE, \BeltoftServices\Status::API_HANDLE, 'wp-i18n' ), \BeltoftServices\Assets::version( 'assets/domain.js' ), true );
		wp_set_script_translations( 'beltoft-services-domain', 'beltoft-services', plugin_dir_path( BELTOFT_SERVICES_FILE ) . 'languages' );

		// The row-by-row DNS records editor of a domain's page.
		if ( \BeltoftServices\Account\Domains::requested()['id'] > 0 ) {
			wp_enqueue_script( 'beltoft-services-dns-editor', plugins_url( 'assets/dns-editor.js', BELTOFT_SERVICES_FILE ), is_string( $parent ) ? array( $parent, \BeltoftServices\Status::HANDLE, \BeltoftServices\Status::API_HANDLE ) : array( \BeltoftServices\Status::HANDLE, \BeltoftServices\Status::API_HANDLE ), \BeltoftServices\Assets::version( 'assets/dns-editor.js' ), true );
		}
	}
	public static function buttons( $buttons, $r ) {
		if ( is_array( $buttons ) && $r instanceof Resource && in_array( $r->get( 'driver' ), array( 'domain', 'dns' ), true ) ) {
			// The panel shows these itself, next to what they change.
			unset( $buttons['set_records'], $buttons['set_nameservers'], $buttons['lock'], $buttons['unlock'], $buttons['check_delegation'] );
		}
		return $buttons;
	}
	/** The domain's own name as the heading of its card, instead of the product name. */
	public static function title( $title, $license ) {
		$r = is_array( $license ) ? Resource::for_license( (int) ( $license['id'] ?? 0 ) ) : null;
		$domain = $r && in_array( $r->get( 'driver' ), array( 'domain', 'dns' ), true ) ? (string) $r->get( 'domain' ) : '';
		return '' !== $domain ? HostName::display( $domain ) : $title;
	}
	public static function account( Resource $r ): void {
		// On a domain page (My Account → Domains → one domain) the tabs print instead of the one card.
		$page = \BeltoftServices\Account\Domains::page();
		if ( $page && (int) ( $page['license']['id'] ?? 0 ) === (int) ( $r->license()['id'] ?? -1 ) ) {
			$d = \BeltoftServices\Account\DomainPage::build( $r, $page );
			include \BeltoftServices\Account\Domains::file( 'domain-page.php' );
			return;
		}
		$domain = (string) $r->get( 'domain' );
		$dns_only = 'dns' === $r->get( 'driver' );
		$client = Clients::dns();
		// A page a customer waits for gets a few seconds, not the thirty a background job may take.
		if ( $client instanceof LiveDns ) { $client = $client->with_timeout( self::PAGE_TIMEOUT ); }
		$zone = $dns_only || ( 'custom' !== $r->get( 'ns_mode' ) && ! $r->get( 'dns_external' ) ) ? $client->zone( $domain ) : null;
		// Without the zone the editor would show no records, and saving would delete them: it is not shown.
		$zone_unavailable = is_wp_error( $zone );
		$rrsets = is_array( $zone ) ? (array) ( $zone['rrsets'] ?? array() ) : array();
		$rows = Records::to_rows( $domain, $rrsets );
		include BELTOFT_SERVICES_PATH . 'templates/account/domain.php';
	}
	/**
	 * Read-only rows for a DNS-only resource: claim, assignment, order, parent DS, records off, import error.
	 *
	 * @param Resource $r Resource.
	 * @return array<string, string>
	 */
	private static function dns_rows( Resource $r ): array {
		$rows  = array();
		$claim = (int) $r->get( 'claim_id' ) > 0 ? DnsClaims::get( (int) $r->get( 'claim_id' ) ) : null;
		if ( $claim ) {
			$rows[ __( 'Claim', 'beltoft-services' ) ] = ! empty( $claim['verified_at'] )
				/* translators: 1: claim number, 2: verification date and time. */
				? sprintf( __( '#%1$d, verified %2$s UTC', 'beltoft-services' ), (int) $claim['id'], $claim['verified_at'] )
				: '#' . (int) $claim['id'];
		}
		$row = DnsAssignments::get( (string) $r->get( 'domain' ) );
		if ( $row ) {
			$rows[ __( 'Assignment status', 'beltoft-services' ) ] = (string) $row['status'];
			if ( 'release_pending' === $row['status'] && ! empty( $row['reserved_until'] ) ) {
				$rows[ __( 'First clear check', 'beltoft-services' ) ] = $row['reserved_until'] . ' UTC';
			}
			if ( ! empty( $row['order_id'] ) ) {
				$rows[ __( 'Order', 'beltoft-services' ) ] = '#' . (int) $row['order_id'];
			}
		}
		$ds = $r->get( 'parent_ds' );
		if ( is_bool( $ds ) ) {
			$rows[ __( 'Parent DS', 'beltoft-services' ) ] = $ds ? __( 'yes', 'beltoft-services' ) : __( 'no', 'beltoft-services' );
		}
		if ( $r->get( 'records_off' ) ) {
			$rows[ __( 'Records off', 'beltoft-services' ) ] = __( 'yes', 'beltoft-services' );
		}
		if ( '' !== (string) $r->get( 'import_error' ) ) {
			$rows[ __( 'Import error', 'beltoft-services' ) ] = (string) $r->get( 'import_error' );
		}
		return $rows;
	}
	/**
	 * Read-only rows for a transfer: where the customer chose to have the DNS, what the order waits for, the name servers it
	 * uses now and what could not be copied.
	 *
	 * @param Resource $r Resource.
	 * @return array<string, string>
	 */
	private static function transfer_rows( Resource $r ): array {
		$labels = array(
			/* translators: %s: name of this shop. */
			TransferDns::FIRST => sprintf( __( 'Move the DNS to %s first (import and name servers before the transfer)', 'beltoft-services' ), Brand::name() ),
			/* translators: %s: name of this shop. */
			TransferDns::AFTER => sprintf( __( 'Move my DNS to %s when the transfer is done', 'beltoft-services' ), Brand::name() ),
			TransferDns::KEEP  => __( 'Keep my DNS where it is', 'beltoft-services' ),
		);
		$plan   = TransferDns::plan( $r );
		$rows   = array();

		if ( 'served' === $plan ) {
			$rows[ __( 'Transfer DNS', 'beltoft-services' ) ] = __( 'Our DNS already serves the domain for this customer (no choice)', 'beltoft-services' );
		} else {
			$rows[ __( 'Transfer DNS', 'beltoft-services' ) ] = '' === (string) $r->get( 'transfer_dns' )
				/* translators: %s: the DNS choice. */
				? sprintf( __( '%s (no choice stored: an older order)', 'beltoft-services' ), $labels[ TransferDns::choice( $r ) ] )
				: $labels[ TransferDns::choice( $r ) ];
		}

		if ( $r->get( 'transfer_free' ) ) {
			$due = (int) $r->get( 'next_payment_at' );
			$rows[ __( 'Payment', 'beltoft-services' ) ] = $due > 0
				/* translators: %s: date */
				? sprintf( __( 'Free transfer. The first yearly payment is due on %s.', 'beltoft-services' ), wp_date( get_option( 'date_format' ), $due ) )
				: __( 'Free transfer. The first yearly payment is due when the domain is up for renewal.', 'beltoft-services' );
		}
		$rows[ __( 'Waiting for', 'beltoft-services' ) ]          = TransferDns::waiting_reason( $r );
		$rows[ __( 'DNS records checked', 'beltoft-services' ) ]  = (int) $r->get( 'dns_reviewed_at' ) > 0 ? ( 'imported' === (string) $r->get( 'dns_reviewed_path' ) ? __( 'Imported a zone file', 'beltoft-services' ) : __( 'Acknowledged that records we could not find are missing', 'beltoft-services' ) ) . ' (' . wp_date( 'Y-m-d H:i', (int) $r->get( 'dns_reviewed_at' ) ) . ')' : '';
		$rows[ __( 'How the DNS was chosen', 'beltoft-services' ) ] = $r->get( 'transfer_dns_detected' ) ? __( 'Detected: the current name servers are a registrar\'s own', 'beltoft-services' ) : '';
		$rows[ __( 'Current name servers', 'beltoft-services' ) ] = implode( ', ', DomainDriver::stored_nameservers( $r ) );
		$rows[ __( 'Delegation checked by customer', 'beltoft-services' ) ] = $r->get( 'transfer_dns_checked' ) ? wp_date( 'Y-m-d H:i', (int) $r->get( 'transfer_dns_checked' ) ) : '';
		$rows[ __( 'Records copied', 'beltoft-services' ) ]       = null !== $r->get( 'records_copied' ) ? (string) (int) $r->get( 'records_copied' ) : '';
		$rows[ __( 'Not copied', 'beltoft-services' ) ]           = (string) $r->get( 'import_error' );

		return $rows;
	}
	public static function admin( Resource $r ): void {
		$details = array_filter( array(
			__( 'Domain', 'beltoft-services' ) => $r->get( 'domain' ),
			__( 'Registrar id', 'beltoft-services' ) => $r->get( 'remote_id' ),
			__( 'Registrar status', 'beltoft-services' ) => $r->get( 'registrar_status' ),
			__( 'Expires', 'beltoft-services' ) => $r->get( 'expires_at' ),
			__( 'Delegation', 'beltoft-services' ) => $r->get( 'delegation' ),
			__( 'Delegation checked', 'beltoft-services' ) => $r->get( 'delegation_checked' ) ? wp_date( 'Y-m-d H:i', (int) $r->get( 'delegation_checked' ) ) : '',
			__( 'Last sync', 'beltoft-services' ) => $r->get( 'last_sync' ) ? wp_date( 'Y-m-d H:i', (int) $r->get( 'last_sync' ) ) : '',
		) );
		if ( 'dns' === $r->get( 'driver' ) ) {
			$details = array_merge( $details, self::dns_rows( $r ) );
		}
		if ( 'domain' === $r->get( 'driver' ) && 'transfer' === $r->get( 'mode' ) ) {
			$details = array_merge( $details, self::transfer_rows( $r ) );
		}
		foreach ( $details as $label => $value ) { printf( '<p><strong>%s:</strong> %s</p>', esc_html( $label ), esc_html( (string) $value ) ); }
		$subscription = (int) ( $r->license()['subscription_id'] ?? 0 );
		if ( $subscription ) { printf( '<p><a href="%s">%s</a></p>', esc_url( get_edit_post_link( $subscription ) ), esc_html__( 'Subscription', 'beltoft-services' ) ); }
	}
}
