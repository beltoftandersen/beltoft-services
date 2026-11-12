<?php
/**
 * The facts of the HostingReady email, and of the thank-you page.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Hosting;

use BeltoftServices\Resource;

defined( 'ABSPATH' ) || exit;

/**
 * Display values for one hosting account: its first site and address, the WordPress admin address, the SFTP
 * details, and the DNS records an external domain still needs. Never a password and never a secret: the
 * WordPress admin password is random and never shown: the customer logs in with one click in My Account or sets one there,
 * and the SFTP password is set there.
 */
final class HostingEmail {

	/**
	 * The values.
	 *
	 * @param Resource $r Hosting resource.
	 * @return array<string, mixed>
	 */
	public static function values( Resource $r ): array {
		$record = Records::for_resource( $r->id() );
		$sites  = Sites::for_resource( $r->id() );
		$node   = null === $record ? array() : (array) ( Nodes::get( $record['node'] ) ?? array() );
		$first  = $sites[0] ?? null;
		$host   = null === $first ? '' : EdgeMap::preview_host( (string) $first['slug'] );
		$plan   = Plan::typed( (array) $r->get( 'plan' ) );

		$records = array();
		$domains = array();

		foreach ( SiteDomains::for_resource( $r->id() ) as $d ) {
			if ( 'preview' === $d['kind'] ) {
				continue;
			}

			$domains[] = array( 'host' => $d['host'], 'state' => $d['state'], 'kind' => $d['kind'] );

			if ( 'external' === $d['kind'] && 'active' !== $d['state'] ) {
				foreach ( SiteDns::instructions( $node, $d['host'], $r->id() ) as $row ) {
					$records[] = array( 'name' => (string) ( $row['name'] ?? $d['host'] ), 'type' => $row['type'], 'value' => $row['value'] );
				}
			}
		}

		return array(
			'site_name'    => null === $first ? '' : ( '' !== $first['label'] ? (string) $first['label'] : (string) $first['slug'] ),
			'site_url'     => '' === $host ? '' : 'https://' . $host . '/',
			'admin_url'    => '' === $host ? '' : 'https://' . $host . '/wp-admin/',
			'admin_user'   => null === $first ? '' : (string) ( $first['flags']['admin_user'] ?? '' ),
			'admin_email'  => null === $first ? '' : (string) ( $first['flags']['admin_email'] ?? '' ),
			'sftp_host'    => (string) ( $node['edge_ip'] ?? '' ),
			'sftp_port'    => (int) ( $record['ssh_port'] ?? 0 ),
			'sftp_user'    => 'web',
			'ssh'          => ! empty( $plan['ssh'] ),
			'domains'      => $domains,
			'dns_records'  => $records,
			'has_email'    => null !== SiteMail::child_of( $r ),
		);
	}
}
