<?php
/**
 * Children::spawn(): one way to make a child service.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Mail;

use BeltoftLicenses\Licenses;
use BeltoftServices\Children;
use BeltoftServices\Drivers\Registry;
use BeltoftServices\Mail\MailDomain;
use BeltoftServices\Mail\MailDriver;
use BeltoftServices\Resource;

require_once __DIR__ . '/MailTestCase.php';

final class ChildrenSpawnTest extends MailTestCase {

	/** Licenses of the parent's customer with the child product slug. */
	private function child_licenses( Resource $parent, string $slug ): array {
		return array_values( array_filter( Licenses::where( 'user_id', (int) $parent->license()['user_id'] ), static fn( $l ) => $slug === $l['product_slug'] ) );
	}

	public function test_spawn_makes_a_linked_child_with_its_own_license_and_queues_its_job(): void {
		$parent = $this->ready_mail( 'parent.example' );
		$slug   = 'child-test-slug';

		$child = Children::spawn( $parent, $slug, Registry::get( MailDriver::ID ), array( 'mailboxes' => 3 ), array( MailDomain::META_DOMAIN => 'kid.example' ), 'no license', 'no link' );

		$this->assertInstanceOf( Resource::class, $child );
		$this->assertSame( $parent->id(), (int) $child->get( Children::PARENT ) );
		$this->assertSame( array( $child->id() ), Children::ids( Resource::find( $parent->id() ) ) );
		$this->assertSame( 'kid.example', $child->get( MailDomain::META_DOMAIN ) );

		$found = $this->child_licenses( $parent, $slug );
		$this->assertCount( 1, $found );
		$this->assertSame( 'child', $found[0]['source'] );
		$this->assertSame( (int) $parent->license()['user_id'], (int) $found[0]['user_id'] );
		$this->assertSame( (string) $parent->license()['email'], (string) $found[0]['email'] );
		$this->assertSame( Licenses::STATUS_ACTIVE, $found[0]['status'] );
	}

	public function test_spawn_revokes_the_license_when_the_resource_cannot_be_created(): void {
		$parent = $this->ready_mail( 'parent2.example' );
		$slug   = 'child-fail-slug';

		$res = Children::spawn( $parent, $slug, Registry::get( MailDriver::ID ), array(), array( 'uuid' => 'not a valid uuid!' ), 'no license', 'no link' );

		$this->assertWPError( $res );
		$found = $this->child_licenses( $parent, $slug );
		$this->assertCount( 1, $found );
		$this->assertSame( Licenses::STATUS_REVOKED, $found[0]['status'] );
		$this->assertSame( array(), Children::ids( Resource::find( $parent->id() ) ) );
	}
}
