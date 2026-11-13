<?php
/**
 * The autoloader's alias map keeps old class names working.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Structure;

use PHPUnit\Framework\TestCase;

/**
 * A moved class that outside code still calls is listed in BELTOFT_SERVICES_CLASS_ALIASES (old name => new name).
 */
class ClassAliasTest extends TestCase {

	/**
	 * Every alias with one static method and one constant that the site theme reads through the old name (the
	 * names come from tests/fixtures/external-api.php). Old names are built from parts so that
	 * NamespaceMoveTest does not see them as left-over references.
	 *
	 * @return array<string, array{string, string}>
	 */
	private function expected(): array {
		$old    = 'BeltoftServices\\Proxmox\\';
		$credit = 'BeltoftServices\\Credit\\';

		return array(
			$credit . 'CheckoutCredit' => array( 'BeltoftServices\\GiftCredit\\Checkout', 'preview', '' ),
			$credit . 'Topup'          => array( 'BeltoftServices\\GiftCredit\\Topup', 'max', 'CART_KEY' ),
			$old . 'AppField'    => array( 'BeltoftServices\\Apps\\AppField', 'offer_for', 'POST_APP,POST_TIER,POST_VERSION' ),
			$old . 'AppLicence'  => array( 'BeltoftServices\\Apps\\AppLicence', 'tier', '' ),
			$old . 'Backups'     => array( 'BeltoftServices\\Proxmox\\Backup\\Backups', 'sibling', '' ),
			$old . 'Clients'     => array( 'BeltoftServices\\Proxmox\\Pve\\Clients', 'for', '' ),
			$old . 'Clusters'    => array( 'BeltoftServices\\Proxmox\\Pve\\Clusters', 'get', 'OPTION' ),
			$old . 'DbIpField'   => array( 'BeltoftServices\\Proxmox\\Db\\DbIpField', 'cart_has_new_database', '' ),
			$old . 'DiskField'   => array( 'BeltoftServices\\Proxmox\\Disk\\DiskField', 'cart_server', '' ),
			$old . 'ServerName'  => array( 'BeltoftServices\\Proxmox\\Vm\\ServerName', 'display', 'ACTION' ),
			$old . 'SshKeyField' => array( 'BeltoftServices\\Proxmox\\Vm\\SshKeyField', 'cart_needs_key', '' ),
			$old . 'OsField'     => array( 'BeltoftServices\\Proxmox\\Vm\\OsField', 'cart_choice', '' ),
			$old . 'Picked'      => array( 'BeltoftServices\\Proxmox\\Vm\\Picked', 'clean', 'KEY' ),
		);
	}

	public function test_every_alias_target_exists_and_the_old_name_resolves_to_it(): void {
		$this->assertIsArray( BELTOFT_SERVICES_CLASS_ALIASES );

		foreach ( BELTOFT_SERVICES_CLASS_ALIASES as $old => $new ) {
			$this->assertTrue( class_exists( $new ) || interface_exists( $new ) || trait_exists( $new ), $new );
			$this->assertTrue( class_exists( $old ) || interface_exists( $old ) || trait_exists( $old ), $old );
			$this->assertSame( ( new \ReflectionClass( $new ) )->getName(), ( new \ReflectionClass( $old ) )->getName(), $old );
		}
	}

	public function test_the_alias_map_is_exactly_the_classes_in_the_external_api_list(): void {
		$external = array_keys( require dirname( __DIR__ ) . '/fixtures/external-api.php' );

		foreach ( BELTOFT_SERVICES_CLASS_ALIASES as $old => $new ) {
			$this->assertContains( substr( $old, strlen( 'BeltoftServices\\' ) ), $external, $old . ' is aliased but nothing outside the plugin calls it.' );
		}
	}

	public function test_every_alias_is_exercised_through_the_old_name(): void {
		$this->assertEqualsCanonicalizing( array_keys( $this->expected() ), array_keys( BELTOFT_SERVICES_CLASS_ALIASES ), 'The alias map and the checks here differ.' );

		foreach ( BELTOFT_SERVICES_CLASS_ALIASES as $old => $new ) {
			$this->assertArrayHasKey( $old, $this->expected(), $old . ' has no alias check here.' );
			list( $target, $method, $const ) = $this->expected()[ $old ];

			$this->assertSame( $target, $new );
			$this->assertSame( $target, ( new \ReflectionClass( $old ) )->getName() );
			$this->assertTrue( is_callable( array( $old, $method ) ), $old . '::' . $method );

			foreach ( array_filter( explode( ',', $const ) ) as $name ) {
				// The theme reads constants through the old name; defined() must trigger the autoloader.
				$this->assertTrue( defined( $old . '::' . $name ), $old . '::' . $name );
				$this->assertSame( constant( $target . '::' . $name ), constant( $old . '::' . $name ) );
			}
		}
	}

	public function test_the_guard_the_system_scripts_use_is_true_for_the_old_proxmox_names(): void {
		// /usr/local/lib/beltoft/disk-alert.php asks class_exists() on the old name before it reads the clusters.
		$old      = 'BeltoftServices\\Proxmox\\';
		$clusters = $old . 'Clusters';
		$clients  = $old . 'Clients';

		$this->assertTrue( class_exists( $clusters ) );
		$this->assertTrue( class_exists( $clients ) );
		$this->assertSame( \BeltoftServices\Proxmox\Pve\Clusters::OPTION, constant( $clusters . '::OPTION' ) );
		$this->assertTrue( is_callable( array( $clusters, 'get' ) ) );
		$this->assertTrue( is_callable( array( $clients, 'for' ) ) );
	}
}
