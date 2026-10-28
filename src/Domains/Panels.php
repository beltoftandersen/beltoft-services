<?php
/** Customer and admin domain details. @package BeltoftServices */
namespace BeltoftServices\Domains;

use BeltoftServices\Resource;

defined( 'ABSPATH' ) || exit;

final class Panels {
	public static function register(): void {
		add_action( 'beltoft_services_account_assets', array( __CLASS__, 'assets' ) );
		add_filter( 'beltoft_services_account_buttons', array( __CLASS__, 'buttons' ), 10, 2 );
		add_filter( 'beltoft_licenses_account_title', array( __CLASS__, 'title' ), 10, 2 );
	}
	public static function assets( $parent ): void {
		wp_enqueue_script( 'beltoft-services-domain', plugins_url( 'assets/domain.js', BELTOFT_SERVICES_FILE ), is_string( $parent ) ? array( $parent ) : array(), \BeltoftServices\Assets::version( 'assets/domain.js' ), true );
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
		return '' !== $domain ? $domain : $title;
	}
	public static function account( Resource $r ): void {
		$domain = (string) $r->get( 'domain' );
		$dns_only = 'dns' === $r->get( 'driver' );
		$zone = $dns_only || 'custom' !== $r->get( 'ns_mode' ) ? Clients::dns()->zone( $domain ) : null;
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
			$rows['Claim'] = '#' . $claim['id'] . ( ! empty( $claim['verified_at'] ) ? ', verified ' . $claim['verified_at'] . ' UTC' : '' );
		}
		$row = DnsAssignments::get( (string) $r->get( 'domain' ) );
		if ( $row ) {
			$rows['Assignment status'] = (string) $row['status'];
			if ( 'release_pending' === $row['status'] && ! empty( $row['reserved_until'] ) ) {
				$rows['First clear check'] = $row['reserved_until'] . ' UTC';
			}
			if ( ! empty( $row['order_id'] ) ) {
				$rows['Order'] = '#' . (int) $row['order_id'];
			}
		}
		$ds = $r->get( 'parent_ds' );
		if ( is_bool( $ds ) ) {
			$rows['Parent DS'] = $ds ? 'yes' : 'no';
		}
		if ( $r->get( 'records_off' ) ) {
			$rows['Records off'] = 'yes';
		}
		if ( '' !== (string) $r->get( 'import_error' ) ) {
			$rows['Import error'] = (string) $r->get( 'import_error' );
		}
		return $rows;
	}
	public static function admin( Resource $r ): void {
		$details = array_filter( array(
			'Domain' => $r->get( 'domain' ),
			'Registrar id' => $r->get( 'remote_id' ),
			'Registrar status' => $r->get( 'registrar_status' ),
			'Expires' => $r->get( 'expires_at' ),
			'Delegation' => $r->get( 'delegation' ),
			'Delegation checked' => $r->get( 'delegation_checked' ) ? wp_date( 'Y-m-d H:i', (int) $r->get( 'delegation_checked' ) ) : '',
			'Last sync' => $r->get( 'last_sync' ) ? wp_date( 'Y-m-d H:i', (int) $r->get( 'last_sync' ) ) : '',
		) );
		if ( 'dns' === $r->get( 'driver' ) ) {
			$details = array_merge( $details, self::dns_rows( $r ) );
		}
		foreach ( $details as $label => $value ) { printf( '<p><strong>%s:</strong> %s</p>', esc_html( $label ), esc_html( (string) $value ) ); }
		$subscription = (int) ( $r->license()['subscription_id'] ?? 0 );
		if ( $subscription ) { printf( '<p><a href="%s">%s</a></p>', esc_url( get_edit_post_link( $subscription ) ), esc_html__( 'Subscription', 'beltoft-services' ) ); }
	}
}
