<?php
/**
 * The account page reads the snapshot list once a minute per box; snapshot actions clear it.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Storage;

use BeltoftServices\Storage\Boxes;
use BeltoftServices\Storage\StorageActions;

require_once __DIR__ . '/StorageTestCase.php';

final class StorageSnapshotCacheTest extends StorageTestCase {

	public function test_the_list_is_read_once_and_a_take_clears_it(): void {
		$r     = $this->ready_storage();
		$calls = 0;
		add_action( 'http_api_debug', static function () use ( &$calls ) { ++$calls; } );

		$first = Boxes::snapshots( $r );
		$this->assertIsArray( $first );
		$this->assertSame( $first, Boxes::snapshots( $r ), 'The second read comes from the cache.' );
		$this->assertNotFalse( get_transient( Boxes::SNAPSHOTS_CACHE . $r->id() ) );

		StorageActions::run( $r, StorageActions::SNAPSHOT, array() );
		$this->assertFalse( get_transient( Boxes::SNAPSHOTS_CACHE . $r->id() ), 'A take clears the cached list.' );
		$this->assertCount( count( $first ) + 1, Boxes::snapshots( $r ), 'The new snapshot shows at once.' );
	}

	public function test_a_cached_list_of_another_box_is_not_used(): void {
		$r = $this->ready_storage();
		set_transient( Boxes::SNAPSHOTS_CACHE . $r->id(), array( 'box' => 'other', 'list' => array( array( 'id' => 'x' ) ) ), 60 );

		$this->assertNotSame( array( array( 'id' => 'x' ) ), Boxes::snapshots( $r ) );
	}
}
