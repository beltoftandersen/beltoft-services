<?php
/**
 * The tools of Full access that cannot be undone or change where money goes, and the summaries of every call that needs
 * the customer's confirmation.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Mcp;

use BeltoftLicenses\Licenses;
use BeltoftServices\Account\Cards;
use BeltoftServices\Drivers\Registry;
use BeltoftServices\Product;
use BeltoftServices\Proxmox\Actions;
use BeltoftServices\Proxmox\AppReinstallPaid;
use BeltoftServices\Proxmox\Backups;
use BeltoftServices\Proxmox\Clusters;
use BeltoftServices\Proxmox\DbAccess;
use BeltoftServices\Proxmox\DbPolicy;
use BeltoftServices\Proxmox\Firewall;
use BeltoftServices\Proxmox\ServerName;
use BeltoftServices\Proxmox\Snapshots;
use BeltoftServices\Proxmox\SshKeyField;
use BeltoftServices\Proxmox\VmRecords;
use BeltoftServices\RateLimit;
use BeltoftServices\Resource;
use BeltoftServices\Settings;
use BeltoftServices\Usage\Rates;

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
	public const NAMES = array( 'delete_snapshot', 'rollback_snapshot', 'restore_backup', 'reinstall_server', 'replace_ssh_key', 'reset_firewall', 'reset_server_password', 'set_database_eviction_policy', 'cancel_subscription', 'remove_addon', 'set_default_payment_method' );

	/**
	 * Add-on kinds (the driver's kind) that remove_addon may cancel.
	 */
	private const ADDON_KINDS = array( 'disk', 'snapshot', 'applic' );

	/**
	 * The summary of a call that needs a confirmation, and what the summary depends on.
	 *
	 * @param string               $name    Tool.
	 * @param array<string, mixed> $args    Arguments without the confirm value, as validated.
	 * @param int                  $user_id Customer.
	 * @return array{text: string, state: string}|\WP_Error
	 */
	public static function summary( string $name, array $args, int $user_id ) {
		switch ( $name ) {
			case 'set_firewall':
				return self::summary_firewall( $args );

			case 'set_database_access':
				return self::summary_world( $args );

			case 'domain_settings':
				return self::summary_nameservers( $args );

			case 'cancel_subscription':
				return self::summary_cancel( $user_id, (int) $args['subscription_id'] );

			case 'remove_addon':
				return self::summary_addon( $user_id, (int) $args['id'] );

			case 'set_default_payment_method':
				return self::summary_card( $user_id, (int) $args['token_id'] );
		}

		$r = Tools::owned( (int) $args['id'], true );

		if ( $r instanceof \WP_Error ) {
			return $r;
		}

		$action = (string) array_search( $name, ActionMap::ACTIONS, true );
		$bad    = self::not_offered( $r, $action );

		if ( null !== $bad ) {
			return $bad;
		}

		$label = self::name( $r );

		switch ( $name ) {
			case 'delete_snapshot':
			case 'rollback_snapshot':
				return self::summary_snapshot( $r, $name, (string) $args['snapshot'], $label );

			case 'restore_backup':
				return self::summary_restore( $r, (string) $args['backup'], $label );

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
	 * Runs one of the tools. The confirmation was accepted before this.
	 *
	 * @param string               $name    Tool.
	 * @param array<string, mixed> $args    Arguments, checked against the schema.
	 * @param int                  $user_id Customer.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function run( string $name, array $args, int $user_id ) {
		switch ( $name ) {
			case 'cancel_subscription':
				return self::cancel( $user_id, (int) $args['subscription_id'], '' );

			case 'remove_addon':
				$r = self::addon( $user_id, (int) $args['id'] );

				return $r instanceof \WP_Error ? $r : self::cancel( $user_id, (int) ( $r->license()['subscription_id'] ?? 0 ), 'add-on removed' );

			case 'set_default_payment_method':
				return self::set_card( $user_id, (int) $args['token_id'] );

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

			case 'set_database_eviction_policy':
				return self::send( $args, DbPolicy::ACTION, array( 'policy' => (string) $args['policy'] ), 'memory-full policy change requested' );
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
		$name    = $license ? trim( wp_strip_all_tags( ServerName::email_label( $r, $license ) ) ) : '';

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

	/**
	 * Is the action not offered for the service now? The same list the dashboard draws its buttons from.
	 *
	 * @param Resource $r      Service.
	 * @param string   $action Action.
	 */
	private static function not_offered( Resource $r, string $action ): ?\WP_Error {
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
		if ( ! in_array( (string) $r->get( 'driver' ), array( SshKeyField::DRIVER, 'proxmox-db' ), true ) ) {
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

		foreach ( 'proxmox' === (string) $r->get( 'driver' ) || 'proxmox-db' === (string) $r->get( 'driver' ) ? Backups::listing( $r )['list'] : array() as $backup ) {
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
	private static function summary_firewall( array $args ) {
		$r = Tools::owned( (int) $args['id'], true );

		if ( $r instanceof \WP_Error ) {
			return $r;
		}

		$bad = self::not_offered( $r, 'fw_apply' );

		if ( null !== $bad ) {
			return $bad;
		}

		$label = self::name( $r );

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
	private static function summary_world( array $args ) {
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
				self::name( $r )
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
	private static function summary_nameservers( array $args ) {
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
			if ( 1 !== preg_match( '/^(?=.{4,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/D', $host ) ) {
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
				self::name( $r ),
				$hosts
			),
			'state' => $r->id() . '|ns|' . implode( ',', $list ),
		);
	}

	// -- Subscriptions and cards -----------------------------------------------------------------

	/**
	 * The services a subscription pays for, as the customer's own.
	 *
	 * @param \WC_Subscription $s       Subscription.
	 * @param int              $user_id Customer.
	 * @return Resource[]
	 */
	private static function services_of( \WC_Subscription $s, int $user_id ): array {
		$out = array();

		foreach ( Licenses::where( 'subscription_id', $s->get_id() ) as $license ) {
			$r = (int) ( $license['user_id'] ?? 0 ) === $user_id ? Resource::for_license( (int) $license['id'] ) : null;

			if ( $r && 'deleted' !== $r->get( 'state' ) ) {
				$out[] = $r;
			}
		}

		return $out;
	}

	/**
	 * The price, period and VAT of a subscription for the customer.
	 *
	 * @param \WC_Subscription $s Subscription.
	 */
	private static function price_line( \WC_Subscription $s ): string {
		$total    = (float) $s->get_total();
		$currency = (string) $s->get_currency();
		$every    = (int) $s->get_billing_interval();
		// WooCommerce Subscriptions words it with the number when it is more than one ("2 months") and without when it is one ("month").
		$unit     = function_exists( 'wcs_get_subscription_period_strings' ) ? (string) wcs_get_subscription_period_strings( $every, (string) $s->get_billing_period() ) : $every . ' ' . (string) $s->get_billing_period();
		/* translators: %s: period such as month or 2 months. */
		$period   = sprintf( __( 'every %s', 'beltoft-services' ), $unit );

		if ( $total <= 0 ) {
			return __( 'It is free.', 'beltoft-services' );
		}

		$tax = (float) $s->get_total_tax();

		return sprintf(
			/* translators: 1: price, 2: period, 3: VAT note. */
			__( 'It costs %1$s %2$s, %3$s.', 'beltoft-services' ),
			Rates::money( $total, 2, $currency ),
			$period,
			$tax > 0
				/* translators: %s: VAT amount. */
				? sprintf( __( 'VAT included (%s)', 'beltoft-services' ), Rates::money( $tax, 2, $currency ) )
				: __( 'no VAT is charged', 'beltoft-services' )
		);
	}

	/**
	 * Can this subscription be cancelled through a connected app? The same oracle as the dashboard's buttons, and never
	 * the registration of a domain.
	 *
	 * @param int $user_id Customer.
	 * @param int $id      Subscription id.
	 * @return array{0: \WC_Subscription, 1: Resource[]}|\WP_Error
	 */
	private static function cancellable( int $user_id, int $id ) {
		$s = Acts::subscription( $user_id, $id );

		if ( $s instanceof \WP_Error ) {
			return $s;
		}

		$services = self::services_of( $s, $user_id );

		foreach ( $services as $r ) {
			if ( 'domain' === (string) $r->get( 'driver' ) ) {
				return new \WP_Error( 'beltoft_services_tool', __( 'A registered domain is not cancelled here. Turn automatic renewal off instead (set_auto_renew), or the customer contacts support.', 'beltoft-services' ) );
			}
		}

		if ( ! isset( wcs_get_all_user_actions_for_subscription( $s, $user_id )['cancel'] ) || ! $s->can_be_updated_to( 'cancelled' ) ) {
			return new \WP_Error( 'beltoft_services_tool', __( 'The dashboard does not offer cancelling for this subscription.', 'beltoft-services' ) );
		}

		return array( $s, $services );
	}

	/**
	 * What happens when a subscription is cancelled, from its dates.
	 *
	 * @param \WC_Subscription $s Subscription.
	 * @return string The end date in the site's format, or '' when it ends at once.
	 */
	private static function ends_on( \WC_Subscription $s ): string {
		$end = $s->has_status( 'active' ) ? (string) $s->calculate_date( 'end_of_prepaid_term' ) : '';
		$at  = '' !== $end && '0' !== $end ? strtotime( $end . ' UTC' ) : false;

		return $at && $at > time() ? wp_date( (string) get_option( 'date_format' ), $at ) : '';
	}

	/**
	 * Cancel a subscription.
	 *
	 * @param int $user_id Customer.
	 * @param int $id      Subscription id.
	 * @return array{text: string, state: string}|\WP_Error
	 */
	private static function summary_cancel( int $user_id, int $id ) {
		$found = self::cancellable( $user_id, $id );

		if ( $found instanceof \WP_Error ) {
			return $found;
		}

		list( $s, $services ) = $found;

		return self::cancel_text( $s, $services );
	}

	/**
	 * The text of a cancellation.
	 *
	 * @param \WC_Subscription $s        Subscription.
	 * @param Resource[]       $services What it pays for.
	 * @return array{text: string, state: string}
	 */
	private static function cancel_text( \WC_Subscription $s, array $services ): array {
		$names = array_map( array( __CLASS__, 'name' ), $services );
		$end   = self::ends_on( $s );
		$text  = sprintf(
			/* translators: 1: names of the services, 2: price sentence. */
			__( 'Cancel the subscription of %1$s. %2$s', 'beltoft-services' ),
			$names ? implode( ', ', $names ) : __( 'this subscription', 'beltoft-services' ),
			self::price_line( $s )
		);

		$text .= ' ' . ( '' !== $end
			/* translators: %s: date. */
			? sprintf( __( 'It is paid until %s: the service keeps working until then and nothing is charged again after that.', 'beltoft-services' ), $end )
			: __( 'It ends now and nothing is charged again.', 'beltoft-services' ) );

		$days  = 0;
		$kinds = array();

		foreach ( $services as $r ) {
			$days    = max( $days, Product::retention_days( (int) ( $r->license()['product_id'] ?? 0 ) ) );
			$driver  = Registry::get( (string) $r->get( 'driver' ) );
			$kinds[] = $driver ? $driver->kind() : '';
		}

		if ( in_array( 'vm', $kinds, true ) || in_array( 'db', $kinds, true ) ) {
			$text .= ' ' . ( $days > 0 && 'on' === Settings::get( 'deletion_mode' )
				/* translators: %d: number of days. */
				? sprintf( __( 'When it has ended, the server or database stops, and its data is deleted %d days later. It cannot be brought back after that.', 'beltoft-services' ), $days )
				: __( 'When it has ended, the server or database stops. Its data is not kept for you after it is removed.', 'beltoft-services' ) );
		}

		if ( in_array( 'disk', $kinds, true ) ) {
			$text .= ' ' . __( 'The extra disk and its data are removed, and snapshots that include it are deleted too.', 'beltoft-services' );
		}

		if ( in_array( 'snapshot', $kinds, true ) ) {
			$text .= ' ' . __( 'The snapshot slots are removed, and snapshots that no longer fit in the remaining slots are deleted.', 'beltoft-services' );
		}

		if ( in_array( 'applic', $kinds, true ) ) {
			$text .= ' ' . __( 'The app licence ends, and the app on the server may stop working without it.', 'beltoft-services' );
		}

		$ids = array_map( static fn( $r ) => $r->id(), $services );
		sort( $ids );

		return array( 'text' => $text, 'state' => $s->get_id() . '|' . $s->get_status() . '|' . $end . '|' . implode( ',', $ids ) );
	}

	/**
	 * An add-on of the customer: a disk, snapshot slots or an app licence.
	 *
	 * @param int $user_id Customer.
	 * @param int $id      Service id.
	 * @return Resource|\WP_Error
	 */
	private static function addon( int $user_id, int $id ) {
		$r = Tools::owned( $id );

		if ( $r instanceof \WP_Error ) {
			return $r;
		}

		$driver = Registry::get( (string) $r->get( 'driver' ) );

		if ( ! $driver || ! in_array( $driver->kind(), self::ADDON_KINDS, true ) ) {
			return new \WP_Error( 'beltoft_services_tool', __( 'This is not an add-on. Use cancel_subscription to cancel a service.', 'beltoft-services' ) );
		}

		return $user_id > 0 ? $r : new \WP_Error( 'beltoft_services_forbidden', __( 'This service is not available.', 'beltoft-services' ) );
	}

	/**
	 * Remove an add-on.
	 *
	 * @param int $user_id Customer.
	 * @param int $id      Service id.
	 * @return array{text: string, state: string}|\WP_Error
	 */
	private static function summary_addon( int $user_id, int $id ) {
		$r = self::addon( $user_id, $id );

		if ( $r instanceof \WP_Error ) {
			return $r;
		}

		$found = self::cancellable( $user_id, (int) ( $r->license()['subscription_id'] ?? 0 ) );

		return $found instanceof \WP_Error ? $found : self::cancel_text( $found[0], $found[1] );
	}

	/**
	 * Cancels a subscription after the confirmation, as the dashboard's Cancel button does.
	 *
	 * @param int    $user_id Customer.
	 * @param int    $id      Subscription id.
	 * @param string $what    Words for the trail, or '' for "subscription cancelled".
	 * @return array<string, mixed>|\WP_Error
	 */
	private static function cancel( int $user_id, int $id, string $what ) {
		$found = self::cancellable( $user_id, $id );

		if ( $found instanceof \WP_Error ) {
			return $found;
		}

		list( $s ) = $found;

		$said = Notices::capture( static fn() => \WCS_User_Change_Status_Handler::change_users_subscription( $s, 'cancelled' ) );
		$now  = wcs_get_subscription( $id );
		$ok   = $now instanceof \WC_Subscription && $now->has_status( array( 'cancelled', 'pending-cancel' ) );

		if ( ! $ok ) {
			return new \WP_Error( 'beltoft_services_tool', implode( ' ', $said['errors'] ) ?: __( 'The subscription could not be cancelled.', 'beltoft-services' ) );
		}

		Acts::log_subscription( $now, ( '' !== $what ? $what : 'subscription cancelled' ) . '' );

		$end = $now->has_status( 'pending-cancel' ) ? (string) $now->get_date( 'end', 'site' ) : '';
		$at  = '' !== $end && '0' !== $end ? strtotime( $end ) : false;

		return array(
			'subscription_id' => $id,
			'status'          => (string) $now->get_status(),
			'ends'            => $at ? wp_date( 'Y-m-d', $at ) : '',
			'message'         => $now->has_status( 'pending-cancel' ) ? 'Cancelled. It keeps working until the end of the period that is paid.' : 'Cancelled. It ended now.',
		);
	}

	/**
	 * Make a saved card the default.
	 *
	 * @param int $user_id  Customer.
	 * @param int $token_id Payment method id.
	 * @return \WC_Payment_Token|\WP_Error
	 */
	private static function card( int $user_id, int $token_id ) {
		$token = class_exists( '\WC_Payment_Tokens' ) ? \WC_Payment_Tokens::get( $token_id ) : null;

		if ( ! $token instanceof \WC_Payment_Token || $user_id <= 0 || (int) $token->get_user_id() !== $user_id ) {
			return new \WP_Error( 'beltoft_services_forbidden', __( 'This payment method is not available.', 'beltoft-services' ) );
		}

		if ( $token->is_default() ) {
			return new \WP_Error( 'beltoft_services_tool', __( 'This is the default payment method already.', 'beltoft-services' ) );
		}

		if ( Cards::is_card( $token ) && method_exists( $token, 'get_expiry_year' ) && (int) $token->get_expiry_year() * 100 + (int) $token->get_expiry_month() < (int) gmdate( 'Ym' ) ) {
			return new \WP_Error( 'beltoft_services_tool', __( 'This card has expired. Choose another.', 'beltoft-services' ) );
		}

		return $token;
	}

	/**
	 * The summary of a new default card.
	 *
	 * @param int $user_id  Customer.
	 * @param int $token_id Payment method id.
	 * @return array{text: string, state: string}|\WP_Error
	 */
	private static function summary_card( int $user_id, int $token_id ) {
		$token = self::card( $user_id, $token_id );

		if ( $token instanceof \WP_Error ) {
			return $token;
		}

		$old = \WC_Payment_Tokens::get_customer_default_token( $user_id );

		return array(
			'text'  => sprintf(
				/* translators: 1: the new card, for example Visa ending 4242, 2: the card that is the default now. */
				__( 'Make %1$s the default payment method. Renewals that use the default payment method, and payments made without choosing a method, are charged to it from now on. The default now is: %2$s. Subscriptions that have a payment method of their own keep it.', 'beltoft-services' ),
				Cards::is_card( $token ) ? Cards::label( $token ) : (string) $token->get_type(),
				$old instanceof \WC_Payment_Token ? ( Cards::is_card( $old ) ? Cards::label( $old ) : (string) $old->get_type() ) : __( 'none', 'beltoft-services' )
			),
			'state' => $token_id . '|' . ( $old instanceof \WC_Payment_Token ? $old->get_id() : 0 ),
		);
	}

	/**
	 * Sets the default card.
	 *
	 * @param int $user_id  Customer.
	 * @param int $token_id Payment method id.
	 * @return array<string, mixed>|\WP_Error
	 */
	private static function set_card( int $user_id, int $token_id ) {
		$token = self::card( $user_id, $token_id );

		if ( $token instanceof \WP_Error ) {
			return $token;
		}

		\WC_Payment_Tokens::set_users_default( $user_id, $token_id );

		Audit::add( $user_id, 'default payment method set to ' . ( Cards::is_card( $token ) ? Cards::label( $token ) : (string) $token->get_type() ) . '' );

		return array( 'token_id' => $token_id, 'default' => true );
	}
}
