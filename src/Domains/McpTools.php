<?php
/**
 * The MCP tools of domains and DNS.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Domains;

use BeltoftServices\Mcp\Definitions;
use BeltoftServices\Mcp\Irreversible;
use BeltoftServices\Mcp\ServerActions;
use BeltoftServices\Mcp\Tools;
use BeltoftServices\Mcp\ToolSet;
use BeltoftServices\Mcp\ToolSetDefaults;

defined( 'ABSPATH' ) || exit;

/**
 * The MCP tools of domains and DNS: the availability check, the records of a zone, the domains waiting for a DNS check, and a
 * domain's settings (name servers, transfer lock, WHOIS privacy). Never the unlock, never the transfer code, never DNSSEC.
 */
final class McpTools implements ToolSet {

	use ToolSetDefaults;

	/**
	 * @return array<string, array<string, mixed>>
	 */
	public static function tools(): array {
		return array(
			'list_dns_records' => Definitions::read( 'Lists the DNS records of one domain or DNS service that we host.' . Definitions::DATA, Definitions::by_id() ),
			'domain_check'     => Definitions::read( 'Checks whether a domain name can be registered and what it costs.', Definitions::obj( array( 'name' => array( 'type' => 'string', 'maxLength' => 253, 'description' => 'A full domain name, for example example.com.' ) ), array( 'name' ) ), 20 ),
			'list_dns_claims'  => Definitions::read( 'Lists the domains the customer has asked us to host DNS for while the domain is registered elsewhere, with the state of each. Only when the site offers it.' . Definitions::DATA, Definitions::none(), 30 ),
			'dns_upsert_record' => Definitions::act(
				'Creates or replaces the records of one name and type in the domain, for example every A record of www. Other records stay as they are. Ask the customer first and read list_dns_records before.',
				Definitions::obj(
					Definitions::dns() + array(
						'records' => array(
							'type'        => 'array',
							'description' => 'The records of this name and type after the change. Each has a value; MX also priority; SRV also priority, weight and port; CAA also flag and tag.',
							'maxItems'    => 100,
							'items'       => array(
								'type'       => 'object',
								'properties' => array(
									'value'    => array( 'type' => 'string', 'maxLength' => 500 ),
									'priority' => array( 'type' => 'integer', 'minimum' => 0, 'maximum' => 65535 ),
									'weight'   => array( 'type' => 'integer', 'minimum' => 0, 'maximum' => 65535 ),
									'port'     => array( 'type' => 'integer', 'minimum' => 0, 'maximum' => 65535 ),
									'flag'     => array( 'type' => 'integer', 'minimum' => 0, 'maximum' => 255 ),
									'tag'      => array( 'type' => 'string', 'maxLength' => 20 ),
								),
								'required'   => array( 'value' ),
							),
						),
						'ttl'     => array( 'type' => 'integer', 'minimum' => 60, 'maximum' => 86400, 'description' => 'Seconds, 60 to 86400. Optional.' ),
					),
					array( 'id', 'type', 'name', 'records' )
				),
				true,
				true
			),
			'dns_delete_record' => Definitions::act(
				'Removes the records of one name and type, or only the record with the given value. Ask the customer first.',
				Definitions::obj( Definitions::dns() + array( 'value' => array( 'type' => 'string', 'maxLength' => 500, 'description' => 'Remove only the record with this value. Optional.' ) ), array( 'id', 'type', 'name' ) ),
				true,
				true
			),
			'domain_settings'  => Definitions::act(
				'Changes a setting of one of the customer\'s domains: set_nameservers (mode ours; mode custom, with two to six hosts, needs Full access and a confirmation because the domain can go offline if the hosts are wrong: the first call returns a summary and a confirm value, show the summary to the customer and send the value only after their clear yes), lock (the transfer lock on), check_delegation (looks at the name servers now), or whois_privacy (WHOIS privacy on or off with enabled, only where the extension allows it, free). DNSSEC is not changed here: it needs a code emailed to the customer and is done in the dashboard. Ask the customer first. Moving a domain away is not possible here.',
				Definitions::obj(
					array(
						'id'     => Definitions::id(),
						'action' => array( 'type' => 'string', 'enum' => array( 'set_nameservers', 'lock', 'check_delegation', 'whois_privacy' ) ),
						'enabled' => array( 'type' => 'boolean', 'description' => 'For whois_privacy: true for on, false for off.' ),
						'mode'   => array( 'type' => 'string', 'enum' => array( 'ours', 'custom' ), 'description' => 'For set_nameservers.' ),
						'hosts'  => array( 'type' => 'array', 'maxItems' => 6, 'items' => array( 'type' => 'string', 'maxLength' => 253 ), 'description' => 'For set_nameservers with mode custom.' ),
					),
					array( 'id', 'action' ),
					true
				),
				true,
				true
			),
		);
	}

	/**
	 * @return array<string, string[]>
	 */
	public static function routes(): array {
		return array(
			'list_dns_records'   => array( 'GET #^/resources/\d+/dns-records$#D' ),
			'dns_upsert_record'  => array( 'PUT #^/resources/\d+/dns-records/[A-Za-z]+/[^/]+$#D' ),
			'dns_delete_record'  => array( 'DELETE #^/resources/\d+/dns-records/[A-Za-z]+/[^/]+$#D' ),
			'list_dns_claims'    => array( 'GET #^/domains/dns-claims$#D' ),
		);
	}

	/**
	 * @return array<string, string>
	 */
	public static function actions(): array {
		return array(
			'set_nameservers'    => 'domain_settings',
			'lock'               => 'domain_settings',
			'check_delegation'   => 'domain_settings',
			'whois_privacy'      => 'domain_settings',
		);
	}

	/**
	 * @return array<string, string>
	 */
	public static function excluded(): array {
		return array(
			'unlock'            => 'never: a domain transfer is only possible in the dashboard',
			'dnssec_enable'     => 'never: DNSSEC changes the keys the registry holds and needs the code emailed to the account address, so it is only available in the dashboard',
			'dnssec_disable'    => 'never: DNSSEC changes the keys the registry holds and needs the code emailed to the account address, so it is only available in the dashboard',
			'dnssec_key_add'    => 'never: DNSSEC changes the keys the registry holds and needs the code emailed to the account address, so it is only available in the dashboard',
			'dnssec_key_remove' => 'never: DNSSEC changes the keys the registry holds and needs the code emailed to the account address, so it is only available in the dashboard',
			'set_records'       => 'route: DNS records have their own tools (dns_upsert_record, dns_delete_record)',
		);
	}

	/**
	 * @return array<string, string>
	 */
	public static function capabilities(): array {
		return array(
			'custom_nameservers'       => 'domain_settings',
		);
	}

	/**
	 * @return array<string, string>
	 */
	public static function capabilities_excluded(): array {
		return array(
			'domain_dnssec'               => 'never: DNSSEC changes the keys the registry holds and needs the code emailed to the account address, so it is only available in the dashboard',
			'domain_transfer_code'        => 'never: the transfer code of a domain is only available in the dashboard, behind its password step',
			'domain_unlock'               => 'never: a domain transfer is only possible in the dashboard',
			'cancel_domain_registration'  => 'never: a registered domain is not cancelled, auto-renew is turned off instead (set_auto_renew)',
		);
	}

	/**
	 * Runs one of the tools of this set.
	 *
	 * @param string               $name    Tool.
	 * @param array<string, mixed> $args    Arguments, checked against the schema.
	 * @param int                  $user_id Customer.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function run( string $name, array $args, int $user_id ) {
		switch ( $name ) {
			case 'domain_check':
				// The search itself, not its public route: that route counts visitors by address, and every Claude user
				// comes from the same few addresses. The customer is counted instead (the limit of the tool).
				$found = Search::find( (string) $args['name'] );

				if ( is_wp_error( $found ) ) {
					return new \WP_Error( 'beltoft_services_tool', $found->get_error_message() );
				}

				$found = array_is_list( $found ) ? array( 'results' => $found ) : $found;
				// Prices are per year, excluding tax, in the currency of the token's owner.
				$found['currency'] = \BeltoftServices\Currency::current();

				return $found;

			case 'list_dns_claims':
				$list = Tools::route( 'GET', '/domains/dns-claims', array(), null, null );

				if ( $list instanceof \WP_Error ) {
					return $list;
				}

				$keep = array_flip( array( 'id', 'domain', 'status', 'expires_at', 'verified_at', 'checked_at', 'message' ) );

				return array( 'claims' => array_map( static fn( $c ) => array_intersect_key( (array) $c, $keep ), array_values( $list ) ) );

			case 'domain_settings':
				return self::domain_settings( $args );
		}

		$id = (int) $args['id'];

		switch ( $name ) {
			case 'list_dns_records':
				return Tools::route( 'GET', '/resources/' . $id . '/dns-records', array(), null, $id, 'DNS records read' );

			case 'dns_upsert_record':
				$body = array( 'records' => $args['records'] );

				if ( isset( $args['ttl'] ) ) {
					$body['ttl'] = (int) $args['ttl'];
				}

				return Tools::route( 'PUT', self::dns_path( $id, $args ), array(), $body, $id, 'DNS record set (' . self::dns_label( $args ) . ')' );

			case 'dns_delete_record':
				return Tools::route( 'DELETE', self::dns_path( $id, $args ), isset( $args['value'] ) ? array( 'value' => (string) $args['value'] ) : array(), null, $id, 'DNS record removed (' . self::dns_label( $args ) . ')' );
		}

		return new \WP_Error( 'beltoft_services_tool', __( 'This is not available.', 'beltoft-services' ) );
	}

	/**
	 * The summary of a call that needs the confirmation: moving a domain to name servers of the customer's own.
	 *
	 * @param string               $name    Tool.
	 * @param array<string, mixed> $args    Arguments without the confirm value, as validated.
	 * @param int                  $user_id Customer.
	 * @return array{text: string, state: string}|\WP_Error|null
	 */
	public static function summary( string $name, array $args, int $user_id ) {
		return 'domain_settings' === $name ? self::summary_nameservers( $args ) : null;
	}

	/**
	 * Is moving a domain to name servers that are not ours worse than the tool usually is?
	 *
	 * @param string               $name Tool.
	 * @param array<string, mixed> $args Arguments (not yet validated).
	 */
	public static function risky( string $name, array $args ): ?bool {
		return 'domain_settings' === $name ? ( 'set_nameservers' === ( $args['action'] ?? null ) && 'custom' === ( $args['mode'] ?? null ) ) : null;
	}

	/**
	 * A setting of a domain: name servers, transfer lock, a check of the name servers, WHOIS privacy. Never the unlock, never DNSSEC (it needs the emailed code).
	 *
	 * @param array<string, mixed> $args Arguments.
	 * @return array<string, mixed>|\WP_Error
	 */
	private static function domain_settings( array $args ) {
		$action = (string) $args['action'];
		$send   = array();

		if ( 'set_nameservers' === $action ) {
			$send['mode'] = (string) ( $args['mode'] ?? '' );

			if ( isset( $args['hosts'] ) ) {
				$send['hosts'] = implode( ',', array_map( 'strval', (array) $args['hosts'] ) );
			}
		}

		if ( 'whois_privacy' === $action ) {
			if ( ! isset( $args['enabled'] ) || ! is_bool( $args['enabled'] ) ) {
				return new \WP_Error( 'beltoft_services_tool', __( 'Say whether WHOIS privacy should be on (enabled true) or off (enabled false).', 'beltoft-services' ) );
			}

			$send['enabled'] = $args['enabled'] ? '1' : '0';
		}

		return Tools::action( (int) $args['id'], $action, $send, str_replace( '_', ' ', $action ) . ' requested' );
	}

	/**
	 * A domain moved to name servers that are not ours.
	 *
	 * @param array<string, mixed> $args Tool arguments.
	 * @return array{text: string, state: string}|\WP_Error
	 */
	private static function summary_nameservers( array $args ) {
		$r = Tools::owned( (int) $args['id'], true );

		if ( $r instanceof \WP_Error ) {
			return $r;
		}

		$bad = ServerActions::not_offered( $r, 'set_nameservers' );

		if ( null !== $bad ) {
			return $bad;
		}

		$list = array_map( static fn( $h ) => strtolower( trim( (string) $h, ". \t" ) ), (array) ( $args['hosts'] ?? array() ) );

		foreach ( $list as $host ) {
			if ( ! HostName::is_host( $host, 253, true ) ) {
				return new \WP_Error( 'beltoft_services_tool', __( 'Each name server must be a host name such as ns1.example.net.', 'beltoft-services' ) );
			}
		}

		if ( count( $list ) < 2 || count( $list ) > 6 || count( array_unique( $list ) ) !== count( $list ) ) {
			return new \WP_Error( 'beltoft_services_tool', __( 'Give two to six different name servers.', 'beltoft-services' ) );
		}

		$hosts = "\n- " . implode( "\n- ", $list ) . "\n";

		return array(
			'text'  => sprintf(
				/* translators: 1: domain name, 2: the name servers, one per line. */
				__( 'Move the domain %1$s to these name servers:%2$s Our DNS records for it stop being used. If these name servers do not answer for the domain, its website and e-mail go offline until it is changed back.', 'beltoft-services' ),
				Irreversible::name( $r ),
				$hosts
			),
			'state' => $r->id() . '|ns|' . implode( ',', $list ),
		);
	}

	/**
	 * The record route of a DNS tool.
	 *
	 * @param int                  $id   Service id.
	 * @param array<string, mixed> $args Arguments.
	 */
	private static function dns_path( int $id, array $args ): string {
		return '/resources/' . $id . '/dns-records/' . rawurlencode( preg_replace( '/[^A-Za-z]/', '', (string) $args['type'] ) ) . '/' . rawurlencode( (string) $args['name'] );
	}

	/**
	 * "A www" for the history.
	 *
	 * @param array<string, mixed> $args Arguments.
	 */
	private static function dns_label( array $args ): string {
		return strtoupper( preg_replace( '/[^A-Za-z]/', '', (string) $args['type'] ) ) . ' ' . sanitize_text_field( (string) $args['name'] );
	}
}
