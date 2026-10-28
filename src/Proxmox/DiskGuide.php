<?php
/**
 * The setup guide for an extra disk, by the family of the server's operating system.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Proxmox;

use BeltoftLicenses\Account\Endpoint;
use BeltoftServices\Resource;

defined( 'ABSPATH' ) || exit;

/**
 * An empty disk has to be formatted and mounted inside the server, which this plugin never does
 * (a wrong device would erase data). The guide gives the commands. The family comes from the label
 * of the server's operating system template (OsField); Linux is the default. No Proxmox call.
 *
 * guide() returns: family, warning, intro, steps (list of text, code), grow (list of text, code).
 */
final class DiskGuide {

	/**
	 * The id of the guide on the disk's card, for links to it.
	 *
	 * @param int $disk_id Resource id of the disk.
	 */
	public static function anchor( int $disk_id ): string {
		return 'beltoft-disk-guide-' . $disk_id;
	}

	/**
	 * The family of an operating system label: linux (also for an empty or unknown label) or windows.
	 *
	 * @param string $label Template label, such as "Ubuntu 26.04".
	 */
	public static function family( string $label ): string {
		$family = false !== stripos( $label, 'windows' ) ? 'windows' : 'linux';

		/**
		 * The family of an operating system label, which picks the disk guide.
		 *
		 * @param string $family linux or windows.
		 * @param string $label  The label.
		 */
		$family = (string) apply_filters( 'beltoft_services_disk_guide_family', $family, $label );

		return '' !== $family ? $family : 'linux';
	}

	/**
	 * The label of the operating system a server was built with, or ''.
	 *
	 * @param Resource|null $server The server resource.
	 */
	public static function os_label( ?Resource $server ): string {
		if ( ! $server ) {
			return '';
		}

		$record = VmRecords::for_resource( $server->id() );

		if ( null === $record ) {
			return '';
		}

		$plan   = (array) $server->get( 'plan' );
		$chosen = (int) $server->get( OsField::META );
		$chosen = $chosen > 0 ? $chosen : (int) ( $plan['template'] ?? 0 );
		$list   = OsField::options_for( (string) $record['cluster'] );

		return isset( $list[ $chosen ] ) ? (string) $list[ $chosen ] : '';
	}

	/**
	 * The guide for an operating system label.
	 *
	 * @param string $label Template label (may be empty).
	 * @return array{family: string, warning: string, intro: string, steps: array<int, array{text: string, code: string}>, grow: array<int, array{text: string, code: string}>}
	 */
	public static function guide( string $label = '' ): array {
		$family = self::family( $label );
		$warning = __( 'Format only the new, empty disk. Formatting a disk that holds data erases it.', 'beltoft-services' );

		if ( 'windows' === $family ) {
			$guide = array(
				'family'  => 'windows',
				'warning' => $warning,
				'intro'   => __( 'Run these in PowerShell as administrator. Replace NUMBER with the number of the new disk and LETTER with the drive letter you want.', 'beltoft-services' ),
				'steps'   => array(
					array( 'text' => __( 'Find the new disk. It shows as RAW, with no partitions.', 'beltoft-services' ), 'code' => 'Get-Disk' ),
					array( 'text' => __( 'Set it up.', 'beltoft-services' ), 'code' => "Initialize-Disk -Number NUMBER -PartitionStyle GPT\nNew-Partition -DiskNumber NUMBER -UseMaximumSize -DriveLetter LETTER\nFormat-Volume -DriveLetter LETTER -FileSystem NTFS" ),
				),
				'grow'    => array(
					array( 'text' => __( 'After Make larger, extend the volume.', 'beltoft-services' ), 'code' => 'Resize-Partition -DriveLetter LETTER -Size (Get-PartitionSupportedSize -DriveLetter LETTER).SizeMax' ),
				),
			);
		} else {
			$guide = array(
				'family'  => 'linux',
				'warning' => $warning,
				'intro'   => __( 'Run these in the server. Replace DEVICE with the name of the new disk, such as sdb.', 'beltoft-services' ),
				'steps'   => array(
					array( 'text' => __( 'Find the new disk. Device names vary, so check which one is empty.', 'beltoft-services' ), 'code' => 'lsblk -f' ),
					array( 'text' => __( 'Format it.', 'beltoft-services' ), 'code' => 'sudo mkfs.ext4 /dev/DEVICE' ),
					array( 'text' => __( 'Create a folder for it.', 'beltoft-services' ), 'code' => 'sudo mkdir -p /mnt/data' ),
					array( 'text' => __( 'Mount it.', 'beltoft-services' ), 'code' => 'sudo mount /dev/DEVICE /mnt/data' ),
					array( 'text' => __( 'Find its UUID.', 'beltoft-services' ), 'code' => 'sudo blkid' ),
					array( 'text' => __( 'To mount it after every restart, add this line to /etc/fstab with your UUID.', 'beltoft-services' ), 'code' => 'UUID=… /mnt/data ext4 defaults,nofail 0 2' ),
					array( 'text' => __( 'Test the line.', 'beltoft-services' ), 'code' => 'sudo mount -a' ),
				),
				'grow'    => array(
					array( 'text' => __( 'After Make larger, for ext4:', 'beltoft-services' ), 'code' => 'sudo resize2fs /dev/DEVICE' ),
					array( 'text' => __( 'For xfs:', 'beltoft-services' ), 'code' => 'sudo xfs_growfs /mnt/data' ),
				),
			);
		}

		/**
		 * The disk setup guide, by operating system family.
		 *
		 * @param array<string, mixed> $guide  The guide.
		 * @param string               $family linux or windows.
		 * @param string               $label  The operating system label.
		 */
		$filtered = apply_filters( 'beltoft_services_disk_guide', $guide, $family, $label );

		return is_array( $filtered ) ? $filtered : $guide;
	}

	/**
	 * Where the server's card is in My Account.
	 *
	 * @param Resource $disk The disk resource.
	 */
	public static function server_url( Resource $disk ): string {
		$server = Resource::for_license( (int) $disk->get( 'server_license' ) );

		return $server ? self::card_url( $server ) : '';
	}

	/**
	 * Where the guide is: on the disk's own card.
	 *
	 * @param Resource $disk The disk resource.
	 */
	public static function guide_url( Resource $disk ): string {
		return self::card_url( $disk, self::anchor( $disk->id() ) );
	}

	/**
	 * The My Account address of a service's card.
	 *
	 * @param Resource $r      Resource.
	 * @param string   $anchor Fragment, or '' for the card itself.
	 */
	public static function card_url( Resource $r, string $anchor = '' ): string {
		$license = $r->license();

		if ( ! $license || ! function_exists( 'wc_get_account_endpoint_url' ) ) {
			return '';
		}

		return Endpoint::url_for_license( $license ) . '#' . ( '' !== $anchor ? $anchor : 'beltoft-service-' . $r->id() );
	}
}
