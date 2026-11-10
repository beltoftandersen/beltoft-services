<?php
/**
 * rename, password reset and SSH key replacement of a Proxmox VM.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Proxmox\Vm;

use BeltoftServices\Alerts;
use BeltoftServices\Drivers\StepResult;
use BeltoftServices\Proxmox\Backup\Backups;
use BeltoftServices\Proxmox\Pve\Prepares;
use BeltoftServices\Proxmox\Snapshot\Snapshots;
use BeltoftServices\Proxmox\Pve\Steps;
use BeltoftServices\Resource;

defined( 'ABSPATH' ) || exit;

/**
 * Rename, reset_password and replace_ssh_key. Built by Actions::run() after the VM is verified, and run under the resource lock.
 */
final class AccessActions {

	use Steps;
	use Prepares;

	/**
	 * Cluster settings (token secret decrypted), as Actions prepared them.
	 *
	 * @var array<string, mixed>
	 */
	private $cluster = array();

	/**
	 * @param Resource $r     Resource.
	 * @param Actions  $owner The Actions run that prepared the client and polled the stored task.
	 */
	public function __construct( Resource $r, Actions $owner ) {
		$this->r = $r;
		$state = $owner->state();

		$this->client    = $state['client'];
		$this->cluster   = $state['cluster'];
		$this->secrets   = $state['secrets'];
		$this->wait      = $state['wait'];
		$this->finished  = $state['finished'];
	}

	/**
	 * Renames the VM: the name is stored first (a reinstall then clones with it too), then written with the
	 * digest of the config just read, in one PUT. Proxmox cloud-init sets the hostname from the VM name when
	 * it next boots, so the customer is told to reboot; the server is never rebooted here.
	 *
	 * @param array<string, mixed> $record VM record.
	 * @param array<string, mixed> $v      Verified VM.
	 * @param string               $name   The name as sent (checked again here).
	 */
	public function rename( array $record, array $v, string $name ): StepResult {
		$clean = ServerName::normalise( $name );

		if ( null === $clean || '' === $clean ) {
			return StepResult::done( array(), 'Rename refused: the name is not valid' );
		}

		// Another operation holds the VM or the server: wait for it, no error, no counted attempt.
		$busy = $this->rename_busy( $v );

		if ( null !== $busy ) {
			return $busy;
		}

		if ( ServerName::of( $this->r ) === $clean && ( $v['config']['name'] ?? '' ) === $clean ) {
			return StepResult::done( array(), 'Customer renamed the server (already named so)' );
		}

		// The remote name first: the stored name changes only once Proxmox has taken it, so a refused or failed
		// write never leaves the card and a later reinstall with a name the VM does not have.
		$failed = $this->put_name( Actions::base( $record, $v ), $clean, (array) $v['config'] );

		if ( null !== $failed ) {
			return $failed;
		}

		if ( ! $this->r->set( array( ServerName::META => $clean ) + ServerName::pending_meta( $clean ) ) ) {
			return StepResult::retry( 0, 'rename: the result could not be stored' );
		}

		return StepResult::done( array(), 'Customer renamed the server; the hostname changes at the next boot' );
	}

	/**
	 * Is the VM or the server busy with something that a name change must not cross? A lock on the VM (a backup,
	 * a snapshot, a migration), a restore, a reinstall, a snapshot operation or rollback in progress.
	 *
	 * @param array<string, mixed> $v Verified VM.
	 * @return StepResult|null A free wait, or null when nothing is going on.
	 */
	private function rename_busy( array $v ): ?StepResult {
		if ( '' !== (string) ( $v['lock'] ?? '' ) ) {
			return $this->waiting( 'rename: the server is locked (' . sanitize_key( (string) $v['lock'] ) . ')', 120 );
		}

		$running = ( new Backups( $this->r ) )->collect_running();

		if ( null !== $running ) {
			return $this->waiting( 'rename: ' . $running->error(), 120 );
		}

		foreach ( array( 'restore', 'wipe', Snapshots::OP, Snapshots::ROLLBACK ) as $key ) {
			if ( $this->r->get( $key ) ) {
				return $this->waiting( 'rename: another operation is in progress', 120 );
			}
		}

		return null;
	}

	/**
	 * A new password: stored first, then sent as `cipassword` alone and, while the VM runs with
	 * the guest agent, to the agent. Nothing else of the cloud-init config is written.
	 *
	 * @param array<string, mixed> $record VM record.
	 * @param array<string, mixed> $v      Verified VM.
	 */
	public function reset_password( array $record, array $v ): StepResult {
		// A retry of a reset in progress is not a new reset; a new one is counted before anything is generated.
		if ( ! $this->r->get( Access::META_RESET ) && ! $this->r->set( array( 'reset_times' => array_merge( Actions::recent( (array) $this->r->get( 'reset_times' ), HOUR_IN_SECONDS ), array( time() ) ) ) ) ) {
			return StepResult::retry( 0, 'reset_password: the request could not be stored' );
		}

		try {
			$password = Access::reset_password( $this->r );
		} catch ( \RuntimeException $e ) {
			unset( $e );

			return StepResult::retry( 0, 'reset_password: the new password could not be stored' );
		}

		$this->secrets[] = $password;
		$base            = Actions::base( $record, $v );

		$answer = $this->client->request( 'PUT', $base . '/config', array( 'cipassword' => $password ) );

		if ( is_wp_error( $answer ) ) {
			$result = $this->classify( $answer, 'reset_password' );

			// Refused for good: the customer keeps the password they had not read yet.
			if ( $result->is_failed() ) {
				Access::abort_reset( $this->r );
			}

			return $result;
		}

		$agent = 'running' === $v['status'] && Provision::agent_enabled( (string) ( $v['config']['agent'] ?? '' ) );

		if ( $agent ) {
			$set = $this->client->request(
				'POST',
				$base . '/agent/set-user-password',
				array(
					'username' => Provision::LOGIN_USER,
					'password' => $password,
				)
			);

			if ( is_wp_error( $set ) ) {
				return StepResult::retry( 0, 'reset_password: the guest agent did not take the password: ' . $this->text( $set ) );
			}
		}

		if ( ! Access::finish_reset( $this->r ) ) {
			return StepResult::retry( 0, 'reset_password: the result could not be stored' );
		}

		return StepResult::done( array(), $agent ? 'Customer reset the server password' : 'Customer reset the server password; it applies when the server next runs cloud-init' );
	}

	/**
	 * Adds or replaces the SSH key. Stored first (a reinstall then uses it too), then written.
	 *
	 * @param array<string, mixed> $record VM record.
	 * @param array<string, mixed> $v      Verified VM.
	 * @param string               $key    The key as sent (checked again here).
	 */
	public function replace_ssh_key( array $record, array $v, string $key ): StepResult {
		$clean = SshKeyField::normalise( $key );

		if ( null === $clean ) {
			return StepResult::done( array(), 'SSH key change refused: the key is not valid' );
		}

		if ( ! $this->r->set( array( 'ssh_key' => $clean ) ) ) {
			return StepResult::retry( 0, 'replace_ssh_key: the key could not be stored' );
		}

		$base = Actions::base( $record, $v );

		// The raw key: the client URL-encodes the sshkeys value itself, once. This is what a
		// reinstall or a regenerated cloud-init drive uses from now on.
		$answer = $this->client->request( 'PUT', $base . '/config', array( 'sshkeys' => $clean . "\n" ) );

		if ( is_wp_error( $answer ) ) {
			return $this->classify( $answer, 'replace_ssh_key' );
		}

		// Cloud-init only appends keys, and only at the next boot. A running VM with the guest
		// agent gets the file replaced now, so the new key works at once and the old one stops.
		if ( 'running' !== $v['status'] || ! Provision::agent_enabled( (string) ( $v['config']['agent'] ?? '' ) ) ) {
			return StepResult::done( array(), 'Customer changed the SSH key; it applies when the server next starts, and the old key keeps working until the server is reinstalled' );
		}

		$written = $this->client->request(
			'POST',
			$base . '/agent/file-write',
			array(
				'file'    => Actions::AUTHORIZED_KEYS,
				'content' => $clean . "\n",
			)
		);

		if ( is_wp_error( $written ) ) {
			$data   = $written->get_error_data();
			$status = is_array( $data ) ? (int) ( $data['status'] ?? 0 ) : 0;

			// No right to write through the agent: retrying cannot help, the owner must grant it.
			if ( 403 === $status ) {
				$text = 'Replace SSH key: the Proxmox token may not write files through the guest agent, so the new key is only in the cloud-init settings (it is added at the next boot and the old key keeps working). Grant the token the privilege VM.GuestAgent.FileWrite (or VM.GuestAgent.Unrestricted) on the pool of the customer VMs.';
				Alerts::admin( $this->r, $text );

				return StepResult::done( array(), 'Customer changed the SSH key; ' . $text );
			}

			return StepResult::retry( 0, 'replace_ssh_key: the guest agent did not take the key: ' . $this->text( $written ) );
		}

		return StepResult::done( array(), 'Customer changed the SSH key' );
	}
}
