<?php
/**
 * The MCP tools of one-click apps.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Apps;

use BeltoftServices\Mcp\Definitions;
use BeltoftServices\Mcp\Irreversible;
use BeltoftServices\Mcp\Secrets;
use BeltoftServices\Mcp\Tools;
use BeltoftServices\Mcp\ToolSet;
use BeltoftServices\Mcp\ToolSetDefaults;

defined( 'ABSPATH' ) || exit;

/**
 * The MCP tools of the apps on a server: trying an install again, changing the domain of the app, and showing the first login once. The login goes through the
 * platform's reveal (Secrets), which is the one gate for a tool that shows a secret.
 */
final class McpTools implements ToolSet {

	use ToolSetDefaults;

	/**
	 * @return array<string, array<string, mixed>>
	 */
	public static function tools(): array {
		return array(
			'retry_app_install' => Definitions::act( 'Tries again to install the app of a server whose installation failed or did not finish. It does not reinstall the server.', Definitions::by_id(), false ),
			'change_app_domain' => Definitions::act(
				'Adds a domain to the app of a server, or changes the one it has. It does not reinstall anything and nothing is erased. mode change: a name below one of the customer\'s domains that use our name servers (zone, and sub for a subdomain; leave sub out for the domain itself). mode own: a domain at another DNS host (own); the customer must add the A record we show in the dashboard, and we switch when it points at the server, for up to 14 days. The app keeps answering on the old address until the new one works, then restarts for up to a minute and the old address stops working. Links saved elsewhere or inside the app are not changed. The dashboard shows the state. A server can do this five times an hour.',
				Definitions::obj(
					array(
						'id'   => Definitions::id(),
						'mode' => array( 'type' => 'string', 'enum' => array( 'change', 'own' ), 'description' => 'change: a domain on our name servers. own: a domain at another DNS host.' ),
						'zone' => array( 'type' => 'string', 'maxLength' => 253, 'description' => 'For mode change: one of the customer\'s domains that use our name servers.' ),
						'sub'  => array( 'type' => 'string', 'maxLength' => 120, 'description' => 'For mode change: the subdomain. Leave out for the domain itself.' ),
						'own'  => array( 'type' => 'string', 'maxLength' => 253, 'description' => 'For mode own: the full domain name.' ),
					),
					array( 'id', 'mode' )
				),
				true
			),
			'reveal_app_login' => Definitions::full(
				'Shows the first login of the app on a server once. The value then is in this conversation and we no longer have it.',
				Definitions::obj( array( 'id' => Definitions::id() ), array( 'id' ), true ),
				true
			),
		);
	}

	/**
	 * @return array<string, string[]>
	 */
	public static function routes(): array {
		return array(
			'reveal_app_login'         => array( 'POST #^/resources/\d+/app-reveal$#D' ),
		);
	}

	/**
	 * @return array<string, string>
	 */
	public static function actions(): array {
		return array(
			'app_retry'          => 'retry_app_install',
			'app_domain'         => 'change_app_domain',
		);
	}

	/**
	 * @return array<string, string>
	 */
	public static function capabilities(): array {
		return array(
			'reveal_app_login'         => 'reveal_app_login',
		);
	}

	/**
	 * Runs one of the tools of this set. The first login is shown by the platform's one reveal (Secrets), which is also the
	 * gate: Secrets::NAMES names the tools that may show a secret.
	 *
	 * @param string               $name    Tool.
	 * @param array<string, mixed> $args    Arguments, checked against the schema.
	 * @param int                  $user_id Customer.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function run( string $name, array $args, int $user_id ) {
		if ( 'reveal_app_login' === $name ) {
			return Secrets::run( $name, $args );
		}

		if ( 'change_app_domain' === $name ) {
			$send = array();

			foreach ( array( 'mode' => 'app_domain_mode', 'zone' => 'app_zone', 'sub' => 'app_sub', 'own' => 'app_own' ) as $from => $to ) {
				if ( isset( $args[ $from ] ) && '' !== (string) $args[ $from ] ) {
					$send[ $to ] = (string) $args[ $from ];
				}
			}

			return Tools::action( (int) $args['id'], 'app_domain', $send, 'app domain change requested' );
		}

		return Tools::action( (int) $args['id'], 'app_retry', array(), 'app install retry requested' );
	}

	/**
	 * The summary of the first login: there must be one to show. Nothing is revealed or stored.
	 *
	 * @param string               $name    Tool.
	 * @param array<string, mixed> $args    Arguments without the confirm value.
	 * @param int                  $user_id Customer.
	 * @return array{text: string, state: string}|\WP_Error|null
	 */
	public static function summary( string $name, array $args, int $user_id ) {
		if ( 'reveal_app_login' !== $name ) {
			return null;
		}

		$id = (int) $args['id'];
		$r  = Tools::owned( $id, true );

		if ( $r instanceof \WP_Error ) {
			return $r;
		}

		$stored = $r->get( 'app_reveal' );

		if ( ! is_string( $stored ) || '' === $stored || (int) $r->get( 'app_reveal_until' ) < time() ) {
			return new \WP_Error( 'beltoft_services_tool', __( 'There is no app login to show. It has been shown already or it is no longer available.', 'beltoft-services' ) );
		}

		/* translators: %s: server name. */
		$text = sprintf( __( 'Show the first login of the app on server %s. It is shown once, then we no longer have it.', 'beltoft-services' ), Irreversible::name( $r ) );

		return Secrets::wrap( $text, $id . '|' . $name );
	}
}
