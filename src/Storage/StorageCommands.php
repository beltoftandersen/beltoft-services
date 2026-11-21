<?php
/**
 * Ready-made commands for a storage box, with the customer's values filled in.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Storage;

defined( 'ABSPATH' ) || exit;

/**
 * Plain text commands for rsync, borg, restic and rclone. The password is never in a command: the tools ask for it, or use
 * an SSH key. Host and user are checked as plain names first, so nothing odd is ever printed into a shell line.
 */
final class StorageCommands {

	/**
	 * The commands: tool label => lines. Empty when the host or user is not a plain name.
	 *
	 * @param string $host Host name.
	 * @param string $user User name.
	 * @return array<string, string[]>
	 */
	public static function for( string $host, string $user ): array {
		if ( 1 !== preg_match( '/^[a-z0-9][a-z0-9.-]{0,200}$/iD', $host ) || 1 !== preg_match( '/^[a-z0-9_-]{1,40}$/iD', $user ) ) {
			return array();
		}

		$ssh = Boxes::PORT_SSH;
		$sftp = Boxes::PORT_SFTP;

		$commands = array(
			'rsync'  => array(
				sprintf( "rsync -az -e 'ssh -p %d' /path/to/data/ %s@%s:backups/", $ssh, $user, $host ),
			),
			'borg'   => array(
				sprintf( 'borg init --encryption=repokey-blake2 ssh://%s@%s:%d/./borg-repo', $user, $host, $ssh ),
				sprintf( 'borg create ssh://%s@%s:%d/./borg-repo::{hostname}-{now} /path/to/data', $user, $host, $ssh ),
			),
			'restic' => array(
				sprintf( 'restic -o sftp.args="-p %d" -r sftp:%s@%s:restic-repo init', $sftp, $user, $host ),
				sprintf( 'restic -o sftp.args="-p %d" -r sftp:%s@%s:restic-repo backup /path/to/data', $sftp, $user, $host ),
			),
			'rclone' => array(
				sprintf( 'rclone config create mybackup sftp host=%s user=%s port=%d', $host, $user, $sftp ),
				'rclone sync /path/to/data mybackup:backups',
			),
		);

		return $commands;
	}
}
