<?php
/**
 * Customer emails: ready, paused, deletion warning, deleted, limit exceeded.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests;

use BeltoftLicenses\Licenses;
use BeltoftServices\Drivers\Registry;
use BeltoftServices\Emails\Emails;
use BeltoftServices\Emails\ServiceEmail;
use BeltoftServices\Http\Connections;
use BeltoftServices\Jobs;
use BeltoftServices\Product;
use BeltoftServices\Resource;
use BeltoftServices\Retention;

/**
 * Email tests. Mails are caught by pre_wp_mail; no request leaves the recorder.
 */
final class EmailsTest extends TestCase {

	private const SECRET = 'S3cret-Value-Never-Shown';

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
		as_unschedule_all_actions( Jobs::SWEEP, array(), Jobs::GROUP );
		$this->settings( array( 'admin_email' => 'ops@example.com' ) );

		$key = base64_encode( random_bytes( 32 ) );
		add_filter( 'beltoft_services_key', fn() => $key );
		delete_option( Connections::OPTION );
	}

	public function tear_down(): void {
		delete_option( Connections::OPTION );
		$this->set_import_running( false );

		parent::tear_down();
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
	 * Mails sent to the customer, optionally only those whose subject contains $text.
	 *
	 * @param string $text Subject text.
	 * @return array<int, array<string, mixed>>
	 */
	private function customer_mails( string $text = '' ): array {
		return array_values(
			array_filter(
				$this->mails,
				static function ( $mail ) use ( $text ) {
					$to = is_array( $mail['to'] ) ? implode( ',', $mail['to'] ) : (string) $mail['to'];
					return false !== strpos( $to, 'customer@example.com' ) && ( '' === $text || false !== stripos( (string) $mail['subject'], $text ) );
				}
			)
		);
	}

	public function test_emails_are_woocommerce_emails(): void {
		$ids = array( 'beltoft_services_ready', 'beltoft_services_paused', 'beltoft_services_deletion_warning', 'beltoft_services_deleted', 'beltoft_services_limit_exceeded', 'beltoft_services_provision_delay' );

		foreach ( $ids as $id ) {
			$email = Emails::get( $id );
			$this->assertInstanceOf( \WC_Email::class, $email, $id );
			$this->assertInstanceOf( ServiceEmail::class, $email, $id );
			$this->assertTrue( $email->is_customer_email(), $id );
			$this->assertSame( BELTOFT_SERVICES_PATH . 'templates/', $email->template_base );
			$this->assertFileExists( BELTOFT_SERVICES_PATH . 'templates/' . $email->template_html );
			$this->assertFileExists( BELTOFT_SERVICES_PATH . 'templates/' . $email->template_plain );
		}

		$this->assertArrayHasKey( 'BeltoftServices_Ready', WC()->mailer()->get_emails() );
	}

	public function test_ready_and_paused_emails_sent_once_per_transition(): void {
		$driver  = $this->fake_driver();
		$product = $this->service_product( 'fake' );
		$license = $this->service_license( $product );

		$this->run_jobs();
		$this->assertCount( 1, $driver->calls( 'provision' ) );

		$ready = $this->customer_mails( 'ready' );
		$this->assertCount( 1, $ready, 'One ready email for requested → provisioning → ready.' );
		$this->assertCount( 1, $this->customer_mails(), 'No other customer email.' );
		$this->assertStringContainsString( esc_url( wc_get_account_endpoint_url( \BeltoftLicenses\Account\Endpoint::slug() ) ), (string) $ready[0]['message'], 'It links to My Account → Licenses.' );

		Licenses::update( (int) $license['id'], array( 'status' => Licenses::STATUS_SUSPENDED ) );
		$this->run_jobs();
		$this->assertCount( 1, $this->customer_mails( 'paused' ) );

		// Nothing moves: nothing more is sent.
		Jobs::enqueue( Resource::for_license( (int) $license['id'] )->id() );
		$this->run_jobs();
		$this->assertCount( 2, $this->customer_mails() );

		// Active again: resumed, ready once more.
		Licenses::update( (int) $license['id'], array( 'status' => Licenses::STATUS_ACTIVE ) );
		$this->run_jobs();
		$this->assertCount( 2, $this->customer_mails( 'ready' ) );
		$this->assertCount( 3, $this->customer_mails() );
	}

	public function test_paused_email_gives_the_reason_and_the_retention_period(): void {
		$this->fake_driver();
		$this->settings( array( 'deletion_mode' => 'on' ) );
		$product = $this->service_product( 'fake', array(), array( 'retention' => 30 ) );
		$license = $this->service_license( $product );
		$this->run_jobs();

		Licenses::update( (int) $license['id'], array( 'status' => Licenses::STATUS_SUSPENDED ) );
		$this->run_jobs();

		$mail = $this->customer_mails( 'paused' );
		$this->assertCount( 1, $mail );
		$this->assertStringContainsString( 'Your subscription is on hold', (string) $mail[0]['message'], 'A fixed text for the cause is in the email.' );
		$this->assertStringNotContainsString( 'License suspended', (string) $mail[0]['message'], 'Never the history text.' );
		$this->assertStringContainsString( wp_date( (string) get_option( 'date_format' ), (int) Resource::for_license( (int) $license['id'] )->get( 'paused_at' ) + 30 * DAY_IN_SECONDS ), (string) $mail[0]['message'], 'The deletion date is given.' );

		// Retention off: the reason is there, no period is promised.
		$this->mails = array();
		$this->settings( array( 'deletion_mode' => 'off' ) );
		$other = $this->service_license( $this->service_product( 'fake', array(), array( 'retention' => 30, 'slug' => 'svc-two' ) ) );
		$this->run_jobs();
		$this->mails = array();
		Licenses::update( (int) $other['id'], array( 'status' => Licenses::STATUS_REVOKED ) );
		$this->run_jobs();

		$mail = $this->customer_mails( 'paused' );
		$this->assertCount( 1, $mail );
		$this->assertStringContainsString( 'cancelled or refunded', (string) $mail[0]['message'] );
		$this->assertStringNotContainsString( 'deleted on', (string) $mail[0]['message'] );
		$this->assertStringContainsString( 'data is kept', (string) $mail[0]['message'] );
	}

	public function test_plain_text_emails_show_names_as_text_not_as_html_entities(): void {
		$this->fake_driver();
		$email = Emails::get( 'beltoft_services_ready' );
		$email->email_type = 'plain';

		$product = $this->service_product( 'fake' );
		$product->set_name( 'Tom & Jerry <b>Pro</b>' );
		$product->save();
		$this->service_license( $product );
		$this->run_jobs();

		$mail = $this->customer_mails( 'ready' );
		$this->assertCount( 1, $mail );
		$this->assertStringContainsString( 'Tom & Jerry Pro', (string) $mail[0]['message'] );
		$this->assertStringNotContainsString( '&amp;', (string) $mail[0]['message'] );
		$this->assertStringNotContainsString( '<b>', (string) $mail[0]['message'] );

		$email->email_type = 'html';
	}

	public function test_customer_reason_never_names_an_admin(): void {
		$this->assertSame( 'Paused by an administrator.', ServiceEmail::customer_reason( 'Paused by boss' ) );
		$this->assertSame( 'Your subscription is on hold, for example because a payment is missing.', ServiceEmail::customer_reason( 'License suspended' ) );
		$this->assertSame( '', ServiceEmail::customer_reason( '' ) );
	}

	public function test_no_paused_email_for_a_service_that_never_existed(): void {
		$this->fake_driver();
		$product  = $this->service_product( 'fake' );
		$license  = $this->service_license( $product, array( 'fire' => false ) );
		$resource = Resource::create( $license, Registry::get( 'fake' ), array() );

		$resource->desire( 'paused', 'License suspended' );
		$this->run_jobs();
		Jobs::run( $resource->id() );

		$this->assertSame( 'paused', Resource::find( $resource->id() )->get( 'state' ) );
		$this->assertSame( array(), $this->customer_mails() );
	}

	public function test_medusa_deleted_email_is_sent_not_lost_to_a_payload_mismatch(): void {
		$this->fake_driver();
		$product  = $this->service_product( 'fake' );
		$license  = $this->service_license( $product, array( 'fire' => false ) );
		$resource = Resource::create( $license, Registry::get( 'fake' ), array(), array( 'state' => 'paused' ) );
		$resource->desire( 'paused', 'License expired' );

		// As Retention does: warned_at is stored, then the action fires.
		$when = time() + 7 * DAY_IN_SECONDS;
		Resource::find( $resource->id() )->set( array( 'warned_at' => time() ) );
		do_action( Retention::WARNING_HOOK, Resource::find( $resource->id() ), $when );

		$warning = $this->customer_mails( 'will be deleted' );
		$this->assertCount( 1, $warning );
		$this->assertStringContainsString( wp_date( (string) get_option( 'date_format' ), $when ), (string) $warning[0]['subject'] . $warning[0]['message'] );

		Resource::find( $resource->id() )->desire( 'deleted', 'Retention over' );
		Jobs::run( $resource->id() );
		$this->assertCount( 1, $this->customer_mails( 'has been deleted' ) );
		$this->assertCount( 2, $this->customer_mails(), 'The warning and the deletion, nothing else.' );
	}

	public function test_no_deleted_email_when_a_new_service_follows(): void {
		$this->fake_driver();
		$product  = $this->service_product( 'fake' );
		$license  = $this->service_license( $product, array( 'fire' => false ) );
		$resource = Resource::create( $license, Registry::get( 'fake' ), array(), array( 'state' => 'ready' ) );

		// A driver switch on an active license: deleted, then made anew.
		$resource->desire( 'deleted', 'Product changed', true );
		Jobs::enqueue( $resource->id() );
		$this->run_jobs();

		$this->assertFalse( Resource::find( $resource->id() )->is_live() );
		$this->assertNotSame( $resource->id(), Resource::for_license( (int) $license['id'] )->id(), 'A new service was made.' );

		$this->assertSame( array(), $this->customer_mails( 'has been deleted' ) );
		$this->assertCount( 1, $this->customer_mails( 'ready' ), 'The new service is announced.' );
	}

	public function test_a_failing_email_never_breaks_the_job(): void {
		$log      = wp_tempnam( 'beltoft-services-log' );
		$previous = ini_set( 'error_log', $log ); // phpcs:ignore WordPress.PHP.IniSet.Risky -- keeps the logged failure out of the test output.
		$driver   = $this->fake_driver();
		$product = $this->service_product( 'fake' );
		$license = $this->service_license( $product );

		add_filter(
			'woocommerce_email_recipient_beltoft_services_ready',
			static function () {
				throw new \RuntimeException( 'Mail server exploded' );
			}
		);

		$this->run_jobs();

		ini_set( 'error_log', (string) $previous ); // phpcs:ignore WordPress.PHP.IniSet.Risky

		$resource = Resource::for_license( (int) $license['id'] );
		$this->assertCount( 1, $driver->calls( 'provision' ) );
		$this->assertSame( 'ready', $resource->get( 'state' ) );
		$this->assertSame( 0, $resource->get( 'attempts' ) );
		$this->assertStringContainsString( 'Mail server exploded', (string) file_get_contents( $log ), 'The failure is logged.' );
		wp_delete_file( $log );
	}

	public function test_medusa_no_email_contains_a_secret(): void {
		$this->fake_driver();
		$product  = $this->service_product( 'fake' );
		$license  = $this->service_license( $product, array( 'fire' => false ) );
		$resource = Resource::create(
			$license,
			Registry::get( 'fake' ),
			array( 'password' => 'Plan-Password-123' ),
			array(
				'state'      => 'ready',
				'password'   => 'Meta-Password-456',
				'api_token'  => self::SECRET,
				'secret'     => 'Meta-Secret-789',
				'remote_id'  => 'remote-secret-id',
				'last_error' => 'token=' . self::SECRET,
			)
		);
		$resource = Resource::find( $resource->id() );
		$key      = (string) $license['license_key'];

		$data = array(
			'deletes_at' => time() + DAY_IN_SECONDS,
			'used'       => 7,
			'limit'      => 5,
		);

		foreach ( array( 'html', 'plain' ) as $type ) {
			foreach ( Emails::IDS as $id ) {
				$email             = Emails::get( $id );
				$email->email_type = $type;
				$email->trigger( $resource, $data );
			}
		}

		$this->assertCount( 2 * count( Emails::IDS ), $this->customer_mails(), 'Every email was rendered and sent.' );

		foreach ( $this->customer_mails() as $mail ) {
			$text = (string) $mail['subject'] . "\n" . (string) $mail['message'];

			foreach ( array( self::SECRET, 'Plan-Password-123', 'Meta-Password-456', 'Meta-Secret-789', 'remote-secret-id', $key, $resource->get( 'uuid' ) ) as $secret ) {
				$this->assertStringNotContainsString( $secret, $text, $mail['subject'] );
			}
		}
	}

	/**
	 * Saves the connection 'svc' with a usage endpoint.
	 */
	private function connection(): void {
		$this->assertTrue(
			Connections::save(
				'svc',
				array(
					'label'             => 'Service',
					'base_url'          => 'https://api.example.com/v1',
					'auth'              => 'hmac',
					'secret'            => self::SECRET,
					'endpoints'         => array(
						'provision' => array(
							'method' => 'POST',
							'path'   => '/tenants',
						),
						'usage'     => array(
							'method' => 'GET',
							'path'   => '/tenants/{resource_id}/usage',
						),
					),
					'body_template'     => '{"tenant":"{resource_id}"}',
					'remote_id_field'   => 'id',
					'usage_used_field'  => 'used',
					'usage_limit_field' => 'limit',
				)
			)
		);
	}

	/**
	 * An HTTP resource on connection 'svc'.
	 *
	 * @param string $state State.
	 */
	private function http_resource( string $state = 'ready' ): Resource {
		$product  = $this->service_product( 'http', array( 'limit' => 5 ), array( 'connection' => 'svc' ) );
		$license  = $this->service_license( $product, array( 'fire' => false ) );
		$resource = Resource::create( $license, Registry::get( 'http' ), Product::plan( $product->get_id() ), array( 'state' => $state ) );
		$this->assertInstanceOf( Resource::class, $resource );

		return $resource;
	}

	/**
	 * Answers every usage request with the given count.
	 *
	 * @param int $used Used.
	 */
	private function usage_is( int $used ): void {
		$this->respond_with(
			static function () use ( $used ) {
				return array(
					'headers'  => array(),
					'body'     => wp_json_encode(
						array(
							'used'  => $used,
							'limit' => 5,
						)
					),
					'response' => array(
						'code'    => 200,
						'message' => 'OK',
					),
					'cookies'  => array(),
					'filename' => null,
				);
			}
		);
	}

	public function test_limit_email_once_per_count(): void {
		$this->connection();
		$resource = $this->http_resource();
		$paused   = $this->http_resource( 'paused' );

		$this->usage_is( 6 );
		Emails::usage();
		$this->assertCount( 1, $this->customer_mails( 'limit' ) );
		$this->assertCount( 1, $this->http_calls(), 'Only the ready resource is asked.' );
		$this->assertStringContainsString( $resource->get( 'uuid' ), $this->http_calls()[0]['url'] );

		Emails::usage();
		$this->assertCount( 1, $this->customer_mails( 'limit' ), 'The same count is not sent twice.' );

		$this->usage_is( 7 );
		Emails::usage();
		$this->assertCount( 2, $this->customer_mails( 'limit' ), 'A new count is.' );

		$this->usage_is( 4 );
		Emails::usage();
		$this->assertCount( 2, $this->customer_mails( 'limit' ), 'Within the limit: nothing.' );

		$this->usage_is( 7 );
		Emails::usage();
		$this->assertCount( 3, $this->customer_mails( 'limit' ), 'Over the limit again after being within it.' );

		$this->assertSame( 'paused', Resource::find( $paused->id() )->get( 'state' ) );
	}

	public function test_usage_job_is_daily_and_never_runs_in_a_page_render(): void {
		Emails::schedule();

		$actions = as_get_scheduled_actions(
			array(
				'hook'   => Emails::USAGE_HOOK,
				'group'  => Jobs::GROUP,
				'status' => \ActionScheduler_Store::STATUS_PENDING,
			)
		);
		$this->assertCount( 1, $actions );
		$schedule = reset( $actions )->get_schedule();
		$this->assertInstanceOf( \ActionScheduler_CronSchedule::class, $schedule );
		$this->assertSame( '0 6 * * *', $schedule->get_recurrence() );
		$this->assertSame( 10, has_action( Emails::USAGE_HOOK, array( Emails::class, 'usage' ) ) );

		Emails::schedule();
		$this->assertCount( 1, as_get_scheduled_actions( array( 'hook' => Emails::USAGE_HOOK, 'status' => \ActionScheduler_Store::STATUS_PENDING ) ), 'Scheduled once.' );

		// Rendering the account panel makes no request.
		$this->connection();
		$resource = $this->http_resource();
		ob_start();
		Registry::get( 'http' )->render_account( $resource );
		ob_end_clean();
		$this->assertSame( array(), $this->http_calls() );
	}

	/**
	 * Sets beltoft-licenses' import flag.
	 *
	 * @param bool $running Running.
	 */
	private function set_import_running( bool $running ): void {
		$flag = new \ReflectionProperty( \BeltoftLicenses\Import::class, 'running' );
		$flag->setAccessible( true );
		$flag->setValue( null, $running );
	}

	public function test_usage_does_nothing_while_driver_activity_is_paused(): void {
		$this->connection();
		$this->http_resource();
		$this->usage_is( 9 );
		Emails::schedule();
		$this->settings( array( 'pause_all' => true ) );

		Emails::usage();

		$this->assertSame( array(), $this->http_calls(), 'No request while the kill switch is on.' );
		$this->assertSame( array(), $this->customer_mails() );
		$this->assertNotEmpty( as_get_scheduled_actions( array( 'hook' => Emails::USAGE_HOOK, 'status' => \ActionScheduler_Store::STATUS_PENDING ) ), 'The schedule stays.' );

		$this->settings( array( 'pause_all' => false ) );
		Emails::usage();
		$this->assertCount( 1, $this->customer_mails( 'limit' ) );
	}

	public function test_no_email_while_licenses_import(): void {
		$this->fake_driver();
		$product  = $this->service_product( 'fake' );
		$license  = $this->service_license( $product, array( 'fire' => false ) );
		$resource = Resource::create( $license, Registry::get( 'fake' ), array(), array( 'state' => 'ready' ) );

		$this->set_import_running( true );
		Emails::on_state_changed( Resource::find( $resource->id() ), 'provisioning', 'ready' );
		Emails::on_state_changed( Resource::find( $resource->id() ), 'ready', 'paused' );
		do_action( Retention::WARNING_HOOK, Resource::find( $resource->id() ), time() + DAY_IN_SECONDS );

		$this->assertSame( array(), $this->customer_mails() );
		$this->assertSame( null, Resource::find( $resource->id() )->get( 'notified' ), 'Nothing is recorded as told.' );

		$this->set_import_running( false );
		Emails::on_state_changed( Resource::find( $resource->id() ), 'provisioning', 'ready' );
		$this->assertCount( 1, $this->customer_mails( 'ready' ) );
	}

	public function test_trigger_restores_the_locale_when_sending_throws(): void {
		$this->fake_driver();
		$product  = $this->service_product( 'fake' );
		$license  = $this->service_license( $product, array( 'fire' => false ) );
		$resource = Resource::create( $license, Registry::get( 'fake' ), array(), array( 'state' => 'ready' ) );

		$restored = 0;
		add_filter(
			'woocommerce_email_restore_locale',
			static function ( $restore ) use ( &$restored ) {
				++$restored;
				return $restore;
			}
		);
		add_filter(
			'woocommerce_email_recipient_beltoft_services_ready',
			static function () {
				throw new \RuntimeException( 'Recipient lookup failed' );
			}
		);

		try {
			Emails::get( 'beltoft_services_ready' )->trigger( $resource );
			$this->fail( 'The exception reaches the caller.' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'Recipient lookup failed', $e->getMessage() );
		}

		$this->assertSame( 1, $restored, 'The locale is restored.' );
	}
}
