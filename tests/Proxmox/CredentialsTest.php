<?php
/**
 * Stored database and app logins are wiped after their window and when the service is deleted.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Proxmox;

use BeltoftServices\Drivers\Registry;
use BeltoftServices\Product;
use BeltoftServices\Proxmox\Credentials;
use BeltoftServices\Proxmox\DbJob;
use BeltoftServices\Resource;
use BeltoftServices\Tests\TestCase;

class CredentialsTest extends TestCase {

	private function db(): Resource {
		$product = $this->service_product( 'proxmox-db', array( 'cluster' => 'lab', 'template' => 9100, 'engine' => 'valkey', 'major' => 8, 'cores' => 2, 'memory_mb' => 4096, 'disk_gb' => 20, 'public_ip' => 0 ), array( 'max' => 3 ) );
		$license = $this->service_license( $product, array( 'fire' => false ) );
		$r       = Resource::create( $license, Registry::get( 'proxmox-db' ), Product::plan( $product->get_id() ), array() );
		$r->set( array( 'state' => 'ready', 'desired' => 'ready' ) );

		return Resource::find( $r->id() );
	}

	private function vm(): Resource {
		$product = $this->service_product( 'proxmox', array( 'cluster' => 'lab', 'template' => 9000, 'cores' => 2, 'memory_mb' => 4096, 'disk_gb' => 30 ), array( 'max' => 3 ) );
		$license = $this->service_license( $product, array( 'fire' => false ) );
		$r       = Resource::create( $license, Registry::get( 'proxmox' ), Product::plan( $product->get_id() ) );
		$r->set( array( 'state' => 'ready', 'desired' => 'ready' ) );

		return Resource::find( $r->id() );
	}

	public function test_the_daily_sweep_wipes_only_expired_database_and_app_logins(): void {
		$old   = time() - 60;
		$later = time() + DAY_IN_SECONDS;
		$db1   = $this->db();
		$db2   = $this->db();
		$app1  = $this->vm();
		$app2  = $this->vm();

		$db1->set( array( DbJob::REVEAL => 'cipher-a', DbJob::UNTIL => $old ) );
		$db2->set( array( DbJob::REVEAL => 'cipher-b', DbJob::UNTIL => $later ) );
		$app1->set( array( 'app_reveal' => 'cipher-c', 'app_reveal_until' => $old ) );
		$app2->set( array( 'app_reveal' => 'cipher-d', 'app_reveal_until' => $later ) );

		delete_transient( Credentials::RAN );
		Credentials::purge_all_expired();

		$this->assertSame( '', (string) Resource::find( $db1->id() )->get( DbJob::REVEAL ) );
		$this->assertSame( 0, (int) Resource::find( $db1->id() )->get( DbJob::UNTIL ) );
		$this->assertSame( 'cipher-b', Resource::find( $db2->id() )->get( DbJob::REVEAL ) );
		$this->assertSame( '', (string) Resource::find( $app1->id() )->get( 'app_reveal' ) );
		$this->assertSame( 'cipher-d', Resource::find( $app2->id() )->get( 'app_reveal' ) );

		// Once a day.
		$db2->set( array( DbJob::UNTIL => $old ) );
		Credentials::purge_all_expired();
		$this->assertSame( 'cipher-b', Resource::find( $db2->id() )->get( DbJob::REVEAL ) );
	}

	public function test_a_deleted_service_keeps_no_login(): void {
		$later = time() + DAY_IN_SECONDS;
		$db    = $this->db();
		$app   = $this->vm();
		$db->set( array( DbJob::REVEAL => 'cipher-a', DbJob::UNTIL => $later ) );
		$app->set( array( 'app_reveal' => 'cipher-c', 'app_reveal_until' => $later ) );

		do_action( 'beltoft_services_state_changed', Resource::find( $db->id() ), 'ready', 'deleted' );
		do_action( 'beltoft_services_state_changed', Resource::find( $app->id() ), 'ready', 'deleted' );

		$this->assertSame( '', (string) Resource::find( $db->id() )->get( DbJob::REVEAL ) );
		$this->assertSame( '', (string) Resource::find( $app->id() )->get( 'app_reveal' ) );

		// Other state changes leave it alone.
		$other = $this->db();
		$other->set( array( DbJob::REVEAL => 'cipher-z', DbJob::UNTIL => $later ) );
		do_action( 'beltoft_services_state_changed', Resource::find( $other->id() ), 'provisioning', 'ready' );
		$this->assertSame( 'cipher-z', Resource::find( $other->id() )->get( DbJob::REVEAL ) );
	}
}
