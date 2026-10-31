<?php
/**
 * The public API reference page.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Api;

use BeltoftServices\Mcp\Access;
use BeltoftServices\Mcp\ActionMap;
use BeltoftServices\Mcp\Endpoint;
use BeltoftServices\Mcp\Tools;

defined( 'ABSPATH' ) || exit;

/**
 * Shortcode [beltoft_services_api_reference]: prints the API reference of the site from the OpenAPI document and the
 * MCP tool table as they are right now, so the page cannot drift from the code. The markup is the template
 * api-reference.php (override it in yourtheme/beltoft-services/api-reference.php). The stylesheet loads only on a page
 * that uses the shortcode.
 */
final class Reference {

	/**
	 * Shortcode tag.
	 */
	public const SHORTCODE = 'beltoft_services_api_reference';

	/**
	 * Stylesheet handle.
	 */
	public const HANDLE = 'beltoft-services-api-reference';

	/**
	 * Hooks.
	 */
	public static function register(): void {
		add_shortcode( self::SHORTCODE, array( __CLASS__, 'shortcode' ) );
	}

	/**
	 * Handler of the shortcode.
	 */
	public static function shortcode( $atts = array() ): string {
		$atts = shortcode_atts( array( 'layout' => 'default' ), (array) $atts, self::SHORTCODE );

		return self::html( 'full' === sanitize_key( (string) $atts['layout'] ) ? 'full' : 'default' );
	}

	/**
	 * The address of the documentation page, empty when the setting has none.
	 */
	public static function page_url(): string {
		return esc_url_raw( (string) \BeltoftServices\Settings::get( 'api_docs_url' ) );
	}

	/**
	 * The reference as HTML.
	 *
	 * @param string $layout default (the page column) or full (a docs app that uses the full width of the browser).
	 */
	public static function html( string $layout = 'default' ): string {
		if ( 'full' === $layout ) {
			return self::html_full();
		}

		/**
		 * Whether to load the API reference stylesheet.
		 *
		 * @param bool $load Load it, default true.
		 */
		if ( apply_filters( 'beltoft_services_api_reference_css', true ) ) {
			wp_enqueue_style( self::HANDLE, plugins_url( 'assets/api-reference.css', BELTOFT_SERVICES_FILE ), array(), \BeltoftServices\Assets::version( 'assets/api-reference.css' ) );
		}

		/**
		 * Whether to load the API reference script (copy buttons and the highlight of the current section).
		 *
		 * @param bool $load Load it, default true.
		 */
		if ( apply_filters( 'beltoft_services_api_reference_js', true ) ) {
			wp_enqueue_script( self::HANDLE, plugins_url( 'assets/api-reference.js', BELTOFT_SERVICES_FILE ), array(), \BeltoftServices\Assets::version( 'assets/api-reference.js' ), array( 'strategy' => 'defer', 'in_footer' => true ) );
		}

		ob_start();
		wc_get_template( 'api-reference.php', self::data(), 'beltoft-services/', BELTOFT_SERVICES_PATH . 'templates/' );

		return (string) ob_get_clean();
	}

	/**
	 * The full width layout: its own stylesheet, script and template.
	 */
	private static function html_full(): string {
		/** This filter is documented in html(). */
		if ( apply_filters( 'beltoft_services_api_reference_css', true ) ) {
			wp_enqueue_style( self::HANDLE . '-full', plugins_url( 'assets/api-reference-full.css', BELTOFT_SERVICES_FILE ), array(), \BeltoftServices\Assets::version( 'assets/api-reference-full.css' ) );
		}

		/** This filter is documented in html(). */
		if ( apply_filters( 'beltoft_services_api_reference_js', true ) ) {
			wp_enqueue_script( self::HANDLE . '-full', plugins_url( 'assets/api-reference-full.js', BELTOFT_SERVICES_FILE ), array(), \BeltoftServices\Assets::version( 'assets/api-reference-full.js' ), array( 'strategy' => 'defer', 'in_footer' => true ) );
		}

		$data              = self::data();
		$data['languages'] = self::languages();

		ob_start();
		wc_get_template( 'api-reference-full.php', $data, 'beltoft-services/', BELTOFT_SERVICES_PATH . 'templates/' );

		return (string) ob_get_clean();
	}

	/**
	 * The languages of the code samples: key => label.
	 *
	 * @return array<string, string>
	 */
	public static function languages(): array {
		return array(
			'curl'   => 'curl',
			'php'    => 'PHP',
			'python' => 'Python',
			'js'     => 'JavaScript',
		);
	}

	/**
	 * What the template prints.
	 *
	 * @return array<string, mixed>
	 */
	public static function data(): array {
		$ops = self::operations();

		return array(
			'groups'   => self::groups( $ops ),
			'base'     => untrailingslashit( rest_url( Routes::NAMESPACE ) ),
			'spec_url' => rest_url( Routes::NAMESPACE . '/openapi.json' ),
			'rate'     => (int) apply_filters( 'beltoft_services_token_rate_limit', Tokens::RATE_LIMIT ),
			'scopes'   => Tokens::scopes(),
			'ops'      => $ops,
			'levels'   => self::levels(),
			'errors'   => self::errors(),
			'mcp'      => array(
				'on'    => Access::enabled(),
				'url'   => Endpoint::url(),
				'tools' => self::tools(),
			),
		);
	}

	/**
	 * The levels of a connected app, in order, with their names.
	 *
	 * @return array<string, string>
	 */
	public static function levels(): array {
		return array(
			Tokens::READ    => __( 'Read only', 'beltoft-services' ),
			Tokens::ACTIONS => __( 'Everyday actions', 'beltoft-services' ),
			Tokens::FULL    => __( 'Full access', 'beltoft-services' ),
		);
	}

	/**
	 * The resource groups: title and one line for each, in the order they are shown.
	 *
	 * @return array<string, array{0: string, 1: string}>
	 */
	private static function group_texts(): array {
		return array(
			'services'  => array( __( 'Services', 'beltoft-services' ), __( 'List your services and get their ids, read the status of one, and ask for an action such as start, shutdown or reboot.', 'beltoft-services' ) ),
			'servers'   => array( __( 'Servers', 'beltoft-services' ), __( 'Usage figures and the firewall of your servers.', 'beltoft-services' ) ),
			'databases' => array( __( 'Databases', 'beltoft-services' ), __( 'Which addresses may connect to a database.', 'beltoft-services' ) ),
			'dns'       => array( __( 'DNS', 'beltoft-services' ), __( 'Read and change the records of the domains and DNS services we host.', 'beltoft-services' ) ),
			'domains'   => array( __( 'Domains', 'beltoft-services' ), __( 'Check whether a domain name is available.', 'beltoft-services' ) ),
			'usage'     => array( __( 'Usage', 'beltoft-services' ), __( 'Current use of CPU, memory and disk, and usage alerts.', 'beltoft-services' ) ),
			'reference' => array( __( 'API document', 'beltoft-services' ), __( 'The machine readable description of this API.', 'beltoft-services' ) ),
		);
	}

	/**
	 * The group of a path: the resource it belongs to.
	 *
	 * @param string $path Path with {name} parts.
	 */
	private static function group_of( string $path ): string {
		$segs  = explode( '/', trim( $path, '/' ) );
		$first = (string) $segs[0];

		if ( 'resources' === $first ) {
			$sub = array(
				'dns-records'  => 'dns',
				'metrics'      => 'servers',
				'firewall'     => 'servers',
				'db-access'    => 'databases',
				'usage-alerts' => 'usage',
			);

			return $sub[ (string) ( $segs[2] ?? '' ) ] ?? 'services';
		}

		if ( 'openapi.json' === $first ) {
			return 'reference';
		}

		return sanitize_key( $first ) ?: 'other';
	}

	/**
	 * The operations grouped by resource, each with what the page needs to print it: anchor, short path, the Claude
	 * tools that do the same, a curl example and an example reply.
	 *
	 * @param array<int, array<string, mixed>> $ops Operations.
	 * @return array<string, array{id: string, title: string, desc: string, ops: array<int, array<string, mixed>>}>
	 */
	public static function groups( array $ops ): array {
		$texts  = self::group_texts();
		$groups = array();
		$rank   = array( 'GET' => 1, 'POST' => 2, 'PUT' => 3, 'DELETE' => 4 );

		usort(
			$ops,
			static fn( $a, $b ) => array( $a['path'], $rank[ $a['method'] ] ?? 9 ) <=> array( $b['path'], $rank[ $b['method'] ] ?? 9 )
		);

		foreach ( $ops as $op ) {
			$key = self::group_of( $op['path'] );

			if ( ! isset( $groups[ $key ] ) ) {
				$groups[ $key ] = array(
					'id'    => 'group-' . $key,
					'title' => $texts[ $key ][0] ?? ucfirst( str_replace( '-', ' ', $key ) ),
					'desc'  => $texts[ $key ][1] ?? '',
					'ops'   => array(),
				);
			}

			$op['id']    = 'ep-' . trim( (string) preg_replace( '/[^a-z0-9]+/', '-', strtolower( $op['method'] . ' ' . $op['path'] ) ), '-' );
			$op['short'] = 1 === preg_match( '#^/resources/\{id\}(/.+)$#D', $op['path'], $m ) ? '...' . $m[1] : $op['path'];
			$op['tools'] = self::tools_for( $op['method'], $op['path'] );
			$op['curl']  = self::curl( $op );
			$op['samples'] = self::samples( $op );
			$op['status_label'] = $op['status'] . ' ' . get_status_header_desc( (int) $op['status'] );
			$op['sample'] = self::sample_reply( $op );

			$groups[ $key ]['ops'][] = $op;
		}

		$ordered = array();

		foreach ( array_keys( $texts ) as $key ) {
			if ( isset( $groups[ $key ] ) ) {
				$ordered[ $key ] = $groups[ $key ];
			}
		}

		return $ordered + $groups;
	}

	/**
	 * The names of the MCP tools that do the same as a route, by the table of what each tool may call.
	 *
	 * @param string $method HTTP method.
	 * @param string $path   Path with {name} parts.
	 * @return array<int, string>
	 */
	public static function tools_for( string $method, string $path ): array {
		$names  = array_keys( Tools::definitions() );
		$sample = (string) preg_replace( array( '#\{id\}#', '#\{\w+\}#' ), array( '1', 'x' ), $path );
		$found  = array();

		if ( 'POST' === $method && preg_match( '#/actions/\{action\}$#D', $path ) ) {
			$found = array_values( array_unique( array_values( ActionMap::ACTIONS ) ) );
		} else {
			$extra = array(
				'GET /resources'      => 'list_services',
				'GET /domains/check'  => 'domain_check',
			);

			if ( isset( $extra[ $method . ' ' . $path ] ) ) {
				$found[] = $extra[ $method . ' ' . $path ];
			}

			foreach ( $names as $name ) {
				if ( ActionMap::permits( (string) $name, $method, $sample ) ) {
					$found[] = (string) $name;
				}
			}
		}

		return array_values( array_intersect( array_unique( $found ), $names ) );
	}

	/**
	 * An example value for a path or query parameter.
	 *
	 * @param string               $name   Name.
	 * @param array<string, mixed> $schema Schema.
	 * @param bool                 $param  A parameter of a request, not a field of a reply.
	 * @param bool                 $dns    Inside a DNS record.
	 * @return mixed
	 */
	private static function sample_value( string $name, array $schema, bool $param = false, bool $dns = false ) {
		$known = array(
			'id'      => 123,
			'type'    => 'A',
			'name'    => 'www',
			'action'  => 'reboot',
			'domain'  => 'example.com',
			'value'   => '203.0.113.10',
			'ip'      => '203.0.113.10',
			'state'   => 'running',
			'status'  => 'ok',
			'renews'  => '2026-12-01',
			'ttl'     => 3600,
			'period'  => 'day',
			'desired' => 'running',
			'app_state' => 'ready',
			'label'   => 'Running',
			'message' => 'Done.',
			'range'   => 'day',
			'records' => array( array( 'value' => '203.0.113.10', 'ttl' => 3600 ) ),
		);

		if ( 'records' === $name ) {
			return $known['records'];
		}

		if ( $dns && 'type' === $name ) {
			return 'A';
		}

		if ( ! $dns && 'type' === $name && ! $param ) {
			$known['type'] = 'server';
		}

		if ( ! $param && 'name' === $name ) {
			$known['name'] = $dns ? 'www' : 'web-1';
		}

		if ( isset( $schema['enum'][0] ) ) {
			return $schema['enum'][0];
		}

		if ( isset( $schema['default'] ) && ! is_array( $schema['default'] ) ) {
			return $schema['default'];
		}

		$type = $schema['type'] ?? 'string';
		$type = is_array( $type ) ? (string) reset( $type ) : (string) $type;

		switch ( $type ) {
			case 'integer':
				return is_int( $known[ $name ] ?? null ) ? $known[ $name ] : 1;
			case 'number':
				return 1.5;
			case 'boolean':
				return true;
			case 'array':
				return array( self::sample_value( $name, (array) ( $schema['items'] ?? array() ), $param, $dns ) );
			case 'object':
				$out   = array();
				$props = (array) ( $schema['properties'] ?? array() );
				$dns   = $dns || ( isset( $props['value'], $props['ttl'] ) );

				foreach ( $props as $key => $child ) {
					$out[ (string) $key ] = self::sample_value( (string) $key, (array) $child, $param, $dns );
				}

				return $out ? $out : new \stdClass();
			default:
				return is_string( $known[ $name ] ?? null ) ? $known[ $name ] : 'example';
		}
	}

	/**
	 * The example reply as pretty JSON, built from the documented reply fields.
	 *
	 * @param array<string, mixed> $op Operation.
	 */
	private static function sample_reply( array $op ): string {
		if ( ! $op['reply_schema'] ) {
			return '';
		}

		return (string) wp_json_encode( self::sample_value( 'reply', array( 'type' => 'object', 'properties' => $op['reply_schema'] ) ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
	}

	/**
	 * The request of an example: method, URL with example values, whether it needs the token, the JSON body.
	 *
	 * @param array<string, mixed> $op Operation.
	 * @return array{method: string, url: string, auth: bool, body: array<string, mixed>}
	 */
	private static function request( array $op ): array {
		$base  = untrailingslashit( rest_url( Routes::NAMESPACE ) );
		$path  = $op['path'];
		$query = array();
		$body  = array();

		foreach ( $op['params'] as $param ) {
			$value = self::sample_value( $param['name'], $param['schema'], true );

			if ( 'name' === $param['name'] && 'query' === $param['in'] && 0 === strpos( $path, '/domains' ) ) {
				$value = 'example.com';
			}

			if ( 'path' === $param['in'] ) {
				$path = str_replace( '{' . $param['name'] . '}', rawurlencode( (string) $value ), $path );
			} elseif ( 'query' === $param['in'] && $param['required'] ) {
				$query[ $param['name'] ] = $value;
			} elseif ( 'body' === $param['in'] && $param['required'] ) {
				$body[ $param['name'] ] = $value;
			}
		}

		return array(
			'method' => (string) $op['method'],
			'url'    => $base . $path . ( $query ? ( false === strpos( $base, '?' ) ? '?' : '&' ) . http_build_query( $query ) : '' ),
			'auth'   => 'none' !== $op['scope'],
			'body'   => $body,
		);
	}

	/**
	 * A curl request that works with a personal token.
	 *
	 * @param array<string, mixed> $op Operation.
	 */
	private static function curl( array $op ): string {
		$req   = self::request( $op );
		$auth  = $req['auth'] ? ' -u "USERNAME:TOKEN"' : '';
		$lines = array( 'curl' . $auth );

		if ( 'GET' !== $req['method'] ) {
			$lines[] = '-X ' . $req['method'];
		}

		if ( $req['body'] ) {
			$lines[] = '-H "Content-Type: application/json"';
			$lines[] = "-d '" . wp_json_encode( $req['body'], JSON_UNESCAPED_SLASHES ) . "'";
		}

		$lines[] = "'" . $req['url'] . "'";

		return implode( " \\\n  ", $lines );
	}

	/**
	 * The example of an operation in every language, with a personal token.
	 *
	 * @param array<string, mixed> $op Operation.
	 * @return array<string, string> Language key => code.
	 */
	public static function samples( array $op ): array {
		$req = self::request( $op );

		return array(
			'curl'   => self::curl( $op ),
			'php'    => self::php_sample( $req ),
			'python' => self::python_sample( $req ),
			'js'     => self::js_sample( $req ),
		);
	}

	/**
	 * A value as a literal: PHP array syntax or Python syntax.
	 *
	 * @param mixed  $value  Value.
	 * @param string $style  php or python.
	 * @param int    $depth  Nesting depth, for the indent.
	 */
	private static function literal( $value, string $style, int $depth = 0 ): string {
		$py  = 'python' === $style;
		$pad = str_repeat( '    ', $depth + 1 );
		$end = str_repeat( '    ', $depth );

		if ( $value instanceof \stdClass ) {
			$value = array();
		}

		if ( is_array( $value ) ) {
			if ( ! $value ) {
				return $py ? '{}' : 'array()';
			}

			$list  = array_keys( $value ) === range( 0, count( $value ) - 1 );
			$lines = array();

			foreach ( $value as $key => $item ) {
				$lines[] = $pad . ( $list ? '' : ( $py ? wp_json_encode( (string) $key, JSON_UNESCAPED_SLASHES ) : "'" . addcslashes( (string) $key, "'\\" ) . "'" ) . ( $py ? ': ' : ' => ' ) ) . self::literal( $item, $style, $depth + 1 ) . ',';
			}

			$open  = $py ? ( $list ? '[' : '{' ) : 'array(';
			$close = $py ? ( $list ? ']' : '}' ) : ')';

			return $open . "\n" . implode( "\n", $lines ) . "\n" . $end . $close;
		}

		if ( is_bool( $value ) ) {
			return $py ? ( $value ? 'True' : 'False' ) : ( $value ? 'true' : 'false' );
		}

		if ( null === $value ) {
			return $py ? 'None' : 'null';
		}

		if ( is_int( $value ) || is_float( $value ) ) {
			return (string) $value;
		}

		return $py ? (string) wp_json_encode( (string) $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) : "'" . addcslashes( (string) $value, "'\\" ) . "'";
	}

	/**
	 * Plain PHP with the curl functions.
	 *
	 * @param array{method: string, url: string, auth: bool, body: array<string, mixed>} $req Request.
	 */
	private static function php_sample( array $req ): string {
		$opts = array();

		if ( $req['auth'] ) {
			$opts[] = "\tCURLOPT_USERPWD => 'USERNAME:TOKEN',";
		}

		if ( 'GET' !== $req['method'] ) {
			$opts[] = "\tCURLOPT_CUSTOMREQUEST => '" . $req['method'] . "',";
		}

		if ( $req['body'] ) {
			$opts[] = "\tCURLOPT_HTTPHEADER => array( 'Content-Type: application/json' ),";
			$opts[] = "\tCURLOPT_POSTFIELDS => json_encode(\n\t\t" . str_replace( "\n", "\n\t", self::literal( $req['body'], 'php' ) ) . "\n\t),";
		}

		$opts[] = "\tCURLOPT_RETURNTRANSFER => true,";

		return "<?php\n\$ch = curl_init( '" . addcslashes( $req['url'], "'\\" ) . "' );\ncurl_setopt_array(\n\t\$ch,\n\tarray(\n" . implode( "\n", array_map( static fn( $l ) => "\t" . $l, $opts ) ) . "\n\t)\n);\n\$response = curl_exec( \$ch );\ncurl_close( \$ch );\n\n\$data = json_decode( \$response, true );";
	}

	/**
	 * Python with the requests library.
	 *
	 * @param array{method: string, url: string, auth: bool, body: array<string, mixed>} $req Request.
	 */
	private static function python_sample( array $req ): string {
		$args = array( '    ' . wp_json_encode( $req['url'], JSON_UNESCAPED_SLASHES ) . ',' );

		if ( $req['auth'] ) {
			$args[] = '    auth=("USERNAME", "TOKEN"),';
		}

		if ( $req['body'] ) {
			$args[] = '    json=' . self::literal( $req['body'], 'python', 1 ) . ',';
		}

		return "import requests\n\nresponse = requests." . strtolower( $req['method'] ) . "(\n" . implode( "\n", $args ) . "\n)\nprint(response.json())";
	}

	/**
	 * JavaScript with fetch.
	 *
	 * @param array{method: string, url: string, auth: bool, body: array<string, mixed>} $req Request.
	 */
	private static function js_sample( array $req ): string {
		$headers = array();
		$opts    = array();

		if ( 'GET' !== $req['method'] ) {
			$opts[] = '  method: "' . $req['method'] . '",';
		}

		if ( $req['auth'] ) {
			$headers[] = '    "Authorization": "Basic " + btoa("USERNAME:TOKEN"),';
		}

		if ( $req['body'] ) {
			$headers[] = '    "Content-Type": "application/json",';
		}

		if ( $headers ) {
			$opts[] = "  headers: {\n" . implode( "\n", $headers ) . "\n  },";
		}

		if ( $req['body'] ) {
			$json   = (string) wp_json_encode( $req['body'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
			$opts[] = '  body: JSON.stringify(' . str_replace( "\n", "\n  ", $json ) . '),';
		}

		$call = 'fetch(' . wp_json_encode( $req['url'], JSON_UNESCAPED_SLASHES ) . ( $opts ? ", {\n" . implode( "\n", $opts ) . "\n}" : '' ) . ')';

		return 'const response = await ' . $call . ";\nconst data = await response.json();";
	}

	/**
	 * The operations of the OpenAPI document, flattened for printing.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function operations(): array {
		$doc = json_decode( (string) wp_json_encode( OpenApi::document() ), true );
		$out = array();

		foreach ( (array) ( $doc['paths'] ?? array() ) as $path => $methods ) {
			foreach ( (array) $methods as $method => $op ) {
				$params = array();

				foreach ( (array) ( $op['parameters'] ?? array() ) as $param ) {
					$params[] = array(
						'name'     => (string) $param['name'],
						'in'       => (string) $param['in'],
						'type'     => self::type( (array) ( $param['schema'] ?? array() ) ),
						'required' => ! empty( $param['required'] ),
						'desc'     => (string) ( $param['schema']['description'] ?? '' ),
						'schema'   => (array) ( $param['schema'] ?? array() ),
					);
				}

				$body = (array) ( $op['requestBody']['content']['application/json']['schema'] ?? array() );

				foreach ( (array) ( $body['properties'] ?? array() ) as $name => $schema ) {
					$params[] = array(
						'name'     => (string) $name,
						'in'       => 'body',
						'type'     => self::type( (array) $schema ),
						'required' => in_array( $name, (array) ( $body['required'] ?? array() ), true ),
						'desc'     => (string) ( $schema['description'] ?? '' ),
						'schema'   => (array) $schema,
					);
				}

				$reply  = array();
				$raw    = array();
				$status = 200;

				foreach ( (array) $op['responses'] as $code => $response ) {
					if ( (int) $code >= 400 ) {
						continue;
					}

					$status = (int) $code;

					$raw = (array) ( $response['content']['application/json']['schema']['properties'] ?? array() );

					foreach ( $raw as $name => $schema ) {
						$reply[] = array(
							'name' => (string) $name,
							'type' => self::type( (array) $schema ),
							'desc' => (string) ( $schema['description'] ?? '' ),
						);
					}
				}

				$out[] = array(
					'method'  => strtoupper( (string) $method ),
					'path'    => (string) $path,
					'summary' => (string) $op['summary'],
					'scope'   => (string) ( $op['x-required-scope'] ?? 'none' ),
					'params'  => $params,
					'reply'   => $reply,
					'reply_schema' => $raw,
					'status'  => $status,
					'errors'  => array_values( array_filter( array_map( 'intval', array_keys( (array) $op['responses'] ) ), static fn( $code ) => $code >= 400 ) ),
				);
			}
		}

		return $out;
	}

	/**
	 * Error statuses and their meaning, from the document.
	 *
	 * @return array<int, string>
	 */
	public static function errors(): array {
		$out = array();

		foreach ( self::operations() as $op ) {
			foreach ( $op['errors'] as $code ) {
				$out[ $code ] = '';
			}
		}

		foreach ( (array) OpenApi::document()['paths'] as $methods ) {
			foreach ( (array) $methods as $op ) {
				foreach ( (array) $op['responses'] as $code => $response ) {
					if ( (int) $code >= 400 ) {
						$out[ (int) $code ] = (string) $response['description'];
					}
				}
			}
		}

		ksort( $out );

		return $out;
	}

	/**
	 * The MCP tools, read from the tool table: name, what it does, and the level that is needed.
	 *
	 * @return array<int, array{name: string, description: string, level: string}>
	 */
	public static function tools(): array {
		$out = array();

		foreach ( Tools::definitions() as $name => $tool ) {
			$out[] = array(
				'name'        => (string) $name,
				'description' => (string) $tool['description'],
				'level'       => (string) ( $tool['level'] ?? ( ! empty( $tool['write'] ) ? Tokens::ACTIONS : Tokens::READ ) ),
			);
		}

		return $out;
	}

	/**
	 * A short type name for a JSON schema.
	 *
	 * @param array<string, mixed> $schema Schema.
	 */
	private static function type( array $schema ): string {
		$type = $schema['type'] ?? 'string';
		$type = is_array( $type ) ? implode( ' or ', array_map( 'strval', $type ) ) : (string) $type;

		return 'array' === $type && isset( $schema['items']['type'] ) && is_string( $schema['items']['type'] ) ? 'array of ' . $schema['items']['type'] : $type;
	}
}
