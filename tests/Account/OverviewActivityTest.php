<?php
/** The activity log of the Overview and its REST route. @package BeltoftServices */
namespace BeltoftServices\Tests\Account;

use BeltoftServices\Account\ActivityMap;
use BeltoftServices\Account\Overview;
use BeltoftServices\Account\OverviewActivity;
use BeltoftServices\Resource;
use BeltoftServices\Tests\Proxmox\Network\MonitoringTestCase;

require_once dirname( __DIR__ ) . '/Proxmox/Network/MonitoringTestCase.php';

class OverviewActivityTest extends MonitoringTestCase {
	public function set_up(): void {
		parent::set_up();
		Overview::reset();

		global $wp_rest_server;
		$wp_rest_server = new \WP_REST_Server();
		do_action( 'rest_api_init', $wp_rest_server );
	}

	public function tear_down(): void {
		global $wp_rest_server;
		$wp_rest_server = null;
		Overview::reset();
		delete_option( 'timezone_string' );
		delete_option( 'date_format' );
		delete_option( 'time_format' );
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	private function as_user( int $user_id ): void {
		Overview::reset();
		wp_set_current_user( $user_id );
	}

	private function server( int $user_id, string $name = 'web-01' ): Resource {
		static $n = 0;
		$r = $this->ready_vm( 5000 + ( $n++ % 10 ) );

		global $wpdb;
		$wpdb->update( \BeltoftLicenses\Install::tables()['licenses'], array( 'user_id' => $user_id ), array( 'id' => (int) $r->license()['id'] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Test fixture.
		$r->set( array( 'server_name' => $name, 'ip' => '192.0.2.10' ) );

		return Resource::find( $r->id() );
	}

	private function license_id( Resource $r ): int {
		return (int) $r->license()['id'];
	}

	/** Writes a history event; the events are added oldest first so the ids follow the time. */
	private function event( int $license, string $message, int $ago = 0, string $type = 'service' ): int {
		global $wpdb;
		$wpdb->insert( \BeltoftLicenses\Install::tables()['events'], array( 'license_id' => $license, 'type' => $type, 'message' => $message, 'created_at' => gmdate( 'Y-m-d H:i:s', time() - $ago ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Test fixture.

		return (int) $wpdb->insert_id;
	}

	private function get( string $query = '' ): \WP_REST_Response {
		$request = new \WP_REST_Request( 'GET', '/beltoft-services/v1/activity' );

		foreach ( wp_parse_args( $query ) as $key => $value ) {
			$request->set_param( $key, $value );
		}

		return rest_get_server()->dispatch( $request );
	}

	private function texts( array $data ): array {
		$out = array();

		foreach ( $data['days'] as $day ) {
			foreach ( $day['lines'] as $line ) {
				$out[] = $line['text'];
			}
		}

		return $out;
	}

	// -- The map -------------------------------------------------------------------------------

	public function test_every_listed_text_gives_its_sentence(): void {
		$expected = array(
			'Service provisioned'                                    => 'Set up and ready',
			'Service resumed'                                        => 'Resumed',
			'Customer shut the server down'                          => 'Server shut down',
			'Customer started the server'                            => 'Server started',
			'Customer restarted the server'                          => 'Server restarted',
			'Customer renamed the server; the hostname changes at the next boot' => 'Server renamed',
			'Console opened'                                         => 'Console opened',
			'Private network attached'                               => 'Private network attached',
			'Private network detached'                               => 'Private network detached',
			'Customer deleted the service'                           => 'Deleted',
			'The virtual machine was deleted'                        => 'Deleted',
			'Customer action "backup_now" done'                      => 'Backup taken',
			'Customer action "mailbox_password" done'                => 'Mailbox password changed',
			'Customer action "mailbox_create" done'                  => 'Mailbox made',
			'Email domain ready'                                     => 'Email domain ready',
			'Customer took the snapshot "deploy"'                    => 'Snapshot "deploy" taken',
			'Snapshot "deploy" deleted'                              => 'Snapshot "deploy" deleted',
			'Server rolled back to the snapshot "deploy" of 2026-10-01 10:00 UTC' => 'Went back to snapshot "deploy"',
			'Server restored from a backup'                          => 'Restored from a backup',
			'Reinstall started by the customer'                      => 'Reinstall started',
			'Monitoring email: server down'                          => 'Alert email sent: server down',
		);

		foreach ( $expected as $message => $sentence ) {
			$said = ActivityMap::sentence( $message );
			$this->assertNotNull( $said, $message );
			$this->assertSame( $sentence, $said['text'], $message );
		}

		$this->assertSame( 'Paused', ActivityMap::sentence( 'Service paused', ActivityMap::HELD )['text'] );
		$this->assertSame( 'Paused after a failed payment', ActivityMap::sentence( 'Service paused', ActivityMap::FAILED )['text'] );
		$this->assertNull( ActivityMap::sentence( 'Service paused' ), 'A pause that is not a held subscription is not shown.' );
	}

	public function test_the_internal_texts_and_the_unlisted_ones_give_nothing(): void {
		foreach ( array(
			'Provisioning started',
			'Deletion started',
			'License ended with the deletion; it is not renewed or made again',
			'Shutdown: the server was already off',
			'Start: the server was already running',
			'Customer renamed the server (already named so)',
			'Customer action "backup_now" failed: The node is busy.',
			'Customer action "refresh" done',
			'Hosting change "resend_country" done',
			'Hosting change "set_php" failed: agent 10.0.0.5',
			'Abuse suspected: cpu 98% for 2 hours',
			'Breach mark cleared by admin',
			'Linked to existing VM 201 on lab by WP-CLI (address 192.0.2.5).',
			'Plan changed on a linked VM; it was not applied.',
			'Retry requested by admin',
			'Service failed: The cluster is not available.',
			'License active',
			'Snapshot "x" quota check',
			'Customer took the snapshot',
			'',
			'Customer deleted the service and more',
		) as $message ) {
			$this->assertNull( ActivityMap::sentence( $message ), $message );
		}
	}

	public function test_a_backup_date_is_in_the_site_date_format_and_time_zone(): void {
		update_option( 'timezone_string', 'Europe/Copenhagen' );
		update_option( 'date_format', 'j F Y' );
		update_option( 'time_format', 'H:i' );

		$this->assertSame( 'Backup of 17 November 2026 16:12 deleted', ActivityMap::sentence( 'Backup of 2026-11-17 15:12 UTC deleted at the customer\'s request' )['text'] );
		$this->assertSame( 'Restored from the backup of 17 November 2026 16:12', ActivityMap::sentence( 'Server restored from the backup of 2026-11-17 15:12 UTC' )['text'] );
	}

	public function test_a_value_in_a_text_stays_text_and_is_escaped_where_it_is_printed(): void {
		$user = self::factory()->user->create();
		$id   = $this->license_id( $this->server( $user ) );
		$this->event( $id, 'Customer took the snapshot "<b>new</b>"', 60 );
		$this->as_user( $user );

		$html = Overview::section( 'activity' );

		$this->assertStringContainsString( 'Snapshot &quot;&lt;b&gt;new&lt;/b&gt;&quot; taken', $html );
		$this->assertStringNotContainsString( '<b>new</b>', $html );
	}

	// -- Names ---------------------------------------------------------------------------------

	public function test_a_line_names_the_service_by_its_own_name_and_links_to_a_living_one(): void {
		$user = self::factory()->user->create();
		$one  = $this->server( $user, 'web-01' );
		$two  = $this->server( $user, 'db-02' );
		$this->event( $this->license_id( $one ), 'Console opened', 120 );
		$this->event( $this->license_id( $two ), 'Console opened', 60 );
		$this->as_user( $user );

		$html = Overview::section( 'activity' );

		$this->assertStringContainsString( 'web-01', $html );
		$this->assertStringContainsString( 'db-02', $html );
		$this->assertSame( 2, substr_count( $html, 'Console opened' ), 'Two services are two lines, never one.' );
		$this->assertMatchesRegularExpression( '#<a class="beltoft-services-ov__who" href="[^"]+">db-02</a>#', $html );
	}

	public function test_a_deleted_service_keeps_its_last_name_and_is_not_a_link(): void {
		$user = self::factory()->user->create();
		$r    = $this->server( $user, 'gone-01' );
		$id   = $this->license_id( $r );
		$this->event( $id, 'Customer deleted the service', 30 );
		$r->close();
		$this->as_user( $user );

		$html = Overview::section( 'activity' );

		$this->assertStringContainsString( '<span class="beltoft-services-ov__who">gone-01</span>', $html );
		$this->assertStringNotContainsString( '>gone-01</a>', $html );
		$this->assertStringContainsString( 'Deleted', $html );
	}

	public function test_a_deletion_is_one_line_however_many_texts_it_wrote(): void {
		$user = self::factory()->user->create();
		$id   = $this->license_id( $this->server( $user ) );
		$this->event( $id, 'Customer deleted the service', 400 );
		$this->event( $id, 'License ended with the deletion; it is not renewed or made again', 399 );
		$this->event( $id, 'Deletion started', 390 );
		$this->event( $id, 'The virtual machine was deleted', 300 );
		$this->as_user( $user );

		$data = OverviewActivity::feed( $user );

		$this->assertSame( array( 'Deleted' ), $this->texts( $data ) );
	}

	// -- Days and counts -----------------------------------------------------------------------

	public function test_lines_are_grouped_by_day_with_today_and_yesterday(): void {
		$user = self::factory()->user->create();
		$id   = $this->license_id( $this->server( $user ) );
		$this->event( $id, 'Console opened', 5 * DAY_IN_SECONDS );
		$this->event( $id, 'Customer started the server', DAY_IN_SECONDS + 30 );
		$this->event( $id, 'Customer restarted the server', 20 );

		$days = OverviewActivity::feed( $user )['days'];

		$this->assertCount( 3, $days );
		$this->assertSame( 'Today', $days[0]['label'] );
		$this->assertSame( 'Yesterday', $days[1]['label'] );
		$this->assertSame( wp_date( get_option( 'date_format' ), time() - 5 * DAY_IN_SECONDS ), $days[2]['label'] );

		$this->as_user( $user );
		$html = Overview::section( 'activity' );

		$this->assertStringContainsString( '<h3 class="beltoft-services-ov__day">Today</h3>', $html );
		$this->assertMatchesRegularExpression( '#<time datetime="\d{4}-\d{2}-\d{2}T[^"]+">\d{2}:\d{2}</time>#', $html );
	}

	public function test_a_run_of_the_same_sentence_for_one_service_on_one_day_is_one_line_with_a_count(): void {
		$user = self::factory()->user->create();
		$one  = $this->license_id( $this->server( $user, 'web-01' ) );
		$two  = $this->license_id( $this->server( $user, 'db-02' ) );
		$this->event( $one, 'Console opened', 50 );
		$this->event( $two, 'Console opened', 45 );
		$this->event( $one, 'Console opened', 40 );
		$this->event( $one, 'Console opened', 30 );

		$days  = OverviewActivity::feed( $user )['days'];
		$lines = $days[0]['lines'];

		$this->assertCount( 2, $lines );
		$by = array_column( $lines, 'sentence', 'name' );
		$this->assertSame( 'Console opened, 3 times', $by['web-01'] );
		$this->assertSame( 'Console opened', $by['db-02'] );
		$this->assertSame( 3, array_column( $lines, 'count', 'name' )['web-01'] );
	}

	public function test_the_same_sentence_on_two_days_is_two_lines(): void {
		$user = self::factory()->user->create();
		$id   = $this->license_id( $this->server( $user ) );
		$this->event( $id, 'Console opened', 2 * DAY_IN_SECONDS );
		$this->event( $id, 'Console opened', 10 );

		$this->assertCount( 2, OverviewActivity::feed( $user )['days'] );
	}

	// -- Pages ---------------------------------------------------------------------------------

	public function test_the_overview_shows_eight_lines_and_a_show_more_button(): void {
		$user = self::factory()->user->create();
		$id   = $this->license_id( $this->server( $user ) );

		for ( $i = 12; $i >= 1; $i-- ) {
			$this->event( $id, 'Customer took the snapshot "s' . $i . '"', $i * 60 );
		}

		$this->as_user( $user );
		$html = Overview::section( 'activity' );

		$this->assertSame( 8, substr_count( $html, '<time ' ) );
		$this->assertStringContainsString( 'bs-btn bs-btn--secondary" data-activity-more', $html );
		$this->assertStringNotContainsString( 'more" hidden', $html );
		$this->assertStringContainsString( 'Show more', $html );
		$this->assertStringContainsString( 'snapshot', strtolower( $html ) );
		$this->assertStringContainsString( 'Snapshot &quot;s1&quot; taken', $html );
		$this->assertStringNotContainsString( '&quot;s9&quot;', $html );
	}

	public function test_a_short_log_has_no_show_more_button_and_a_log_without_lines_is_hidden(): void {
		$user = self::factory()->user->create();
		$id   = $this->license_id( $this->server( $user ) );
		$this->as_user( $user );

		$this->assertSame( '', Overview::section( 'activity' ) );

		$this->event( $id, 'Console opened', 10 );
		$this->as_user( $user );

		$this->assertMatchesRegularExpression( '#<p class="beltoft-services-ov__more" hidden>#', Overview::section( 'activity' ) );
	}

	public function test_the_service_select_is_printed_for_two_services_with_history(): void {
		$user = self::factory()->user->create();
		$one  = $this->license_id( $this->server( $user, 'web-01' ) );
		$this->event( $one, 'Console opened', 10 );
		$this->as_user( $user );

		$this->assertStringNotContainsString( 'data-activity-filter', Overview::section( 'activity' ), 'One service needs no filter.' );

		$two = $this->license_id( $this->server( $user, 'db-02' ) );
		$this->event( $two, 'Console opened', 10 );
		$this->as_user( $user );
		$html = Overview::section( 'activity' );

		$this->assertStringContainsString( 'data-activity-filter', $html );
		$this->assertStringContainsString( 'All services', $html );
		$this->assertStringContainsString( '>db-02</option>', $html );
	}

	public function test_a_text_is_found_behind_many_internal_ones(): void {
		$user = self::factory()->user->create();
		$id   = $this->license_id( $this->server( $user ) );
		$this->event( $id, 'Customer took the snapshot "old"', 900000 );

		for ( $i = 0; $i < 150; $i++ ) {
			$this->event( $id, 'Retry requested by admin ' . $i, 800000 - $i );
		}

		$this->assertSame( array( 'Snapshot "old" taken' ), $this->texts( OverviewActivity::feed( $user ) ) );
	}

	public function test_a_request_reads_at_most_a_bounded_number_of_rows(): void {
		global $wpdb;
		$user = self::factory()->user->create();
		$id   = $this->license_id( $this->server( $user ) );
		$this->event( $id, 'Console opened', 9000000 );
		$table = \BeltoftLicenses\Install::tables()['events'];

		for ( $i = 0; $i < 12; $i++ ) {
			$wpdb->query( $wpdb->prepare( "INSERT INTO %i (license_id, type, message, created_at) SELECT license_id, type, CONCAT('Retry ', id, UUID()), created_at FROM %i WHERE license_id = %d", $table, $table, $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL -- Test fixture: doubles the noise.
		}

		$page = OverviewActivity::page( OverviewActivity::licenses_of( $user ), 0, 8 );

		$this->assertSame( array(), $page['lines'], 'The old line is out of reach of one request.' );
		$this->assertGreaterThan( 0, $page['next'], 'The cursor lets the next request go on.' );
	}

	// -- The route -----------------------------------------------------------------------------

	public function test_the_route_needs_a_signed_in_customer(): void {
		wp_set_current_user( 0 );

		$this->assertSame( 401, $this->get()->get_status() );
	}

	public function test_the_route_pages_with_a_cursor_newest_first_without_overlap(): void {
		$user = self::factory()->user->create();
		$id   = $this->license_id( $this->server( $user ) );

		for ( $i = 12; $i >= 1; $i-- ) {
			$this->event( $id, 'Customer took the snapshot "s' . $i . '"', $i * 60 );
		}

		wp_set_current_user( $user );
		$first = $this->get( 'limit=5' )->get_data();

		$this->assertSame( array( 'Snapshot "s1" taken', 'Snapshot "s2" taken', 'Snapshot "s3" taken', 'Snapshot "s4" taken', 'Snapshot "s5" taken' ), $this->texts( $first ) );
		$this->assertGreaterThan( 0, $first['next'] );
		$this->assertArrayHasKey( 'services', $first );

		$second = $this->get( 'limit=5&before=' . $first['next'] )->get_data();

		$this->assertSame( array( 'Snapshot "s6" taken', 'Snapshot "s7" taken', 'Snapshot "s8" taken', 'Snapshot "s9" taken', 'Snapshot "s10" taken' ), $this->texts( $second ) );
		$this->assertArrayNotHasKey( 'services', $second );

		$third = $this->get( 'limit=5&before=' . $second['next'] )->get_data();

		$this->assertSame( array( 'Snapshot "s11" taken', 'Snapshot "s12" taken' ), $this->texts( $third ) );
		$this->assertSame( 0, $third['next'], 'The end of the history.' );
	}

	public function test_the_route_gives_only_the_own_licences_ended_ones_too(): void {
		$mine  = self::factory()->user->create();
		$other = self::factory()->user->create();
		$live  = $this->server( $mine, 'web-01' );
		$gone  = $this->server( $mine, 'gone-01' );
		$theirs = $this->server( $other, 'other-01' );
		$this->event( $this->license_id( $live ), 'Console opened', 30 );
		$this->event( $this->license_id( $gone ), 'Customer deleted the service', 20 );
		$this->event( $this->license_id( $theirs ), 'Customer took the snapshot "theirs"', 10 );
		$gone->close();

		wp_set_current_user( $mine );
		$data = $this->get()->get_data();
		$json = wp_json_encode( $data );

		$this->assertSame( array( 'Deleted', 'Console opened' ), $this->texts( $data ) );
		$this->assertStringNotContainsString( 'other-01', $json );
		$this->assertStringNotContainsString( 'theirs', $json );
		$this->assertStringContainsString( 'gone-01', $json );
		$this->assertSame( '', $data['days'][0]['lines'][0]['url'], 'An ended service is not a link.' );
	}

	public function test_a_licence_of_another_customer_is_refused_and_a_filter_narrows_to_one_service(): void {
		$mine   = self::factory()->user->create();
		$other  = self::factory()->user->create();
		$one    = $this->server( $mine, 'web-01' );
		$two    = $this->server( $mine, 'db-02' );
		$theirs = $this->server( $other, 'other-01' );
		$this->event( $this->license_id( $one ), 'Console opened', 30 );
		$this->event( $this->license_id( $two ), 'Customer started the server', 20 );
		$this->event( $this->license_id( $theirs ), 'Console opened', 10 );

		wp_set_current_user( $mine );

		$this->assertSame( 403, $this->get( 'service=' . $this->license_id( $theirs ) )->get_status() );
		$this->assertSame( 403, $this->get( 'service=999999' )->get_status() );
		$this->assertSame( array( 'Server started' ), $this->texts( $this->get( 'service=' . $this->license_id( $two ) )->get_data() ) );
	}

	public function test_the_route_is_rate_limited_like_the_other_reads(): void {
		$user = self::factory()->user->create();
		$this->server( $user );
		wp_set_current_user( $user );

		$last = 200;

		for ( $i = 0; $i < 25; $i++ ) {
			$last = $this->get()->get_status();
		}

		$this->assertSame( 429, $last );
	}

	// -- No raw history text -------------------------------------------------------------------

	public function test_no_raw_history_text_reaches_the_page_or_the_route(): void {
		$user = self::factory()->user->create();
		$id   = $this->license_id( $this->server( $user ) );
		$this->event( $id, 'Abuse suspected: secret admin text 192.0.2.77', 90 );
		$this->event( $id, 'Customer action "reboot" failed: The cluster node-7 is not available.', 80 );
		$this->event( $id, 'Customer started the server', 70 );
		$this->event( $id, 'Hosting change "set_php" done', 60 );
		$this->as_user( $user );

		$html = Overview::section( 'activity' );
		$json = wp_json_encode( $this->get()->get_data() );

		foreach ( array( $html, $json ) as $out ) {
			$this->assertStringNotContainsString( 'secret admin text', $out );
			$this->assertStringNotContainsString( '192.0.2.77', $out );
			$this->assertStringNotContainsString( 'node-7', $out );
			$this->assertStringNotContainsString( 'Customer started the server', $out );
			$this->assertStringNotContainsString( 'Hosting change', $out );
			$this->assertStringContainsString( 'Server started', $out );
			// The sentence of an optional feature (hosting) shows when that feature is there.
			if ( $this->optional( 'area_slugs' ) ) {
				$this->assertStringContainsString( 'PHP version changed', $out );
			}
		}
	}

	// -- Pauses and backups --------------------------------------------------------------------

	private function subscription( int $user_id, string $status ): \WC_Subscription {
		$sub = wcs_create_subscription( array( 'customer_id' => $user_id, 'billing_period' => 'month', 'billing_interval' => 1, 'status' => $status ) );
		$this->assertInstanceOf( \WC_Subscription::class, $sub );
		$product = new \WC_Product_Simple();
		$product->set_name( 'Server' );
		$product->set_regular_price( '10.00' );
		$product->save();
		$sub->add_product( $product, 1 );
		$sub->set_requires_manual_renewal( true );
		$sub->calculate_totals();
		$sub->save();

		return $sub;
	}

	public function test_a_pause_is_shown_only_while_the_subscription_is_on_hold_and_says_when_a_payment_failed(): void {
		global $wpdb;
		$user = self::factory()->user->create();
		$sub  = $this->subscription( $user, 'on-hold' );
		$id   = $this->license_id( $this->server( $user ) );
		$wpdb->update( \BeltoftLicenses\Install::tables()['licenses'], array( 'subscription_id' => $sub->get_id() ), array( 'id' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Test fixture.
		$this->event( $id, 'Service paused', 3600 );

		$this->as_user( $user );
		$this->assertSame( array( 'Paused' ), $this->texts( OverviewActivity::feed( $user ) ) );

		$bad = wc_create_order( array( 'customer_id' => $user ) );
		$bad->set_status( 'failed' );
		$bad->update_meta_data( '_subscription_renewal', $sub->get_id() );
		$bad->save();
		$this->as_user( $user );
		OverviewActivity::reset();

		$this->assertSame( array( 'Paused after a failed payment' ), $this->texts( OverviewActivity::feed( $user ) ) );

		$sub->update_status( 'active' );
		$this->as_user( $user );
		OverviewActivity::reset();

		$this->assertSame( array(), $this->texts( OverviewActivity::feed( $user ) ) );
	}

	public function test_the_failed_renewal_is_looked_up_once_per_subscription(): void {
		global $wpdb;
		$user = self::factory()->user->create();
		$sub  = $this->subscription( $user, 'on-hold' );
		$id   = $this->license_id( $this->server( $user ) );
		$wpdb->update( \BeltoftLicenses\Install::tables()['licenses'], array( 'subscription_id' => $sub->get_id() ), array( 'id' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Test fixture.

		foreach ( array( 3900, 3800, 3700, 3600 ) as $ago ) {
			$this->event( $id, 'Service paused', $ago );
		}

		$calls = 0;
		add_filter(
			'woocommerce_subscription_last_order',
			static function ( $order ) use ( &$calls ) {
				++$calls;
				return $order;
			}
		);
		$this->as_user( $user );
		OverviewActivity::feed( $user );
		remove_all_filters( 'woocommerce_subscription_last_order' );

		$this->assertLessThanOrEqual( 1, $calls );
	}

	public function test_the_nightly_backup_is_a_line_unless_the_customer_just_took_one(): void {
		$user = self::factory()->user->create();
		$r    = $this->server( $user );
		$r->set( array( 'backup_ok_at' => time() - 3600 ) );
		$this->as_user( $user );

		$this->assertSame( array( 'Backup taken' ), $this->texts( OverviewActivity::feed( $user ) ) );

		$this->event( $this->license_id( $r ), 'Customer action "backup_now" done', 3590 );
		$this->as_user( $user );

		$this->assertSame( array( 'Backup taken' ), $this->texts( OverviewActivity::feed( $user ) ), 'One line, not two.' );
	}
}
