<?php
/**
 * The live mailcow client.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Mail;

defined( 'ABSPATH' ) || exit;

/**
 * Talks to a real mailcow (PRODUCTION mail server). Built only by MailClients::for(), which passes the allowance read
 * from the constant BELTOFT_SERVICES_MAIL_LIVE. The API key goes in the X-API-Key header only and is scrubbed from
 * every error text. wp_remote_request() is used, not the safe variant: the host is the admin's own setting, never user
 * input, and may be a private address.
 */
final class LiveMailcowClient implements MailcowClient {

	public const TIMEOUT = 20;

	/** Mailcow message keys that mean "this object is there already". */
	private const EXISTS = array( 'domain_exists', 'object_exists', 'mailbox_exists', 'alias_exists', 'is_alias_or_mailbox' );

	/** @var string */
	private $base;

	/** @var string */
	private $key;

	/**
	 * @param string $base Base URL, https only, without a path.
	 * @param string $key  API key.
	 * @throws \InvalidArgumentException When the URL is not https or the key is empty.
	 */
	public function __construct( string $base, string $key ) {
		$parts = wp_parse_url( trim( $base ) );

		if ( ! is_array( $parts ) || 'https' !== ( $parts['scheme'] ?? '' ) || empty( $parts['host'] ) || '' === $key ) {
			throw new \InvalidArgumentException( 'The mail server address must be an https address and the key must be set.' );
		}

		$this->base = 'https://' . $parts['host'] . ( isset( $parts['port'] ) ? ':' . (int) $parts['port'] : '' );
		$this->key  = $key;
	}

	public function add_domain( array $attr ) {
		return $this->post( 'add/domain', $attr );
	}

	public function edit_domain( string $domain, array $attr ) {
		return $this->post( 'edit/domain', array( 'items' => array( $domain ), 'attr' => $attr ) );
	}

	public function get_domain( string $domain ) {
		return $this->read_one( 'get/domain/' . rawurlencode( $domain ) );
	}

	public function delete_domain( string $domain ) {
		return $this->post( 'delete/domain', array( $domain ) );
	}

	public function add_dkim( string $domain, string $selector, int $size ) {
		return $this->post( 'add/dkim', array( 'domain' => $domain, 'dkim_selector' => $selector, 'key_size' => (string) $size ) );
	}

	public function get_dkim( string $domain ) {
		$row = $this->read_one( 'get/dkim/' . rawurlencode( $domain ) );

		return is_array( $row ) && empty( $row['dkim_txt'] ) ? null : $row;
	}

	public function add_mailbox( array $attr ) {
		return $this->post( 'add/mailbox', $attr );
	}

	public function edit_mailbox( string $email, array $attr ) {
		return $this->post( 'edit/mailbox', array( 'items' => array( $email ), 'attr' => $attr ) );
	}

	public function get_mailbox( string $email ) {
		return $this->read_one( 'get/mailbox/' . rawurlencode( $email ) );
	}

	public function list_mailboxes( string $domain ) {
		$rows = $this->request( 'GET', 'get/mailbox/all/' . rawurlencode( $domain ) );

		return is_wp_error( $rows ) ? $rows : array_values( array_filter( $rows, 'is_array' ) );
	}

	public function delete_mailbox( string $email ) {
		return $this->post( 'delete/mailbox', array( $email ) );
	}

	public function set_mailbox_rate_limit( string $email, int $value, string $frame ) {
		return $this->post( 'edit/rl-mbox', array( 'items' => array( $email ), 'attr' => array( 'rl_value' => (string) $value, 'rl_frame' => $frame ) ) );
	}

	public function set_domain_rate_limit( string $domain, int $value, string $frame ) {
		return $this->post( 'edit/rl-domain', array( 'items' => array( $domain ), 'attr' => array( 'rl_value' => (string) $value, 'rl_frame' => $frame ) ) );
	}

	public function add_alias( string $address, string $goto ) {
		$reply = $this->post( 'add/alias', array( 'address' => $address, 'goto' => $goto, 'active' => '1' ) );

		if ( is_wp_error( $reply ) ) {
			return $reply;
		}

		// Success replies carry msg = [ "alias_added", "<address>", "<id>" ].
		foreach ( $reply as $item ) {
			$msg = is_array( $item ) && is_array( $item['msg'] ?? null ) ? $item['msg'] : array();

			if ( isset( $msg[2] ) && ctype_digit( (string) $msg[2] ) ) {
				return (int) $msg[2];
			}
		}

		return 0;
	}

	public function list_aliases( string $domain ) {
		$rows = $this->request( 'GET', 'get/alias/all' );

		if ( is_wp_error( $rows ) ) {
			return $rows;
		}

		$out = array();

		foreach ( $rows as $row ) {
			if ( is_array( $row ) && strtolower( (string) ( $row['domain'] ?? '' ) ) === strtolower( $domain ) ) {
				$out[] = $row;
			}
		}

		return $out;
	}

	public function delete_alias( int $id ) {
		return $this->post( 'delete/alias', array( (string) $id ) );
	}

	/**
	 * One object read: the row, or null when mailcow answers with an empty list or object.
	 *
	 * @param string $path Path after /api/v1/.
	 * @return array<string, mixed>|\WP_Error|null
	 */
	private function read_one( string $path ) {
		$row = $this->request( 'GET', $path );

		if ( is_wp_error( $row ) ) {
			return $row;
		}

		return array() === $row ? null : $row;
	}

	/**
	 * A write.
	 *
	 * @param string            $path Path after /api/v1/.
	 * @param array<mixed>      $body JSON body.
	 * @return array<int, mixed>|\WP_Error
	 */
	private function post( string $path, array $body ) {
		return $this->request( 'POST', $path, $body );
	}

	/**
	 * One request.
	 *
	 * @param string            $method GET or POST.
	 * @param string            $path   Path after /api/v1/.
	 * @param array<mixed>|null $body   JSON body.
	 * @return array<mixed>|\WP_Error
	 */
	private function request( string $method, string $path, ?array $body = null ) {
		$args = array(
			'method'      => $method,
			'timeout'     => self::TIMEOUT,
			'redirection' => 0,
			'headers'     => array(
				'X-API-Key'    => $this->key,
				'Accept'       => 'application/json',
				'Content-Type' => 'application/json',
			),
		);

		if ( null !== $body ) {
			$args['body'] = (string) wp_json_encode( $body );
		}

		$response = wp_remote_request( $this->base . '/api/v1/' . $path, $args );

		if ( is_wp_error( $response ) ) {
			return new \WP_Error( 'mailcow_transport', $this->scrub( $response->get_error_message() ) );
		}

		return self::parse( (int) wp_remote_retrieve_response_code( $response ), (string) wp_remote_retrieve_body( $response ), $this->key );
	}

	/**
	 * Turns a reply into data or a WP_Error. Mailcow answers 200 with an error item in the body, so the body is read too.
	 *
	 * @param int    $status HTTP status.
	 * @param string $body   Body.
	 * @param string $key    Key to scrub from messages.
	 * @return array<mixed>|\WP_Error
	 */
	public static function parse( int $status, string $body, string $key = '' ) {
		if ( $status < 200 || $status >= 300 ) {
			return new \WP_Error( 'mailcow_http', sprintf( 'The mail server answered HTTP %d.', $status ), array( 'status' => $status ) );
		}

		$json = json_decode( $body, true );

		if ( ! is_array( $json ) ) {
			return new \WP_Error( 'mailcow_error', 'The mail server sent an answer that is not JSON.', array( 'key' => '', 'status' => $status ) );
		}

		$items = isset( $json['type'] ) ? array( $json ) : $json;

		foreach ( $items as $item ) {
			if ( ! is_array( $item ) || ! in_array( $item['type'] ?? '', array( 'error', 'danger' ), true ) ) {
				continue;
			}

			$msg  = $item['msg'] ?? '';
			$name = is_array( $msg ) ? (string) ( $msg[0] ?? '' ) : (string) $msg;
			$text = is_array( $msg ) ? implode( ' ', array_map( 'strval', array_filter( $msg, 'is_scalar' ) ) ) : (string) $msg;
			$text = '' === $key ? $text : str_replace( $key, '***', $text );
			$code = in_array( $name, self::EXISTS, true ) ? 'mailcow_exists' : 'mailcow_error';

			return new \WP_Error( $code, 'The mail server refused it: ' . $text, array( 'key' => $name, 'status' => $status ) );
		}

		return $json;
	}

	/**
	 * Removes the key from a text.
	 *
	 * @param string $text Text.
	 */
	private function scrub( string $text ): string {
		return str_replace( $this->key, '***', $text );
	}
}
