<?php
/**
 * The shop name used in customer-facing text.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests;

use BeltoftServices\Brand;

/**
 * Brand.
 */
class BrandTest extends TestCase {

	public function test_name_is_the_site_name_and_filterable(): void {
		update_option( 'blogname', 'Example Shop' );
		$this->assertSame( 'Example Shop', Brand::name() );

		add_filter( 'beltoft_services_brand_name', static fn() => 'Other' );
		$this->assertSame( 'Other', Brand::name() );
	}
}
