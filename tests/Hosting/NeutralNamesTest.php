<?php
/**
 * The hosting says "Hosting" unless the shop names the product, and a customer text says "WordPress" only where it is about WordPress.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Hosting;

require_once __DIR__ . '/HostingCase.php';

use BeltoftServices\Hosting\Config;
use BeltoftServices\Hosting\HostingDriver;

/**
 * The shop's setting `hosting_label` and the words of the hosting module.
 */
class NeutralNamesTest extends HostingCase {

	/**
	 * Files whose customer texts are about WordPress on purpose: the WordPress parts of a website's page, the checkout choice of what to
	 * install, the messages of the actions that need WordPress, the settings screen. Every other file of the hosting module is neutral:
	 * it may not name WordPress in a text (the website may have none).
	 */
	private const ABOUT_WORDPRESS = array(
		'src/Hosting/HostingView.php',
		'src/Hosting/HostingAccount.php',
		'src/Hosting/HostingDriver.php',
		'src/Hosting/HostingSettings.php',
		'src/Hosting/SiteField.php',
		'src/Hosting/SiteNewDomain.php',
		'templates/emails/beltoft-services-hosting-notice.php',
		'templates/emails/plain/beltoft-services-hosting-notice.php',
		'src/Hosting/Plan.php',
		'src/Hosting/SiteCron.php',
		'src/Hosting/Actions/Base.php',
		'src/Hosting/Actions/SiteActions.php',
		'src/Hosting/Actions/PhpActions.php',
		'src/Hosting/Actions/CountryActions.php',
		'src/Hosting/Actions/StagingActions.php',
		'src/Hosting/Actions/Steps.php',
		'templates/account/hosting/add-site.php',
		'templates/account/hosting/site.php',
		'templates/account/hosting/site-cron.php',
		'templates/account/hosting/site-cron-row.php',
		'templates/account/hosting/site-login.php',
		'templates/account/hosting/site-cache.php',
		'templates/account/hosting/site-country.php',
		'templates/account/hosting/site-staging.php',
		'templates/account/hosting/site-wp-address.php',
		'templates/emails/beltoft-services-hosting-ready.php',
		'templates/emails/plain/beltoft-services-hosting-ready.php',
	);

	public function test_the_product_is_called_hosting_until_the_shop_names_it(): void {
		$this->assertSame( 'Hosting', Config::label() );
		$this->assertSame( 'Hosting', ( new HostingDriver() )->label() );
		$this->assertSame( 'Hosting', \BeltoftServices\Account\ServiceArea::get( \BeltoftServices\Account\Hosting::class )->label() );

		$this->settings( array( 'hosting_label' => 'Web hosting' ) );
		$this->assertSame( 'Web hosting', Config::label() );
		$this->assertSame( 'Web hosting', ( new HostingDriver() )->label() );
		$this->assertSame( 'Web hosting', \BeltoftServices\Account\ServiceArea::get( \BeltoftServices\Account\Hosting::class )->label() );

		$this->settings( array( 'hosting_label' => '   ' ) );
		$this->assertSame( 'Hosting', Config::label(), 'An empty name is the neutral one.' );

		$this->settings( array( 'hosting_label' => '<b>Webhotel</b>' ) );
		$this->assertSame( 'Webhotel', Config::label(), 'No markup.' );
	}

	public function test_the_name_is_a_setting_of_the_hosting_screen_and_is_cleaned(): void {
		$fields = apply_filters( 'beltoft_services_settings_fields', array() );
		$this->assertArrayHasKey( 'hosting_label', $fields );
		$this->assertSame( 'Hosting', $fields['hosting_label']['default'] );

		$clean = \BeltoftServices\Hosting\HostingSettings::sanitize( array( 'hosting_label' => '  <i>Web</i> hosting ' . str_repeat( 'x', 80 ) ) );
		$this->assertLessThanOrEqual( 60, mb_strlen( $clean['hosting_label'] ) );
		$this->assertStringStartsWith( 'Web hosting', $clean['hosting_label'] );
	}

	public function test_the_menu_uses_the_name(): void {
		$user = self::factory()->user->create();
		wp_set_current_user( $user );
		$this->settings( array( 'hosting_label' => 'Webhotel' ) );
		$menu = apply_filters( 'woocommerce_account_menu_items', array( 'dashboard' => 'Dashboard', 'customer-logout' => 'Log out' ) );

		$this->assertSame( 'Webhotel', $menu['hosting'] );
		wp_set_current_user( 0 );
	}

	public function test_no_neutral_customer_text_names_wordpress(): void {
		$root  = dirname( __DIR__, 2 ) . '/';
		$files = array_merge(
			glob( $root . 'src/Hosting/*.php' ),
			glob( $root . 'src/Hosting/Actions/*.php' ),
			glob( $root . 'templates/account/hosting/*.php' ),
			glob( $root . 'templates/emails/*hosting*.php' ),
			glob( $root . 'templates/emails/plain/*hosting*.php' ),
			array( $root . 'src/Account/Hosting.php' )
		);
		$this->assertNotEmpty( $files );

		$found = array();

		foreach ( $files as $file ) {
			$relative = substr( $file, strlen( $root ) );

			if ( in_array( $relative, self::ABOUT_WORDPRESS, true ) ) {
				continue;
			}

			preg_match_all( "/(?:__|_e|esc_html__|esc_attr__|esc_html_e|esc_attr_e|_x|_n)\\(\\s*'((?:[^'\\\\\\\\]|\\\\\\\\.)*WordPress(?:[^'\\\\\\\\]|\\\\\\\\.)*)'/", (string) file_get_contents( $file ), $m );

			foreach ( $m[1] as $text ) {
				$found[] = $relative . ': ' . $text;
			}
		}

		$this->assertSame( array(), $found, 'A text that names WordPress belongs in a file that is about WordPress (see ABOUT_WORDPRESS), or it must be neutral.' );
	}

	public function test_the_files_that_are_about_wordpress_exist(): void {
		foreach ( self::ABOUT_WORDPRESS as $file ) {
			$this->assertFileExists( dirname( __DIR__, 2 ) . '/' . $file );
		}
	}
}
