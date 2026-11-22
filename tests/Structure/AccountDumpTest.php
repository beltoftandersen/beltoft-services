<?php
/**
 * Helper for the My Account code move (plan 2026-11-22-feature-code-home): dumps what a customer sees, to files, so the tree before
 * and the tree after can be compared with diff. Runs only with SURFACE_DUMP=<directory> (use a fresh database, so ids are the same).
 *
 * Writes account-menu.txt (menu items in order with counts, tab groups), account-areas.txt (each area's labels, list HTML and service
 * pages), account-panel.txt (badge group, label and message per state and kind), account-slugs.txt (slug names) and
 * account-activity.txt (activity sentences). Class names that moved are never printed.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Structure;

use BeltoftLicenses\Account\Endpoint;
use BeltoftLicenses\Account\View;
use BeltoftServices\Account\ActivityMap;
use BeltoftServices\Account\MenuCounts;
use BeltoftServices\Account\Overview;
use BeltoftServices\Account\Panel;
use BeltoftServices\Account\ServiceArea;
use BeltoftServices\Account\Slugs;
use BeltoftServices\Drivers\Registry;
use BeltoftServices\Product;
use BeltoftServices\Resource;
use BeltoftServices\Tests\TestCase;

/**
 * Not an assertion test.
 */
final class AccountDumpTest extends TestCase {

	/**
	 * Resources of the full customer: label => resource.
	 *
	 * @var array<string, Resource>
	 */
	private array $made = array();

	private int $user = 0;

	private function dir(): string {
		$dir = (string) getenv( 'SURFACE_DUMP' );

		if ( '' === $dir ) {
			$this->markTestSkipped( 'Only runs with SURFACE_DUMP=<directory>.' );
		}

		if ( ! is_dir( $dir ) ) {
			mkdir( $dir, 0777, true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir
		}

		return rtrim( $dir, '/' );
	}

	private function put( string $dir, string $name, string $text ): void {
		$text = (string) preg_replace( '/\bservice[- ][a-z0-9]{6}\b/i', 'SERVICE', $text );
		$text = (string) preg_replace( '/\bvm-[0-9a-f]{8}\b/', 'VMNAME', $text );

		$this->assertNotFalse( file_put_contents( $dir . '/' . $name, $text ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
	}

	/**
	 * One service of a driver kind for the full customer.
	 *
	 * @param string               $label Label in the dump.
	 * @param string               $kind  Driver kind.
	 * @param array<string, mixed> $meta  Resource meta.
	 * @param string               $state Resource state.
	 */
	private function make( string $label, string $kind, array $meta = array(), string $state = 'ready', string $id = '' ): void {
		$driver = null;

		foreach ( Registry::all() as $candidate ) {
			if ( $candidate->kind() === $kind && ( '' === $id || $candidate->id() === $id ) ) {
				$driver = $candidate;
				break;
			}
		}

		if ( null === $driver ) {
			$this->fake_driver( $kind );
			$driver = Registry::get( $kind );
		}

		$slug     = 'dump-' . $label;
		$product  = $this->service_product( $driver->id(), array(), array( 'slug' => $slug ) );
		$license  = $this->service_license( $product, array( 'fire' => false, 'user_id' => $this->user ) );
		$resource = Resource::create( $license, $driver, Product::plan( $product->get_id() ), $meta );

		if ( ! $resource instanceof Resource ) {
			return;
		}

		$resource->set( array( 'state' => $state, 'desired' => 'ready' ) );
		$this->made[ $label ] = Resource::find( $resource->id() );
	}

	private function normalise( string $text ): string {
		$text = (string) preg_replace( '/(nonce[^"\']{0,40}["\'=: ]+)[0-9a-f]{10}\b/i', '$1NONCE', $text );
		$text = (string) preg_replace( '/_wpnonce=[0-9a-f]{10}/', '_wpnonce=NONCE', $text );
		$text = (string) preg_replace( '/\b\d{4}-\d{2}-\d{2}( \d{2}:\d{2}(:\d{2})?)?\b/', 'DATE', $text );
		$text = (string) preg_replace( '/\b\d{1,2}[ .]?(Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Oct|Nov|Dec)[a-z]*\.? \d{4}\b/i', 'DATE', $text );
		$text = (string) preg_replace( '/\b(Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Oct|Nov|Dec)[a-z]* \d{1,2}, \d{4}\b/i', 'DATE', $text );
		$text = (string) preg_replace( '/\b\d+ (second|minute|hour|day|week|month)s?( ago)?\b/', 'AGO', $text );
		$text = (string) preg_replace( '/\bservice[- ][a-z0-9]{6}\b/i', 'SERVICE', $text );
		$text = (string) preg_replace( '/\s+/', ' ', $text );

		return trim( (string) preg_replace( '/> </', ">\n<", $text ) );
	}

	/**
	 * Output of a callback, or the message of what it threw.
	 *
	 * @param callable $fn Callback printing HTML.
	 */
	private function capture( callable $fn ): string {
		ob_start();

		try {
			$fn();
			$out = (string) ob_get_clean();
		} catch ( \Throwable $e ) {
			ob_end_clean();
			$out = 'THROWN ' . $e->getMessage();
		}

		return $this->normalise( $out );
	}

	private function set_up_services(): void {
		$key = base64_encode( random_bytes( 32 ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Test key.
		add_filter( 'beltoft_services_key', static fn() => $key );
		$this->settings( array( 'domain_nameservers' => 'ns1.example.net,ns2.example.net', 'domain_dns_claims_enabled' => 0, 'domain_search_url' => '' ) );
		$this->user = self::factory()->user->create( array( 'user_login' => 'dumpfull' ) );
		wp_set_current_user( $this->user );

		$apps   = \BeltoftServices\Apps\Apps::ids();
		$app_id = $apps ? (string) reset( $apps ) : '';

		$this->make( 'domain', 'domain', array( 'domain' => 'dump.example', 'mode' => 'register', 'zone_ready' => true ) );
		$this->make( 'domain-pending', 'domain', array( 'domain' => 'pending.example', 'mode' => 'transfer' ), 'provisioning' );
		$this->make( 'dns', 'dns', array( 'domain' => 'zone.example', 'mode' => 'dns', 'zone_ready' => true ) );
		$this->make( 'vm', 'vm', array( 'hostname' => 'dumpvm', 'vmid' => 501 ) );
		$this->make( 'vm-new', 'vm', array( 'hostname' => 'newvm' ), 'requested' );
		$this->make( 'vm-app', 'vm', array( 'hostname' => 'appvm', 'vmid' => 502, 'app_id' => $app_id, 'app_state' => 'installing' ) );
		$this->make( 'db', 'db', array( 'db_state' => 'ready', 'db_address' => '10.64.0.2' ) );
		$this->make( 'db-failed', 'db', array(), 'failed' );
		$this->make( 'hosting', 'hosting', array() );
		$this->make( 'http', 'http', array() );
		$this->make( 'mail', 'mail', array( 'mail_domain' => 'mail.dump.example' ) );
		$this->make( 'mail-wait', 'mail', array( 'mail_domain' => 'wait.dump.example', 'mail_wait_until' => time() + 100000 ), 'requested' );
		$this->make( 'ssl', 'ssl', array( 'ssl_domain' => 'dump.example' ) );
		$this->make( 'lickey', 'lickey', array() );
		$this->make( 'storage', 'storage', array() );
		$this->make( 'dedicated', 'dedicated', array() );
		$this->make( 'paused', 'vm', array( 'hostname' => 'pausedvm' ), 'paused' );
		$this->make( 'deleted', 'db', array(), 'deleted' );
	}

	private function menu_text(): string {
		$lines = array();
		$base  = array(
			'dashboard'       => 'Dashboard',
			'orders'          => 'Orders',
			'downloads'       => 'Downloads',
			'edit-address'    => 'Addresses',
			'edit-account'    => 'Account details',
			'customer-logout' => 'Log out',
		);

		foreach ( array( $this->user, 0 ) as $user ) {
			wp_set_current_user( $user );
			MenuCounts::reset();
			Overview::reset();
			$items = apply_filters( 'woocommerce_account_menu_items', $base, array() );

			foreach ( (array) $items as $key => $label ) {
				$lines[] = 'user=' . ( $user ? 'full' : 'guest' ) . ' item ' . $key . ' | ' . wp_strip_all_tags( (string) $label ) . ' | count=' . MenuCounts::current( (string) $key );
			}

			foreach ( (array) apply_filters( 'beltoft_licenses_account_groups', array() ) as $group ) {
				$lines[] = 'user=' . ( $user ? 'full' : 'guest' ) . ' group ' . (string) ( $group['slug'] ?? '' ) . ' | ' . (string) ( $group['label'] ?? '' ) . ' | ' . (string) ( $group['empty'] ?? '' );
			}
		}

		wp_set_current_user( $this->user );

		return implode( "\n", $lines ) . "\n";
	}

	/**
	 * @param callable $fn Callback.
	 */
	private function area_page( ServiceArea $area, array $views, string $value ): string {
		$GLOBALS['wp']->query_vars[ $area->slug() ] = $value;

		return $this->capture( static fn() => $area->render( $views ) );
	}

	private function areas_text(): string {
		$out      = array();
		$licenses = Endpoint::licenses_for_user( $this->user );
		$other    = self::factory()->user->create( array( 'user_login' => 'dumpnone' ) );

		foreach ( ServiceArea::all() as $area ) {
			$key   = $area->key_name();
			$out[] = '== area ' . $key . ' slug=' . $area->slug() . ' label=' . $area->label() . ' all=' . $area->all_label() . ' empty=' . $area->empty_line();
			$out[] = 'group ' . wp_json_encode( array( $area->group()['slug'], $area->group()['label'], $area->group()['empty'] ) );
			$out[] = 'buttons ' . wp_json_encode( $area->buttons() );
			$out[] = 'tabs ' . wp_json_encode( $area->tabs() );
			$out[] = 'url_list ' . $area->url_list();

			$members = array_values( array_filter( $licenses, static fn( $l ) => $area->is_member( $l ) ) );
			$views   = array_map( static fn( array $l ): array => View::license( $l ), $members );

			$out[] = '-- list';
			$out[] = $this->area_page( $area, $views, '' );

			foreach ( $members as $license ) {
				$out[] = '-- page license ' . $license['id'] . ' url=' . $area->url( $license );

				foreach ( array_merge( array( '' ), $area->tabs() ) as $tab ) {
					$value = (string) $license['id'] . ( '' === $tab ? '' : '/' . $tab );
					$out[] = '-- tab ' . $tab;
					$out[] = $this->area_page( $area, $views, $value );
				}
			}

			wp_set_current_user( $other );
			$out[] = '-- empty list';
			$out[] = $this->area_page( $area, array(), '' );
			wp_set_current_user( $this->user );
			unset( $GLOBALS['wp']->query_vars[ $area->slug() ] );
		}

		// The two picker pages and the server list, under the names the theme calls.
		$servers = class_exists( '\BeltoftServices\Account\Servers' ) ? '\BeltoftServices\Account\Servers' : '';

		if ( $servers ) {
			$views = array_map( static fn( array $l ): array => View::license( $l ), array_values( array_filter( $licenses, static fn( $l ) => $servers::is_server( $l ) ) ) );
			$out[] = '== servers list';
			$out[] = $this->capture( static fn() => $servers::render( $views ) );
			$out[] = 'sort ' . wp_json_encode( array_map( 'count', $servers::sort( $views ) ) );
			$out[] = 'buttons ' . wp_json_encode( $servers::buttons() );
			$out[] = 'slug ' . $servers::slug() . ' url_list ' . $servers::url_list() . ' url_new ' . $servers::url_new();
		}

		foreach ( array( '\BeltoftServices\Proxmox\Db\DbPicker', '\BeltoftServices\Account\DbPicker' ) as $picker ) {
			if ( class_exists( $picker ) ) {
				$out[] = '== db picker ' . $picker::url();
				$out[] = $this->capture( static fn() => $picker::render() );
				break;
			}
		}

		$picker = '\BeltoftServices\Account\ServerPicker';
		$out[]  = '== server picker ' . $picker::url() . ' ' . $picker::FIELD . ' ' . $picker::NONCE . ' ' . $picker::prefill_url( 0, array() );
		$out[]  = $this->capture( static fn() => $picker::render() );

		Overview::reset();
		$out[] = '== overview';
		$out[] = $this->capture( static fn() => print( Overview::all() ) );

		foreach ( array( 'databases', 'hosting', 'apps', 'mail', 'domains', 'servers', 'licenses', 'activity' ) as $section ) {
			Overview::reset();
			$out[] = '== overview section ' . $section;
			$out[] = $this->normalise( Overview::section( $section ) );
		}

		return implode( "\n", $out ) . "\n";
	}

	private function panel_text(): string {
		$out    = array();
		$states = array( 'ready', 'failed', 'paused', 'deleting', 'deleted', 'requested', 'provisioning', '', 'bogus' );

		foreach ( $states as $state ) {
			$out[] = 'null ' . $state . ' ' . wp_json_encode( array( Panel::group( $state ), Panel::label( $state ), Panel::message( $state ) ) );
		}

		foreach ( $this->made as $label => $r ) {
			foreach ( $states as $state ) {
				$out[] = $label . ' ' . $state . ' ' . wp_json_encode( array( Panel::group( $state, $r ), Panel::label( $state, $r ), Panel::message( $state, $r ) ) );
			}

			$out[] = $label . ' own ' . wp_json_encode( array( Panel::group( (string) $r->get( 'state' ), $r ), Panel::label( (string) $r->get( 'state' ), $r ), Panel::message( (string) $r->get( 'state' ), $r ) ) );
		}

		return implode( "\n", $out ) . "\n";
	}

	private function slugs_text(): string {
		$out = array();

		foreach ( $this->made as $label => $r ) {
			$license = (array) $r->license();
			$type    = Slugs::type_of( $license );
			$out[]   = $label . ' type=' . $type . ' name=' . Slugs::name_of( $r, $license, $type ) . ' slug=' . Slugs::for_license( $license ) . ' value=' . Slugs::value( $license, 'dns' );
		}

		foreach ( array( 'domains', 'email', 'ssl', 'servers', 'databases', 'apps', 'licences', 'hosting' ) as $type ) {
			foreach ( array( 'Foo.Example.com', 'my_server 1', 'a.b', 'xn--bcher-kva.example' ) as $name ) {
				$out[] = 'slugify ' . $type . ' ' . $name . ' => ' . Slugs::slugify( $name, $type );
			}

			foreach ( array( 'new', 'add', 'dns', '123', 'plain' ) as $slug ) {
				$out[] = 'reserved ' . $type . ' ' . $slug . ' => ' . wp_json_encode( Slugs::reserved( $slug, $type ) );
			}

			foreach ( array( 'some.example/dns', 'new', 'add', '', '7/dns' ) as $value ) {
				$GLOBALS['wp']->query_vars['dumpep'] = $value;
				$asked                               = Slugs::parse( 'dumpep', $type );
				$out[]                               = 'parse ' . $type . ' ' . $value . ' ' . wp_json_encode( $asked ) . ' unknown ' . wp_json_encode( Slugs::unknown( $asked ) );
			}
		}

		unset( $GLOBALS['wp']->query_vars['dumpep'] );

		foreach ( array( array( 'new' ), array( 'add' ), array( 'x', 'dns' ), array() ) as $parts ) {
			$out[] = 'segments ' . wp_json_encode( $parts ) . ' ' . wp_json_encode( Slugs::segments( $parts ) );
		}

		return implode( "\n", $out ) . "\n";
	}

	private function activity_text(): string {
		$out      = array();
		$messages = array(
			'Customer took the snapshot "a"',
			'Snapshot "a" deleted',
			'Domain renewed after paid subscription order #5',
			'Deletion warning: the service will be deleted on 2026-01-02 03:04 UTC.',
			'Customer made the disk larger: pay as you go size 40 GB',
			'Customer action "backup_now" done',
			'Customer action "start" done',
			'Customer action "bogus" done',
			'Hosting change "bogus" done',
			'Hosting change "" done',
			'Something else',
		);

		foreach ( array( 'add_site', 'remove_site', 'set_php', 'php_rollback', 'set_workers', 'purge_cache', 'set_country_block', 'names', 'remove_domain', 'set_sftp_password', 'add_ssh_key', 'remove_ssh_key', 'wordpress_address', 'staging_create', 'staging_refresh', 'staging_push', 'staging_undo', 'staging_delete', 'backup_now', 'restore_backup', 'restore_undo', 'backup_delete', 'set_cron' ) as $action ) {
			$messages[] = 'Hosting change "' . $action . '" done';
		}

		foreach ( $messages as $message ) {
			$out[] = $message . ' => ' . wp_json_encode( ActivityMap::sentence( $message ) ) . ' pause=' . wp_json_encode( ActivityMap::is_pause( $message ) );
		}

		return implode( "\n", $out ) . "\n";
	}

	public function test_dump(): void {
		$dir = $this->dir();

		$this->set_up_services();
		$this->put( $dir, 'account-menu.txt', $this->menu_text() );
		$this->put( $dir, 'account-panel.txt', $this->panel_text() );
		$this->put( $dir, 'account-slugs.txt', $this->slugs_text() );
		$this->put( $dir, 'account-activity.txt', $this->activity_text() );
		$this->put( $dir, 'account-areas.txt', $this->areas_text() );
		$this->put( $dir, 'account-areas-order.txt', implode( "\n", array_map( static fn( ServiceArea $a ): string => $a->key_name(), ServiceArea::all() ) ) . "\n" );
	}
}
