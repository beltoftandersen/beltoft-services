<?php
/**
 * More email domains on one plan.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Mail;

use BeltoftServices\Children;
use BeltoftServices\Drivers\Registry;
use BeltoftServices\Mail\Aliases;
use BeltoftServices\Mail\MailDomain;
use BeltoftServices\Mail\MailDomains;
use BeltoftServices\Mail\MailPlan;
use BeltoftServices\Mail\MailSwitch;
use BeltoftServices\Mail\Mailboxes;
use BeltoftServices\Product;
use BeltoftServices\Resource;

require_once __DIR__ . '/MailTestCase.php';

final class MailDomainsTest extends MailTestCase {

	/** Adds a domain and runs its jobs. */
	private function add_ready( Resource $root, string $name ): Resource {
		$child = MailDomains::add( $root, $name );
		$this->assertInstanceOf( Resource::class, $child );
		$this->run_jobs();

		return Resource::find( $child->id() );
	}

	public function test_a_team_plan_takes_a_second_and_third_domain_and_refuses_a_fourth(): void {
		$root = $this->ready_mail( 'root.example' );
		$this->assertInstanceOf( Resource::class, MailDomains::add( $root, 'two.example' ) );
		$this->assertInstanceOf( Resource::class, MailDomains::add( $root, 'three.example' ) );

		$res = MailDomains::add( $root, 'four.example' );
		$this->assertWPError( $res );
		$this->assertSame( 'no_room', $res->get_error_code() );
		$this->assertCount( 3, MailPlan::members( $root ) );
	}

	public function test_the_child_inherits_the_root_limits_and_provisions_like_any_mail_domain(): void {
		$root  = $this->ready_mail( 'root.example' );
		$child = MailDomains::add( $root, 'Child.Example' );
		$this->assertInstanceOf( Resource::class, $child );

		$license = $child->license();
		$this->assertSame( 'child', $license['source'] );
		$this->assertSame( 1, (int) $license['max_activations'] );
		$this->assertStringEndsWith( '-domain', (string) $license['product_slug'] );
		$this->assertSame( $root->id(), Children::parent_of( $child )->id() );
		$this->assertSame( 'child.example', $child->get( MailDomain::META_DOMAIN ) );

		$this->run_jobs();
		$child = Resource::find( $child->id() );
		$this->assertSame( 'ready', $child->get( 'state' ) );
		$this->assertSame( self::PLAN, $child->get( 'mail_limits' ) );
		$this->assertIsArray( $this->mailcow->domain( 'child.example' ) );
	}

	public function test_a_domain_held_by_another_customer_or_already_in_the_plan_is_refused(): void {
		$root = $this->ready_mail( 'root.example' );
		$this->ready_mail( 'other.example' );

		foreach ( array( 'other.example', 'root.example', 'reserved.example', 'not a domain' ) as $name ) {
			$res = MailDomains::add( $root, $name );
			$this->assertWPError( $res, $name );
			$this->assertSame( 'bad_domain', $res->get_error_code(), $name );
		}

		$this->add_ready( $root, 'mine.example' );
		$again = MailDomains::add( $root, 'mine.example' );
		$this->assertWPError( $again );
		$this->assertSame( 'bad_domain', $again->get_error_code() );
		$this->assertCount( 2, MailPlan::members( $root ) );
	}

	public function test_a_paused_root_refuses_new_domains_and_pauses_its_children(): void {
		$root  = $this->ready_mail( 'root.example' );
		$child = $this->add_ready( $root, 'child.example' );
		$this->assertSame( 'ready', $child->get( 'state' ) );

		$this->assertTrue( $root->desire( 'paused', 'Test' ) );
		\BeltoftServices\Jobs::enqueue( $root->id() );
		$this->run_jobs();

		$this->assertSame( 'paused', Resource::find( $root->id() )->get( 'state' ) );
		$this->assertSame( 'paused', Resource::find( $child->id() )->get( 'state' ) );

		$res = MailDomains::add( Resource::find( $root->id() ), 'late.example' );
		$this->assertWPError( $res );
		$this->assertSame( 'not_ready', $res->get_error_code() );

		$this->assertTrue( Resource::find( $root->id() )->desire( 'ready', 'Test' ) );
		\BeltoftServices\Jobs::enqueue( $root->id() );
		$this->run_jobs();
		$this->assertSame( 'ready', Resource::find( $child->id() )->get( 'state' ) );
	}

	public function test_removing_a_domain_with_mailboxes_is_refused(): void {
		$root  = $this->ready_mail( 'root.example' );
		$child = $this->add_ready( $root, 'child.example' );
		$this->assertIsArray( Mailboxes::create( $child, 'anna' ) );

		$res = MailDomains::remove( $child );
		$this->assertWPError( $res );
		$this->assertSame( 'has_mailboxes', $res->get_error_code() );

		$this->assertNotWPError( Mailboxes::delete( $child, 'anna@child.example' ) );
		$this->assertTrue( MailDomains::remove( Resource::find( $child->id() ) ) );
		$this->run_jobs();
		$this->assertSame( 'deleted', Resource::find( $child->id() )->get( 'state' ) );
		$this->assertCount( 1, MailPlan::members( Resource::find( $root->id() ) ) );
	}

	public function test_removing_a_domain_is_refused_when_the_mail_server_cannot_be_asked(): void {
		$root  = $this->ready_mail( 'root.example' );
		$child = $this->add_ready( $root, 'child.example' );
		$this->assertIsArray( Mailboxes::create( $child, 'anna' ) );
		$this->mailcow->fail_next( 'list_mailboxes', new \WP_Error( 'mailcow_transport', 'down' ) );

		$res = MailDomains::remove( Resource::find( $child->id() ) );
		$this->assertWPError( $res );
		$this->assertSame( 'unavailable', $res->get_error_code() );
		$this->assertSame( 'Try again in a moment.', $res->get_error_message() );
		$this->assertSame( 'ready', Resource::find( $child->id() )->get( 'desired' ) );
	}

	public function test_a_domain_with_an_alias_but_no_mailbox_is_not_removed(): void {
		$root  = $this->ready_mail( 'root.example' );
		$child = $this->add_ready( $root, 'child.example' );
		$this->assertIsArray( Aliases::create( $child, 'info', 'x@elsewhere.example' ) );

		$res = MailDomains::remove( Resource::find( $child->id() ) );
		$this->assertWPError( $res );
		$this->assertSame( 'has_mailboxes', $res->get_error_code() );
		$this->assertSame( 'ready', Resource::find( $child->id() )->get( 'desired' ) );
	}

	public function test_a_child_that_cannot_be_made_leaves_no_active_license(): void {
		$root = $this->ready_mail( 'root.example' );
		$res  = MailDomains::add( $root, 'new.example', array( 'uuid' => 'not a valid uuid!' ) );
		$this->assertWPError( $res );

		$slug  = (string) $root->license()['product_slug'] . '-domain';
		$found = array_values( array_filter( \BeltoftLicenses\Licenses::where( 'user_id', (int) $root->license()['user_id'] ), static fn( $l ) => $slug === $l['product_slug'] ) );
		$this->assertCount( 1, $found );
		$this->assertSame( \BeltoftLicenses\Licenses::STATUS_REVOKED, $found[0]['status'] );
		$this->assertCount( 1, MailPlan::members( $root ) );
	}

	public function test_removing_a_domain_is_refused_while_the_plan_is_paused(): void {
		$root  = $this->ready_mail( 'root.example' );
		$child = $this->add_ready( $root, 'child.example' );
		$this->assertTrue( $root->set( array( 'state' => 'paused', 'desired' => 'paused' ) ) );

		$res = MailDomains::remove( Resource::find( $child->id() ) );
		$this->assertWPError( $res );
		$this->assertSame( 'not_active', $res->get_error_code() );
		$this->assertSame( 'You can remove a domain when the plan is active again.', $res->get_error_message() );
	}

	public function test_a_plan_upgrade_raises_the_limits_of_every_domain(): void {
		$root  = $this->ready_mail( 'root.example' );
		$child = $this->add_ready( $root, 'child.example' );
		$bigger = array( 'mailboxes' => 8, 'quota_mb' => 20480, 'aliases' => 80, 'domains' => 5 );

		$this->assertTrue( $root->change_plan( $bigger, 'Upgrade' ) );
		\BeltoftServices\Jobs::enqueue( $root->id() );
		$this->run_jobs();

		$this->assertSame( $bigger, Resource::find( $root->id() )->get( 'mail_limits' ) );
		$this->assertSame( $bigger, Resource::find( $child->id() )->get( 'mail_limits' ) );
		$this->assertSame( '8', $this->mailcow->domain( 'child.example' )['mailboxes'] );
		$this->assertSame( 8, MailPlan::limits( Resource::find( $child->id() ) )['mailboxes'] );
	}

	public function test_a_switch_to_a_plan_with_fewer_domains_than_in_use_is_refused(): void {
		$product = $this->service_product( 'mail', self::PLAN, array( 'slug' => 'mail-sw' ) );
		$license = $this->service_license( $product, array( 'fire' => false, 'subscription_id' => 7272 ) );
		$root    = Resource::create( $license, Registry::get( 'mail' ), Product::plan( $product->get_id() ), array( MailDomain::META_DOMAIN => 'sw-root.example' ) );
		$this->assertInstanceOf( Resource::class, $root );
		$this->proof['check_txt'] = array( 'status' => 'found', 'servers' => array(), 'reason' => 'found' );
		$this->assertTrue( $root->set( array( 'state' => 'ready' ) ) );
		$this->assertTrue( Registry::get( 'mail' )->provision( $root )->is_done() );
		$root = Resource::find( $root->id() );
		$root->set( array( 'steps' => array( 'proof', 'domain' ) ) );
		$this->add_ready( Resource::find( $root->id() ), 'sw-two.example' );

		$target = $this->service_product( 'mail', array( 'mailboxes' => 9, 'quota_mb' => 20480, 'aliases' => 90, 'domains' => 1 ), array( 'slug' => 'mail-one' ) );
		MailSwitch::register();
		$sub = new class( 7272 ) {
			public function __construct( private int $id ) {}
			public function get_id() {
				return $this->id;
			}
		};

		$message = apply_filters( 'woocommerce_subscriptions_switch_error_message', '', $target->get_id(), 1, 0, $sub, null );
		$this->assertSame( 'Your plan has 2 email domains. Choose a plan with at least that many.', $message );
	}

	public function test_a_child_still_being_set_up_does_not_block_mailboxes_on_the_other_domains(): void {
		$root = $this->ready_mail( 'root.example' );
		$this->proof['check_txt'] = array( 'status' => 'missing', 'servers' => array(), 'reason' => 'not_found' );
		$child = MailDomains::add( $root, 'waiting.example' );
		$this->assertInstanceOf( Resource::class, $child );
		$this->assertNotContains( 'domain', (array) Resource::find( $child->id() )->get( 'steps' ) );

		$res = Mailboxes::create( $root, 'anna' );
		$this->assertIsArray( $res );
		$this->assertSame( array( 'mailboxes' => 1, 'aliases' => 0, 'domains' => 2 ), MailPlan::used( $root ) );
	}

	/**
	 * A registered or transferred domain with its order line, as the Domains driver leaves it.
	 *
	 * @param int      $user   Customer.
	 * @param string   $name   Domain.
	 * @param string   $mode   register or transfer.
	 * @param int|null $attach Root id on the order line, null for none.
	 * @return array{0: Resource, 1: \WC_Order}
	 */
	private function bought_domain( int $user, string $name, string $mode, ?int $attach ): array {
		$product = $this->service_product( 'domain', array(), array( 'slug' => 'domain-' . wp_generate_password( 5, false ) ) );
		$order   = wc_create_order();
		$item    = new \WC_Order_Item_Product();
		$item->set_product_id( $product->get_id() );
		$item->add_meta_data( '_beltoft_services_domain', array( 'domain' => $name, 'mode' => $mode ), true );
		if ( null !== $attach ) {
			$item->add_meta_data( '_beltoft_services_mail_attach', $attach, true );
		}
		$order->add_item( $item );
		$order->save();
		$license = $this->service_license( $product, array( 'fire' => false, 'user_id' => $user, 'order_id' => $order->get_id() ) );
		$r       = Resource::create( $license, new \BeltoftServices\Domains\DomainDriver(), array(), array( 'domain' => $name, 'mode' => $mode, 'order_item_id' => (int) $item->get_id() ) );
		$this->assertInstanceOf( Resource::class, $r );
		$r->set( array( 'state' => 'ready' ) );

		return array( Resource::find( $r->id() ), $order );
	}

	private function ready_event( Resource $r ): void {
		do_action( 'beltoft_services_state_changed', $r, 'provisioning', 'ready' );
	}

	public function test_a_registered_domain_bought_for_a_plan_joins_it_when_ready(): void {
		$user = self::factory()->user->create();
		$root = $this->ready_mail( 'root.example', array(), $user );
		list( $r ) = $this->bought_domain( $user, 'bought.example', 'register', $root->id() );

		$this->ready_event( $r );
		$names = array_map( static fn( Resource $m ) => \BeltoftServices\Mail\MailGuard::domain( $m ), MailPlan::members( $root ) );
		$this->assertContains( 'bought.example', $names );

		$this->ready_event( $r ); // Ready again later (resume): nothing is added twice.
		$this->assertCount( 2, MailPlan::members( $root ) );
	}

	public function test_a_completed_transfer_joins_the_plan_too(): void {
		$user = self::factory()->user->create();
		$root = $this->ready_mail( 'root.example', array(), $user );
		list( $r ) = $this->bought_domain( $user, 'moved.example', 'transfer', $root->id() );

		$this->ready_event( $r );
		$this->assertCount( 2, MailPlan::members( $root ) );
	}

	public function test_a_domain_without_the_mark_or_a_state_other_than_ready_does_nothing(): void {
		$user = self::factory()->user->create();
		$root = $this->ready_mail( 'root.example', array(), $user );
		list( $plain ) = $this->bought_domain( $user, 'plain.example', 'register', null );
		list( $marked ) = $this->bought_domain( $user, 'marked.example', 'register', $root->id() );

		$this->ready_event( $plain );
		do_action( 'beltoft_services_state_changed', $marked, 'ready', 'paused' );
		$this->assertCount( 1, MailPlan::members( $root ) );
	}

	public function test_a_stale_mark_does_nothing_and_leaves_an_order_note(): void {
		$user  = self::factory()->user->create();
		$root  = $this->ready_mail( 'root.example', array(), $user );
		$other = self::factory()->user->create();
		$theirs = $this->ready_mail( 'theirs.example', array(), $other );

		// The root belongs to somebody else.
		list( $r, $order ) = $this->bought_domain( $user, 'steal.example', 'register', $theirs->id() );
		$this->ready_event( $r );
		$this->assertCount( 1, MailPlan::members( $theirs ) );
		$this->assertNotEmpty( wc_get_order_notes( array( 'order_id' => $order->get_id() ) ) );

		// The plan is full.
		MailDomains::add( $root, 'two.example' );
		MailDomains::add( $root, 'three.example' );
		list( $full, $full_order ) = $this->bought_domain( $user, 'late.example', 'register', $root->id() );
		$this->ready_event( $full );
		$this->assertCount( 3, MailPlan::members( $root ) );
		$this->assertNotEmpty( wc_get_order_notes( array( 'order_id' => $full_order->get_id() ) ) );

		// The root is gone.
		$root->set( array( 'state' => 'deleted', 'desired' => 'deleted' ) );
		list( $gone, $gone_order ) = $this->bought_domain( $user, 'orphan.example', 'register', $root->id() );
		$this->ready_event( $gone );
		$this->assertNotEmpty( wc_get_order_notes( array( 'order_id' => $gone_order->get_id() ) ) );
	}

	public function test_the_cart_takes_the_mark_only_for_a_root_of_the_user_with_room(): void {
		$user = self::factory()->user->create();
		$root = $this->ready_mail( 'root.example', array(), $user );
		$other = self::factory()->user->create();
		$theirs = $this->ready_mail( 'theirs.example', array(), $other );
		wp_set_current_user( $user );

		$data = \BeltoftServices\Domains\Cart::line_data( 'fresh.example', 'register', '', '', $root->id() );
		$this->assertSame( $root->id(), $data['beltoft_services_mail_attach'] );

		foreach ( array( $theirs->id(), 999999, 0 ) as $bad ) {
			$data = \BeltoftServices\Domains\Cart::line_data( 'fresh.example', 'register', '', '', $bad );
			$this->assertIsArray( $data );
			$this->assertArrayNotHasKey( 'beltoft_services_mail_attach', $data );
		}

		wp_set_current_user( 0 );
		$data = \BeltoftServices\Domains\Cart::line_data( 'fresh.example', 'register', '', '', $root->id() );
		$this->assertArrayNotHasKey( 'beltoft_services_mail_attach', $data );

		wp_set_current_user( $user );
		MailDomains::add( $root, 'two.example' );
		MailDomains::add( $root, 'three.example' );
		$data = \BeltoftServices\Domains\Cart::line_data( 'fresh.example', 'register', '', '', $root->id() );
		$this->assertArrayNotHasKey( 'beltoft_services_mail_attach', $data, 'No room.' );
	}

	public function test_the_order_line_keeps_the_mark(): void {
		$item = new \WC_Order_Item_Product();
		\BeltoftServices\Domains\Cart::order_item( $item, 'k', array( 'beltoft_services_domain' => array( 'domain' => 'a.example', 'mode' => 'register' ), 'beltoft_services_mail_attach' => 42 ), wc_create_order() );
		$this->assertSame( 42, (int) $item->get_meta( '_beltoft_services_mail_attach' ) );
	}

	public function test_a_domain_already_in_the_plan_counts_as_joined_without_a_note(): void {
		$user = self::factory()->user->create();
		$root = $this->ready_mail( 'root.example', array(), $user );
		MailDomains::add( $root, 'bought.example' );
		list( $r, $order ) = $this->bought_domain( $user, 'bought.example', 'register', $root->id() );

		$this->ready_event( $r );
		$this->assertSame( 'joined', Resource::find( $r->id() )->get( MailDomains::ATTACH_DONE ) );
		$this->assertCount( 2, MailPlan::members( $root ) );
		$this->assertSame( array(), wc_get_order_notes( array( 'order_id' => $order->get_id() ) ) );
	}

	public function test_the_cart_filter_is_hooked_and_a_transfer_that_is_not_ready_fires_nothing(): void {
		$this->assertNotFalse( has_filter( 'beltoft_services_domain_cart_attach' ) );
		$user = self::factory()->user->create();
		$root = $this->ready_mail( 'root.example', array(), $user );
		list( $r, $order ) = $this->bought_domain( $user, 'pending.example', 'transfer', $root->id() );

		foreach ( array( 'provisioning', 'failed', 'paused' ) as $state ) {
			do_action( 'beltoft_services_state_changed', $r, 'new', $state );
		}
		$this->assertCount( 1, MailPlan::members( $root ) );
		$this->assertSame( array(), wc_get_order_notes( array( 'order_id' => $order->get_id() ) ) );
	}

	public function test_the_transfer_link_fragment_is_an_id_of_the_transfer_markup(): void {
		$default = \BeltoftServices\Domains\Search::transfer_markup();
		$source  = (string) file_get_contents( dirname( __DIR__, 2 ) . '/templates/account/mail.php' );
		$this->assertSame( 1, preg_match( "/register \. '#([A-Za-z0-9_-]+)'/", $source, $m ), 'The template has a default fragment.' );
		$this->assertStringContainsString( 'id="' . $m[1] . '"', $default );
	}

	public function test_a_domain_that_finds_its_plan_busy_joins_on_one_scheduled_retry(): void {
		global $wpdb;
		$user = self::factory()->user->create();
		$root = $this->ready_mail( 'root.example', array(), $user );
		list( $r, $order ) = $this->bought_domain( $user, 'late.example', 'register', $root->id() );
		add_filter( 'beltoft_services_desire_lock_wait', '__return_zero' );

		$other = new \mysqli( DB_HOST, DB_USER, DB_PASSWORD, DB_NAME ); // phpcs:ignore WordPress.DB.RestrictedClasses.mysql__mysqli -- a second connection holds the lock.
		$held  = $other->query( $wpdb->prepare( 'SELECT GET_LOCK( %s, 1 )', \BeltoftServices\Locks::name( 'resource', $root->id() ) ) );
		$this->assertSame( '1', $held->fetch_row()[0] );

		$this->ready_event( $r );
		$this->ready_event( $r );
		$this->assertSame( '', (string) Resource::find( $r->id() )->get( MailDomains::ATTACH_DONE ), 'Not final: the plan was only busy.' );
		$this->assertCount( 1, MailPlan::members( $root ) );
		$when = as_next_scheduled_action( MailDomains::RETRY_HOOK, array( $r->id() ), 'beltoft-services' );
		$this->assertNotFalse( $when, 'One retry is scheduled.' );
		$this->assertCount( 1, as_get_scheduled_actions( array( 'hook' => MailDomains::RETRY_HOOK, 'args' => array( $r->id() ), 'status' => \ActionScheduler_Store::STATUS_PENDING ), 'ids' ) );

		$other->close();
		remove_filter( 'beltoft_services_desire_lock_wait', '__return_zero' );
		MailDomains::retry( $r->id() );

		$this->assertSame( 'joined', Resource::find( $r->id() )->get( MailDomains::ATTACH_DONE ) );
		$this->assertCount( 2, MailPlan::members( $root ) );
		unset( $order );
	}

	public function test_a_plan_that_stays_busy_past_the_window_is_given_up(): void {
		$user = self::factory()->user->create();
		$root = $this->ready_mail( 'root.example', array(), $user );
		list( $r, $order ) = $this->bought_domain( $user, 'late.example', 'register', $root->id() );
		$r->set( array( MailDomains::ATTACH_UNTIL => time() - 10 ) );

		// Busy again, but the window is over.
		$other = new \mysqli( DB_HOST, DB_USER, DB_PASSWORD, DB_NAME ); // phpcs:ignore WordPress.DB.RestrictedClasses.mysql__mysqli -- a second connection holds the lock.
		global $wpdb;
		add_filter( 'beltoft_services_desire_lock_wait', '__return_zero' );
		$other->query( $wpdb->prepare( 'SELECT GET_LOCK( %s, 1 )', \BeltoftServices\Locks::name( 'resource', $root->id() ) ) );
		MailDomains::retry( $r->id() );
		$other->close();
		remove_filter( 'beltoft_services_desire_lock_wait', '__return_zero' );

		$this->assertSame( 'refused', Resource::find( $r->id() )->get( MailDomains::ATTACH_DONE ) );
		$this->assertFalse( as_next_scheduled_action( MailDomains::RETRY_HOOK, array( $r->id() ), 'beltoft-services' ) );
		unset( $order );
	}

	public function test_the_plan_lock_is_taken_before_the_lock_of_a_domain(): void {
		global $wpdb;
		$user  = self::factory()->user->create();
		$root  = $this->ready_mail( 'root.example', array(), $user );
		$child = MailDomains::add( $root, 'two.example' );
		$this->assertInstanceOf( Resource::class, $child );

		$taken = array();
		$spy   = static function ( $sql ) use ( &$taken ) {
			if ( preg_match( '/GET_LOCK\(\s*\'([^\']+)\'/', (string) $sql, $m ) ) {
				$taken[] = $m[1];
			}
			return $sql;
		};
		add_filter( 'query', $spy );
		$ran = MailPlan::locked( $child, static fn(): bool => true );
		remove_filter( 'query', $spy );

		$this->assertTrue( $ran );
		$this->assertSame(
			array( \BeltoftServices\Locks::name( 'resource', $root->id() ), \BeltoftServices\Locks::name( 'resource', $child->id() ) ),
			array_slice( $taken, 0, 2 ),
			'The root first, like removing a domain and the plan job do.'
		);
		unset( $wpdb );
	}
}
