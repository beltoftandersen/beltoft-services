<?php
/**
 * The DNS set-up guide of a domain the customer points at a website themselves.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Hosting;

use BeltoftServices\Domains\DnsQuery;
use BeltoftServices\Resource;

defined( 'ABSPATH' ) || exit;

/**
 * One guide per domain (the name with and without www together, and any other name under it that still waits): the
 * records to add as a table (type, name, value, each to copy), what was found for each record at the last check, where
 * the domain's DNS seems to be managed, and a check the customer can start. The periodic check (DomainSweep) and the
 * customer's check both go through SiteDns::check_external(), which decides the state; this class only shows why.
 *
 * Nothing here calls the network while the page is drawn: the page reads what the last check stored.
 */
final class DnsGuide {

	/** Checks a customer may start per hour and hosting account. */
	public const LIMIT = 30;

	/** Seconds the result of a check is kept for the page. */
	private const KEEP = DAY_IN_SECONDS;

	/**
	 * The waiting outside names of a website, by the domain they belong to: domain => host names, the domain itself first.
	 *
	 * @param array<int, array<string, mixed>> $rows The website's name rows (host, kind, state).
	 * @return array<string, string[]>
	 */
	public static function groups( array $rows ): array {
		$groups = array();

		foreach ( $rows as $row ) {
			if ( 'external' !== ( $row['kind'] ?? '' ) || 'active' === ( $row['state'] ?? '' ) ) {
				continue;
			}

			$host   = (string) $row['host'];
			$domain = Addons::registrable( $host );
			$domain = '' === $domain ? $host : $domain;

			$groups[ $domain ][] = $host;
		}

		foreach ( $groups as $domain => $hosts ) {
			usort( $hosts, static fn( $a, $b ) => ( $a === $domain ? 0 : 1 ) <=> ( $b === $domain ? 0 : 1 ) ?: strcmp( $a, $b ) );
			$groups[ $domain ] = $hosts;
		}

		return $groups;
	}

	/**
	 * The name of a record as a DNS provider's form wants it: the part in front of the domain, "@" for the domain itself.
	 *
	 * @param string $name   Full record name.
	 * @param string $domain The domain.
	 */
	public static function label( string $name, string $domain ): string {
		if ( $name === $domain ) {
			return '@';
		}

		return str_ends_with( $name, '.' . $domain ) ? substr( $name, 0, -1 - strlen( $domain ) ) : $name;
	}

	/**
	 * The records to add for the waiting names of a domain.
	 *
	 * @param int                  $resource_id Hosting resource id.
	 * @param string               $domain      The domain.
	 * @param string[]             $hosts       Waiting names (groups()).
	 * @param string[]             $all         Every name of the account (a www name whose other name is on the account needs no proof).
	 * @param array<string, mixed> $node        Node settings.
	 * @return array<int, array{key: string, type: string, name: string, label: string, value: string}>
	 */
	public static function records( int $resource_id, string $domain, array $hosts, array $all, array $node ): array {
		$edge = SiteDns::edge( $node );
		$out  = array();

		foreach ( $hosts as $host ) {
			foreach ( array( 'A', 'AAAA' ) as $type ) {
				if ( '' !== (string) ( $edge[ $type ] ?? '' ) ) {
					$out[] = array( 'key' => $type . '|' . $host, 'type' => $type, 'name' => $host, 'label' => self::label( $host, $domain ), 'value' => (string) $edge[ $type ] );
				}
			}
		}

		foreach ( $hosts as $host ) {
			$paired = 0 === strpos( $host, 'www.' ) && in_array( substr( $host, 4 ), $all, true );

			if ( $paired || SiteDns::under_verified_domain( $resource_id, $host ) ) {
				continue;
			}

			$name  = SiteDns::PROOF_LABEL . '.' . $host;
			$out[] = array( 'key' => 'TXT|' . $host, 'type' => 'TXT', 'name' => $name, 'label' => self::label( $name, $domain ), 'value' => SiteDns::proof_token( $resource_id, $host ) );
		}

		return $out;
	}

	/**
	 * What the last check found for a domain, or an empty array when it was never checked.
	 *
	 * @param int    $resource_id Hosting resource id.
	 * @param string $domain      The domain.
	 * @return array<string, mixed> time, provider, rows (record key => ok true|false|null, seen string[]).
	 */
	public static function status( int $resource_id, string $domain ): array {
		$stored = get_transient( self::key( $resource_id, $domain ) );

		return is_array( $stored ) ? $stored : array();
	}

	/**
	 * Transient name.
	 *
	 * @param int    $resource_id Hosting resource id.
	 * @param string $domain      The domain.
	 */
	private static function key( int $resource_id, string $domain ): string {
		return 'beltoft_services_hdns_' . $resource_id . '_' . substr( md5( $domain ), 0, 16 );
	}

	/**
	 * Checks the DNS of a name's domain now: looks each record up, lets SiteDns decide the state of every name, keeps
	 * what was found for the page and brings the web server up to date when a name went live.
	 *
	 * @param Resource $r    Hosting resource.
	 * @param string   $host One of the account's outside names.
	 * @return array<string, mixed>|\WP_Error checked, live (names that are active now), waiting.
	 */
	public static function check( Resource $r, string $host ) {
		$host = strtolower( trim( $host ) );
		$row  = SiteDomains::find( $host );

		if ( null === $row || (int) $row['resource_id'] !== $r->id() || 'external' !== $row['kind'] ) {
			return new \WP_Error( 'not_found', __( 'That address is not on your account.', 'beltoft-services' ) );
		}

		$record = Records::for_resource( $r->id() );
		$node   = $record ? Nodes::get( (string) $record['node'] ) : null;

		if ( null === $node ) {
			return new \WP_Error( 'not_ready', __( 'Your hosting is not ready yet.', 'beltoft-services' ) );
		}

		$domain = Addons::registrable( $host );
		$domain = '' === $domain ? $host : $domain;
		$mine   = array();
		$all    = array();

		foreach ( SiteDomains::for_resource( $r->id() ) as $d ) {
			$all[] = (string) $d['host'];
			$of    = Addons::registrable( (string) $d['host'] );

			if ( 'external' === $d['kind'] && ( '' === $of ? (string) $d['host'] : $of ) === $domain ) {
				$mine[ (string) $d['host'] ] = (string) $d['state'];
			}
		}

		uksort( $mine, static fn( $a, $b ) => ( $a === $domain ? 0 : 1 ) <=> ( $b === $domain ? 0 : 1 ) ?: strcmp( $a, $b ) );

		$rows = array();

		foreach ( self::records( $r->id(), $domain, array_keys( $mine ), $all, $node ) as $rec ) {
			if ( 'TXT' === $rec['type'] ) {
				$texts = DnsQuery::texts( $rec['name'] );
				$clean = is_wp_error( $texts ) ? array() : array_map( static fn( $t ) => trim( (string) $t, " \t\"" ), $texts );

				$rows[ $rec['key'] ] = array( 'ok' => in_array( $rec['value'], $clean, true ), 'seen' => array() );
				continue;
			}

			$found = DnsQuery::addresses( $rec['name'], $rec['type'] );
			$found = is_wp_error( $found ) ? array() : $found;

			$rows[ $rec['key'] ] = array( 'ok' => in_array( strtolower( $rec['value'] ), $found, true ), 'seen' => array_slice( array_values( array_diff( $found, array( strtolower( $rec['value'] ) ) ) ), 0, 4 ) );
		}

		// The state is decided by the one owner of that rule, the domain itself first (its www name leans on it).
		$changed = false;
		$live    = array();
		$waiting = array();

		foreach ( $mine as $name => $before ) {
			$state = SiteDns::check_external( $name, $node );
			$state = null === $state ? $before : $state;

			if ( $state !== $before ) {
				$changed = true;
			}

			if ( 'active' === $state ) {
				$live[] = $name;
			} else {
				$waiting[] = $name;
			}
		}

		set_transient( self::key( $r->id(), $domain ), array( 'time' => time(), 'provider' => self::provider( $domain ), 'rows' => $rows ), self::KEEP );

		if ( $changed && 'ready' === $r->get( 'state' ) && 'ready' === $r->get( 'desired' ) ) {
			( new HostingActions( $r ) )->sync_edge();
		}

		return array( 'checked' => true, 'live' => $live, 'waiting' => $waiting );
	}

	/**
	 * Where the DNS of a domain seems to be managed, from its name servers: a provider's name, or ''.
	 *
	 * @param string $domain The domain.
	 */
	public static function provider( string $domain ): string {
		/**
		 * The name servers of a domain. Tests answer here; nothing in a test run reaches the network.
		 *
		 * @param string[]|null $servers Null to look them up.
		 * @param string        $domain  The domain.
		 */
		$servers = apply_filters( 'beltoft_services_hosting_dns_nameservers', null, $domain );

		if ( null === $servers ) {
			$found   = @dns_get_record( $domain . '.', DNS_NS ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a failed lookup is an expected result.
			$servers = array();

			foreach ( is_array( $found ) ? $found : array() as $ns ) {
				$servers[] = strtolower( (string) ( $ns['target'] ?? '' ) );
			}
		}

		/**
		 * DNS providers by the ending of their name servers' host names.
		 *
		 * @param array<string, string> $providers Ending => provider name.
		 */
		$known = (array) apply_filters(
			'beltoft_services_hosting_dns_providers',
			array(
				'one.com'               => 'one.com',
				'simply.com'            => 'Simply.com',
				'unoeuro.com'           => 'Simply.com',
				'gratisdns.dk'          => 'GratisDNS',
				'dandomain.dk'          => 'DanDomain',
				'curanet.dk'            => 'Curanet',
				'scannet.dk'            => 'ScanNet',
				'nordicway.dk'          => 'Nordicway',
				// Written in two parts: a code check reads the plain name as a file loaded from a remote service.
				'cloudflare' . '.com'    => 'Cloudflare',
				'domaincontrol.com'     => 'GoDaddy',
				'registrar-servers.com' => 'Namecheap',
				'dns-parking.com'       => 'Hostinger',
				'hostinger.com'         => 'Hostinger',
				'ui-dns.com'            => 'IONOS',
				'ui-dns.de'             => 'IONOS',
				'ui-dns.org'            => 'IONOS',
				'ui-dns.biz'            => 'IONOS',
				'googledomains.com'     => 'Google Domains',
				'squarespacedns.com'    => 'Squarespace',
				'wixdns.net'            => 'Wix',
				'gandi.net'             => 'Gandi',
				'ovh.net'               => 'OVHcloud',
				'name.com'              => 'Name.com',
				'digitalocean.com'      => 'DigitalOcean',
				'dnsimple.com'          => 'DNSimple',
			)
		);

		foreach ( (array) $servers as $server ) {
			$server = rtrim( strtolower( (string) $server ), '.' );

			foreach ( $known as $ending => $name ) {
				if ( $server === $ending || str_ends_with( $server, '.' . $ending ) ) {
					return (string) $name;
				}
			}
		}

		return '';
	}

	/**
	 * Prints the guides of a website.
	 *
	 * @param Resource             $r     Hosting resource.
	 * @param array<string, mixed> $site  Site (domains).
	 * @param array<string, mixed> $node  Node settings.
	 * @param bool                 $ready Whether the account takes actions now.
	 */
	public static function render( Resource $r, array $site, array $node, bool $ready ): void {
		$mine = array_column( (array) $site['domains'], 'host' );
		$rows = array();
		$all  = array();

		foreach ( SiteDomains::for_resource( $r->id() ) as $d ) {
			$all[] = (string) $d['host'];

			if ( in_array( $d['host'], $mine, true ) ) {
				$rows[] = $d;
			}
		}

		foreach ( self::groups( $rows ) as $domain => $hosts ) {
			$records = self::records( $r->id(), $domain, $hosts, $all, $node );

			if ( array() === $records ) {
				continue;
			}

			$status = self::status( $r->id(), $domain );
			$shown  = \BeltoftServices\Domains\HostName::display( $domain );

			echo '<section class="beltoft-hosting-dns" data-hosting-scope aria-label="' . esc_attr( sprintf( /* translators: %s: domain name. */ __( 'DNS for %s', 'beltoft-services' ), $shown ) ) . '">';
			echo '<h5 class="beltoft-hosting-dns__title">' . esc_html( sprintf( /* translators: %s: domain name. */ __( 'Point %s to this website', 'beltoft-services' ), $shown ) ) . '</h5>';
			echo '<ol class="beltoft-hosting-dns__steps">';
			echo '<li>' . esc_html( sprintf( /* translators: %s: domain name. */ __( 'Log in where the DNS of %s is managed. That is usually where you bought the domain.', 'beltoft-services' ), $shown ) );

			if ( '' !== (string) ( $status['provider'] ?? '' ) ) {
				echo ' ' . esc_html( sprintf( /* translators: %s: name of a DNS provider. */ __( 'For this domain it looks like %s.', 'beltoft-services' ), (string) $status['provider'] ) );
			}

			echo '</li>';
			echo '<li>' . esc_html__( 'Add every record in the table. Copy the name and the value as they are.', 'beltoft-services' ) . '</li>';
			echo '<li>' . esc_html__( 'Come back here and press Check now.', 'beltoft-services' ) . '</li>';
			echo '</ol>';

			echo '<table class="beltoft-hosting-dns__table"><thead><tr><th scope="col">' . esc_html__( 'Type', 'beltoft-services' ) . '</th><th scope="col">' . esc_html__( 'Name', 'beltoft-services' ) . '</th><th scope="col">' . esc_html__( 'Value', 'beltoft-services' ) . '</th><th scope="col">' . esc_html__( 'Status', 'beltoft-services' ) . '</th></tr></thead><tbody>';

			foreach ( $records as $rec ) {
				$found = isset( $status['rows'][ $rec['key'] ] ) ? (array) $status['rows'][ $rec['key'] ] : null;

				if ( null === $found ) {
					$state = '<span class="beltoft-hosting-dns__state">' . esc_html__( 'Not checked yet', 'beltoft-services' ) . '</span>';
				} elseif ( ! empty( $found['ok'] ) ) {
					$state = '<span class="beltoft-hosting-dns__state is-ok">' . esc_html__( 'Found', 'beltoft-services' ) . '</span>';
				} elseif ( ! empty( $found['seen'] ) ) {
					$state = '<span class="beltoft-hosting-dns__state is-wrong">' . esc_html( sprintf( /* translators: %s: one or more IP addresses. */ __( 'Points to %s now', 'beltoft-services' ), implode( ', ', array_map( 'strval', (array) $found['seen'] ) ) ) ) . '</span>';
				} else {
					$state = '<span class="beltoft-hosting-dns__state is-missing">' . esc_html__( 'Not found yet', 'beltoft-services' ) . '</span>';
				}

				printf(
					'<tr><td data-label="%1$s">%2$s</td><td data-label="%3$s"><code>%4$s</code> <button type="button" class="beltoft-hosting-linkbtn" data-hosting-action="copy" data-copy="%4$s">%5$s</button></td><td data-label="%6$s"><code>%7$s</code> <button type="button" class="beltoft-hosting-linkbtn" data-hosting-action="copy" data-copy="%7$s">%5$s</button></td><td data-label="%8$s">%9$s</td></tr>',
					esc_attr__( 'Type', 'beltoft-services' ),
					esc_html( $rec['type'] ),
					esc_attr__( 'Name', 'beltoft-services' ),
					esc_attr( $rec['label'] ),
					esc_html__( 'Copy', 'beltoft-services' ),
					esc_attr__( 'Value', 'beltoft-services' ),
					esc_attr( $rec['value'] ),
					esc_attr__( 'Status', 'beltoft-services' ),
					$state // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts above.
				);
			}

			echo '</tbody></table>';

			echo '<div class="beltoft-hosting-dns__check">';

			if ( $ready ) {
				echo '<button type="button" class="beltoft-hosting-btn" data-hosting-action="dns_check" data-host="' . esc_attr( $hosts[0] ) . '">' . esc_html__( 'Check now', 'beltoft-services' ) . '</button>';
			}

			echo '<span class="beltoft-hosting-dns__when">';

			if ( ! empty( $status['time'] ) ) {
				echo esc_html( sprintf( /* translators: %s: date and time. */ __( 'Last checked %s.', 'beltoft-services' ), wp_date( 'j F Y, H:i', (int) $status['time'] ) ) ) . ' ';
			}

			echo esc_html__( 'We also check by ourselves every 15 minutes. A change of DNS can take up to an hour to show.', 'beltoft-services' ) . '</span></div>';

			echo '<p class="beltoft-hosting-help">' . esc_html( sprintf( /* translators: 1: "@", 2: domain name. */ __( 'About the name %1$s: it means the domain itself. Some providers want %1$s, some want the field left empty, and some want %2$s written out.', 'beltoft-services' ), '@', $shown ) ) . '</p>';
			if ( in_array( $domain, $all, true ) && in_array( 'www.' . $domain, $all, true ) ) {
				echo '<p class="beltoft-hosting-help">' . esc_html__( 'The redirect starts when both addresses point here.', 'beltoft-services' ) . ' ' . esc_html__( 'Then you choose which one is the main address.', 'beltoft-services' ) . '</p>';
			}

			echo '<p class="beltoft-hosting-help">' . esc_html__( 'If the name already has an A record that points somewhere else, change that record instead of adding a second one.', 'beltoft-services' ) . '</p>';
			echo '</section>';
		}
	}
}
