<?php
/**
 * The tools that show a secret: the password of a server or a database, the first login of an app, a CA certificate.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Mcp;

use BeltoftServices\Proxmox\Access;
use BeltoftServices\Proxmox\DbDriver;
use BeltoftServices\Proxmox\DbGuest;
use BeltoftServices\Proxmox\DbRoutes;
use BeltoftServices\Resource;

defined( 'ABSPATH' ) || exit;

/**
 * Full access and the customer's confirmation, and then the dashboard's own reveal: the routes the Reveal buttons call,
 * with their owner check, their floor of requests and their one-time rule (the stored value is wiped before it is
 * returned, so a second call gets the dashboard's "already shown" answer). The route is reached through Tools::route,
 * so a tool can reach only the route it is named for in ActionMap.
 *
 * A secret is never in the confirmation, the history or an e-mail, and never in the result of any other tool. What the
 * customer is told: the value is now in the conversation, and how to change it. Each reveal of a password or login
 * writes a line to the history of the service and sends the customer an e-mail ("A connected app showed your server
 * password"), through the notice e-mail. A CA certificate is public and is only noted in the history.
 *
 * Domain transfer codes and console tickets have no tool at any level (see ActionMap).
 */
final class Secrets {

	/**
	 * The tools this class runs.
	 */
	public const NAMES = array( 'reveal_server_password', 'reveal_database_password', 'reveal_app_login', 'get_database_ca_certificate' );

	/**
	 * What each tool shows: the route, the key of the value in the route's answer, the e-mail kind and words for the history.
	 */
	private const KINDS = array(
		'reveal_server_password'      => array( '/reveal', 'password', 'reveal_server', 'server password' ),
		'reveal_database_password'    => array( '/db-reveal', 'password', 'reveal_database', 'database password' ),
		'reveal_app_login'            => array( '/app-reveal', 'credentials', 'reveal_app', 'app login' ),
		'get_database_ca_certificate' => array( '/db-ca', 'text', '', 'database CA certificate' ),
	);

	/**
	 * The summary of a call, and whether there is anything to show. Nothing is revealed or stored.
	 *
	 * @param string               $name Tool.
	 * @param array<string, mixed> $args Arguments without the confirm value.
	 * @return array{text: string, state: string}|\WP_Error
	 */
	public static function summary( string $name, array $args ) {
		$id = (int) $args['id'];
		$r  = Tools::owned( $id, 'get_database_ca_certificate' !== $name );

		if ( $r instanceof \WP_Error ) {
			return $r;
		}

		$label = Irreversible::name( $r );

		switch ( $name ) {
			case 'reveal_server_password':
				if ( ! Access::revealable( $r ) ) {
					return new \WP_Error( 'beltoft_services_tool', __( 'There is no password to show. It has been shown already or it is no longer available. reset_server_password makes a new one.', 'beltoft-services' ) );
				}

				/* translators: %s: server name. */
				$text = sprintf( __( 'Show the password of server %s. It is shown once, then we no longer have it.', 'beltoft-services' ), $label );
				break;

			case 'reveal_database_password':
				if ( DbDriver::ID !== $r->get( 'driver' ) || ! DbRoutes::revealable( $r ) ) {
					return new \WP_Error( 'beltoft_services_tool', __( 'There is no password to show. It has been shown already or it is no longer available. reset_database_password makes a new one.', 'beltoft-services' ) );
				}

				/* translators: %s: database name. */
				$text = sprintf( __( 'Show the password of database %s. It is shown once, then we no longer have it.', 'beltoft-services' ), $label );
				break;

			case 'reveal_app_login':
				$stored = $r->get( 'app_reveal' );

				if ( ! is_string( $stored ) || '' === $stored || (int) $r->get( 'app_reveal_until' ) < time() ) {
					return new \WP_Error( 'beltoft_services_tool', __( 'There is no app login to show. It has been shown already or it is no longer available.', 'beltoft-services' ) );
				}

				/* translators: %s: server name. */
				$text = sprintf( __( 'Show the first login of the app on server %s. It is shown once, then we no longer have it.', 'beltoft-services' ), $label );
				break;

			default:
				if ( DbDriver::ID !== $r->get( 'driver' ) || ! DbGuest::pem( (string) $r->get( 'db_ca_pem' ) ) ) {
					return new \WP_Error( 'beltoft_services_tool', __( 'The certificate is not available.', 'beltoft-services' ) );
				}

				/* translators: %s: database name. */
				return array(
					'text'  => sprintf( __( 'Show the CA certificate of database %s. It is a public certificate, not a password: it is the text to save as a file so a client can check the database over TLS.', 'beltoft-services' ), $label ),
					'state' => $id . '|ca',
				);
		}

		return array(
			'text'  => $text . ' ' . __( 'The value will then be in this conversation, which the app keeps, so anyone who can read the conversation can read it. The customer gets an e-mail about it, and the history of the service notes it. Afterwards the customer can change the password in their account, or ask for a new one.', 'beltoft-services' ),
			'state' => $id . '|' . $name,
		);
	}

	/**
	 * Shows the secret. The confirmation was accepted before this.
	 *
	 * @param string               $name Tool.
	 * @param array<string, mixed> $args Arguments.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function run( string $name, array $args ) {
		$id                                = (int) $args['id'];
		list( $path, $key, $kind, $words ) = self::KINDS[ $name ];
		$method                            = 'get_database_ca_certificate' === $name ? 'GET' : 'POST';
		$done                              = Tools::route( $method, '/resources/' . $id . $path, array(), 'POST' === $method ? array() : null, $id, $words . ' shown' );

		if ( $done instanceof \WP_Error ) {
			return $done;
		}

		$value = $done[ $key ] ?? null;

		if ( null === $value || array() === $value || '' === $value ) {
			return new \WP_Error( 'beltoft_services_tool', __( 'There is nothing to show.', 'beltoft-services' ) );
		}

		if ( '' === $kind ) {
			// A read leaves no line of its own in Tools::route: this one is shown on purpose, so it is noted.
			Tools::log( $id, $words . ' shown', true );

			return array(
				'certificate' => (string) $value,
				'note'        => 'The CA certificate is public. Save it as a file, for example ca.crt, and give it to the client as the trusted certificate.',
			);
		}

		$r = Resource::find( $id );

		if ( $r ) {
			Told::send( get_current_user_id(), $r, $kind, '' );
		}

		$change = 'reveal_database_password' === $name
			? 'To change it, use reset_database_password, or the customer does it in the dashboard.'
			: ( 'reveal_server_password' === $name ? 'To change it, use reset_server_password, or the customer does it in the dashboard.' : 'The customer can change it in the app itself after logging in.' );

		if ( 'reveal_app_login' === $name ) {
			return array( 'login' => $value, 'shown_once' => true, 'note' => 'This login is now in this conversation. We no longer have it. ' . $change );
		}

		return array( 'password' => (string) $value, 'shown_once' => true, 'note' => 'This password is now in this conversation. We no longer have it, and it is not shown again. ' . $change );
	}
}
