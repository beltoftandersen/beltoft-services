<?php
/**
 * An offline mailcow.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Mail;

defined( 'ABSPATH' ) || exit;

/**
 * Keeps domains, mailboxes and aliases in memory (or, with $persist, in an option, so staging keeps them between
 * requests) and answers like mailcow does, as far as it is documented: success is a list with a "success" item, a
 * missing object reads as null, a duplicate is mailcow_exists, a domain limit is mailcow_error with the key
 * max_mailbox_exceeded or max_alias_exceeded. `fail_next( $method, $error )` makes the next call of a method fail,
 * and `calls()` lists what was called (never a key: there is none). Tests inject it with the filter
 * beltoft_services_mail_client.
 */
class FakeMailcowClient implements MailcowClient {

	public const OPTION = 'beltoft_services_fake_mailcow';

	/** @var array<string, array<string, mixed>> */
	private $domains = array();

	/** @var array<string, array<string, mixed>> */
	private $dkim = array();

	/** @var array<string, array<string, mixed>> */
	private $mailboxes = array();

	/** @var array<int, array<string, mixed>> */
	private $aliases = array();

	/** @var int */
	private $next_alias = 1;

	/** @var array<int, array<int, mixed>> */
	private $calls = array();

	/** @var array<string, array{0:\WP_Error,1:int}> */
	private $failures = array();

	/** @var bool */
	private $persist;

	/**
	 * @param bool $persist Keep the state in an option.
	 */
	public function __construct( bool $persist = false ) {
		$this->persist = $persist;
		$this->load();
	}

	/**
	 * Calls made so far, each as [ method, argument, ... ].
	 *
	 * @return array<int, array<int, mixed>>
	 */
	public function calls(): array {
		return $this->calls;
	}

	/**
	 * Calls of one method.
	 *
	 * @param string $method Method name.
	 * @return array<int, array<int, mixed>>
	 */
	public function calls_of( string $method ): array {
		return array_values( array_filter( $this->calls, static fn( $call ) => $call[0] === $method ) );
	}

	/**
	 * A call of a method fails once with this error: the next one, or the one after $skip calls that work.
	 *
	 * @param string    $method Method name.
	 * @param \WP_Error $error  Error.
	 * @param int       $skip  Calls of the method that still work first.
	 */
	public function fail_next( string $method, \WP_Error $error, int $skip = 0 ): void {
		$this->failures[ $method ] = array( $error, $skip );
	}

	/**
	 * Test access: a stored mailbox row, or null.
	 *
	 * @param string $email Address.
	 * @return array<string, mixed>|null
	 */
	public function mailbox( string $email ): ?array {
		$this->load();

		return $this->mailboxes[ strtolower( $email ) ] ?? null;
	}

	/**
	 * Test access: a stored domain row, or null.
	 *
	 * @param string $domain Domain.
	 * @return array<string, mixed>|null
	 */
	public function domain( string $domain ): ?array {
		$this->load();

		return $this->domains[ strtolower( $domain ) ] ?? null;
	}

	/**
	 * Test access: set the bytes a mailbox uses.
	 *
	 * @param string $email Address.
	 * @param int    $bytes Bytes.
	 */
	public function set_usage( string $email, int $bytes ): void {
		$this->load();
		$email = strtolower( $email );

		if ( isset( $this->mailboxes[ $email ] ) ) {
			$this->mailboxes[ $email ]['quota_used'] = $bytes;
			$this->save();
		}
	}

	public function add_domain( array $attr ) {
		$domain = strtolower( (string) ( $attr['domain'] ?? '' ) );

		if ( $err = $this->enter( 'add_domain', $domain ) ) {
			return $err;
		}

		if ( isset( $this->domains[ $domain ] ) ) {
			return $this->error( 'domain_exists', $domain );
		}

		$this->domains[ $domain ] = array_merge( array( 'active' => '1', 'mailboxes' => '10', 'aliases' => '400', 'defquota' => '3072', 'maxquota' => '10240', 'quota' => '10240' ), $attr, array( 'domain' => $domain ) );
		$this->save();

		return $this->ok( 'domain_added', $domain );
	}

	public function edit_domain( string $domain, array $attr ) {
		$domain = strtolower( $domain );

		if ( $err = $this->enter( 'edit_domain', $domain, $attr ) ) {
			return $err;
		}

		if ( ! isset( $this->domains[ $domain ] ) ) {
			return $this->error( 'domain_invalid', $domain );
		}

		$this->domains[ $domain ] = array_merge( $this->domains[ $domain ], $attr );
		$this->save();

		return $this->ok( 'domain_modified', $domain );
	}

	public function get_domain( string $domain ) {
		$domain = strtolower( $domain );

		if ( $err = $this->enter( 'get_domain', $domain ) ) {
			return $err;
		}

		return $this->domains[ $domain ] ?? null;
	}

	public function delete_domain( string $domain ) {
		$domain = strtolower( $domain );

		if ( $err = $this->enter( 'delete_domain', $domain ) ) {
			return $err;
		}

		foreach ( array_keys( $this->mailboxes ) as $email ) {
			if ( str_ends_with( $email, '@' . $domain ) ) {
				return $this->error( 'domain_not_empty', $domain );
			}
		}

		unset( $this->domains[ $domain ], $this->dkim[ $domain ] );
		$this->aliases = array_filter( $this->aliases, static fn( $alias ) => $alias['domain'] !== $domain );
		$this->save();

		return $this->ok( 'domain_removed', $domain );
	}

	public function add_dkim( string $domain, string $selector, int $size ) {
		$domain = strtolower( $domain );

		if ( $err = $this->enter( 'add_dkim', $domain, $selector, $size ) ) {
			return $err;
		}

		if ( ! isset( $this->domains[ $domain ] ) ) {
			return $this->error( 'domain_invalid', $domain );
		}

		$this->dkim[ $domain ] = array(
			'dkim_selector' => $selector,
			'length'        => (string) $size,
			'pubkey'        => 'FAKEPUBKEY' . substr( md5( $domain . $selector ), 0, 16 ),
		);
		$this->save();

		return $this->ok( 'dkim_added', $domain );
	}

	public function get_dkim( string $domain ) {
		$domain = strtolower( $domain );

		if ( $err = $this->enter( 'get_dkim', $domain ) ) {
			return $err;
		}

		if ( ! isset( $this->dkim[ $domain ] ) ) {
			return null;
		}

		$row             = $this->dkim[ $domain ];
		$row['dkim_txt'] = 'v=DKIM1;k=rsa;t=s;s=email;p=' . $row['pubkey'];

		return $row;
	}

	public function add_mailbox( array $attr ) {
		$domain = strtolower( (string) ( $attr['domain'] ?? '' ) );
		$email  = strtolower( (string) ( $attr['local_part'] ?? '' ) ) . '@' . $domain;

		if ( $err = $this->enter( 'add_mailbox', $email ) ) {
			return $err;
		}

		if ( ! isset( $this->domains[ $domain ] ) ) {
			return $this->error( 'domain_invalid', $domain );
		}

		if ( isset( $this->mailboxes[ $email ] ) ) {
			return $this->error( 'object_exists', $email );
		}

		$count = count( array_filter( array_keys( $this->mailboxes ), static fn( $e ) => str_ends_with( $e, '@' . $domain ) ) );

		if ( $count >= (int) $this->domains[ $domain ]['mailboxes'] ) {
			return $this->error( 'max_mailbox_exceeded', (string) $count );
		}

		$quota = (int) ( $attr['quota'] ?? 0 );

		if ( $quota > (int) $this->domains[ $domain ]['maxquota'] ) {
			return $this->error( 'mailbox_quota_exceeds_domain_quota', (string) $this->domains[ $domain ]['maxquota'] );
		}

		$this->mailboxes[ $email ] = array(
			'username'   => $email,
			'domain'     => $domain,
			'name'       => (string) ( $attr['name'] ?? '' ),
			'quota'      => $quota * 1048576,
			'quota_used' => 0,
			'active'     => (string) ( $attr['active'] ?? '1' ),
			'messages'   => 0,
			'password'   => 'set',
			'rl'         => false,
		);
		$this->save();

		return $this->ok( 'mailbox_added', $email );
	}

	public function edit_mailbox( string $email, array $attr ) {
		$email = strtolower( $email );

		// A password never enters the call log.
		$logged = $attr;
		unset( $logged['password'], $logged['password2'] );

		if ( $err = $this->enter( 'edit_mailbox', $email, $logged ) ) {
			return $err;
		}

		if ( ! isset( $this->mailboxes[ $email ] ) ) {
			return $this->error( 'access_denied', $email );
		}

		if ( isset( $attr['quota'] ) ) {
			$domain = $this->mailboxes[ $email ]['domain'];

			if ( (int) $attr['quota'] > (int) $this->domains[ $domain ]['maxquota'] ) {
				return $this->error( 'mailbox_quota_exceeds_domain_quota', (string) $this->domains[ $domain ]['maxquota'] );
			}

			$this->mailboxes[ $email ]['quota'] = (int) $attr['quota'] * 1048576;
		}

		if ( isset( $attr['active'] ) ) {
			$this->mailboxes[ $email ]['active'] = (string) $attr['active'];
		}

		if ( isset( $attr['password'] ) ) {
			$this->mailboxes[ $email ]['password'] = 'changed';
		}

		$this->save();

		return $this->ok( 'mailbox_modified', $email );
	}

	public function get_mailbox( string $email ) {
		$email = strtolower( $email );

		if ( $err = $this->enter( 'get_mailbox', $email ) ) {
			return $err;
		}

		return $this->mailboxes[ $email ] ?? null;
	}

	public function list_mailboxes( string $domain ) {
		$domain = strtolower( $domain );

		if ( $err = $this->enter( 'list_mailboxes', $domain ) ) {
			return $err;
		}

		return array_values( array_filter( $this->mailboxes, static fn( $row ) => $row['domain'] === $domain ) );
	}

	public function delete_mailbox( string $email ) {
		$email = strtolower( $email );

		if ( $err = $this->enter( 'delete_mailbox', $email ) ) {
			return $err;
		}

		unset( $this->mailboxes[ $email ] );
		$this->save();

		return $this->ok( 'mailbox_removed', $email );
	}

	public function set_mailbox_rate_limit( string $email, int $value, string $frame ) {
		$email = strtolower( $email );

		if ( $err = $this->enter( 'set_mailbox_rate_limit', $email, $value, $frame ) ) {
			return $err;
		}

		if ( ! isset( $this->mailboxes[ $email ] ) ) {
			return $this->error( 'access_denied', $email );
		}

		$this->mailboxes[ $email ]['rl'] = array( 'value' => $value, 'frame' => $frame );
		$this->save();

		return $this->ok( 'rl_saved', $email );
	}

	public function add_alias( string $address, string $goto ) {
		$address = strtolower( $address );

		if ( $err = $this->enter( 'add_alias', $address, $goto ) ) {
			return $err;
		}

		$domain = (string) substr( strrchr( $address, '@' ) ?: '', 1 );

		if ( ! isset( $this->domains[ $domain ] ) ) {
			return $this->error( 'domain_invalid', $domain );
		}

		if ( isset( $this->mailboxes[ $address ] ) ) {
			return $this->error( 'is_alias_or_mailbox', $address );
		}

		foreach ( $this->aliases as $alias ) {
			if ( $alias['address'] === $address ) {
				return $this->error( 'is_alias_or_mailbox', $address );
			}
		}

		$count = count( array_filter( $this->aliases, static fn( $alias ) => $alias['domain'] === $domain ) );

		if ( $count >= (int) $this->domains[ $domain ]['aliases'] ) {
			return $this->error( 'max_alias_exceeded', (string) $count );
		}

		$id                   = $this->next_alias++;
		$this->aliases[ $id ] = array( 'id' => $id, 'address' => $address, 'goto' => strtolower( $goto ), 'domain' => $domain, 'active' => 1 );
		$this->save();

		return $id;
	}

	public function list_aliases( string $domain ) {
		$domain = strtolower( $domain );

		if ( $err = $this->enter( 'list_aliases', $domain ) ) {
			return $err;
		}

		return array_values( array_filter( $this->aliases, static fn( $alias ) => $alias['domain'] === $domain ) );
	}

	public function delete_alias( int $id ) {
		if ( $err = $this->enter( 'delete_alias', $id ) ) {
			return $err;
		}

		unset( $this->aliases[ $id ] );
		$this->save();

		return $this->ok( 'alias_removed', (string) $id );
	}

	/**
	 * Logs a call, reloads the state and returns a queued failure for the method, once.
	 *
	 * @param string $method Method.
	 * @param mixed  ...$args Arguments.
	 */
	private function enter( string $method, ...$args ): ?\WP_Error {
		$this->calls[] = array_merge( array( $method ), $args );
		$this->load();

		if ( isset( $this->failures[ $method ] ) ) {
			if ( $this->failures[ $method ][1] > 0 ) {
				--$this->failures[ $method ][1];

				return null;
			}

			$error = $this->failures[ $method ][0];
			unset( $this->failures[ $method ] );

			return $error;
		}

		return null;
	}

	/**
	 * A mailcow success reply.
	 *
	 * @param string $key  Message key.
	 * @param string $name Object.
	 * @return array<int, array<string, mixed>>
	 */
	private function ok( string $key, string $name ): array {
		return array( array( 'type' => 'success', 'log' => array(), 'msg' => array( $key, $name ) ) );
	}

	/**
	 * A mailcow refusal, in the shape LiveMailcowClient::parse() gives.
	 *
	 * @param string $key  Message key.
	 * @param string $name Object.
	 */
	private function error( string $key, string $name ): \WP_Error {
		$exists = in_array( $key, array( 'domain_exists', 'object_exists', 'is_alias_or_mailbox' ), true );

		return new \WP_Error( $exists ? 'mailcow_exists' : 'mailcow_error', 'The mail server refused it: ' . $key . ' ' . $name, array( 'key' => $key, 'status' => 200 ) );
	}

	private function load(): void {
		if ( ! $this->persist ) {
			return;
		}

		$saved = get_option( self::OPTION, array() );
		$saved = is_array( $saved ) ? $saved : array();

		$this->domains    = (array) ( $saved['domains'] ?? array() );
		$this->dkim       = (array) ( $saved['dkim'] ?? array() );
		$this->mailboxes  = (array) ( $saved['mailboxes'] ?? array() );
		$this->aliases    = (array) ( $saved['aliases'] ?? array() );
		$this->next_alias = max( 1, (int) ( $saved['next_alias'] ?? 1 ) );
	}

	private function save(): void {
		if ( ! $this->persist ) {
			return;
		}

		$state = array( 'domains' => $this->domains, 'dkim' => $this->dkim, 'mailboxes' => $this->mailboxes, 'aliases' => $this->aliases, 'next_alias' => $this->next_alias );

		if ( false === get_option( self::OPTION, false ) ) {
			add_option( self::OPTION, $state, '', false );
		} else {
			update_option( self::OPTION, $state, false );
		}
	}
}
