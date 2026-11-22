<?php
/**
 * The MCP tools of an email domain.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Mail;

use BeltoftServices\Mcp\Definitions;
use BeltoftServices\Mcp\Irreversible;
use BeltoftServices\Mcp\Tools;
use BeltoftServices\Mcp\ToolSet;
use BeltoftServices\Mcp\ToolSetDefaults;

defined( 'ABSPATH' ) || exit;

/**
 * The MCP tools of an email domain: the domain's information, mailboxes and aliases, the DNS check. The dashboard's own routes, so the
 * owner check, the plan limits and the names kept inside the domain are the same. A password is never in a result, and the password
 * reset and reveal have no tool (ActionMap::capabilities_excluded() has the reasons).
 */
final class McpTools implements ToolSet {

	use ToolSetDefaults;

	/** What an email change that is queued answers: accepted, not done; the domain's task says how it ended. */
	public const QUEUED = 'Accepted and queued: it runs in the background, one change of the domain at a time (another change is refused until it is done). email_domain_info shows the task of the domain: working while it runs, then last_result with ok and the outcome text.';

	/**
	 * @return array<string, array<string, mixed>>
	 */
	public static function tools(): array {
		return array(
			'email_domain_info' => Definitions::read( 'Shows one email domain: its state, the plan limits (mailboxes, size of each, aliases), every mailbox with its storage use and whether a password can still be shown, every alias with where it delivers, the DNS records with ok, missing or conflict for each, and the settings for a mail program. No password is shown here.' . Definitions::DATA, Definitions::by_id(), 30 ),
			'create_mailbox'   => Definitions::act( 'Makes a mailbox in an email domain, with the size of the plan. It is queued and made in the background (email_domain_info shows the task of the domain, then the mailbox with reveal true). It gets a password that is never shown here: the customer shows it once in the dashboard, after a code we email to them. Ask the customer first.', Definitions::obj( array( 'id' => Definitions::id(), 'name' => array( 'type' => 'string', 'maxLength' => 64, 'description' => 'The part before the @: letters, numbers, dots, dashes and underscores.' ), 'display_name' => array( 'type' => 'string', 'maxLength' => 100, 'description' => 'The name shown for the mailbox. Optional.' ) ), array( 'id', 'name' ) ), false ),
			'add_email_alias'  => Definitions::act( 'Makes an alias in an email domain: an address that delivers to one or more other addresses. There is no catch-all. Delivering to other domains is allowed only when the site allows it. Ask the customer first.', Definitions::obj( array( 'id' => Definitions::id(), 'name' => array( 'type' => 'string', 'maxLength' => 64, 'description' => 'The part before the @.' ), 'deliver_to' => array( 'type' => 'array', 'maxItems' => 10, 'items' => array( 'type' => 'string', 'maxLength' => 254 ), 'description' => 'One to ten addresses.' ) ), array( 'id', 'name', 'deliver_to' ) ), false ),
			'remove_email_alias' => Definitions::act( 'Deletes an alias of an email domain. Mail sent to it afterwards bounces. Ask the customer first.', Definitions::obj( array( 'id' => Definitions::id(), 'address' => array( 'type' => 'string', 'maxLength' => 254, 'description' => 'The alias address, from email_domain_info.' ) ), array( 'id', 'address' ) ), true, true ),
			'email_dns_check'  => Definitions::act( 'Asks for a look at the DNS of an email domain now. The look is queued and runs in the background (email_domain_info shows the task, then which records are in place, missing or in conflict). A record that is ours to publish in the customer\'s own zone is published.', Definitions::by_id(), false, true ),
			'delete_mailbox'   => Definitions::full(
				'Deletes a mailbox and every message in it for good. Get the address from email_domain_info.',
				Definitions::obj( array( 'id' => Definitions::id(), 'email' => array( 'type' => 'string', 'maxLength' => 254, 'description' => 'The mailbox address, from email_domain_info.' ) ), array( 'id', 'email' ), true )
			),
		);
	}

	/**
	 * @return array<string, string[]>
	 */
	public static function routes(): array {
		return array(
			'email_domain_info'        => array( 'GET #^/resources/\d+/mail$#D' ),
			'create_mailbox'           => array( 'POST #^/resources/\d+/mail/mailboxes$#D' ),
			'delete_mailbox'           => array( 'DELETE #^/resources/\d+/mail/mailboxes/[^/]+$#D' ),
			'add_email_alias'          => array( 'POST #^/resources/\d+/mail/aliases$#D' ),
			'remove_email_alias'       => array( 'DELETE #^/resources/\d+/mail/aliases/[^/]+$#D' ),
			'email_dns_check'          => array( 'POST #^/resources/\d+/mail/dns/check$#D' ),
		);
	}

	/**
	 * @return array<string, string>
	 */
	public static function capabilities(): array {
		return array(
			'delete_mailbox'           => 'delete_mailbox',
		);
	}

	/**
	 * @return array<string, string>
	 */
	public static function capabilities_excluded(): array {
		return array(
			'reset_mailbox_password'     => 'never: a new mailbox password needs the code emailed to the account address, so it is only available in the dashboard',
			'reveal_mailbox_password'    => 'never: showing a mailbox password needs the code emailed to the account address, so it is only available in the dashboard',
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
		$id = (int) $args['id'];

		if ( 'email_domain_info' === $name ) {
			$view = Tools::route( 'GET', '/resources/' . $id . '/mail', array(), null, $id, 'email domain read' );

			if ( $view instanceof \WP_Error ) {
				return $view;
			}

			// The word the dashboard asks to be typed is not for a result.
			unset( $view['delete_word'] );

			return $view;
		}

		if ( 'delete_mailbox' === $name ) {
			$done = Tools::route( 'DELETE', '/resources/' . $id . '/mail/mailboxes/' . rawurlencode( (string) $args['email'] ), array( 'confirm' => MailRoutes::CONFIRM_DELETE ), null, $id, 'mailbox deleted' );

			return $done instanceof \WP_Error ? $done : array( 'deleted' => (string) $done['deleted'], 'message' => 'The mailbox and every message in it were deleted.' );
		}

		return self::act( $name, $args );
	}

	/**
	 * The summary of a call that needs the confirmation: only the mailbox deletion.
	 *
	 * @param string               $name    Tool.
	 * @param array<string, mixed> $args    Arguments without the confirm value, as validated.
	 * @param int                  $user_id Customer.
	 * @return array{text: string, state: string}|\WP_Error|null
	 */
	public static function summary( string $name, array $args, int $user_id ) {
		return 'delete_mailbox' === $name ? self::summary_mailbox( $args ) : null;
	}

	/**
	 * The email tools: the dashboard's own routes, so the owner check, the plan limits and the names kept inside the domain
	 * are the same. A password is never in a result.
	 *
	 * @param string               $name Tool.
	 * @param array<string, mixed> $args Arguments, checked against the schema.
	 * @return array<string, mixed>|\WP_Error
	 */
	private static function act( string $name, array $args ) {
		$id   = (int) $args['id'];
		$base = '/resources/' . $id . '/mail';

		switch ( $name ) {
			case 'create_mailbox':
				$body = array( 'local' => (string) $args['name'] );

				if ( isset( $args['display_name'] ) ) {
					$body['name'] = (string) $args['display_name'];
				}

				$done = Tools::route( 'POST', $base . '/mailboxes', array(), $body, $id, 'mailbox created' );

				return $done instanceof \WP_Error ? $done : array( 'queued' => true, 'address' => (string) $done['email'], 'quota_mb' => (int) $done['quota_mb'], 'message' => self::QUEUED . ' When it is done the mailbox is in the list with reveal true. Its password is not shown here: the customer shows it once in the dashboard, after a code we email to them.' );

			case 'add_email_alias':
				$done = Tools::route( 'POST', $base . '/aliases', array(), array( 'address' => (string) $args['name'], 'goto' => array_values( array_map( 'strval', (array) $args['deliver_to'] ) ) ), $id, 'alias created' );

				return $done instanceof \WP_Error ? $done : array( 'created' => (string) $done['address'], 'delivers_to' => $done['goto'] );

			case 'remove_email_alias':
				$done = Tools::route( 'DELETE', $base . '/aliases/' . rawurlencode( (string) $args['address'] ), array(), null, $id, 'alias deleted' );

				return $done instanceof \WP_Error ? $done : array( 'deleted' => (string) $done['deleted'] );
		}

		$done = Tools::route( 'POST', $base . '/dns/check', array(), array(), $id, 'email DNS check requested' );

		return $done instanceof \WP_Error ? $done : array( 'queued' => true, 'message' => self::QUEUED . ' The records below are as they were when you asked.', 'dns' => (array) $done['dns'] );
	}

	/**
	 * The summary of a mailbox deletion: the dashboard's checks (a ready email domain, a mailbox of it that exists).
	 *
	 * @param array<string, mixed> $args Arguments (id, email).
	 * @return array{text: string, state: string}|\WP_Error
	 */
	private static function summary_mailbox( array $args ) {
		$r = Tools::owned( (int) $args['id'], true );

		if ( $r instanceof \WP_Error ) {
			return $r;
		}

		$bad = MailGuard::check( $r );

		if ( $bad ) {
			return new \WP_Error( 'beltoft_services_tool', $bad->get_error_message() );
		}

		$email = MailGuard::inside( $r, $args['email'] );

		if ( null === $email ) {
			return new \WP_Error( 'beltoft_services_tool', __( 'That mailbox is not part of this email domain.', 'beltoft-services' ) );
		}

		$box = MailClients::for()->get_mailbox( $email );

		if ( is_wp_error( $box ) || null === $box ) {
			return new \WP_Error( 'beltoft_services_tool', is_wp_error( $box ) ? __( 'The mail server could not be reached. Try again in a moment.', 'beltoft-services' ) : __( 'That mailbox does not exist.', 'beltoft-services' ) );
		}

		return array(
			'text'  => sprintf(
				/* translators: 1: mailbox address, 2: name of the service. */
				__( 'Delete the mailbox %1$s of %2$s. Every message in it is deleted for good and cannot be brought back. Its password stops working and mail sent to it afterwards bounces.', 'beltoft-services' ),
				$email,
				Irreversible::name( $r )
			),
			'state' => $r->id() . '|delete_mailbox|' . $email,
		);
	}
}
