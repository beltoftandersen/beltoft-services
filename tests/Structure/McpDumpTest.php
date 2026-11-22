<?php
/**
 * Helper for refactors of the MCP tools that must not change behaviour (companion of SurfaceDumpTest, same SURFACE_DUMP=<directory>).
 *
 * Writes mcp-list-<scope>.json (tools/list for each token scope: names, descriptions, schemas, annotations, in order),
 * mcp-map.txt (the tool to REST route and action tables, sorted), mcp-reads.txt (every read tool run against a server, a
 * database, a domain and an unknown id, ids and times normalised) and mcp-summaries.txt (the first call of every tool that needs
 * a confirmation: its summary or its refusal, nothing is done).
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Structure;

use BeltoftServices\Api\Tokens;
use BeltoftServices\Mcp\ActionMap;
use BeltoftServices\Mcp\Definitions;
use BeltoftServices\Mcp\Tools;
use BeltoftServices\Tests\Mcp\McpTestCase;

require_once dirname( __DIR__ ) . '/Mcp/McpTestCase.php';

/**
 * Not an assertion test.
 */
final class McpDumpTest extends McpTestCase {

	/**
	 * A table of ActionMap, as a constant (before) or as a method (after).
	 *
	 * @param string $name Table.
	 * @return array<string, mixed>
	 */
	private function table( string $name ): array {
		if ( method_exists( ActionMap::class, strtolower( $name ) ) ) {
			return (array) call_user_func( array( ActionMap::class, strtolower( $name ) ) );
		}

		return (array) constant( ActionMap::class . '::' . $name );
	}

	/**
	 * @param string $text   Text.
	 * @param array  $ids    Value => label.
	 */
	private function normal( string $text, array $ids ): string {
		$text = preg_replace( '/Service [A-Za-z0-9]{6}\b/', 'Service NAME', $text );
		$text = preg_replace_callback(
			'/("(?:[a-z_]*id|net|network|service)":\s*|#|\/resources\/)(\d+)/',
			static fn( $m ) => $m[1] . ( $ids[ (int) $m[2] ] ?? $m[2] ),
			(string) $text
		);
		$text = preg_replace( '/("services": \[\s+)\d+/', '$1ID', (string) $text );
		$text = preg_replace( '/\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}(:\d{2})?/', 'DATETIME', (string) $text );
		$text = preg_replace( '/\b1[5-9]\d{8}\b/', 'TIMESTAMP', (string) $text );
		$text = preg_replace( '/"confirm":\s*"[^"]*"/', '"confirm":"TOKEN"', (string) $text );
		$text = preg_replace( '/\\\\"confirm\\\\":\s*\\\\"[^"\\\\]*\\\\"/', '"confirm":"TOKEN"', (string) $text );

		return (string) $text;
	}

	public function test_dump(): void {
		$dir = (string) getenv( 'SURFACE_DUMP' );

		if ( '' === $dir ) {
			$this->markTestSkipped( 'Only runs with SURFACE_DUMP=<directory>.' );
		}

		if ( ! is_dir( $dir ) ) {
			mkdir( $dir, 0777, true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir
		}

		$put = function ( string $name, string $text ) use ( $dir ): void {
			$this->assertNotFalse( file_put_contents( rtrim( $dir, '/' ) . '/' . $name, $text ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		};

		foreach ( array( Tokens::READ, Tokens::ACTIONS, Tokens::FULL ) as $scope ) {
			$put( 'mcp-list-' . $scope . '.json', (string) wp_json_encode( Tools::listing( $scope ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n" );
		}

		$put( 'mcp-definitions.json', (string) wp_json_encode( Definitions::all(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n" );

		$map = '';

		foreach ( array( 'ACTIONS', 'EXCLUDED', 'CAPABILITIES', 'CAPABILITIES_EXCLUDED', 'ROUTES' ) as $table ) {
			$rows = $this->table( $table );
			ksort( $rows );
			$map .= '== ' . $table . "\n" . wp_json_encode( $rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n";
		}

		$map .= "== dashboard_only\n" . wp_json_encode( array_values( Tokens::dashboard_only_actions() ) ) . "\n";
		$put( 'mcp-map.txt', $map );

		$user   = (int) self::factory()->user->create();
		$sub    = $this->subscription( $user );
		$server = $this->server( $user );
		$db     = $this->database( $user );
		$domain = $this->domain_paid_by( $user, $sub );
		$this->email_to( $server );
		$this->email_to( $db );
		$this->email_to( $domain );
		add_filter( 'beltoft_services_mcp_rate_limit', '__return_zero' );
		$token = $this->oauth( $user, Tokens::FULL );

		$ids = array(
			$user                            => 'USER',
			$server->id()                    => 'SERVER',
			$db->id()                        => 'DB',
			$domain->id()                    => 'DOMAIN',
			(int) $server->license()['id']   => 'SERVER_LICENSE',
			(int) $db->license()['id']       => 'DB_LICENSE',
			(int) $domain->license()['id']   => 'DOMAIN_LICENSE',
			$sub->get_id()                   => 'SUBSCRIPTION',
		);
		krsort( $ids );

		$candidates = array( 'server' => $server->id(), 'db' => $db->id(), 'domain' => $domain->id(), 'unknown' => 999999 );
		$licenses   = array( 'server' => (int) $server->license()['id'], 'db' => (int) $db->license()['id'], 'domain' => (int) $domain->license()['id'] );
		$reads      = '';
		$sums       = '';

		foreach ( Definitions::all() as $name => $tool ) {
			$is_read = Tokens::READ === $tool['level'];

			if ( ! $is_read && ! $tool['confirm'] ) {
				continue;
			}

			foreach ( $candidates as $label => $id ) {
				$args = (array) $this->dummy( $tool['schema'], $id );

				// Ids of other things are real or certainly unknown, never a number that may or may not exist.
				foreach ( array( 'license_id', 'subscription_id', 'order_id', 'network' ) as $key ) {
					if ( isset( $args[ $key ] ) ) {
						$args[ $key ] = 'unknown' === $label ? 999999 : ( 'license_id' === $key ? $licenses[ $label ] : ( 'subscription_id' === $key ? $sub->get_id() : 999999 ) );
					}
				}

				if ( ! $is_read && in_array( $name, array( 'order_server', 'order_database', 'delete_server', 'delete_database' ), true ) ) {
					// Described only for the unknown id: they are money and deletion paths with their own tests.
					if ( 'unknown' !== $label ) {
						continue;
					}
				}

				// The extra arguments of a tool that the generic filler leaves out are not needed for the first call.
				$result = $this->tool( $name, $args, $token );
				$line   = $name . ' [' . $label . '] ' . ( ! empty( $result['isError'] ) ? 'ERROR ' : '' ) . $this->normal( $this->text( $result ), $ids ) . "\n";

				if ( $is_read ) {
					$reads .= $line;
				} else {
					$sums .= $line;
				}

				// An id-less tool gives the same answer for every candidate.
				if ( ! isset( $tool['schema']['properties']->id ) && ! isset( ( (array) $tool['schema']['properties'] )['id'] ) ) {
					break;
				}
			}
		}

		$put( 'mcp-reads.txt', $reads );
		$put( 'mcp-summaries.txt', $sums );
	}
}
