<?php
/**
 * The public API reference page.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Api;

use BeltoftServices\Mcp\Access;
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
	public static function shortcode(): string {
		return self::html();
	}

	/**
	 * The address of the documentation page, empty when the setting has none.
	 */
	public static function page_url(): string {
		return esc_url_raw( (string) \BeltoftServices\Settings::get( 'api_docs_url' ) );
	}

	/**
	 * The reference as HTML.
	 */
	public static function html(): string {
		/**
		 * Whether to load the API reference stylesheet.
		 *
		 * @param bool $load Load it, default true.
		 */
		if ( apply_filters( 'beltoft_services_api_reference_css', true ) ) {
			wp_enqueue_style( self::HANDLE, plugins_url( 'assets/api-reference.css', BELTOFT_SERVICES_FILE ), array(), \BeltoftServices\Assets::version( 'assets/api-reference.css' ) );
		}

		ob_start();
		wc_get_template( 'api-reference.php', self::data(), 'beltoft-services/', BELTOFT_SERVICES_PATH . 'templates/' );

		return (string) ob_get_clean();
	}

	/**
	 * What the template prints.
	 *
	 * @return array<string, mixed>
	 */
	public static function data(): array {
		return array(
			'base'     => untrailingslashit( rest_url( Routes::NAMESPACE ) ),
			'spec_url' => rest_url( Routes::NAMESPACE . '/openapi.json' ),
			'rate'     => (int) apply_filters( 'beltoft_services_token_rate_limit', Tokens::RATE_LIMIT ),
			'scopes'   => Tokens::scopes(),
			'ops'      => self::operations(),
			'errors'   => self::errors(),
			'mcp'      => array(
				'on'    => Access::enabled(),
				'url'   => Endpoint::url(),
				'tools' => self::tools(),
			),
		);
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
					);
				}

				$reply  = array();
				$status = 200;

				foreach ( (array) $op['responses'] as $code => $response ) {
					if ( (int) $code >= 400 ) {
						continue;
					}

					$status = (int) $code;

					foreach ( (array) ( $response['content']['application/json']['schema']['properties'] ?? array() ) as $name => $schema ) {
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
