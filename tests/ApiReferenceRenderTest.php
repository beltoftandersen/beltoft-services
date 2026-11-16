<?php
/**
 * Characterisation of the two API reference layouts: fixed data in, the printed HTML must not change when the
 * templates share parts (whitespace between tags is not compared). Regenerate with BS_WRITE_GOLDEN=1 only on purpose.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests;

use BeltoftServices\Api\Reference;

final class ApiReferenceRenderTest extends TestCase {

	/**
	 * Fixed values for the templates.
	 *
	 * @return array<string, mixed>
	 */
	private function data( bool $mcp, int $rate ): array {
		$ops = array(
			array(
				'id'           => 'get-resources',
				'method'       => 'GET',
				'path'         => '/resources',
				'short'        => '/resources',
				'summary'      => 'List your services & more.',
				'scope'        => 'read',
				'status'       => 200,
				'status_label' => 'Example reply, 200',
				'tools'        => array( 'list_services', 'get_service' ),
				'params'       => array(),
				'reply'        => array(
					array( 'name' => 'id', 'type' => 'integer', 'desc' => 'The id.' ),
					array( 'name' => 'state', 'type' => 'string', 'desc' => '' ),
				),
				'errors'       => array( 401, 403 ),
				'curl'         => 'curl -u "USERNAME:TOKEN" https://example.org/wp-json/x/resources',
				'sample'       => '[{"id":1}]',
				'samples'      => array( 'curl' => 'curl x', 'php' => '<?php echo 1;', 'python' => 'print(1)', 'js' => 'fetch()' ),
			),
			array(
				'id'           => 'post-resources-id-actions',
				'method'       => 'POST',
				'path'         => '/resources/{id}/actions/{name}',
				'short'        => '/resources/{id}/actions',
				'summary'      => 'Ask for an action.',
				'scope'        => 'none',
				'status'       => 202,
				'status_label' => 'Accepted, 202',
				'tools'        => array(),
				'params'       => array(
					array( 'name' => 'id', 'in' => 'path', 'type' => 'integer', 'required' => true, 'desc' => '' ),
					array( 'name' => 'force', 'in' => 'query', 'type' => 'boolean', 'required' => false, 'desc' => 'Do it now.' ),
				),
				'reply'        => array(),
				'errors'       => array(),
				'curl'         => 'curl -X POST https://example.org/wp-json/x/resources/1/actions/start',
				'sample'       => '',
				'samples'      => array( 'curl' => 'curl y' ),
			),
		);

		return array(
			'groups'    => array(
				'services' => array(
					'id'    => 'api-services',
					'title' => 'Services',
					'desc'  => 'List your services.',
					'ops'   => $ops,
				),
				'empty'    => array(
					'id'    => 'api-other',
					'title' => 'Other',
					'desc'  => '',
					'ops'   => array( $ops[1] ),
				),
			),
			'base'      => 'https://example.org/wp-json/x',
			'spec_url'  => 'https://example.org/wp-json/x/openapi.json',
			'rate'      => $rate,
			'scopes'    => array( 'read' => 'Read only', 'actions' => 'Read and actions' ),
			'ops'       => $ops,
			'levels'    => array( 'read' => 'Read only', 'actions' => 'Everyday actions', 'full' => 'Full access' ),
			'errors'    => array( 401 => 'Not signed in.', 429 => 'Too many requests & more.' ),
			'languages' => Reference::languages(),
			'mcp'       => array(
				'on'    => $mcp,
				'url'   => 'https://example.org/mcp',
				'tools' => array(
					array( 'name' => 'list_services', 'description' => 'Lists <services>.', 'level' => 'read' ),
					array( 'name' => 'do_action', 'description' => 'Does an action.', 'level' => 'actions' ),
				),
			),
		);
	}

	private function normal( string $html ): string {
		// Whitespace between tags and before a closing tag is not shown by a browser: not compared.
		return trim( (string) preg_replace( '/\s+(<\/)/', '$1', (string) preg_replace( '/>\s+</', '><', (string) preg_replace( '/\s+/', ' ', $html ) ) ) );
	}

	/**
	 * @return array<string, array{0: string, 1: bool, 2: int}>
	 */
	public static function cases(): array {
		return array(
			'short-mcp'    => array( 'api-reference.php', true, 60 ),
			'short-nomcp'  => array( 'api-reference.php', false, 0 ),
			'full-mcp'     => array( 'api-reference-full.php', true, 60 ),
			'full-nomcp'   => array( 'api-reference-full.php', false, 0 ),
		);
	}

	/**
	 * @dataProvider cases
	 */
	public function test_the_layout_prints_what_it_printed_before_it_shared_parts( string $template, bool $mcp, int $rate ): void {
		ob_start();
		wc_get_template( $template, $this->data( $mcp, $rate ), 'beltoft-services/', BELTOFT_SERVICES_PATH . 'templates/' );
		$html = $this->normal( (string) ob_get_clean() );

		$name = BELTOFT_SERVICES_PATH . 'tests/fixtures/golden/' . basename( $template, '.php' ) . ( $mcp ? '-mcp' : '-nomcp' ) . '.html';

		if ( getenv( 'BS_WRITE_GOLDEN' ) ) {
			file_put_contents( $name, $html . "\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		}

		$this->assertSame( $this->normal( (string) file_get_contents( $name ) ), $html );
	}
}
