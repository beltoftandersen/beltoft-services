<?php
/**
 * The summaries and the execution of the irreversible tools on a server, a database, a mailbox or a domain.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Mcp;

use BeltoftServices\Domains\HostName;
use BeltoftServices\Drivers\Registry;
use BeltoftServices\Mail\MailClients;
use BeltoftServices\Mail\MailGuard;
use BeltoftServices\Mail\MailRoutes;
use BeltoftServices\Proxmox\Vm\Actions;
use BeltoftServices\Proxmox\AppReinstallPaid;
use BeltoftServices\Proxmox\Backup\Backups;
use BeltoftServices\Proxmox\Pve\Clusters;
use BeltoftServices\Proxmox\DbAccess;
use BeltoftServices\Proxmox\DbActions;
use BeltoftServices\Proxmox\DbPolicy;
use BeltoftServices\Proxmox\Network\Firewall;
use BeltoftServices\Proxmox\Snapshot\Snapshots;
use BeltoftServices\Proxmox\Vm\ProxmoxDriver;
use BeltoftServices\Proxmox\Vm\SshKeyField;
use BeltoftServices\Proxmox\Vm\VmRecords;
use BeltoftServices\Resource;

defined( 'ABSPATH' ) || exit;

/**
 * Called by Irreversible, which owns the confirmation flow. Every tool here does what the dashboard does and goes
 * through the same code: the action route of the service, which answers with the dashboard's own checks.
 */
final class ServerActions {

	/**
	 * The summary of a tool about a service the customer owns, whose action is offered now.
	 *
	 * @param string               $name   Tool.
	 * @param array<string, mixed> $args   Arguments.
	 * @param Resource             $r      Service.
	 * @param string               $action The dashboard's action for the tool.
	 * @param string               $label  The service's name.
	 * @return array{text: string, state: string}|\WP_Error
	 */
	public static function summary( string $name, array $args, Resource $r, string $action, string $label ) {
		switch ( $name ) {
			case 'delete_snapshot':
			case 'rollback_snapshot':
				return self::summary_snapshot( $r, $name, (string) $args['snapshot'], $label );

			case 'restore_backup':
				return self::summary_restore( $r, (string) $args['backup'], $label );

			case 'reset_database_password':
				return self::checked( $r, $action, array(), sprintf(
					/* translators: %s: database name. */
					__( 'Make a new password for database %s. The old password stops working at once, so every application that connects with it must be changed. The new password is not shown here: it can be shown once afterwards, with the customer\'s yes again.', 'beltoft-services' ),
					$label
				) );

			case 'reinstall_server':
				return self::summary_reinstall( $r, $args, $label );

			case 'replace_ssh_key':
				return self::summary_key( $r, (string) $args['key'], $label );

			case 'reset_firewall':
				return self::checked( $r, $action, array(), sprintf(
					/* translators: %s: server name. */
					__( 'Start the firewall of server %s again from its default rules. The rules the customer has set are removed. Anything the default rules do not allow is blocked, and the change runs in the background.', 'beltoft-services' ),
					$label
				) );

			case 'reset_server_password':
				return self::checked( $r, $action, array(), sprintf(
					/* translators: %s: server name. */
					__( 'Make a new password for server %s. The old password stops working at once, so anything that logs in with it must be changed. The new password is not shown here: it can be shown once afterwards, with the customer\'s yes again. A password can be reset three times an hour.', 'beltoft-services' ),
					$label
				) );

			case 'set_database_eviction_policy':
				return self::summary_policy( $r, (string) $args['policy'], $label );
		}

		return new \WP_Error( 'beltoft_services_tool', __( 'This is not available.', 'beltoft-services' ) );
	}

	/**
	 * Runs one of the tools of this class. The confirmation was accepted before this.
	 *
	 * @param string               $name Tool.
	 * @param array<string, mixed> $args Arguments, checked against the schema.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function run( string $name, array $args ) {
		switch ( $name ) {
			case 'delete_mailbox':
				$id   = (int) $args['id'];
				$done = Tools::route( 'DELETE', '/resources/' . $id . '/mail/mailboxes/' . rawurlencode( (string) $args['email'] ), array( 'confirm' => MailRoutes::CONFIRM_DELETE ), null, $id, 'mailbox deleted' );

				return $done instanceof \WP_Error ? $done : array( 'deleted' => (string) $done['deleted'], 'message' => 'The mailbox and every message in it were deleted.' );

			case 'reset_mailbox_password':
				$id   = (int) $args['id'];
				$done = Tools::route( 'POST', '/resources/' . $id . '/mail/mailboxes/' . rawurlencode( (string) $args['email'] ) . '/password', array(), array(), $id, 'new mailbox password made' );

				return $done instanceof \WP_Error ? $done : array( 'done' => true, 'message' => 'The old password no longer works. The new one is not shown here. The customer shows it once in the dashboard, or reveal_mailbox_password shows it after the customer says yes.' );

			case 'delete_snapshot':
				return self::send( $args, 'snapshot_delete', array( 'id' => (string) $args['snapshot'] ), 'delete snapshot requested' );

			case 'rollback_snapshot':
				return self::send( $args, 'snapshot_rollback', array( 'id' => (string) $args['snapshot'], 'confirm' => Snapshots::CONFIRM_ROLLBACK ), 'roll back to snapshot requested' );

			case 'restore_backup':
				return self::send( $args, 'restore', array( 'volid' => (string) $args['backup'], 'confirm' => Actions::CONFIRM_RESTORE ), 'restore from backup requested' );

			case 'reinstall_server':
				return self::reinstall( $args );

			case 'replace_ssh_key':
				return self::send( $args, 'replace_ssh_key', array( 'key' => (string) $args['key'] ), 'SSH key change requested' );

			case 'reset_firewall':
				return self::send( $args, 'fw_reset', array(), 'firewall reset requested' );

			case 'reset_server_password':
				$done = Tools::action( (int) $args['id'], 'reset_password', array(), 'server password reset requested', true );

				return $done instanceof \WP_Error ? $done : array( 'requested' => true, 'message' => 'Done. A new password is being made. It is not shown here. Once the server has it, reveal_server_password shows it once, after the customer\'s yes.' );

			case 'reset_database_password':
				$done = Tools::action( (int) $args['id'], DbActions::RESET, array( 'confirm' => DbActions::CONFIRM ), 'new database password requested', true );

				return $done instanceof \WP_Error ? $done : array( 'requested' => true, 'message' => 'Done. A new password is being made. It is not shown here. Once the database has it, reveal_database_password shows it once, after the customer\'s yes.' );

			case 'set_database_eviction_policy':
				return self::send( $args, DbPolicy::ACTION, array( 'policy' => (string) $args['policy'] ), 'memory-full policy change requested' );
		}

		return new \WP_Error( 'beltoft_services_tool', __( 'This is not available.', 'beltoft-services' ) );
	}

	/**
	 * Is the action not offered for the service now? The same list the dashboard draws its buttons from.
	 *
	 * @param Resource $r      Service.
	 * @param string   $action Action.
	 */
	public static function not_offered( Resource $r, string $action ): ?\WP_Error {
		$driver  = Registry::get( (string) $r->get( 'driver' ) );
		$offered = $driver ? $driver->customer_actions( $r ) : array();

		return array_key_exists( $action, $offered ) ? null : new \WP_Error( 'beltoft_services_tool', __( 'This is not available for this service now.', 'beltoft-services' ) );
	}

	/**
	 * The dashboard's own check of an action, without counting a request or storing anything: the Proxmox actions have
	 * one, with the words the dashboard shows. Other drivers answer at the route.
	 *
	 * @param Resource             $r      Service.
	 * @param string               $action Action.
	 * @param array<string, mixed> $args   Action arguments, with the typed word the dashboard asks for.
	 */
	private static function refusal( Resource $r, string $action, array $args ): ?string {
		if ( ! in_array( (string) $r->get( 'driver' ), array( ProxmoxDriver::ID, 'proxmox-db' ), true ) ) {
			return null;
		}

		return Actions::check( $r, $action, $args );
	}

	/**
	 * A summary when the dashboard's check passes, else its refusal.
	 *
	 * @param Resource             $r      Service.
	 * @param string               $action Action.
	 * @param array<string, mixed> $args   Action arguments.
	 * @param string               $text   Summary.
	 * @param string               $state  What the summary depends on, besides the service's state.
	 * @return array{text: string, state: string}|\WP_Error
	 */
	private static function checked( Resource $r, string $action, array $args, string $text, string $state = '' ) {
		$why = self::refusal( $r, $action, $args );

		if ( null !== $why ) {
			return new \WP_Error( 'beltoft_services_tool', $why );
		}

		return array( 'text' => $text, 'state' => $r->id() . '|' . $action . '|' . $r->get( 'state' ) . '|' . $state );
	}

	/**
	 * Posts an action with the dashboard's own route.
	 *
	 * @param array<string, mixed>  $args    Tool arguments (id).
	 * @param string                $action  Action.
	 * @param array<string, string> $send    Action arguments.
	 * @param string                $history What to write after "Claude: ".
	 * @return array<string, mixed>|\WP_Error
	 */
	private static function send( array $args, string $action, array $send, string $history ) {
		return Tools::action( (int) $args['id'], $action, $send, $history );
	}

	/**
	 * The summary of a mailbox deletion: the dashboard's checks (a ready email domain, a mailbox of it that exists).
	 *
	 * @param array<string, mixed> $args Arguments (id, email).
	 * @param string               $name Tool: delete_mailbox or reset_mailbox_password.
	 * @return array{text: string, state: string}|\WP_Error
	 */
	public static function summary_mailbox( array $args, string $name = 'delete_mailbox' ) {
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

		if ( 'reset_mailbox_password' === $name ) {
			return array(
				'text'  => sprintf(
					/* translators: 1: mailbox address, 2: name of the service. */
					__( 'Make a new password for the mailbox %1$s of %2$s. The old password stops working at once, so every mail program and phone that uses it must be changed. The new password is not shown here: it can be shown once afterwards, with the customer\'s yes again.', 'beltoft-services' ),
					$email,
					Irreversible::name( $r )
				),
				'state' => $r->id() . '|reset_mailbox_password|' . $email,
			);
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

	// -- Snapshots, backups ----------------------------------------------------------------------

	/**
	 * Delete or roll back a snapshot.
	 *
	 * @param Resource $r        Server.
	 * @param string   $name     delete_snapshot or rollback_snapshot.
	 * @param string   $snapshot Snapshot id.
	 * @param string   $label    The server's name.
	 * @return array{text: string, state: string}|\WP_Error
	 */
	private static function summary_snapshot( Resource $r, string $name, string $snapshot, string $label ) {
		$rollback = 'rollback_snapshot' === $name;
		$action   = $rollback ? 'snapshot_rollback' : 'snapshot_delete';
		$entry    = Snapshots::entry( $r, $snapshot );
		$known    = null !== $entry;
		$title    = $known && '' !== (string) ( $entry['label'] ?? '' ) ? (string) $entry['label'] : $snapshot;
		$taken    = $known && (int) ( $entry['taken_at'] ?? 0 ) > 0 ? wp_date( (string) get_option( 'date_format' ) . ' ' . (string) get_option( 'time_format' ), (int) $entry['taken_at'] ) : '';
		$args     = $rollback ? array( 'id' => $snapshot, 'confirm' => Snapshots::CONFIRM_ROLLBACK ) : array( 'id' => $snapshot );

		if ( $rollback ) {
			$text = '' !== $taken
				/* translators: 1: snapshot name, 2: server name, 3: date and time the snapshot was taken. */
				? sprintf( __( 'Roll back server %2$s to the snapshot "%1$s", taken %3$s. Everything that changed on the server after that snapshot is lost: files, installed software and settings. The server is stopped while this runs and started again afterwards, so it is down for a few minutes.', 'beltoft-services' ), $title, $label, $taken )
				/* translators: 1: snapshot name, 2: server name. */
				: sprintf( __( 'Roll back server %2$s to the snapshot "%1$s". Everything that changed on the server after that snapshot is lost: files, installed software and settings. The server is stopped while this runs and started again afterwards, so it is down for a few minutes.', 'beltoft-services' ), $title, $label );
		} else {
			/* translators: 1: snapshot name, 2: server name. */
			$text = sprintf( __( 'Delete the snapshot "%1$s" of server %2$s. The snapshot is removed for good and cannot be brought back. The server itself is not changed.', 'beltoft-services' ), $title, $label );
		}

		return self::checked( $r, $action, $args, $text, $snapshot . '|' . ( $known ? (string) ( $entry['state'] ?? 'ok' ) : '' ) );
	}

	/**
	 * Restore a server or a database from a backup.
	 *
	 * @param Resource $r      Service.
	 * @param string   $volid  Backup id.
	 * @param string   $label  The service's name.
	 * @return array{text: string, state: string}|\WP_Error
	 */
	private static function summary_restore( Resource $r, string $volid, string $label ) {
		$when = '';

		foreach ( ProxmoxDriver::ID === (string) $r->get( 'driver' ) || 'proxmox-db' === (string) $r->get( 'driver' ) ? Backups::listing( $r )['list'] : array() as $backup ) {
			if ( (string) $backup['volid'] === $volid ) {
				$when = (string) $backup['when'];
			}
		}

		$database = 'proxmox-db' === (string) $r->get( 'driver' );

		if ( $database ) {
			$text = '' !== $when
				/* translators: 1: database name, 2: date and time of the backup. */
				? sprintf( __( 'Restore database %1$s from the backup of %2$s. This replaces all data in the database with the backup, and everything written after that backup is lost. The database is not available while it restores.', 'beltoft-services' ), $label, $when )
				/* translators: %s: database name. */
				: sprintf( __( 'Restore database %s from the chosen backup. This replaces all data in the database with the backup, and everything written after that backup is lost. The database is not available while it restores.', 'beltoft-services' ), $label );
		} else {
			$text = '' !== $when
				/* translators: 1: server name, 2: date and time of the backup. */
				? sprintf( __( 'Restore server %1$s from the backup of %2$s. This replaces everything on the server with the backup, and everything written after that backup is lost. The server is stopped for the restore and is down until it has finished.', 'beltoft-services' ), $label, $when )
				/* translators: %s: server name. */
				: sprintf( __( 'Restore server %s from the chosen backup. This replaces everything on the server with the backup, and everything written after that backup is lost. The server is stopped for the restore and is down until it has finished.', 'beltoft-services' ), $label );
		}

		return self::checked( $r, 'restore', array( 'volid' => $volid, 'confirm' => Actions::CONFIRM_RESTORE ), $text, $volid );
	}

	// -- Reinstall and the rest of the server ----------------------------------------------------

	/**
	 * What the reinstall form would post for these tool arguments.
	 *
	 * @param array<string, mixed> $args Tool arguments.
	 * @return array<string, string>
	 */
	private static function reinstall_args( array $args ): array {
		$map  = array( 'os' => 'os', 'app' => 'app_version', 'tier' => 'app_tier', 'domain_mode' => 'app_domain_mode', 'domain_zone' => 'app_zone', 'domain_sub' => 'app_sub', 'domain_own' => 'app_own' );
		$send = array();

		foreach ( $map as $from => $to ) {
			if ( isset( $args[ $from ] ) && '' !== (string) $args[ $from ] ) {
				$send[ $to ] = (string) $args[ $from ];
			}
		}

		return $send;
	}

	/**
	 * Does the reinstall pick an app that has a licence the server does not hold yet?
	 *
	 * @param Resource              $r    Server.
	 * @param array<string, string> $send Reinstall arguments.
	 */
	private static function needs_licence( Resource $r, array $send ): bool {
		$pick = AppReinstallPaid::pick( $send );

		return null !== $pick && ! AppReinstallPaid::licensed( $r, $pick['app'] );
	}

	/**
	 * Reinstall.
	 *
	 * @param Resource             $r     Server.
	 * @param array<string, mixed> $args  Tool arguments.
	 * @param string               $label The server's name.
	 * @return array{text: string, state: string}|\WP_Error
	 */
	private static function summary_reinstall( Resource $r, array $args, string $label ) {
		$send   = self::reinstall_args( $args );
		$record = VmRecords::for_resource( $r->id() );
		$os     = isset( $send['os'] ) && null !== $record ? (string) ( Clusters::templates( (string) $record['cluster'] )[ (int) $send['os'] ] ?? '' ) : '';
		$paid   = self::needs_licence( $r, $send );
		/* translators: %s: server name. */
		$text = sprintf( __( 'Erase server %s and install it again.', 'beltoft-services' ), $label );

		if ( '' !== $os ) {
			/* translators: %s: name of the operating system. */
			$text .= ' ' . sprintf( __( 'Operating system: %s.', 'beltoft-services' ), $os );
		}

		if ( isset( $send['app_version'] ) ) {
			/* translators: %s: the app choice: keep, none, a version or app/version. */
			$text .= ' ' . sprintf( __( 'App choice: %s.', 'beltoft-services' ), $send['app_version'] );
		}

		if ( isset( $send['app_domain_mode'] ) ) {
			$name = 'own' === $send['app_domain_mode'] ? (string) ( $send['app_own'] ?? '' ) : trim( ( isset( $send['app_sub'] ) ? $send['app_sub'] . '.' : '' ) . ( $send['app_zone'] ?? '' ), '.' );
			/* translators: 1: domain choice (none, keep, change or own), 2: the domain name, or a dash when there is none. */
			$text .= ' ' . sprintf( __( 'Domain for the app: %1$s, %2$s.', 'beltoft-services' ), $send['app_domain_mode'], '' !== $name ? $name : '-' );
		}

		$text .= ' ' . __( 'Everything on the server is lost: files, databases, installed software and settings. A new password is made, and the server is down while it is reinstalled. A server can be reinstalled once an hour.', 'beltoft-services' );

		if ( $paid ) {
			$text .= ' ' . __( 'This app has a licence that is paid for at checkout. It is not bought here: after the customer says yes, the answer says where to finish it, and nothing is erased until the licence is paid.', 'beltoft-services' );
		}

		// A licence that still has to be paid is checked by the route when it runs: the dashboard's check refuses it until then.
		if ( $paid ) {
			return array( 'text' => $text, 'state' => $r->id() . '|reinstall|' . $r->get( 'state' ) . '|' . (string) wp_json_encode( $send ) );
		}

		return self::checked( $r, 'reinstall', $send + array( 'confirm' => Actions::CONFIRM ), $text, (string) wp_json_encode( $send ) );
	}

	/**
	 * Reinstall, and a licence that is to be bought instead.
	 *
	 * @param array<string, mixed> $args Tool arguments.
	 * @return array<string, mixed>|\WP_Error
	 */
	private static function reinstall( array $args ) {
		$send = self::reinstall_args( $args ) + array( 'confirm' => Actions::CONFIRM );
		$done = Tools::route( 'POST', '/resources/' . (int) $args['id'] . '/actions/reinstall', array(), array( 'args' => $send ), (int) $args['id'], 'reinstall requested' );

		if ( $done instanceof \WP_Error ) {
			return $done;
		}

		// The checkout address the dashboard answers with carries a nonce made for a browser session; it cannot work
		// from here, so it is not passed on. The request is kept, and the customer chooses the app again on the page.
		if ( ! empty( $done['redirect'] ) ) {
			$r = Resource::find( (int) $args['id'] );

			return array(
				'requested'       => false,
				'licence_needed'  => true,
				'message'         => 'Nothing was erased. This app has a licence that is paid for at checkout, which cannot be done from here. The customer chooses the app again on the Reinstall tab of the server in the dashboard and pays the licence there; the server is reinstalled when it is paid.',
				'dashboard_page'  => $r ? Details::page( $r, 'vm', (array) $r->license(), 'reinstall' ) : '',
			);
		}

		return array( 'requested' => true, 'message' => 'Requested. It runs in the background. Use server_status to follow it.' );
	}

	/**
	 * The SSH key.
	 *
	 * @param Resource $r     Server.
	 * @param string   $key   Key as sent.
	 * @param string   $label The server's name.
	 * @return array{text: string, state: string}|\WP_Error
	 */
	private static function summary_key( Resource $r, string $key, string $label ) {
		$clean = SshKeyField::normalise( $key );

		if ( null === $clean ) {
			return new \WP_Error( 'beltoft_services_tool', SshKeyField::invalid_message() );
		}

		$parts = preg_split( '/[ \t]+/', $clean, 3 ) ?: array( '', '' );
		$raw   = preg_split( '/[ \t]+/', trim( $key ), 3 ) ?: array();
		$note  = isset( $raw[2] ) ? trim( sanitize_text_field( substr( (string) $raw[2], 0, 60 ) ) ) : '';
		$blob  = (string) base64_decode( (string) $parts[1], true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Reading a public key to show its fingerprint.
		$print = 'SHA256:' . rtrim( base64_encode( hash( 'sha256', $blob, true ) ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- The fingerprint of a public key.
		/* translators: 1: key type, for example ssh-ed25519, 2: the fingerprint, 3: the comment the key carries. */
		$shown = sprintf( __( '%1$s key, fingerprint %2$s, comment "%3$s"', 'beltoft-services' ), (string) $parts[0], $print, '' !== $note ? $note : '-' );

		return self::checked(
			$r,
			'replace_ssh_key',
			array( 'key' => $clean ),
			/* translators: 1: server name, 2: description of the key. */
			sprintf( __( 'Set a new SSH key on server %1$s: %2$s. The old key stops working, on a running server at once and otherwise at the next start. If the customer does not have the private key that belongs to the new one, they cannot log in over SSH.', 'beltoft-services' ), $label, $shown ),
			hash( 'sha256', $clean )
		);
	}

	/**
	 * What a Valkey database does when its memory is full.
	 *
	 * @param Resource $r      Database.
	 * @param string   $policy Policy.
	 * @param string   $label  The database's name.
	 * @return array{text: string, state: string}|\WP_Error
	 */
	private static function summary_policy( Resource $r, string $policy, string $label ) {
		$engine  = DbPolicy::engine( $r );
		$choices = null === $engine ? array() : $engine->eviction_choices();

		if ( ! isset( $choices[ $policy ] ) ) {
			return new \WP_Error( 'beltoft_services_tool', __( 'Choose one of the listed settings.', 'beltoft-services' ) );
		}

		$now     = DbPolicy::current( $r );
		$current = isset( $choices[ $now ] ) ? (string) $choices[ $now ][0] : $now;

		return array(
			'text'  => sprintf(
				/* translators: 1: database name, 2: the new setting, 3: what the new setting does, 4: the setting it has now. */
				__( 'Change what database %1$s does when its memory is full to "%2$s". %3$s It is "%4$s" now. This decides whether writes fail or data is dropped, and it applies at once to the running database.', 'beltoft-services' ),
				$label,
				(string) $choices[ $policy ][0],
				(string) $choices[ $policy ][1],
				$current
			),
			'state' => $r->id() . '|policy|' . $policy . '|' . $now,
		);
	}

	// -- Escalations of the everyday tools -------------------------------------------------------

	/**
	 * The firewall turned off, or rules that open many ports to anyone.
	 *
	 * @param array<string, mixed> $args Tool arguments.
	 * @return array{text: string, state: string}|\WP_Error
	 */
	public static function summary_firewall( array $args ) {
		$r = Tools::owned( (int) $args['id'], true );

		if ( $r instanceof \WP_Error ) {
			return $r;
		}

		$bad = self::not_offered( $r, 'fw_apply' );

		if ( null !== $bad ) {
			return $bad;
		}

		$label = Irreversible::name( $r );

		if ( false === $args['on'] ) {
			return array(
				'text'  => sprintf(
					/* translators: %s: server name. */
					__( 'Turn the firewall of server %s off. Every port of the server can then be reached from the whole internet, whatever the rules say. The rules are kept and can be turned on again.', 'beltoft-services' ),
					$label
				),
				'state' => $r->id() . '|fw_off|' . (string) wp_json_encode( $r->get( Firewall::ON ) ),
			);
		}

		$wide = Tools::open_ports( $args['rules'] )['ranges'];

		return array(
			'text'  => sprintf(
				/* translators: 1: server name, 2: the wide rules, for example tcp 1-65535. */
				__( 'Change the firewall of server %1$s so that these rules let anyone on the internet reach many ports: %2$s. Anything listening on those ports, including software the customer forgot, can then be reached from everywhere. All the rules in the call replace the rules now in force.', 'beltoft-services' ),
				$label,
				implode( ', ', $wide )
			),
			'state' => $r->id() . '|fw_wide|' . (string) wp_json_encode( $wide ),
		);
	}

	/**
	 * A database opened to everyone.
	 *
	 * @param array<string, mixed> $args Tool arguments.
	 * @return array{text: string, state: string}|\WP_Error
	 */
	public static function summary_world( array $args ) {
		$r = Tools::owned( (int) $args['id'], true );

		if ( $r instanceof \WP_Error ) {
			return $r;
		}

		if ( ! DbAccess::editable( $r ) ) {
			return new \WP_Error( 'beltoft_services_tool', __( 'The access list can be changed when the database is ready and has a public IP address.', 'beltoft-services' ) );
		}

		return array(
			'text'  => sprintf(
				/* translators: %s: database name. */
				__( 'Open database %s to everyone. Anyone on the internet can then try to log in to it, and attacks on the password are likely. It stays open until it is closed again (world_off), and the customer gets an e-mail about it. Prefer adding the addresses that need access.', 'beltoft-services' ),
				Irreversible::name( $r )
			),
			'state' => $r->id() . '|world|' . (string) wp_json_encode( $r->get( DbAccess::WORLD ) ),
		);
	}

	/**
	 * A domain moved to name servers that are not ours.
	 *
	 * @param array<string, mixed> $args Tool arguments.
	 * @return array{text: string, state: string}|\WP_Error
	 */
	public static function summary_nameservers( array $args ) {
		$r = Tools::owned( (int) $args['id'], true );

		if ( $r instanceof \WP_Error ) {
			return $r;
		}

		$bad = self::not_offered( $r, 'set_nameservers' );

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
}
