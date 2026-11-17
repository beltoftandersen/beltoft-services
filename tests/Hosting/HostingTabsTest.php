<?php
/**
 * The hosting page has tabs like the server page: Overview, Websites, Files, Backups and Email, each its own address.
 * Only the tab asked for is printed; a panel printed outside a page keeps every part in one run.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Hosting;

require_once __DIR__ . '/HostingCase.php';

use BeltoftServices\Account\Hosting;
use BeltoftServices\Account\ServiceArea;
use BeltoftServices\Hosting\HostingAccount;
use BeltoftServices\Hosting\HostingView;
use BeltoftServices\Resource;

/**
 * HostingAccount::render() on a page of the Hosting area, HostingView::tabs() and ::link().
 */
class HostingTabsTest extends HostingCase {

	public function set_up(): void {
		parent::set_up();
		$this->settings( array( 'hosting_php_versions' => '8.3,8.4,8.5' ) );
	}

	public function tear_down(): void {
		$this->set_page( null, '' );
		parent::tear_down();
	}

	private function account(): Resource {
		$r = $this->resource( array( 'sites' => 3, 'php_workers' => 10, 'php_memory_mb' => 512, 'upload_mb' => 100 ) );
		$this->provision_fully( $r );
		$r->set( array( 'state' => 'ready', 'desired' => 'ready' ) );

		return Resource::find( $r->id() );
	}

	/**
	 * Makes ServiceArea::page() answer as on the hosting page of a license.
	 *
	 * @param Resource|null $r   Resource, or null to end the page.
	 * @param string        $tab Requested tab.
	 */
	private function set_page( ?Resource $r, string $tab ): void {
		$prop = new \ReflectionProperty( ServiceArea::class, 'page' );
		$prop->setAccessible( true );
		$prop->setValue( null, null === $r ? null : array( 'license' => $r->license(), 'tab' => $tab, 'resource' => $r, 'area' => ServiceArea::get( Hosting::class ), 'path' => array(), 'base' => '' ) );
	}

	private function page( Resource $r, string $tab = '' ): string {
		$this->set_page( $r, $tab );
		ob_start();
		HostingAccount::render( Resource::find( $r->id() ) );
		$html = (string) ob_get_clean();
		$this->set_page( null, '' );

		return $html;
	}

	public function test_the_tabs_offered_start_with_the_overview_and_have_backups_only_when_they_are_on(): void {
		$r    = $this->account();
		$tabs = HostingView::tabs( $r );
		$keys = array_keys( $tabs );

		$this->assertSame( 'overview', $keys[0] );
		$this->assertSame( 'Overview', $tabs['overview'] );
		$this->assertSame( 'email', end( $keys ) );
		$this->assertSame( \BeltoftServices\Hosting\HostingBackups::enabled( $r ), isset( $tabs['backups'] ) );
		$this->assertSame( array( 'websites', 'files', 'backups', 'email' ), HostingView::TABS );
	}

	public function test_a_page_without_a_tab_shows_the_overview_with_the_tab_strip_and_nothing_of_the_others(): void {
		$r    = $this->account();
		$html = $this->page( $r );

		$this->assertStringContainsString( '<nav class="beltoft-services-tabs" aria-label="Hosting sections">', $html );
		$this->assertStringContainsString( 'aria-current="page">Overview</a>', $html );
		$this->assertStringContainsString( 'class="bsh-overview"', $html );
		$this->assertStringContainsString( 'id="beltoft-hosting-monitoring"', $html );
		$this->assertStringContainsString( 'id="beltoft-hosting-checklist"', $html );
		$this->assertStringNotContainsString( 'beltoft-hosting-site"', $html );
		$this->assertStringNotContainsString( 'id="beltoft-hosting-files"', $html );
		$this->assertStringNotContainsString( 'id="beltoft-hosting-email"', $html );
		$this->assertStringNotContainsString( 'class="bsh-jump"', $html, 'The links to the parts are the tabs.' );
	}

	public function test_each_tab_prints_only_its_own_parts(): void {
		$r = $this->account();

		$websites = $this->page( $r, 'websites' );
		$this->assertStringContainsString( 'aria-current="page">Websites</a>', $websites );
		$this->assertStringContainsString( 'data-hosting-action="login"', $websites );
		$this->assertStringContainsString( 'id="beltoft-hosting-add"', $websites );
		$this->assertStringNotContainsString( 'class="bsh-overview"', $websites );
		$this->assertStringNotContainsString( 'id="beltoft-hosting-email"', $websites );

		$files = $this->page( $r, 'files' );
		$this->assertStringContainsString( 'id="beltoft-hosting-files"', $files );
		$this->assertStringNotContainsString( 'data-hosting-action="login"', $files );

		$email = $this->page( $r, 'email' );
		$this->assertStringContainsString( 'id="beltoft-hosting-email"', $email );
		$this->assertStringNotContainsString( 'id="beltoft-hosting-files"', $email );
	}

	public function test_a_tab_that_is_not_offered_shows_the_overview(): void {
		$r = $this->account();

		$this->assertStringContainsString( 'class="bsh-overview"', $this->page( $r, 'nonsense' ) );
	}

	public function test_the_task_line_and_the_watcher_attribute_are_on_every_tab(): void {
		$r = $this->account();

		foreach ( array( '', 'websites', 'files', 'email' ) as $tab ) {
			$html = $this->page( $r, $tab );
			$this->assertStringContainsString( 'data-hosting-resource="' . $r->id() . '"', $html, $tab );
			$this->assertStringNotContainsString( 'data-task-line', $html, $tab . ': the shared progress line says it' );
		}
	}

	public function test_links_between_the_tabs_go_to_the_tab_and_stay_anchors_outside_a_page(): void {
		$r    = $this->account();
		$html = $this->page( $r );

		$this->assertMatchesRegularExpression( '#href="[^"]*websites[^"]*\#beltoft-site-w' . $r->id() . '-connect">Connect a domain</a>#', $html );
		$this->assertMatchesRegularExpression( '#href="[^"]*email[^"]*\#beltoft-hosting-email">Set up email</a>#', $html );

		ob_start();
		HostingAccount::render( Resource::find( $r->id() ) );
		$plain = (string) ob_get_clean();
		$this->assertStringContainsString( 'href="#beltoft-hosting-email">Set up email</a>', $plain );
		$this->assertStringNotContainsString( 'beltoft-services-tabs', $plain );
		$this->assertStringContainsString( 'class="bsh-jump"', $plain );
		$this->assertStringContainsString( 'id="beltoft-hosting-files"', $plain, 'Outside a page every part is printed.' );
		$this->assertStringContainsString( 'id="beltoft-hosting-email"', $plain );
	}

	public function test_the_area_accepts_the_tab_addresses(): void {
		$area = ServiceArea::get( Hosting::class );

		$this->assertSame( array( 'websites', 'files', 'backups', 'email' ), $area->tabs() );
		$this->assertSame( array( 'overview', 'websites', 'files', 'backups', 'email' ), array_keys( $area->nav() ) );
	}
}
