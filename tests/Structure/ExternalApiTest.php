<?php
/**
 * The names that code outside this plugin calls stay public.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Structure;

use BeltoftServices\Tests\Support\ExternalApiCheck;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/Support/ExternalApiCheck.php';

/**
 * Site themes and add-on plugins call a small set of this plugin's classes directly. Nothing inside the plugin
 * may call some of them, so they look like dead code. tests/fixtures/external-api.php lists every such name;
 * removing, renaming, moving or hiding one fails here. A class that moves keeps its old name working (the
 * autoloader's alias map) until every caller has been changed, and the list is then updated in the same commit.
 */
class ExternalApiTest extends TestCase {

	use ExternalApiCheck;

	public function test_every_name_called_from_outside_exists_and_is_public(): void {
		$missing = $this->missing_names( require dirname( __DIR__ ) . '/fixtures/external-api.php' );

		$this->assertSame( array(), $missing, 'Names called from outside the plugin that are gone or no longer public.' );
	}
}
