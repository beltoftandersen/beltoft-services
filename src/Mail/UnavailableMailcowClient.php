<?php
/**
 * The client used when live mail is allowed and chosen but cannot be built.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Mail;

defined( 'ABSPATH' ) || exit;

/**
 * Answers every call with mailcow_transport, so jobs retry and nothing is recorded as done. Used instead of the fake
 * client when wp-config.php allows live mail and the settings chose live, but the address or key is missing or
 * unreadable (a changed BELTOFT_SERVICES_KEY, a cleared address).
 */
final class UnavailableMailcowClient implements MailcowClient {

	public function add_domain( array $attr ) {
		return $this->fail();
	}

	public function edit_domain( string $domain, array $attr ) {
		return $this->fail();
	}

	public function get_domain( string $domain ) {
		return $this->fail();
	}

	public function delete_domain( string $domain ) {
		return $this->fail();
	}

	public function add_dkim( string $domain, string $selector, int $size ) {
		return $this->fail();
	}

	public function get_dkim( string $domain ) {
		return $this->fail();
	}

	public function add_mailbox( array $attr ) {
		return $this->fail();
	}

	public function edit_mailbox( string $email, array $attr ) {
		return $this->fail();
	}

	public function get_mailbox( string $email ) {
		return $this->fail();
	}

	public function list_mailboxes( string $domain ) {
		return $this->fail();
	}

	public function delete_mailbox( string $email ) {
		return $this->fail();
	}

	public function set_mailbox_rate_limit( string $email, int $value, string $frame ) {
		return $this->fail();
	}

	public function add_alias( string $address, string $goto ) {
		return $this->fail();
	}

	public function list_aliases( string $domain ) {
		return $this->fail();
	}

	public function delete_alias( int $id ) {
		return $this->fail();
	}

	public function set_domain_rate_limit( string $domain, int $value, string $frame ) {
		return $this->fail();
	}

	public function set_smtp_access( string $email, bool $allow ) {
		return $this->fail();
	}

	/**
	 * The one answer.
	 */
	private function fail(): \WP_Error {
		return new \WP_Error( 'mailcow_transport', 'The mail server connection is not configured.' );
	}
}
