<?php
/**
 * Builds the node API client and the agent client of a hosting node.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Hosting;

use BeltoftServices\Drivers\LiveGate;
use BeltoftServices\Proxmox\Pve\Client;

defined( 'ABSPATH' ) || exit;

/**
 * The only place a client of a node is built. Gated exactly like Proxmox\Pve\Clients::for(): a live client needs the
 * node to say "live" AND the constant BELTOFT_SERVICES_HOSTING_LIVE to be true in wp-config.php AND an explicit
 * site tag. No filter can switch it on.
 *
 * Both live clients exist: LiveAgent for the node agent and the Proxmox LiveClient for the node API. A live node
 * that is not allowed or has no site tag never gets a fake that would report success: it gets an exception.
 */
final class Clients {

	/**
	 * The shared live/fake gate.
	 */
	private static function gate_object(): LiveGate {
		return new LiveGate( 'BELTOFT_SERVICES_HOSTING_LIVE', 'hosting ', 'Set the site tag for hosting node %s' );
	}

	/**
	 * Whether wp-config.php allows live hosting calls.
	 */
	public static function live_allowed(): bool {
		return self::gate_object()->live_allowed();
	}

	/**
	 * Is this node live (and allowed to be) but without an explicit site tag? Its ownership tag would be a
	 * guess, so nothing may be done to it.
	 *
	 * @param array<string, mixed> $node    Node settings.
	 * @param bool                 $allowed Whether wp-config.php allows live calls.
	 */
	public static function untagged( array $node, bool $allowed ): bool {
		return self::gate_object()->untagged( $node, $allowed );
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
		return self::gate_object()->gate( $id, $node, $allowed );
	}

	/**
	 * Which client a node gets: live only when it says so AND the allowance is true.
	 *
	 * @param array<string, mixed> $node    Node settings.
	 * @param bool                 $allowed Whether wp-config.php allows live calls.
	 * @return string 'live' or 'fake'.
	 */
	public static function kind( array $node, bool $allowed ): string {
		return self::gate_object()->kind( $node, $allowed );
	}

	/**
	 * The node API client.
	 *
	 * @param array<string, mixed> $node Node settings.
	 * @throws \RuntimeException For a live node without a site tag or with settings the live client refuses.
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
		if ( self::untagged( $node, $allowed ) ) {
			throw new \RuntimeException( 'The site tag is not set.' );
		}

		// The only place a live node API client is built: the node says live, the constant allows it and the site
		// tag is set. It is the path-agnostic Proxmox\Pve\LiveClient (token, CA file, public key pin, timeouts), which
		// asks for the allowance again and refuses bad settings; the lxc paths are the callers'.
		if ( 'live' === self::kind( $node, $allowed ) ) {
			return new \BeltoftServices\Proxmox\Pve\LiveClient(
				array(
					'api_url'      => (string) ( $node['api_url'] ?? '' ),
					'token_id'     => (string) ( $node['token_id'] ?? '' ),
					'token_secret' => (string) ( $node['token_secret'] ?? '' ),
					'ca_file'      => (string) ( $node['ca_file'] ?? '' ),
					'pin_sha256'   => (string) ( $node['pin_sha256'] ?? '' ),
				),
				$allowed
			);
		}

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
