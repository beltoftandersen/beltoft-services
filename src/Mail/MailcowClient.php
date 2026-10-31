<?php
/**
 * What the plugin needs from a mailcow server.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Mail;

defined( 'ABSPATH' ) || exit;

/**
 * The mailcow REST API as far as the mail driver uses it. Every method returns the decoded reply or a WP_Error whose
 * code is one of: mailcow_exists (the object is there already), mailcow_error (mailcow refused it, data key = mailcow's
 * message key), mailcow_http (a non-2xx status) or mailcow_transport (no answer). The error text never holds the key.
 *
 * Sizes follow mailcow: quotas are sent in MiB, and a mailbox read gives quota and quota_used in bytes.
 */
interface MailcowClient {

	/**
	 * POST add/domain.
	 *
	 * @param array<string, mixed> $attr Domain attributes (domain, active, mailboxes, aliases, defquota, maxquota, quota, ...).
	 * @return array<int, mixed>|\WP_Error
	 */
	public function add_domain( array $attr );

	/**
	 * POST edit/domain.
	 *
	 * @param string               $domain Domain.
	 * @param array<string, mixed> $attr   Attributes to change.
	 * @return array<int, mixed>|\WP_Error
	 */
	public function edit_domain( string $domain, array $attr );

	/**
	 * GET get/domain/<domain>.
	 *
	 * @param string $domain Domain.
	 * @return array<string, mixed>|\WP_Error|null Null when the domain does not exist.
	 */
	public function get_domain( string $domain );

	/**
	 * POST delete/domain.
	 *
	 * @param string $domain Domain.
	 * @return array<int, mixed>|\WP_Error
	 */
	public function delete_domain( string $domain );

	/**
	 * POST add/dkim.
	 *
	 * @param string $domain   Domain.
	 * @param string $selector DKIM selector.
	 * @param int    $size     Key size in bits.
	 * @return array<int, mixed>|\WP_Error
	 */
	public function add_dkim( string $domain, string $selector, int $size );

	/**
	 * GET get/dkim/<domain>.
	 *
	 * @param string $domain Domain.
	 * @return array<string, mixed>|\WP_Error|null Null when the domain has no key (dkim_txt, pubkey, dkim_selector, length).
	 */
	public function get_dkim( string $domain );

	/**
	 * POST add/mailbox.
	 *
	 * @param array<string, mixed> $attr local_part, domain, name, quota (MiB), password, password2, active, force_pw_update.
	 * @return array<int, mixed>|\WP_Error
	 */
	public function add_mailbox( array $attr );

	/**
	 * POST edit/mailbox.
	 *
	 * @param string               $email Mailbox address.
	 * @param array<string, mixed> $attr  Attributes to change (quota, active, password, password2).
	 * @return array<int, mixed>|\WP_Error
	 */
	public function edit_mailbox( string $email, array $attr );

	/**
	 * GET get/mailbox/<email>.
	 *
	 * @param string $email Mailbox address.
	 * @return array<string, mixed>|\WP_Error|null Null when it does not exist.
	 */
	public function get_mailbox( string $email );

	/**
	 * GET get/mailbox/all/<domain>.
	 *
	 * @param string $domain Domain.
	 * @return array<int, array<string, mixed>>|\WP_Error Rows with username, quota and quota_used (bytes), active, messages.
	 */
	public function list_mailboxes( string $domain );

	/**
	 * POST delete/mailbox.
	 *
	 * @param string $email Mailbox address.
	 * @return array<int, mixed>|\WP_Error
	 */
	public function delete_mailbox( string $email );

	/**
	 * POST edit/rl-mbox: the sending limit of one mailbox. Mailcow keeps one value and one frame per mailbox.
	 *
	 * @param string $email Mailbox address.
	 * @param int    $value Messages per frame.
	 * @param string $frame s, m, h or d.
	 * @return array<int, mixed>|\WP_Error
	 */
	public function set_mailbox_rate_limit( string $email, int $value, string $frame );

	/**
	 * POST add/alias.
	 *
	 * @param string $address Alias address.
	 * @param string $goto    Comma separated destinations.
	 * @return int|\WP_Error The alias id (0 when mailcow did not report it).
	 */
	public function add_alias( string $address, string $goto );

	/**
	 * GET get/alias/all, narrowed to one domain.
	 *
	 * @param string $domain Domain.
	 * @return array<int, array<string, mixed>>|\WP_Error Rows with id, address, goto, active.
	 */
	public function list_aliases( string $domain );

	/**
	 * POST delete/alias. Mailcow deletes an alias by its numeric id, not by its address.
	 *
	 * @param int $id Alias id.
	 * @return array<int, mixed>|\WP_Error
	 */
	public function delete_alias( int $id );

	/**
	 * POST edit/rl-domain: the sending limit of a whole domain.
	 *
	 * @param string $domain Domain.
	 * @param int    $value  Messages per frame.
	 * @param string $frame  s, m, h or d.
	 * @return array<int, mixed>|\WP_Error
	 */
	public function set_domain_rate_limit( string $domain, int $value, string $frame );
}
