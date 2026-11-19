<?php
/**
 * The emailed code (StepUp) before a server or a database gets a new password. Showing the password afterwards keeps its own yes.
 * The core route asks for the purpose of an action on the filter beltoft_services_action_step_up and does the check, so the
 * dashboard and the REST API with a token pass through the same code. A connected app (MCP) cannot be given the code: the two tools are
 * not offered (see Mcp\ActionMap).
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Proxmox\Pve;

defined( 'ABSPATH' ) || exit;

final class ResetStepUp {

	/** The step-up purpose of making a new password for a server or a database. */
	public const PURPOSE = 'password_reset';

	/** The actions that need the code: the server's reset and the database's new password. */
	public const ACTIONS = array( 'reset_password', 'db_reset_password' );

	/**
	 * Hooks.
	 */
	public static function register(): void {
		add_filter( 'beltoft_services_stepup_purposes', array( __CLASS__, 'purposes' ) );
		add_filter( 'beltoft_services_stepup_text', array( __CLASS__, 'text' ), 10, 2 );
		add_filter( 'beltoft_services_action_step_up', array( __CLASS__, 'purpose_of' ), 10, 3 );
	}

	/**
	 * @param mixed $purposes Purposes so far.
	 * @return array<int, string>
	 */
	public static function purposes( $purposes ): array {
		return array_merge( (array) $purposes, array( self::PURPOSE ) );
	}

	/**
	 * What the code email says this purpose is for.
	 *
	 * @param mixed $text    Text so far.
	 * @param mixed $purpose Purpose.
	 */
	public static function text( $text, $purpose ): string {
		return self::PURPOSE === $purpose ? __( 'making a new password for a server or a database', 'beltoft-services' ) : (string) $text;
	}

	/**
	 * Listener of beltoft_services_action_step_up.
	 *
	 * @param mixed $purpose  Purpose so far ('' for none).
	 * @param mixed $resource Resource.
	 * @param mixed $action   Action name.
	 * @return mixed
	 */
	public static function purpose_of( $purpose, $resource, $action ) {
		return is_string( $action ) && in_array( $action, self::ACTIONS, true ) ? self::PURPOSE : $purpose;
	}
}
