<?php
/**
 * Builds the node API client and the agent client of a hosting node.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Hosting;

use BeltoftServices\Proxmox\Client;
use BeltoftServices\Proxmox\ProxmoxSettings;

defined( 'ABSPATH' ) || exit;

/**
 * The only place a client of a node is built. Gated exactly like Proxmox\Clients::for(): a live client needs the
 * node to say "live" AND the constant BELTOFT_SERVICES_HOSTING_LIVE to be true in wp-config.php AND an explicit
 * site tag. No filter can switch it on.
 *
 * The agent has a live client (LiveAgent). The node API has none yet: a node that is live and allowed gets a
 * RuntimeException from build_node(), never a fake that would report success. A live agent client without the node
 * API client is not used by the driver, which builds both (Runs::connect()).
 */
final class Clients {

	/**
	 * Whether wp-config.php allows live hosting calls.
	 */
	public static function live_allowed(): bool {
		return defined( 'BELTOFT_SERVICES_HOSTING_LIVE' ) && true === constant( 'BELTOFT_SERVICES_HOSTING_LIVE' );
	}

	/**
	 * Is this node live (and allowed to be) but without an explicit site tag? Its ownership tag would be a
	 * guess, so nothing may be done to it.
	 *
	 * @param array<string, mixed> $node    Node settings.
	 * @param bool                 $allowed Whether wp-config.php allows live calls.
	 */
	public static function untagged( array $node, bool $allowed ): bool {
		return $allowed && 'live' === ( $node['client'] ?? 'fake' ) && '' === ProxmoxSettings::explicit_site_tag();
	}

	/**
	 * The gate before a client is built for a node: the retry text when the node is live without a site tag
	 * (and the admin is alerted, once a day), otherwise null.
	 *
	 * @param string               $id      Node id.
	 * @param array<string, mixed> $node    Node settings.
	 * @param bool                 $allowed Whether wp-config.php allows live calls.
	 */
	public static function gate( string $id, array $node, bool $allowed ): ?string {
		if ( ! self::untagged( $node, $allowed ) ) {
			return null;
		}

		\BeltoftServices\Alerts::site_tag( 'hosting ' . $id );

		/* Admin text, not shown to customers. */
		return sprintf( 'Set the site tag for hosting node %s', $id );
	}

	/**
	 * Which client a node gets: live only when it says so AND the allowance is true.
	 *
	 * @param array<string, mixed> $node    Node settings.
	 * @param bool                 $allowed Whether wp-config.php allows live calls.
	 * @return string 'live' or 'fake'.
	 */
	public static function kind( array $node, bool $allowed ): string {
		return $allowed && 'live' === ( $node['client'] ?? 'fake' ) ? 'live' : 'fake';
	}

	/**
	 * The node API client.
	 *
	 * @param array<string, mixed> $node Node settings.
	 * @throws \RuntimeException For a live node (no live client exists yet) or a live node without a site tag.
	 */
	public static function for_node( array $node ): Client {
		return self::build_node( $node, self::live_allowed() );
	}

	/**
	 * The agent client.
	 *
	 * @param array<string, mixed> $node Node settings.
	 * @throws \RuntimeException For a live node (no live client exists yet) or a live node without a site tag.
	 */
	public static function agent_for( array $node ): Agent {
		return self::build_agent( $node, self::live_allowed() );
	}

	/**
	 * for_node() with the allowance given.
	 *
	 * @param array<string, mixed> $node    Node settings.
	 * @param bool                 $allowed Whether wp-config.php allows live calls.
	 * @throws \RuntimeException For a live node or a live node without a site tag.
	 */
	public static function build_node( array $node, bool $allowed ): Client {
		self::refuse_live( $node, $allowed );

		$client = new FakeNode( ! empty( $node['persist'] ) );
		$public = self::public_view( $node );

		/**
		 * Lets tests inject a node client (a FakeNode). It does not receive the allowance or any secret, and
		 * only a FakeNode is accepted.
		 *
		 * @param Client               $client The client.
		 * @param array<string, mixed> $node   Node settings without secrets.
		 */
		$filtered = apply_filters( 'beltoft_services_hosting_client', $client, $public );

		return $filtered instanceof FakeNode ? $filtered : $client;
	}

	/**
	 * agent_for() with the allowance given.
	 *
	 * @param array<string, mixed> $node    Node settings.
	 * @param bool                 $allowed Whether wp-config.php allows live calls.
	 * @throws \RuntimeException For a live node or a live node without a site tag.
	 */
	public static function build_agent( array $node, bool $allowed ): Agent {
		if ( self::untagged( $node, $allowed ) ) {
			throw new \RuntimeException( 'The site tag is not set.' );
		}

		// The only place a live agent client is built: the node says live, the constant allows it and the site
		// tag is set. LiveAgent refuses again without the allowance, and without a complete mutual TLS setup.
		if ( 'live' === self::kind( $node, $allowed ) ) {
			return new LiveAgent( $node, $allowed );
		}

		$agent  = new FakeAgent( ! empty( $node['persist'] ) );
		$public = self::public_view( $node );

		/**
		 * Lets tests inject an agent client (a FakeAgent). It does not receive the allowance or any secret,
		 * and only a FakeAgent is accepted.
		 *
		 * @param Agent                $agent The client.
		 * @param array<string, mixed> $node  Node settings without secrets.
		 */
		$filtered = apply_filters( 'beltoft_services_hosting_agent', $agent, $public );

		return $filtered instanceof FakeAgent ? $filtered : $agent;
	}

	/**
	 * Refuses a live node.
	 *
	 * @param array<string, mixed> $node    Node settings.
	 * @param bool                 $allowed Whether wp-config.php allows live calls.
	 * @throws \RuntimeException When the node is live and allowed.
	 */
	private static function refuse_live( array $node, bool $allowed ): void {
		if ( self::untagged( $node, $allowed ) ) {
			throw new \RuntimeException( 'The site tag is not set.' );
		}

		if ( 'live' === self::kind( $node, $allowed ) ) {
			throw new \RuntimeException( 'No live hosting client exists in this version.' );
		}
	}

	/**
	 * The node settings without any secret.
	 *
	 * @param array<string, mixed> $node Node settings.
	 * @return array<string, mixed>
	 */
	private static function public_view( array $node ): array {
		foreach ( Nodes::SECRETS as $key ) {
			unset( $node[ $key ] );
		}

		return $node;
	}
}
