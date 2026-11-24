<?php
/**
 * Test bootstrap of the hosting feature: answers the neutral test hooks of the shared bootstrap and TestCase.
 *
 * tests/bootstrap.php includes this file when it exists (and BELTOFT_TEST_WITHOUT_HOSTING is not set), so the services
 * tests run without it.
 *
 * @package BeltoftServices
 */

// Its tables, once per test database.
tests_add_filter(
	'beltoft_services_tests_install',
	static function () {
		\BeltoftServices\Hosting\Schema::create_tables();
	}
);

// Before every test: the hosting usage memo outlives a test (tables and options behind it are reset around each one).
tests_add_filter(
	'beltoft_services_tests_reset',
	static function () {
		\BeltoftServices\Hosting\HostingCapacity::forget();
	}
);

// Recurring jobs that must not run in whichever test comes after their time.
tests_add_filter(
	'beltoft_services_tests_clear_hooks',
	static function ( $hooks ) {
		$hooks[] = \BeltoftServices\Hosting\Addons::SWEEP_HOOK;
		return $hooks;
	}
);

// What hosting adds to the lists and fixtures that the tests of the shared code pin. Those tests leave these entries out
// (TestCase::optional(), without_optional()); tests/Hosting asserts them against its own fixtures. The entries that
// are keys of a fixture are read from it, so the two halves cannot drift apart.
$beltoft_hosting_fixture = static function ( string $file ) {
	$path = __DIR__ . '/fixtures/' . $file;

	return 'php' === pathinfo( $file, PATHINFO_EXTENSION ) ? require $path : json_decode( (string) file_get_contents( $path ), true ); // phpcs:ignore WordPressVIPMinimum.Files.IncludingFile.UsingVariable, WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
};

$beltoft_hosting_optional = array(
	'driver_ids'           => array( 'hosting' ),
	'companion_driver_ids' => array( 'hosting' ),
	'account_area_classes' => array( \BeltoftServices\Hosting\Hosting::class ),
	'area_slugs'           => array( 'hosting' ),
	'order_url_settings'   => array( 'hosting_order_url' ),
	'email_ids'            => array_keys( $beltoft_hosting_fixture( 'email-definitions.php' ) ),
	'email_value_keys'     => array_keys( $beltoft_hosting_fixture( 'email-values.json' )['proxmox'] ),
	'email_golden_keys'    => array_keys( $beltoft_hosting_fixture( 'emails-golden.json' ) ),
	'settings_keys'        => array_keys( $beltoft_hosting_fixture( 'settings-fields.json' )['fields'] ),
);

foreach ( $beltoft_hosting_optional as $beltoft_hosting_kind => $beltoft_hosting_list ) {
	tests_add_filter(
		'beltoft_services_tests_optional_' . $beltoft_hosting_kind,
		static function ( $list ) use ( $beltoft_hosting_list ) {
			return array_merge( (array) $list, $beltoft_hosting_list );
		}
	);
}

unset( $beltoft_hosting_fixture, $beltoft_hosting_optional, $beltoft_hosting_kind, $beltoft_hosting_list );
