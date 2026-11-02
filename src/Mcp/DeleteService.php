<?php
/**
 * The tools of Full access that delete a pay-as-you-go server or database.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Mcp;

use BeltoftServices\Resource;
use BeltoftServices\Usage\CustomerDelete;

defined( 'ABSPATH' ) || exit;

/**
 * Thin: the rules, the words and the work are in BeltoftServices\Usage\CustomerDelete, which the dashboard uses too. The
 * customer's yes is the confirmation token, which Tools::call() has accepted before run(); the word the dashboard asks to be typed
 * is not needed again. Irreversible, so the tools are not idempotent and a confirmation works once.
 */
final class DeleteService {

	/**
	 * The tools this class runs, each with what it deletes.
	 */
	public const NAMES = array( 'delete_server', 'delete_database' );

	/**
	 * The service of the call: the customer's own, of the kind the tool is for.
	 *
	 * @param string $name    Tool.
	 * @param int    $id      Service id.
	 * @param int    $user_id Customer.
	 * @return Resource|\WP_Error
	 */
	private static function service( string $name, int $id, int $user_id ) {
		$r       = Resource::find( $id );
		$license = $r ? $r->license() : array();

		if ( ! $r || $user_id < 1 || empty( $license['user_id'] ) || (int) $license['user_id'] !== $user_id ) {
			return new \WP_Error( 'beltoft_services_forbidden', __( 'This service is not available.', 'beltoft-services' ) );
		}

		$kind = CustomerDelete::kind( $r );
		$want = 'delete_database' === $name ? 'database' : 'server';

		if ( 'disk' === $kind ) {
			return new \WP_Error( 'beltoft_services_tool', __( 'This is an extra disk. Remove it with remove_addon.', 'beltoft-services' ) );
		}

		if ( $kind !== $want ) {
			return new \WP_Error(
				'beltoft_services_tool',
				'' === $kind
					? __( 'Only a pay as you go server or database can be deleted here. Another service is ended with cancel_subscription.', 'beltoft-services' )
					/* translators: 1: what the service is (server or database), 2: the tool for it. */
					: sprintf( __( 'This service is a %1$s. Use %2$s.', 'beltoft-services' ), $kind, 'database' === $kind ? 'delete_database' : 'delete_server' )
			);
		}

		$why = CustomerDelete::blocker( $r );

		return null === $why ? $r : new \WP_Error( 'beltoft_services_tool', $why );
	}

	/**
	 * What deleting does, for the customer's confirmation.
	 *
	 * @param string               $name    Tool.
	 * @param array<string, mixed> $args    Arguments.
	 * @param int                  $user_id Customer.
	 * @return array{text: string, state: string}|\WP_Error
	 */
	public static function summary( string $name, array $args, int $user_id ) {
		$r = self::service( $name, (int) $args['id'], $user_id );

		if ( $r instanceof \WP_Error ) {
			return $r;
		}

		$kind = CustomerDelete::kind( $r );

		return array(
			'text'  => sprintf(
				/* translators: 1: what it is (server or database), 2: its name, 3: what deleting does, 4: the billing rule. */
				__( 'Delete the %1$s %2$s. %3$s The other services and the payment method are not touched. %4$s', 'beltoft-services' ),
				$kind,
				Irreversible::name( $r ),
				CustomerDelete::text( $kind ),
				CustomerDelete::billing_note( $kind )
			),
			'state' => $r->id() . '|' . $r->get( 'state' ),
		);
	}

	/**
	 * Deletes after the confirmation, as the dashboard's Delete does.
	 *
	 * @param string               $name    Tool.
	 * @param array<string, mixed> $args    Arguments.
	 * @param int                  $user_id Customer.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function run( string $name, array $args, int $user_id ) {
		$r = self::service( $name, (int) $args['id'], $user_id );

		if ( $r instanceof \WP_Error ) {
			return $r;
		}

		$kind = CustomerDelete::kind( $r );
		$done = CustomerDelete::run( $r, $user_id, '', true );

		if ( $done instanceof \WP_Error ) {
			return new \WP_Error( 'beltoft_services_tool', $done->get_error_message() );
		}

		return array(
			'deleting' => true,
			'message'  => 'The ' . $kind . ' is being deleted. This takes a minute. Billing stops in the hour it is deleted. Nothing else was touched.',
		);
	}
}
