<?php
/**
 * Shared set-up of the MCP tests: the owner switch, fake DNS, and helpers that sign in as a connected app and call tools.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Mcp;

use BeltoftServices\Crypto;
use BeltoftServices\Domains\DomainDriver;
use BeltoftServices\Domains\FakeDns;
use BeltoftServices\Drivers\Registry;
use BeltoftServices\Product;
use BeltoftServices\Resource;
use BeltoftServices\Tests\TestCase;

require_once __DIR__ . '/CallsTools.php';

/**
 * Base of the MCP tests.
 */
abstract class McpTestCase extends TestCase {

	use CallsTools;

	protected const PASSWORD = 'Abcdefghijklmnopqrstuvwxyz012345';
	protected const PEM      = "-----BEGIN CERTIFICATE-----\nMIIBszCCAVmgAwIBAgIUQ2VydGlmaWNhdGVGb3JUZXN0aW5nMAoGCCqGSM49BAMC\n-----END CERTIFICATE-----\n";
	protected const FP       = '00:11:22:33:44:55:66:77:88:99:AA:BB:CC:DD:EE:FF:00:11:22:33:44:55:66:77:88:99:AA:BB:CC:DD:EE:FF';

	/**
	 * @var FakeDns
	 */
	protected $dns;

	public function set_up(): void {
		parent::set_up();

		$this->mcp_set_up();
		$this->dns = new FakeDns();
		$dns       = $this->dns;
		$this->settings( array( 'domain_nameservers' => 'ns1.example.net,ns2.example.net' ) );
		add_filter( 'beltoft_services_domains_dns', static fn() => $dns );
		add_filter( 'beltoft_services_zone_lock_wait', '__return_zero' );
	}

	public function tear_down(): void {
		$this->mcp_tear_down();
		remove_all_filters( 'beltoft_services_domains_dns' );
		remove_all_filters( 'beltoft_services_zone_lock_wait' );
		remove_all_filters( 'beltoft_services_domains_client' );
		remove_all_filters( 'woocommerce_subscription_is_manual' );

		parent::tear_down();
	}

	/**
	 * A VPS-like resource of the fake driver, ready, with reboot offered.
	 */
	protected function server( int $user = 0 ): Resource {
		$driver                   = $this->fake_driver();
		$driver->customer_actions = array( 'reboot' => 'Reboot', 'start' => 'Start', 'shutdown' => 'Shut down', 'snapshot_take' => 'Snapshot', 'reinstall' => 'Reinstall' );
		$row                      = $this->service_license( $this->service_product( 'fake', array(), array( 'max' => 3 ) ), array( 'fire' => false ) + ( $user ? array( 'user_id' => $user ) : array() ) );

		return Resource::create( $row, $driver, array(), array( 'state' => 'ready' ) );
	}

	protected function owner( Resource $r ): int {
		return (int) Resource::find( $r->id() )->license()['user_id'];
	}

	protected function database( int $user = 0 ): Resource {
		$product = $this->service_product( 'proxmox-db', array( 'cluster' => 'lab', 'template' => 9100, 'engine' => 'postgres', 'major' => 18, 'cores' => 2, 'memory_mb' => 4096, 'disk_gb' => 20, 'public_ip' => 0 ), array( 'max' => 3 ) );
		$license = $this->service_license( $product, array( 'fire' => false ) + ( $user ? array( 'user_id' => $user ) : array() ) );
		$r       = Resource::create( $license, Registry::get( 'proxmox-db' ), Product::plan( $product->get_id() ), array() );
		$r->set( array(
			'state'           => 'ready',
			'desired'         => 'ready',
			'db_state'        => 'ready',
			'db_address'      => '10.64.0.2',
			'db_ca_pem'       => self::PEM,
			'db_fingerprint'  => self::FP,
			'db_reveal'       => Crypto::encrypt( self::PASSWORD ),
			'db_reveal_until' => time() + DAY_IN_SECONDS,
		) );

		return Resource::find( $r->id() );
	}

	protected function domain( int $user, string $name = 'example.com' ): Resource {
		$product = $this->service_product( 'domain', array(), array( 'slug' => 'domain-' . wp_generate_password( 5, false ) ) );
		$license = $this->service_license( $product, array( 'fire' => false, 'user_id' => $user ) );
		$r       = Resource::create( $license, new DomainDriver(), array(), array( 'domain' => $name, 'mode' => 'register', 'zone_ready' => true ) );
		$r->set( array( 'state' => 'ready' ) );
		$this->dns->create_zone( $name, array( 'ns1.example.net', 'ns2.example.net' ) );

		return Resource::find( $r->id() );
	}

	/**
	 * A subscription of a customer.
	 *
	 * @param int    $user   Customer.
	 * @param string $price  Price.
	 * @param bool   $manual Renews by invoice.
	 * @param string $status Status.
	 */
	protected function subscription( int $user, string $price = '12.00', bool $manual = false, string $status = 'active' ): \WC_Subscription {
		// The test site has no gateway that takes subscription payments: manual is what the subscription says it is.
		add_filter( 'woocommerce_subscription_is_manual', static fn( $m, $sub ) => true === $sub->get_requires_manual_renewal(), 10, 2 );

		$sub = wcs_create_subscription( array( 'customer_id' => $user, 'billing_period' => 'year', 'billing_interval' => 1, 'status' => $status ) );
		$this->assertInstanceOf( \WC_Subscription::class, $sub );
		$product = new \WC_Product_Simple();
		$product->set_name( 'Domain' );
		$product->set_regular_price( $price );
		$product->save();
		$sub->add_product( $product, 1 );

		if ( ! $manual ) {
			$sub->set_payment_method( 'bacs' );
		}

		$sub->set_requires_manual_renewal( $manual );
		$sub->calculate_totals();
		$sub->update_dates( array( 'next_payment' => gmdate( 'Y-m-d H:i:s', time() + 200 * DAY_IN_SECONDS ) ) );
		$sub->save();

		return $sub;
	}

	/**
	 * A domain of a customer that is paid by a subscription.
	 *
	 * @param int               $user Customer.
	 * @param \WC_Subscription $sub  Subscription.
	 * @param string            $name Domain.
	 */
	protected function domain_paid_by( int $user, \WC_Subscription $sub, string $name = 'example.com' ): Resource {
		$product = $this->service_product( 'domain', array(), array( 'slug' => 'domain-' . wp_generate_password( 5, false ) ) );
		$license = $this->service_license( $product, array( 'fire' => false, 'user_id' => $user, 'subscription_id' => $sub->get_id() ) );
		$r       = Resource::create( $license, new DomainDriver(), array(), array( 'domain' => $name, 'mode' => 'register', 'zone_ready' => true, 'is_locked' => true, 'nameservers' => array( 'ns1.example.net', 'ns2.example.net' ), 'ns_mode' => 'ours', 'expires_at' => '2027-03-01 00:00:00' ) );
		$r->set( array( 'state' => 'ready' ) );
		$this->dns->create_zone( $name, array( 'ns1.example.net', 'ns2.example.net' ) );

		return Resource::find( $r->id() );
	}
}
