<?php
/**
 * What a feature tells the DNS watch about the records it needs.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Domains;

use BeltoftServices\Resource;

defined( 'ABSPATH' ) || exit;

/**
 * A feature (email, later apps, hosting, SSL) registers one object through the filter beltoft_services_dns_watches, keyed by its id.
 * The engine (DnsWatch) does the looking, the confirming, the mails and the words; the feature answers only what is its own: which
 * records it needs, how a found value is read, how a record is written into a zone of ours, and what it does when something changes.
 *
 * Instances live in memory only. Stored data holds the feature id and the meta prefix, never a class name.
 */
interface DnsWatchFeature {

	/** The feature id: mail, app, hosting, ssl. */
	public function id(): string;

	/** The word that starts the staff alert subjects, for example "Mail". */
	public function label(): string;

	/** What is missing, in words, for a staff subject: "email DNS". */
	public function noun(): string;

	/** The driver id (activation kind) of the resources it watches. */
	public function kind(): string;

	/** Prefix of the resource meta the state lives in: the status key itself, and prefix_since, _checked and so on. */
	public function prefix(): string;

	/** Resource meta key that holds the rows (what was found per record). */
	public function rows_key(): string;

	/** Resource meta key that holds ours|external. */
	public function mode_key(): string;

	/**
	 * Action Scheduler hook of a job. Queued actions hold the hook name, so a feature that has queued jobs keeps its names.
	 *
	 * @param string $job look, recheck_all, recheck or confirm.
	 */
	public function hook( string $job ): string;

	/**
	 * Does the feature show the DNS word in the list rows, badges and status poll of this resource?
	 *
	 * @param Resource $r Resource.
	 */
	public function shows_status( Resource $r ): bool;

	/**
	 * The stored rows of a resource, or the rows the feature derives when none are stored yet (apps that were live before the watch).
	 *
	 * @param Resource $r Resource.
	 * @return array<int, mixed>
	 */
	public function rows( Resource $r ): array;

	/**
	 * The status as stored, for the status the engine writes: waiting, ok or timeout. Apps store ok as live.
	 *
	 * @param string $status Engine status.
	 */
	public function status_in( string $status ): string;

	/**
	 * The status the engine reads, for the status as stored.
	 *
	 * @param string $status Stored status.
	 */
	public function status_out( string $status ): string;

	/**
	 * May the look run now? Return false to skip it (apps: the server is not ready, or the clock of the wait has not started and the
	 * feature starts it itself).
	 *
	 * @param Resource $r Resource.
	 */
	public function before_look( Resource $r ): bool;

	/**
	 * Does the feature want this customer email? Apps send none while the first wait for the name runs.
	 *
	 * @param string   $event reminder, lost, back or timeout.
	 * @param Resource $r     Resource.
	 */
	public function mails( string $event, Resource $r ): bool;

	/** Is the resource one of this feature's? */
	public function owns( Resource $r ): bool;

	/** Is the service in the state in which its records are looked at again (ready)? */
	public function active( Resource $r ): bool;

	/** The domain the records belong to, as stored. */
	public function domain( Resource $r ): string;

	/** The customer whose zone may be written, 0 for none. */
	public function zone_user( Resource $r ): int;

	/** Is the domain a zone of ours for this customer right now (live, not stored)? */
	public function on_our_servers( Resource $r ): bool;

	/**
	 * The keys of the records that must be found, whatever rows are stored.
	 *
	 * @return string[]
	 */
	public function needed(): array;

	/**
	 * Does the record count as needed? Only needed records decide whether the watch is ok; the others are looked for and shown.
	 *
	 * @param string $key Record key.
	 */
	public function required( string $key ): bool;

	/**
	 * Reads public DNS for one stored row and returns it with its status.
	 *
	 * @param array<string, mixed> $row    Stored row.
	 * @param string               $domain Domain.
	 * @return array<string, mixed>|null The row, or null when the lookup failed (nothing is learned).
	 */
	public function read_row( array $row, string $domain ): ?array;

	/**
	 * Writes one row into the customer's zone, only where it is missing and free. Through ZoneWriter only.
	 *
	 * @param int                  $user_id Customer.
	 * @param string               $domain  Zone.
	 * @param array<string, mixed> $row     Stored row.
	 * @return array{0:string,1:string,2:bool}|\WP_Error Status, reason, whether this call wrote it; or a transient error.
	 */
	public function publish( int $user_id, string $domain, array $row );

	/**
	 * The rows still to add, as the customer would type them: key, name (full), type, value, priority, status.
	 *
	 * @param Resource $r           Resource.
	 * @param bool     $needed_only Leave out the recommended records.
	 * @return array<int, array<string, mixed>>
	 */
	public function missing_rows( Resource $r, bool $needed_only = false ): array;

	/** The state word of a part that works while another part does not ('' when the feature has none). */
	public function partial_state(): string;

	/** The words of the partial state, for the list row and the badge. */
	public function partial_word(): string;

	/**
	 * Does a part work while another does not? Mail: the MX is found, SPF or DKIM is not.
	 *
	 * @param Resource $r       Resource.
	 * @param string[] $ok_keys Keys of the records that are found.
	 */
	public function partial_applies( Resource $r, array $ok_keys ): bool;

	/**
	 * Is something held right now because of the missing records (mail: sending)?
	 *
	 * @param Resource $r Resource.
	 */
	public function held( Resource $r ): bool;

	/**
	 * The values of the generic emails: dns_name, dns_what_stops, dns_where, dns_link, dns_missing, dns_lost, dns_lost_names, dns_moved, dns_guide.
	 *
	 * @param string   $event reminder, lost, back or timeout.
	 * @param Resource $r     Resource.
	 * @return array<string, mixed>
	 */
	public function copy( string $event, Resource $r ): array;

	/**
	 * Lines for the staff alert about an event, after the generic ones.
	 *
	 * @param string               $event lost, left or no_dns.
	 * @param Resource             $r     Resource.
	 * @param array<string, mixed> $ctx   For left: lost (needed records not found at the new DNS).
	 * @return string[]
	 */
	public function alert_lines( string $event, Resource $r, array $ctx = array() ): array;

	/**
	 * The feature's own reaction to an event: changed (rows were stored: mail syncs its sending hold), found, lost_confirmed, back, timeout,
	 * moved_away, moved_here, slow_stop. Never pause, delete or reinstall anything because of a DNS finding.
	 *
	 * @param string               $event Event.
	 * @param Resource             $r     Resource.
	 * @param array<string, mixed> $ctx   Event data.
	 */
	public function on_event( string $event, Resource $r, array $ctx = array() ): void;
}
