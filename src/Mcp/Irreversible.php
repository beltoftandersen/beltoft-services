<?php
/**
 * The tools of Full access that cannot be undone or change where money goes, and the summaries of every call that needs
 * the customer's confirmation.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Mcp;

use BeltoftServices\RateLimit;
use BeltoftServices\Resource;

defined( 'ABSPATH' ) || exit;

/**
 * Every tool here does what the dashboard does and goes through the same code: the action route of the service, which
 * answers with the dashboard's own checks, or the subscription and payment methods of WooCommerce. What the dashboard
 * asks a customer to type (REINSTALL, RESTORE, ROLLBACK, OPEN TO EVERYONE, a domain name) is supplied by the server
 * once the confirmation token has been accepted: the token is the customer's yes.
 *
 * summary() is also the dry run. It runs the same checks that need no side effect, so a customer is not asked to
 * confirm something that would be refused, and it names the service, the consequences and, where money is involved, the
 * price, the period and the VAT. It changes nothing, and it is computed again when the confirmation arrives.
 *
 * Nothing here returns a secret. The tools that show one are in Secrets.
 */
final class Irreversible {

	/**
	 * The tools this class runs.
	 */
	public const NAMES = array( 'cancel_subscription', 'remove_addon', 'set_default_payment_method', 'delete_server', 'delete_database' );

	/**
	 * The summary of a call that needs a confirmation, and what the summary depends on.
	 *
	 * @param string               $name    Tool.
	 * @param array<string, mixed> $args    Arguments without the confirm value, as validated.
	 * @param int                  $user_id Customer.
	 * @return array{text: string, state: string}|\WP_Error
	 */
	public static function summary( string $name, array $args, int $user_id ) {
		$own = ToolSets::summary( $name, $args, $user_id );

		if ( null !== $own ) {
			return $own;
		}

		switch ( $name ) {
			case 'cancel_subscription':
			case 'remove_addon':
			case 'set_default_payment_method':
				return BillingActions::summary( $name, $args, $user_id );

			case 'delete_server':
			case 'delete_database':
				return DeleteService::summary( $name, $args, $user_id );

		}

		return new \WP_Error( 'beltoft_services_tool', __( 'This is not available.', 'beltoft-services' ) );
	}

	/**
	 * Runs one of the tools. The confirmation was accepted before this.
	 *
	 * @param string               $name    Tool.
	 * @param array<string, mixed> $args    Arguments, checked against the schema.
	 * @param int                  $user_id Customer.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function run( string $name, array $args, int $user_id ) {
		if ( in_array( $name, DeleteService::NAMES, true ) ) {
			return DeleteService::run( $name, $args, $user_id );
		}

		if ( in_array( $name, BillingActions::NAMES, true ) ) {
			return BillingActions::run( $name, $args, $user_id );
		}

		return new \WP_Error( 'beltoft_services_tool', __( 'This is not available.', 'beltoft-services' ) );
	}

	/**
	 * Writes a line about a call to the customer's trail: the history of the service the call is about, the notes of a
	 * subscription, or the customer's own log. Never a result or a secret.
	 *
	 * @param array<string, mixed> $args Arguments.
	 * @param string               $text What happened, after "Claude: ".
	 * @param bool                 $asked The line only says a summary was made (limited, kept apart).
	 */
	public static function trail( array $args, string $text, bool $asked = false ): void {
		// A summary is cheap to ask for: its line is written once a minute for the same words, and a customer gets at most ten
		// such lines an hour, so asking can not grow the history, the notes or the log without limit.
		if ( $asked && ( RateLimit::exceeded( get_current_user_id(), 'beltoft-services:mcp-asked:' . md5( wp_json_encode( $args ) . $text ), 1, MINUTE_IN_SECONDS ) || RateLimit::exceeded( get_current_user_id(), 'beltoft-services:mcp-asked-cap', 10, HOUR_IN_SECONDS ) ) ) {
			return;
		}

		if ( isset( $args['id'] ) && is_int( $args['id'] ) ) {
			Tools::log( $args['id'], $text, true );

			return;
		}

		if ( isset( $args['subscription_id'] ) && is_int( $args['subscription_id'] ) ) {
			$s = Acts::subscription( get_current_user_id(), $args['subscription_id'] );

			if ( $s instanceof \WC_Subscription ) {
				Acts::log_subscription( $s, $text );

				return;
			}
		}

		Audit::add( get_current_user_id(), $text, $asked );
	}

	/**
	 * What the customer calls a service.
	 *
	 * @param Resource $r Service.
	 */
	public static function name( Resource $r ): string {
		$license = $r->license();
		// How a service is called is the feature's word (filter beltoft_services_mcp_service_name).
		$name    = $license ? trim( wp_strip_all_tags( (string) apply_filters( 'beltoft_services_mcp_service_name', '', $r, $license ) ) ) : '';

		/* translators: 1: name of the service, 2: its id. */
		return '' !== $name ? sprintf( __( '%1$s (service #%2$d)', 'beltoft-services' ), $name, $r->id() ) : sprintf(
			/* translators: %d: service id. */
			__( 'service #%d', 'beltoft-services' ),
			$r->id()
		);
	}

	/**
	 * A tool name in words for the trail ("delete snapshot").
	 *
	 * @param string $name Tool.
	 */
	public static function label( string $name ): string {
		return str_replace( '_', ' ', $name );
	}
}
