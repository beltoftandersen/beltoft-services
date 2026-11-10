<?php
/**
 * The rules of customer snapshots: who gets them, how many, what may be asked.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Proxmox\Snapshot;

use BeltoftServices\Jobs;
use BeltoftServices\Proxmox\Vm\Actions;
use BeltoftServices\Proxmox\Disk\DiskMeta;
use BeltoftServices\Proxmox\Vm\ProxmoxDriver;
use BeltoftServices\Proxmox\Vm\VmRecords;
use BeltoftServices\Resource;
use BeltoftServices\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Read-only rules over the snapshot meta of a server resource. Nothing here calls Proxmox.
 */
final class Policy {

	/**
	 * Is the switch in the settings on?
	 */
	public static function switched_on(): bool {
		return (bool) Settings::get( 'snapshots_enabled' );
	}

	/**
	 * Does this server get snapshots? The switch is on, it is not an adopted machine, and every disk it has is
	 * qcow2 (an old server on raw volumes gets none). No Proxmox call: the boot volume's format was recorded when
	 * the VM was built, the extra disks' formats are in their volume names.
	 *
	 * @param Resource $r Resource.
	 */
	public static function enabled( Resource $r ): bool {
		return self::eligible( $r ) && self::idle( $r );
	}

	/**
	 * Could this server get snapshots, leaving aside what it is doing now? The switch is on, it is not an adopted
	 * machine, and every disk is qcow2. What the paid slots are sold on.
	 *
	 * @param Resource $r Resource.
	 */
	public static function eligible( Resource $r ): bool {
		if ( ! self::switched_on() || true === $r->get( 'linked' ) || ProxmoxDriver::ID !== $r->get( 'driver' ) || null === VmRecords::for_resource( $r->id() ) ) {
			return false;
		}

		if ( 'qcow2' !== $r->get( 'boot_format' ) ) {
			return false;
		}

		foreach ( DiskMeta::for_server( $r ) as $disk ) {
			$name = (string) $disk->get( 'volname' );

			if ( '' !== $name && 'qcow2' !== self::format_of( $name ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Is nothing else changing the server? It is ready and meant to be, with no reconciler operation or plan
	 * change pending, no restore marker and no reinstall or wipe under way. A take, a delete or a rollback never
	 * starts over any of these.
	 *
	 * @param Resource $r Resource.
	 */
	public static function idle( Resource $r ): bool {
		return 'ready' === $r->get( 'state' )
			&& 'ready' === $r->get( 'desired' )
			&& '' === (string) $r->get( 'op' )
			&& ! $r->get( 'plan_dirty' )
			&& ! is_array( $r->get( 'restore' ) )
			&& ! $r->get( 'wipe' );
	}

	/**
	 * Whether the server has anything of snapshots in its meta (a list entry or a marker), whether or not the
	 * setting is on. What callers use to skip a Proxmox call when there is nothing to clean up.
	 *
	 * @param Resource $r Server resource.
	 */
	public static function tracked( Resource $r ): bool {
		return array() !== self::entries( $r ) || array() !== (array) $r->get( Snapshots::OP ) || is_array( $r->get( Snapshots::ROLLBACK ) );
	}

	/**
	 * The snapshots in the list, oldest first.
	 *
	 * @param Resource $r Resource.
	 * @return array<int, array<string, mixed>>
	 */
	public static function entries( Resource $r ): array {
		$out = array();

		foreach ( (array) $r->get( Snapshots::LIST ) as $entry ) {
			if ( is_array( $entry ) && isset( $entry['id'], $entry['snap'] ) ) {
				$out[] = $entry;
			}
		}

		return $out;
	}

	/**
	 * The entries that cover a volume.
	 *
	 * @param Resource $r     Resource.
	 * @param string   $volid Volume id.
	 * @return array<int, array<string, mixed>>
	 */
	public static function covering( Resource $r, string $volid ): array {
		return array_values(
			array_filter(
				self::entries( $r ),
				static fn( $e ) => in_array( $volid, (array) ( $e['covers'] ?? array() ), true )
			)
		);
	}

	/**
	 * What the reinstall form says about the snapshots (D8): they all go, with their number. Empty when there are
	 * none. The card (phase E) shows it; nothing else uses it.
	 *
	 * @param Resource $r Resource.
	 */
	public static function reinstall_warning( Resource $r ): string {
		$count = count( self::entries( $r ) );

		if ( 0 === $count ) {
			return '';
		}

		/* translators: %d: number of snapshots */
		return sprintf( _n( 'Reinstalling deletes %d snapshot of this server.', 'Reinstalling deletes all %d snapshots of this server.', $count, 'beltoft-services' ), $count );
	}

	/**
	 * What the restore form says about the snapshots (D9): a restore replaces the server, so its snapshots go.
	 * Empty when there are none.
	 *
	 * @param Resource $r Resource.
	 */
	public static function restore_warning( Resource $r ): string {
		return array() === self::entries( $r ) ? '' : __( 'Restoring a backup deletes the snapshots of this server.', 'beltoft-services' );
	}

	/**
	 * Is a take open (marker and entry both there) that has not outlived its age limit?
	 *
	 * @param Resource $r Resource.
	 */
	public static function take_open( Resource $r ): bool {
		$op = (array) $r->get( Snapshots::OP );

		return 'take' === ( $op['op'] ?? '' ) && null !== self::entry( $r, $op['id'] ?? null ) && ! self::stale( $op );
	}

	/**
	 * Has the operation been open longer than OP_MAX_AGE?
	 *
	 * @param array<string, mixed> $op Marker.
	 */
	public static function stale( array $op ): bool {
		return 'take' === ( $op['op'] ?? '' ) && (int) ( $op['at'] ?? 0 ) > 0 && time() - (int) $op['at'] > Snapshots::OP_MAX_AGE;
	}

	/**
	 * Slots bought on top of the free ones. The paid add-on answers through the filter; without it there are none.
	 *
	 * @param Resource $r Resource.
	 */
	public static function paid_slots( Resource $r ): int {
		return max( 0, (int) apply_filters( 'beltoft_services_snapshot_paid_slots', 0, $r ) );
	}

	/**
	 * Snapshots the server may hold: the free slots and the paid ones, never above the maximum.
	 *
	 * @param Resource $r Resource.
	 */
	public static function slots( Resource $r ): int {
		$free = max( 0, (int) Settings::get( 'snapshot_free_slots' ) );

		return min( max( 1, (int) Settings::get( 'snapshot_max_slots' ) ), $free + self::paid_slots( $r ) );
	}

	/**
	 * Snapshots that take a slot: every entry, also one that is being made or deleted.
	 *
	 * @param Resource $r Resource.
	 */
	public static function used( Resource $r ): int {
		return count( self::entries( $r ) );
	}

	/**
	 * Days a snapshot taken now is kept: the longer paid time when the server has a paid slot.
	 *
	 * @param Resource $r Resource.
	 */
	public static function days_for( Resource $r ): int {
		if ( self::paid_slots( $r ) > 0 ) {
			$own = SnapshotMeta::days_for( $r ); // A pack's own period wins over the setting.

			return max( 1, $own > 0 ? $own : (int) Settings::get( 'snapshot_paid_days' ) );
		}

		return max( 1, (int) Settings::get( 'snapshot_free_days' ) );
	}

	/**
	 * Is this id one of the server's snapshots?
	 *
	 * @param Resource $r  Resource.
	 * @param mixed    $id Id as sent.
	 */
	public static function owns( Resource $r, $id ): bool {
		return null !== self::entry( $r, $id );
	}

	/**
	 * One snapshot of the list, or null.
	 *
	 * @param Resource $r  Resource.
	 * @param mixed    $id Id as sent.
	 * @return array<string, mixed>|null
	 */
	public static function entry( Resource $r, $id ): ?array {
		if ( ! is_string( $id ) || 1 !== preg_match( '/^[a-f0-9]{8}$/D', $id ) ) {
			return null;
		}

		foreach ( self::entries( $r ) as $entry ) {
			if ( $entry['id'] === $id ) {
				return $entry;
			}
		}

		return null;
	}

	/**
	 * The snapshot actions offered now. A step of an operation in flight stays offered (the retry of an action
	 * that is not offered any more is dropped by Jobs::act()).
	 *
	 * @param Resource $r Resource.
	 * @return array<string, string> Action => label.
	 */
	public static function offered( Resource $r ): array {
		$out     = array();
		$entries = self::entries( $r );
		$op      = (array) $r->get( Snapshots::OP );
		$ready   = array_filter( $entries, static fn( $e ) => 'ok' === ( $e['state'] ?? 'ok' ) );
		$on      = self::enabled( $r );

		if ( ( $on && count( $entries ) < self::slots( $r ) ) || 'take' === ( $op['op'] ?? '' ) ) {
			$out['snapshot_take'] = __( 'Take a snapshot', 'beltoft-services' );
		}

		if ( $entries && true !== $r->get( 'linked' ) ) {
			$out['snapshot_delete'] = __( 'Delete a snapshot', 'beltoft-services' );
		}

		if ( ( $on && $ready ) || is_array( $r->get( Snapshots::ROLLBACK ) ) ) {
			$out['snapshot_rollback'] = __( 'Roll back to a snapshot', 'beltoft-services' );
		}

		return $out;
	}

	/**
	 * The label as it will be kept: letters, digits, space, dash, underscore and dot, one line, at most
	 * MAX_LABEL characters. Nothing usable left (or nothing sent) gives a label made of the date.
	 *
	 * @param array<string, mixed> $args Arguments.
	 */
	public static function label_from( array $args ): string {
		$label = isset( $args['label'] ) && is_string( $args['label'] ) ? $args['label'] : '';
		$label = trim( (string) preg_replace( '/\s+/', ' ', (string) preg_replace( '/[^A-Za-z0-9 _.\-]/', '', $label ) ) );
		$label = trim( substr( $label, 0, Snapshots::MAX_LABEL ) );

		return '' === $label ? 'Snapshot ' . gmdate( 'Y-m-d H:i' ) : $label;
	}

	/**
	 * Why an action with these arguments cannot be accepted, in words for the customer; null when it can. No
	 * Proxmox call. Used by Actions::check() (the route answers at once, and act() asks again).
	 *
	 * @param Resource             $r      Resource.
	 * @param string               $action Action.
	 * @param array<string, mixed> $args   Arguments.
	 */
	public static function check( Resource $r, string $action, array $args ): ?string {
		$op      = (array) $r->get( Snapshots::OP );
		$rolling = (array) $r->get( Snapshots::ROLLBACK );

		// A rollback that has begun is finished whatever happens to the switch or a disk meanwhile: the server is off.
		if ( 'snapshot_rollback' === $action && isset( $args['id'] ) && ( $rolling['id'] ?? '' ) === $args['id'] ) {
			return null;
		}

		// A take that is under way is resumed by the next take, whatever label it carries; the op owns the entry.
		if ( 'snapshot_take' === $action && 'take' === ( $op['op'] ?? '' ) && null !== self::entry( $r, $op['id'] ?? null ) ) {
			return null;
		}

		// Nothing else starts while a rollback or a take is open (a take past its age limit is closed by the next run).
		if ( $rolling || self::take_open( $r ) ) {
			return __( 'Another snapshot action is still running on this server. Try again in a few minutes.', 'beltoft-services' );
		}

		if ( 'snapshot_delete' === $action ) {
			$entry = self::entry( $r, $args['id'] ?? null );

			// An entry that is still being made is the take's; one that is being deleted may be asked again.
			return null !== $entry && in_array( $entry['state'] ?? 'ok', array( 'ok', 'deleting' ), true ) ? null : __( 'Choose one of your snapshots.', 'beltoft-services' );
		}

		if ( ! self::enabled( $r ) ) {
			return __( 'Snapshots are not available for this server.', 'beltoft-services' );
		}

		if ( 'snapshot_take' === $action ) {
			if ( self::used( $r ) >= self::slots( $r ) ) {
				return __( 'All your snapshot slots are in use. Delete a snapshot first.', 'beltoft-services' );
			}

			foreach ( self::entries( $r ) as $entry ) {
				if ( 0 === strcasecmp( (string) ( $entry['label'] ?? '' ), self::label_from( $args ) ) ) {
					return __( 'You already have a snapshot with that name.', 'beltoft-services' );
				}
			}

			return null;
		}

		$entry = self::entry( $r, $args['id'] ?? null );

		if ( null === $entry || 'ok' !== ( $entry['state'] ?? 'ok' ) ) {
			return __( 'Choose one of your snapshots.', 'beltoft-services' );
		}

		if ( Snapshots::CONFIRM_ROLLBACK !== ( $args['confirm'] ?? '' ) ) {
			/* translators: %s: the word to type, ROLLBACK. */
			return sprintf( __( 'Type %s to confirm. Rolling back replaces everything on the server with the snapshot.', 'beltoft-services' ), Snapshots::CONFIRM_ROLLBACK );
		}

		return Actions::disk_block( $r, 'rollback' );
	}

	/**
	 * The drives of a VM configuration that hold a disk; a cd-rom, the cloud-init drive (made again from the
	 * configuration) and an empty drive are left out. The snapshot covers all of them, a `backup=0` extra disk too.
	 *
	 * @param array<string, mixed> $config VM configuration.
	 * @return array<string, string> Drive => volume id.
	 */
	public static function disks( array $config ): array {
		$out = array();

		foreach ( $config as $key => $value ) {
			if ( 1 !== preg_match( '/^(?:scsi|virtio|sata|ide)\d+$|^(?:efidisk0|tpmstate0)$/D', (string) $key ) || ! is_string( $value ) || false !== strpos( ',' . $value . ',', ',media=cdrom,' ) || false !== strpos( explode( ',', $value )[0], 'cloudinit' ) || 'none' === $value ) {
				continue;
			}

			$out[ (string) $key ] = explode( ',', $value )[0];
		}

		return $out;
	}

	/**
	 * Free storage kept back for snapshots: the setting, or the reserve for extra disks when it is 0.
	 */
	public static function reserve_gb(): int {
		$own = (int) Settings::get( 'snapshot_reserve_gb' );

		return $own > 0 ? $own : max( 0, (int) Settings::get( 'disk_reserve_gb' ) );
	}

	/**
	 * The format of a volume, by its extension: qcow2, raw, or '' when it has neither.
	 *
	 * @param string $volid Volume id or name.
	 */
	public static function format_of( string $volid ): string {
		return 1 === preg_match( '/\.(raw|qcow2)$/', $volid, $m ) ? $m[1] : '';
	}
}
