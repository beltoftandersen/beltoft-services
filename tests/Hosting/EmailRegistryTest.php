<?php
/**
 * Without the hosting email registry no hosting email exists (moved here from EmailsTest).
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Hosting;

use BeltoftServices\Drivers\Registry;
use BeltoftServices\Emails\Emails;
use BeltoftServices\Resource;
use BeltoftServices\Tests\TestCase;

/**
 * The hosting emails come through the registry filters only.
 */
final class EmailRegistryTest extends TestCase {

	/**
	 * Mails caught by pre_wp_mail.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private $mails = array();

	public function set_up(): void {
		parent::set_up();

		$this->mails = array();
		add_filter( 'pre_wp_mail', array( $this, 'catch_mail' ), 10, 2 );
		$this->settings( array( 'admin_email' => 'ops@example.com' ) );
		$key = base64_encode( random_bytes( 32 ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Test key.
		add_filter( 'beltoft_services_key', fn() => $key );
	}

	/**
	 * Records a mail instead of sending it.
	 *
	 * @param mixed                $pre  Short-circuit value.
	 * @param array<string, mixed> $atts Mail arguments.
	 * @return bool
	 */
	public function catch_mail( $pre, $atts ) {
		$this->mails[] = $atts;
		return true;
	}

	/**
	 * Mails sent to the customer.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function customer_mails(): array {
		return array_values(
			array_filter(
				$this->mails,
				static function ( $mail ) {
					$to = is_array( $mail['to'] ) ? implode( ',', $mail['to'] ) : (string) $mail['to'];
					return false !== strpos( $to, 'customer@example.com' );
				}
			)
		);
	}

	public function test_without_the_hosting_registry_no_hosting_email_exists_and_nothing_is_sent_or_thrown(): void {
		$this->fake_driver( 'hosting' );
		$product  = $this->service_product( 'hosting' );
		$license  = $this->service_license( $product, array( 'fire' => false ) );
		$resource = Resource::find( Resource::create( $license, Registry::get( 'hosting' ), array(), array( 'state' => 'ready' ) )->id() );

		$with = Emails::ids();
		$this->assertContains( 'beltoft_services_hosting_ready', $with );
		$this->assertSame( 'beltoft_services_hosting_malware_found', end( $with ), 'The hosting emails come after the core ones.' );

		$registry = \BeltoftServices\Hosting\EmailRegistry::class;
		remove_filter( 'beltoft_services_email_classes', array( $registry, 'classes' ) );
		remove_filter( 'beltoft_services_email_definitions', array( $registry, 'definitions' ) );
		remove_filter( 'beltoft_services_email_value_defaults', array( $registry, 'value_defaults' ) );
		// A new mailer, as a request without hosting would have: the old one keeps the instances it built.
		$instance = new \ReflectionProperty( \WC_Emails::class, 'instance' );
		$old      = $instance->getValue();
		$instance->setValue( null, null );

		$this->assertNotContains( 'beltoft_services_hosting_ready', Emails::ids() );
		$this->assertArrayNotHasKey( 'BeltoftServices_HostingReady', WC()->mailer()->get_emails() );
		$this->assertArrayNotHasKey( 'hosting_notice', \BeltoftServices\Emails\ValueDefaults::all() );
		$this->assertNull( Emails::get( 'beltoft_services_hosting_ready' ) );

		$this->mails = array();
		$this->assertFalse( Emails::send( 'beltoft_services_hosting_ready', $resource ) );
		$resource->set( array( 'notified' => '' ) );
		Emails::on_state_changed( $resource, 'provisioning', 'ready' );
		$this->assertCount( 0, $this->customer_mails(), 'Nothing is sent.' );

		try {
			\BeltoftServices\Emails\Definitions::get( 'beltoft_services_hosting_ready' );
			$this->fail( 'An unknown id still throws.' );
		} catch ( \InvalidArgumentException $e ) {
			$this->assertStringContainsString( 'beltoft_services_hosting_ready', $e->getMessage() );
		} finally {
			$instance->setValue( null, $old );
			\BeltoftServices\Hosting\EmailRegistry::register();
		}
	}
}
