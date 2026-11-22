<?php
/**
 * The email tab and the licences plugin's tab split, run together: the email domain of a hosting account is a child
 * licence, the email area claims it (Email::is_member), and the split of the licences plugin must hand it to that tab.
 * On 2026-11-16 the split dropped every child licence: the email page of such a domain was empty and said "not found",
 * while each plugin's own tests passed.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Account;

use BeltoftLicenses\Account\Endpoint;
use BeltoftServices\Mail\Email;
use BeltoftServices\Account\ServiceArea;
use BeltoftServices\Drivers\Registry;
use BeltoftServices\Mail\MailDriver;
use BeltoftServices\Resource;
use BeltoftServices\Tests\TestCase;

final class EmailChildTabTest extends TestCase {

	public function test_the_email_domain_of_a_hosting_account_is_on_the_email_tab_and_not_on_the_licenses_tab(): void {
		$user    = self::factory()->user->create();
		$license = \BeltoftLicenses\Licenses::create( array( 'user_id' => $user, 'email' => 'christian.beltoft@gmail.com', 'source' => 'child', 'status' => 'active' ) );
		$this->assertIsArray( $license );
		$driver = Registry::get( MailDriver::ID );
		$this->assertInstanceOf( Resource::class, Resource::create( $license, $driver, array( 'mailboxes' => 1, 'domains' => 1 ), array( 'mail_domain' => 'example.org' ) ) );

		$area = ServiceArea::get( Email::class );
		$this->assertTrue( $area->is_member( $license ), 'The email area claims the child licence.' );

		$tabs = Endpoint::split( array( $license ) );

		$this->assertSame( array( (int) $license['id'] ), array_map( 'intval', array_column( $tabs[ $area->slug() ] ?? array(), 'id' ) ), 'The licences plugin hands it to the email tab.' );
		$this->assertSame( array(), $tabs[''], 'It is not a licence of its own.' );
	}
}
