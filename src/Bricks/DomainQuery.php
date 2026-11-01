<?php
/**
 * The domain search for native Bricks query loops: a query type "Domain availability", dynamic data tags for a result row,
 * and the one-click add link a result row's button uses.
 *
 * The rows come from the same search code as the REST route (Search::visitor_search: per-visitor limits and the site-wide
 * cap), called in the visitor's own request. The term is read from a URL parameter.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Bricks;

use BeltoftServices\Domains\Cart;
use BeltoftServices\Domains\Search;
use BeltoftServices\RateLimit;

defined( 'ABSPATH' ) || exit;

/**
 * Query type, tags and add handler.
 */
final class DomainQuery {

	public const TYPE   = 'beltoft_domains';
	public const ACTION = 'beltoft_domain_add';

	/** Tag name => label. */
	public const TAGS = array(
		'bds_domain'        => 'Domain',
		'bds_tld'           => 'Extension',
		'bds_status'        => 'Status (available, taken, unknown)',
		'bds_available'     => 'Available (1 or 0)',
		'bds_price'         => 'Price per year',
		'bds_renewal_price' => 'Renewal price',
		'bds_add_url'       => 'Add to order link',
		'bds_search_term'   => 'Search term',
		'bds_error'         => 'Search error message',
	);

	/**
	 * The last search of this request: term, rows and error.
	 *
	 * @var array<string, array>
	 */
	private static $memo = array();

	public static function register(): void {
		add_filter( 'bricks/setup/control_options', array( __CLASS__, 'query_type' ) );
		add_filter( 'bricks/query/run', array( __CLASS__, 'run' ), 10, 2 );
		add_filter( 'bricks/query/loop_object_id', array( __CLASS__, 'loop_object_id' ), 10, 2 );
		add_filter( 'bricks/query/loop_object_type', array( __CLASS__, 'loop_object_type' ), 10, 2 );
		add_filter( 'bricks/dynamic_tags_list', array( __CLASS__, 'tags_list' ) );
		add_filter( 'bricks/dynamic_data/render_tag', array( __CLASS__, 'render_tag' ), 20, 3 );
		add_filter( 'bricks/dynamic_data/render_content', array( __CLASS__, 'render_content' ), 20, 3 );
		add_filter( 'bricks/frontend/render_data', array( __CLASS__, 'render_content' ), 20, 2 );
		add_action( 'template_redirect', array( __CLASS__, 'no_cache' ), 1 );
		add_action( 'admin_post_' . self::ACTION, array( __CLASS__, 'handle_add' ) );
		add_action( 'admin_post_nopriv_' . self::ACTION, array( __CLASS__, 'handle_add' ) );
	}

	/** The URL parameter that carries the search term. */
	public static function param(): string {
		$param = sanitize_key( (string) apply_filters( 'beltoft_services_domain_search_param', 'domain' ) );
		return '' !== $param ? $param : 'domain';
	}

	/** The term from the URL, as typed. */
	public static function term(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a public, read-only search term.
		$value = isset( $_GET[ self::param() ] ) && is_scalar( $_GET[ self::param() ] ) ? sanitize_text_field( wp_unslash( (string) $_GET[ self::param() ] ) ) : '';
		return trim( $value );
	}

	public static function query_type( $options ) {
		if ( is_array( $options ) && isset( $options['queryTypes'] ) && is_array( $options['queryTypes'] ) ) {
			$options['queryTypes'][ self::TYPE ] = esc_html__( 'Domain availability', 'beltoft-services' );
		}
		return $options;
	}

	/** The search of this request (once): array( term, rows, error ). */
	public static function search(): array {
		$term = self::term();
		if ( isset( self::$memo[ $term ] ) ) { return self::$memo[ $term ]; }
		$out = array( 'term' => $term, 'rows' => array(), 'error' => '' );
		if ( '' !== $term ) {
			$reply = Search::visitor_search( $term );
			if ( is_wp_error( $reply ) ) {
				$out['error'] = $reply->get_error_message();
			} else {
				$out['rows'] = self::rows( (array) ( $reply['results'] ?? array() ), $term );
			}
		}
		self::$memo = array( $term => $out );
		return $out;
	}

	/** The rows the search script shows: an extension that is not sold is left out, unless it is the exact one typed. */
	public static function rows( array $results, string $typed ): array {
		$typed_extension = strpos( $typed, '.' ) > 0;
		$rows            = array();
		foreach ( array_values( $results ) as $index => $item ) {
			if ( ! is_array( $item ) || empty( $item['domain'] ) ) { continue; }
			$status = (string) ( $item['status'] ?? '' );
			if ( 'not_sold' === $status && ! ( $typed_extension && 0 === $index ) ) { continue; }
			$free   = 'free' === $status;
			$domain = (string) $item['domain'];
			$price  = isset( $item['price'] ) && null !== $item['price'] ? (string) $item['price'] : '';
			$rows[] = array(
				'__bds'         => 1,
				'domain'        => $domain,
				'tld'           => (string) substr( $domain, (int) strpos( $domain, '.' ) + 1 ),
				'status'        => $free ? 'available' : ( 'taken' === $status ? 'taken' : 'unknown' ),
				'available'     => $free ? 1 : 0,
				'price'         => '' !== $price ? sprintf( /* translators: %s: price in euro */ __( '€%s / year', 'beltoft-services' ), $price ) : '',
				'renewal_price' => $free && '' !== $price ? sprintf( /* translators: %s: price in euro */ __( 'Renews at €%s / year', 'beltoft-services' ), $price ) : '',
				'note'          => $free ? (string) ( $item['note'] ?? '' ) : '',
				'primary'       => 0 === count( $rows ) ? 1 : 0,
				'add_url'       => $free ? self::add_url( $domain ) : '',
			);
		}
		return $rows;
	}

	public static function add_url( string $domain ): string {
		return add_query_arg(
			array( 'action' => self::ACTION, 'domain' => $domain, '_wpnonce' => wp_create_nonce( self::ACTION . '_' . $domain ) ),
			admin_url( 'admin-post.php' )
		);
	}

	/** The loop's rows. Pages that run this query depend on the visitor's search, so they are not cacheable. */
	public static function run( $result, $query ) {
		if ( ! is_object( $query ) || self::TYPE !== ( $query->object_type ?? '' ) ) { return $result; }
		if ( ! headers_sent() ) { nocache_headers(); }
		if ( ! defined( 'DONOTCACHEPAGE' ) ) { define( 'DONOTCACHEPAGE', true ); }
		return self::search()['rows'];
	}

	/** Sends no-cache headers early for a page that has a search term and a Domain availability loop. */
	public static function no_cache(): void {
		if ( '' === self::term() || ! is_singular() ) { return; }
		$meta = get_post_meta( (int) get_queried_object_id(), '_bricks_page_content_2', true );
		$raw  = is_array( $meta ) ? (string) wp_json_encode( $meta ) : (string) $meta;
		if ( false !== strpos( $raw, self::TYPE ) ) {
			nocache_headers();
			if ( ! defined( 'DONOTCACHEPAGE' ) ) { define( 'DONOTCACHEPAGE', true ); }
		}
	}

	/** The row of the loop iteration in progress, or null outside this query type. */
	public static function current_row(): ?array {
		if ( ! class_exists( '\\Bricks\\Query' ) ) { return null; }
		$object = \Bricks\Query::get_loop_object();
		return is_array( $object ) && ! empty( $object['__bds'] ) ? $object : null;
	}

	public static function loop_object_id( $id, $object ) {
		return is_array( $object ) && ! empty( $object['__bds'] ) ? abs( crc32( (string) $object['domain'] ) ) : $id;
	}

	public static function loop_object_type( $type, $object ) {
		return is_array( $object ) && ! empty( $object['__bds'] ) ? self::TYPE : $type;
	}

	public static function tags_list( $tags ) {
		$tags = is_array( $tags ) ? $tags : array();
		foreach ( self::TAGS as $name => $label ) {
			$tags[] = array( 'name' => '{' . $name . '}', 'label' => $label, 'group' => esc_html__( 'Domain search', 'beltoft-services' ) );
		}
		return $tags;
	}

	/** The value of one tag, in the current loop row (row tags are empty outside the loop). */
	public static function value( string $name ): string {
		if ( 'bds_search_term' === $name ) { return self::search()['term']; }
		if ( 'bds_error' === $name ) { return self::search()['error']; }
		$row = self::current_row();
		if ( null === $row ) { return ''; }
		$key = 'bds_' === substr( $name, 0, 4 ) ? substr( $name, 4 ) : $name;
		return isset( $row[ $key ] ) ? (string) $row[ $key ] : '';
	}

	public static function render_tag( $value, $post = null, $context = 'text' ) {
		unset( $post, $context );
		if ( ! is_string( $value ) ) { return $value; }
		$name = trim( $value, '{}' );
		return isset( self::TAGS[ $name ] ) ? self::value( $name ) : $value;
	}

	public static function render_content( $content, $post = null, $context = 'text' ) {
		unset( $post );
		if ( ! is_string( $content ) || false === strpos( $content, '{bds_' ) ) { return $content; }
		return (string) preg_replace_callback(
			'/\{(bds_[a-z_]+)\}/',
			static function ( $m ) use ( $context ) {
				if ( ! isset( self::TAGS[ $m[1] ] ) ) { return $m[0]; }
				$value = self::value( $m[1] );
				return 'link' === $context ? esc_url_raw( $value ) : esc_html( $value );
			},
			$content
		);
	}

	/** The add link: checks the one-use nonce and the limits, asks the registrar again, then goes on to the checkout. Registration only. */
	public static function handle_add(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- the nonce is checked on the next lines, for this exact domain.
		$domain = isset( $_GET['domain'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['domain'] ) ) : '';
		$nonce  = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['_wpnonce'] ) ) : '';
		// phpcs:enable
		if ( ! wp_verify_nonce( $nonce, self::ACTION . '_' . $domain ) ) {
			wp_die( esc_html__( 'This link has expired. Go back, search again and try again.', 'beltoft-services' ), '', array( 'response' => 403, 'back_link' => true ) );
		}
		if ( RateLimit::exceeded( RateLimit::visitor(), 'domain-cart', (int) apply_filters( 'beltoft_services_domain_cart_limit', 20 ) ) ) {
			wp_die( esc_html__( 'Too many requests. Please wait a minute.', 'beltoft-services' ), '', array( 'response' => 429, 'back_link' => true ) );
		}
		// add_line asks the registrar again and refuses a name that is not free.
		$result = Cart::add_line( $domain, 'register' );
		if ( is_wp_error( $result ) ) {
			$data = $result->get_error_data();
			wp_die( esc_html( $result->get_error_message() ), '', array( 'response' => (int) ( is_array( $data ) ? ( $data['status'] ?? 400 ) : 400 ), 'back_link' => true ) );
		}
		nocache_headers();
		wp_safe_redirect( $result['checkout_url'] );
		exit;
	}

	/** Test seam: forget the remembered search. */
	public static function reset(): void {
		self::$memo = array();
	}
}
