<?php
/**
 * Box sizes read like the provider sells them, and the default country is translated.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Storage;

use BeltoftServices\Storage\Boxes;
use BeltoftServices\Storage\StorageSettings;

require_once __DIR__ . '/StorageTestCase.php';

final class StorageSizeTextTest extends StorageTestCase {

	public function test_the_provider_sizes_read_as_sold(): void {
		$this->assertSame( '1 TB', Boxes::size_text( 1099511627776 ) );
		$this->assertSame( '5 TB', Boxes::size_text( 5497558138880 ) );
		$this->assertSame( '10 TB', Boxes::size_text( 10995116277760 ) );
		$this->assertSame( '20 TB', Boxes::size_text( 21990232555520 ) );
		$this->assertSame( '512 GB', Boxes::size_text( 549755813888 ) );
		$this->assertSame( '0 B', Boxes::size_text( 0 ) );
		$this->assertSame( '1.5 GB', Boxes::size_text( 1610612736 ) );
	}

	public function test_the_default_country_is_translatable_and_a_typed_one_stays(): void {
		add_filter( 'gettext', $f = static fn( $t, $o, $d ) => 'Germany' === $o && 'beltoft-services' === $d ? 'Tyskland' : $t, 10, 3 );
		$this->settings( array( 'storage_country' => '' ) );
		$this->assertSame( 'Tyskland', StorageSettings::country() );
		$this->settings( array( 'storage_country' => 'Germany' ) );
		$this->assertSame( 'Tyskland', StorageSettings::country() );
		$this->settings( array( 'storage_country' => 'Finland' ) );
		$this->assertSame( 'Finland', StorageSettings::country() );
		remove_filter( 'gettext', $f, 10 );
	}
}
