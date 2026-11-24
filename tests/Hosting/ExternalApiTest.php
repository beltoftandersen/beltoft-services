<?php
/**
 * The names that code outside the plugin calls in hosting (fixtures/external-api.php).
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Hosting;

use BeltoftServices\Tests\Support\ExternalApiCheck;
use PHPUnit\Framework\TestCase;

require_once BELTOFT_SERVICES_TESTS_DIR . '/Support/ExternalApiCheck.php';

/**
 * Same rule as tests/Structure/ExternalApiTest.php for the hosting list.
 */
class ExternalApiTest extends TestCase {

	use ExternalApiCheck;

	public function test_every_hosting_name_called_from_outside_exists_and_is_public(): void {
		$this->assertSame( array(), $this->missing_names( require __DIR__ . '/fixtures/external-api.php' ), 'Names called from outside the plugin that are gone or no longer public.' );
	}
}
