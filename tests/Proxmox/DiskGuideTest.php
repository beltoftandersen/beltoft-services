<?php
/** The extra disk setup guide and the disk-ready email. @package BeltoftServices */

namespace BeltoftServices\Tests\Proxmox;

use BeltoftServices\Drivers\Registry;
use BeltoftServices\Emails\Emails;
use BeltoftServices\Proxmox\DiskGuide;
use BeltoftServices\Proxmox\DiskPanels;
use BeltoftServices\Resource;
use BeltoftServices\Tests\TestCase;

final class DiskGuideTest extends TestCase {

	/**
	 * @var array<int, array<string, mixed>>
	 */
	private array $mails = array();

	public function set_up(): void {
		parent::set_up();
		$this->mails = array();
		add_filter(
			'pre_wp_mail',
			function ( $pre, $atts ) {
				$this->mails[] = $atts;
				return true;
			},
			10,
			2
		);
		$this->fake_driver();
	}

	/**
	 * @return string[][]
	 */
	public static function linux_labels(): array {
		return array( array( 'Ubuntu 26.04' ), array( 'Debian 12' ), array( 'Rocky Linux 9' ), array( 'AlmaLinux 9' ), array( '' ), array( 'Something new' ) );
	}

	/**
	 * @dataProvider linux_labels
	 */
	public function test_a_linux_label_gets_the_linux_steps_with_the_nofail_line( string $label ): void {
		$guide = DiskGuide::guide( $label );
		$codes = implode( "\n", array_merge( array_column( $guide['steps'], 'code' ), array_column( $guide['grow'], 'code' ) ) );

		$this->assertSame( 'linux', $guide['family'] );
		$this->assertSame( 'lsblk -f', $guide['steps'][0]['code'], 'The device names come first.' );
		foreach ( array( 'sudo mkfs.ext4 /dev/DEVICE', 'sudo mkdir -p /mnt/data', 'sudo mount /dev/DEVICE /mnt/data', 'sudo blkid', 'UUID=YOUR-UUID /mnt/data ext4 defaults,nofail 0 2', 'sudo mount -a', 'sudo resize2fs /dev/DEVICE', 'sudo xfs_growfs /mnt/data' ) as $line ) {
			$this->assertStringContainsString( $line, $codes );
		}
		$this->assertLessThan( strpos( $codes, 'blkid' ), strpos( $codes, 'mkfs.ext4' ) );
		$this->assertLessThan( strpos( $codes, 'nofail' ), strpos( $codes, 'blkid' ) );
		$this->assertLessThan( strpos( $codes, 'mount -a' ), strpos( $codes, 'nofail' ) );
		$this->assertStringContainsString( 'Format only the new, empty disk', $guide['warning'] );
		$this->assertStringNotContainsString( "\u{2014}", wp_json_encode( $guide, JSON_UNESCAPED_UNICODE ) );
	}

	public function test_a_windows_label_gets_the_windows_steps_and_no_fstab(): void {
		$guide = DiskGuide::guide( 'Windows Server 2022' );
		$codes = implode( "\n", array_column( $guide['steps'], 'code' ) );

		$this->assertSame( 'windows', $guide['family'] );
		$this->assertStringContainsString( 'Get-Disk', $codes );
		$this->assertStringContainsString( 'Format-Volume', $codes );
		$this->assertStringNotContainsString( 'fstab', wp_json_encode( $guide ) );
		$this->assertStringContainsString( 'Format only the new, empty disk', $guide['warning'] );
	}

	public function test_the_guide_can_be_filtered(): void {
		add_filter(
			'beltoft_services_disk_guide',
			static function ( $guide, $family ) {
				$guide['intro'] = 'Custom ' . $family;
				return $guide;
			},
			10,
			2
		);
		add_filter( 'beltoft_services_disk_guide_family', static fn( $family, $label ) => 'Arch' === $label ? 'windows' : $family, 10, 2 );

		$this->assertSame( 'Custom linux', DiskGuide::guide( 'Debian 12' )['intro'] );
		$this->assertSame( 'windows', DiskGuide::guide( 'Arch' )['family'] );
	}

	private function disk(): Resource {
		$product = $this->service_product( 'fake', array(), array( 'slug' => 'dg-' . wp_generate_password( 6, false ) ) );
		$server  = Resource::create( $this->service_license( $product, array( 'fire' => false ) ), Registry::get( 'fake' ), array(), array( 'state' => 'ready' ) );
		$license = $this->service_license( $product, array( 'fire' => false, 'user_id' => $server->license()['user_id'], 'email' => 'customer@example.com' ) );
		$disk    = Resource::create( $license, Registry::get( 'fake' ), array(), array( 'state' => 'ready', 'size_gb' => 50, 'slot' => 'scsi1', 'server_license' => $server->license()['id'] ) );
		$disk->set( array( 'driver' => 'proxmox-disk' ) );

		return Resource::find( $disk->id() );
	}

	public function test_the_disk_card_has_the_guide_in_details_with_an_anchor(): void {
		$disk = $this->disk();

		ob_start();
		DiskPanels::account( $disk );
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( '<details class="beltoft-services-disk__guide" id="beltoft-disk-guide-' . $disk->id() . '">', $html );
		$this->assertStringContainsString( 'defaults,nofail 0 2', $html );
		$this->assertStringContainsString( 'lsblk -f', $html );
		$this->assertStringContainsString( 'Format only the new, empty disk', $html );
		$this->assertStringContainsString( 'sudo xfs_growfs /mnt/data', $html );
	}

	public function test_the_disk_ready_email_points_to_the_disk_in_html_and_plain(): void {
		$disk  = $this->disk();
		$email = Emails::get( 'beltoft_services_ready' );

		$email->email_type = 'html';
		$email->trigger( $disk );
		$html = (string) $this->mails[0]['message'];

		$this->assertStringContainsString( 'Find your disk in My account &gt; VPS &gt; your server &gt; Disks.', $html );
		$this->assertStringContainsString( esc_url( DiskGuide::server_url( $disk ) ), $html );
		$this->assertStringContainsString( esc_url( DiskGuide::guide_url( $disk ) ), $html );

		$this->mails       = array();
		$email->email_type = 'plain';
		$email->trigger( $disk );
		$plain = (string) $this->mails[0]['message'];
		$email->email_type = 'html';

		$this->assertStringContainsString( 'Find your disk in My account > VPS > your server > Disks.', $plain );
		$this->assertStringContainsString( DiskGuide::server_url( $disk ), $plain );
		$this->assertStringContainsString( DiskGuide::guide_url( $disk ), $plain );
	}

	public function test_a_server_ready_email_has_no_disk_pointer(): void {
		$product  = $this->service_product( 'fake', array(), array( 'slug' => 'dg2' ) );
		$resource = Resource::create( $this->service_license( $product, array( 'fire' => false, 'email' => 'customer@example.com' ) ), Registry::get( 'fake' ), array(), array( 'state' => 'ready' ) );

		Emails::get( 'beltoft_services_ready' )->trigger( $resource );

		$this->assertStringNotContainsString( 'Find your disk', (string) $this->mails[0]['message'] );
	}
}
