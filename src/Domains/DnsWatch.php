<?php
/**
 * The DNS watch: one look at the DNS records a service needs, for every feature that registers one.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Domains;

use BeltoftServices\Alerts;
use BeltoftServices\Jobs;
use BeltoftServices\Resource;

defined( 'ABSPATH' ) || exit;

/**
 * The engine never names a feature: it asks the DnsWatchFeature objects that are registered through the filter
 * beltoft_services_dns_watches (id => object). What it owns is the order of the rules, the same for every feature:
 *
 * 1. A lookup that fails learns nothing.
 * 2. A needed record that was ok and is not found is read once more at once; only then does the state change.
 * 3. A lost record is confirmed by a second read after the confirm minutes; only then is the customer told, once.
 * 4. A record that flaps gets at most one lost or back mail per gap.
 * 5. Reminders on the days of the setting, at most one per day named; the count is stored before the mail goes.
 * 6. The feature reacts to the event (mail holds or lifts sending). A DNS finding never pauses, deletes or reinstalls anything.
 *
 * State is resource meta under the feature's prefix: `<p>` (waiting, ok, timeout), `<p>_since`, `_checked`, `_rechecked`, `_reminded`,
 * `_lost_at`, `_lost_keys`, `_lost_mails`, `_lost_pending`, `_last_mail`, `_back_at`, `_timeout_mailed`, `_admin_alerted`, `_moved`, `_manual`.
 * Every write goes through Resource::locked and reads the resource again first. The rows hold the status of each record.
 */
final class DnsWatch {

	/** The least gap between two reads, whatever calls the job. */
	public const MIN_GAP = 300;

	/** The action the engine fires on every event, for observers: ( $event, $feature_id, Resource, $ctx ). */
	public const EVENT = 'beltoft_services_dns_watch_event';

	/**
	 * Hooks the whole watch once: settings, words, emails, jobs. Called after every feature has registered its object.
	 */
	public static function register(): void {
		DnsWatchSettings::register();
		DnsWatchState::register();
		DnsWatchMail::register();
		DnsWatchJobs::register();
	}

	// -- The features ----------------------------------------------------------------------------

	/**
	 * The registered features by id.
	 *
	 * @return array<string, DnsWatchFeature>
	 */
	public static function features(): array {
		/**
		 * The features that use the DNS watch, keyed by feature id. A feature registers one object here.
		 *
		 * @param array<string, DnsWatchFeature> $features Features so far.
		 */
		$features = apply_filters( 'beltoft_services_dns_watches', array() );
		$out      = array();

		foreach ( is_array( $features ) ? $features : array() as $id => $feature ) {
			if ( $feature instanceof DnsWatchFeature && is_string( $id ) && $feature->id() === $id ) {
				$out[ $id ] = $feature;
			}
		}

		return $out;
	}

	/**
	 * One feature.
	 *
	 * @param string $id Feature id.
	 */
	public static function feature( string $id ): ?DnsWatchFeature {
		return self::features()[ $id ] ?? null;
	}

	/**
	 * The feature that watches a resource, if any.
	 *
	 * @param Resource $r Resource.
	 */
	public static function feature_of( Resource $r ): ?DnsWatchFeature {
		foreach ( self::features() as $feature ) {
			if ( $feature->owns( $r ) ) {
				return $feature;
			}
		}

		return null;
	}

	// -- Rows ------------------------------------------------------------------------------------

	/**
	 * Are the needed records in place? Each needed record must be there and ok; the recommended ones never decide.
	 *
	 * @param DnsWatchFeature                  $f    Feature.
	 * @param array<int, array<string, mixed>> $rows Rows with status.
	 */
	public static function complete( DnsWatchFeature $f, array $rows ): bool {
		$ok = DnsWatchState::keys_ok( $rows );

		return array() === array_diff( $f->needed(), $ok );
	}

	/**
	 * A row with the outcome of publish(): the status and reason, and the mark written_by_us when this call wrote the record (a record
	 * that was there before, or that the customer owns, never gets it: it is not ours to remove).
	 *
	 * @param array<string, mixed> $row    Row.
	 * @param array<int, mixed>    $result Result of DnsWatchFeature::publish().
	 * @return array<string, mixed>
	 */
	public static function with_result( array $row, array $result ): array {
		$row['status'] = $result[0];
		$row['reason'] = $result[1];

		if ( ! empty( $result[2] ) ) {
			$row['written_by_us'] = true;
		}

		return $row;
	}

	// -- Small helpers ---------------------------------------------------------------------------

	/**
	 * Is the resource waiting for records?
	 *
	 * @param DnsWatchFeature $f Feature.
	 * @param Resource        $r Resource.
	 */
	public static function waiting( DnsWatchFeature $f, Resource $r ): bool {
		return 'waiting' === (string) $r->get( $f->prefix() );
	}

	/**
	 * One read of one row. A read that failed (the feature answers null, or flags the row `unknown`) is unknown: it never counts as a record that
	 * is gone. The flag is taken off the row; the tally counts the lookups of this look for the sweep's health.
	 *
	 * @param DnsWatchFeature      $f      Feature.
	 * @param array<string, mixed> $row    Stored row.
	 * @param string               $domain Domain.
	 * @param array{total:int,bad:int} $tally  Counts, by reference.
	 * @return array{0: array<string, mixed>|null, 1: bool} The row (null when nothing was learned) and whether the read is unknown.
	 */
	private static function read( DnsWatchFeature $f, array $row, string $domain, array &$tally ): array {
		$read = $f->read_row( $row, $domain );

		++$tally['total'];

		$unknown = null === $read || ! empty( $read['unknown'] );

		if ( $unknown ) {
			++$tally['bad'];
		}

		if ( is_array( $read ) ) {
			unset( $read['unknown'] );
		}

		return array( $read, $unknown );
	}

	/**
	 * The status of a resource as the engine reads it: waiting, ok or timeout (a feature may store ok under another word).
	 *
	 * @param DnsWatchFeature $f Feature.
	 * @param Resource        $r Resource.
	 */
	public static function status( DnsWatchFeature $f, Resource $r ): string {
		return $f->status_out( (string) $r->get( $f->prefix() ) );
	}

	/**
	 * Tells the feature and the observers what happened.
	 *
	 * @param DnsWatchFeature      $f     Feature.
	 * @param string               $event Event.
	 * @param Resource             $r     Resource.
	 * @param array<string, mixed> $ctx   Data.
	 */
	public static function event( DnsWatchFeature $f, string $event, Resource $r, array $ctx = array() ): void {
		$f->on_event( $event, $r, $ctx );

		/**
		 * The DNS watch noticed something about a service. For observers only; the feature's own reaction has run.
		 *
		 * @param string               $event      changed, found, lost_confirmed, back, timeout, moved_away, moved_here or slow_stop.
		 * @param string               $feature_id Feature id.
		 * @param Resource             $r          Resource.
		 * @param array<string, mixed> $ctx        Data.
		 */
		do_action( 'beltoft_services_dns_watch_event', $event, $f->id(), $r, $ctx );
	}

	/**
	 * Stores a result under the resource lock, only while the resource is still waiting.
	 *
	 * @param DnsWatchFeature      $f     Feature.
	 * @param int                  $id    Resource id.
	 * @param array<string, mixed> $meta  Meta.
	 * @param int|null             $again Seconds until the next read, null for none.
	 * @return bool Whether it was stored.
	 */
	public static function store( DnsWatchFeature $f, int $id, array $meta, ?int $again ): bool {
		$r = Resource::find( $id );

		if ( ! $r ) {
			return false;
		}

		$done = $r->locked(
			static function () use ( $f, $id, $meta ): bool {
				$fresh = Resource::find( $id );

				return $fresh && self::waiting( $f, $fresh ) && $fresh->set( $meta );
			}
		);

		if ( true === $done && null !== $again ) {
			DnsWatchJobs::look( $f, $id, $again );
		}

		return true === $done;
	}

	/**
	 * Starts the look again (the customer asks, after the days ran out).
	 *
	 * @param DnsWatchFeature $f Feature.
	 * @param Resource        $r Resource.
	 */
	public static function restart( DnsWatchFeature $f, Resource $r ): bool {
		$p      = $f->prefix();
		$stored = $r->locked(
			static function () use ( $r, $p ): bool {
				$fresh = Resource::find( $r->id() );

				return $fresh && $fresh->set( array( $p => 'waiting', $p . '_since' => time(), $p . '_checked' => null ) );
			}
		);

		if ( true === $stored ) {
			DnsWatchJobs::look( $f, $r->id(), 0 );
		}

		return true === $stored;
	}

	// -- The look while the records are awaited --------------------------------------------------

	/**
	 * One bounded read of public DNS for one service: each record that is not ok yet is looked up, the result is stored under the
	 * resource lock after the resource was read again, and the next read is queued.
	 *
	 * @param DnsWatchFeature $f      Feature.
	 * @param int             $id     Resource id.
	 * @param bool            $manual The customer pressed Check now: the least gap between two reads does not apply (the feature keeps its own limits).
	 */
	public static function run( DnsWatchFeature $f, int $id, bool $manual = false ): void {
		$r = Resource::find( $id );

		if ( ! $r || ! $r->is_live() || ! self::waiting( $f, $r ) || ! $f->before_look( $r ) ) {
			return;
		}

		$p       = $f->prefix();
		$now     = time();
		$checked = (int) $r->get( $p . '_checked' );

		if ( ! $manual && $checked > 0 && $now - $checked < self::MIN_GAP ) {
			DnsWatchJobs::look( $f, $id, self::MIN_GAP - ( $now - $checked ) );
			return;
		}

		$since = (int) $r->get( $p . '_since' );

		if ( $since > 0 && $now >= $since + DnsWatchSettings::wait_days( $f->id() ) * DAY_IN_SECONDS ) {
			if ( self::store( $f, $id, array( $p => 'timeout', $p . '_checked' => $now, $p . '_rechecked' => $now ), null ) ) {
				self::timeout_notice( $f, $id );
				self::event( $f, 'timeout', Resource::find( $id ) ?? $r );
			}

			return;
		}

		$domain = $f->domain( $r );
		$rows   = $f->rows( $r );
		$user   = $f->zone_user( $r );
		$ours   = $f->on_our_servers( $r );
		$tally  = array( 'total' => 0, 'bad' => 0 );

		foreach ( $rows as $i => $row ) {
			if ( ! is_array( $row ) || 'ok' === ( $row['status'] ?? '' ) ) {
				continue;
			}

			// A zone of ours: try to write the record again. publish() re-checks the owner, takes the zone lock and never
			// overwrites a record that conflicts, so a customer who removed the conflicting record gets ours written.
			if ( $ours ) {
				$result = $f->publish( $user, $domain, $row );

				if ( ! is_wp_error( $result ) ) {
					$rows[ $i ]['status'] = $result[0];
					$rows[ $i ]['reason'] = $result[1];
					// Stored as each record is published, so a stopped run never loses what it already wrote into the zone.
					self::store( $f, $id, array( $f->rows_key() => $rows ), null );
				}

				continue;
			}

			list( $read ) = self::read( $f, $row, $domain, $tally );

			if ( null !== $read ) {
				$rows[ $i ] = $read;
			}
		}

		$done = self::complete( $f, $rows );
		$meta = array( $f->rows_key() => $rows, $p . '_checked' => $now, $f->mode_key() => $ours ? 'ours' : 'external' );

		if ( $done ) {
			$meta[ $p ] = $f->status_in( 'ok' );
		}

		if ( self::store( $f, $id, $meta, $done ? null : DnsWatchSettings::look_seconds( $f->id() ) ) ) {
			// What depends on the records (mail: the sending hold) follows the rows just stored.
			self::event( $f, 'changed', Resource::find( $id ) ?? $r );

			if ( $done ) {
				self::event( $f, 'found', Resource::find( $id ) ?? $r );
				self::after_found( $f, $id );
			} elseif ( 0 === $tally['bad'] ) {
				// A look that failed sends nothing: no reminder and no lost mail rest on a lookup that learned nothing.
				self::remind( $f, $id );
				self::lost_reminders( $f, $id );
			}

			return;
		}

		DnsWatchJobs::look( $f, $id, 60 );
	}

	// -- The daily look after the records were found ---------------------------------------------

	/**
	 * The sweep: one look per service whose records were found, spread over the hours until the next sweep so the lookups of many
	 * never come at once, and one slow look per service that stopped being looked at, until the day the setting names. Services still
	 * waiting are not in it (the short look has them).
	 *
	 * @param DnsWatchFeature $f Feature.
	 */
	public static function recheck_all( DnsWatchFeature $f ): void {
		$step   = 0;
		$spread = DnsWatchSettings::recheck_spread( $f->id() );

		DnsWatchHealth::reset( $f );

		foreach ( Resource::find_by_meta( $f->kind(), $f->prefix(), $f->status_in( 'ok' ) ) as $id ) {
			Jobs::schedule_once( time() + min( $step * 4, $spread ), $f->hook( 'recheck' ), array( (int) $id ) );
			++$step;
		}

		foreach ( Resource::find_by_meta( $f->kind(), $f->prefix(), 'timeout' ) as $id ) {
			$r = Resource::find( (int) $id );

			if ( $r && self::slow_look_due( $f, $r ) ) {
				Jobs::schedule_once( time() + min( $step * 4, $spread ), $f->hook( 'recheck' ), array( (int) $id ) );
				++$step;
			}
		}
	}

	/**
	 * Is a service that stopped being looked at due for its slow look? Not before the days between looks have passed since the last
	 * one, never after the day the setting names.
	 *
	 * @param DnsWatchFeature $f Feature.
	 * @param Resource        $r Resource.
	 */
	private static function slow_look_due( DnsWatchFeature $f, Resource $r ): bool {
		$p = $f->prefix();

		if ( ! $r->is_live() || ! $f->active( $r ) || 'timeout' !== (string) $r->get( $p ) ) {
			return false;
		}

		$since = (int) $r->get( $p . '_since' );

		if ( $since > 0 && time() >= $since + DnsWatchSettings::stop_days( $f->id() ) * DAY_IN_SECONDS ) {
			return false;
		}

		return time() - (int) $r->get( $p . '_rechecked' ) >= DnsWatchSettings::weekly_days( $f->id() ) * DAY_IN_SECONDS - 12 * HOUR_IN_SECONDS;
	}

	/**
	 * The look at one service after its records were found (or, slowly, after we stopped looking): all records are read once and stored,
	 * so the recommended ones show their real state. For a zone of ours each record is written again where it is missing and free. A
	 * needed record that is no longer found is read again straight away; if it is still gone the service goes back to waiting and a
	 * confirmation is queued a few minutes later: only then is the customer told, once.
	 *
	 * A lookup that fails changes nothing. At most one look per guard time for a service (`_rechecked`).
	 *
	 * @param DnsWatchFeature $f  Feature.
	 * @param int             $id Resource id.
	 */
	public static function recheck( DnsWatchFeature $f, int $id ): void {
		$r = Resource::find( $id );

		if ( ! $r || ! $r->is_live() || ! $f->active( $r ) ) {
			return;
		}

		$p      = $f->prefix();
		$status = self::status( $f, $r );

		if ( 'ok' !== $status && 'timeout' !== $status ) {
			return;
		}

		$timeout = 'timeout' === $status;
		$now     = time();

		if ( $timeout ? ! self::slow_look_due( $f, $r ) : $now - (int) $r->get( $p . '_rechecked' ) < DnsWatchSettings::recheck_guard( $f->id() ) ) {
			return;
		}

		$domain = $f->domain( $r );
		$rows   = $f->rows( $r );
		$user   = $f->zone_user( $r );

		if ( '' === $domain || array() === $rows ) {
			return;
		}

		// A zone of ours is asked, once a week, whether the name servers still point to us.
		self::watch_delegation( $domain );

		$ours   = $f->on_our_servers( $r );
		$stored = (string) $r->get( $f->mode_key() );

		// A name that became ours since the last look (the customer moved their DNS to us): the records are written into the new zone.
		if ( $ours && 'ours' !== $stored && '' !== $stored ) {
			self::adopt_zone( $f, $r );
			return;
		}

		$before = DnsWatchState::keys_ok( $rows );
		$tally  = array( 'total' => 0, 'bad' => 0 );

		foreach ( $rows as $i => $row ) {
			if ( ! is_array( $row ) || ! isset( $row['name'], $row['type'], $row['value'], $row['key'] ) ) {
				continue;
			}

			if ( $ours ) {
				$result = $f->publish( $user, $domain, $row );

				if ( ! is_wp_error( $result ) ) {
					$rows[ $i ] = self::with_result( $rows[ $i ], $result );
				}

				continue;
			}

			list( $read, $unknown ) = self::read( $f, $row, $domain, $tally );

			// A lookup that failed learns nothing: the row stays as it was.
			if ( $unknown ) {
				continue;
			}

			// Not found: look once more before saying so, so a flaky answer never marks a customer's records lost.
			if ( 'ok' !== $read['status'] && 'ok' === ( $row['status'] ?? '' ) ) {
				list( $read, $unknown ) = self::read( $f, $row, $domain, $tally );

				if ( ! $unknown && 'ok' !== $read['status'] ) {
					$rows[ $i ] = $read;
				}

				continue;
			}

			$rows[ $i ] = $read;
		}

		DnsWatchHealth::note( $f, $tally['bad'], $tally['total'] );

		$lost = ! self::complete( $f, $rows );

		// Most lookups of this sweep failed: the resolver is the problem, not the customer's DNS. Nothing is stored as lost.
		if ( $lost && ! $timeout && DnsWatchHealth::tripped( $f ) ) {
			$counts = DnsWatchHealth::counts( $f );
			Alerts::dns_sweep_unhealthy( $f->label(), $f->id(), $counts[0], $counts[1] );

			return;
		}

		$meta = array( $f->rows_key() => $rows, $p . '_rechecked' => $now );
		$gone = array_values( array_intersect( $f->needed(), $before, array_diff( $f->needed(), DnsWatchState::keys_ok( $rows ) ) ) );

		// The service was ours when it was set up and is not on our servers any more: the stored mode follows the live one.
		$left = ! $ours && 'ours' === $stored;

		if ( $left ) {
			$meta[ $f->mode_key() ] = 'external';
		}

		if ( $left && self::away( $domain ) ) {
			$meta[ $p . '_moved' ] = $now;
		}

		if ( $timeout ) {
			self::finish_slow_look( $f, $r, $meta, ! $lost );
			return;
		}

		if ( $lost ) {
			// Waiting again, as if just set up; the reminder of a new service is not sent, the confirmation below decides on mail.
			$meta += array(
				$p              => 'waiting',
				$p . '_since'   => $now,
				$p . '_checked' => $now,
				$p . '_reminded'     => $now,
				$p . '_lost_pending' => $now,
				$p . '_lost_keys'    => $gone,
				$p . '_lost_at'      => 0,
				$p . '_lost_mails'   => 0,
			);
		}

		$stored_ok = $r->locked(
			static function () use ( $f, $id, $meta ): bool {
				$fresh = Resource::find( $id );

				return $fresh && 'ok' === self::status( $f, $fresh ) && $fresh->set( $meta );
			}
		);

		if ( true !== $stored_ok ) {
			return;
		}

		$fresh = Resource::find( $id ) ?? $r;

		self::event( $f, 'changed', $fresh );

		if ( $left ) {
			Alerts::dns_watch_left( $fresh, $f->label(), $domain, $lost, $f->id(), $f->alert_lines( 'left', $fresh, array( 'lost' => $lost ) ) );
			self::event( $f, 'moved_away', $fresh, array( 'lost' => $lost ) );
		}

		if ( $lost ) {
			DnsWatchJobs::look( $f, $id, DnsWatchSettings::look_seconds( $f->id() ) );
			DnsWatchJobs::confirm( $f, $id, DnsWatchSettings::confirm_seconds( $f->id() ) );
		}
	}

	/**
	 * The end of a slow look at a service we stopped looking at: the rows are stored; when all needed records are found it is ok again,
	 * else the staff hear once when it has been without the records for the days the setting names.
	 *
	 * @param DnsWatchFeature      $f     Feature.
	 * @param Resource             $r     Resource.
	 * @param array<string, mixed> $meta  Meta to store.
	 * @param bool                 $found All needed records were found.
	 */
	private static function finish_slow_look( DnsWatchFeature $f, Resource $r, array $meta, bool $found ): void {
		$id = $r->id();
		$p  = $f->prefix();

		if ( $found ) {
			$meta[ $p ] = $f->status_in( 'ok' );
		}

		$stored = $r->locked(
			static function () use ( $id, $meta, $p ): bool {
				$fresh = Resource::find( $id );

				return $fresh && 'timeout' === (string) $fresh->get( $p ) && $fresh->set( $meta );
			}
		);

		if ( true !== $stored ) {
			return;
		}

		$fresh = Resource::find( $id ) ?? $r;

		self::event( $f, 'changed', $fresh );

		if ( $found ) {
			self::event( $f, 'found', $fresh );
			self::after_found( $f, $id );
			return;
		}

		$since = (int) $fresh->get( $p . '_since' );
		$days  = DnsWatchSettings::admin_days( $f->id() );

		if ( $since > 0 && time() >= $since + $days * DAY_IN_SECONDS && ! (int) $fresh->get( $p . '_admin_alerted' ) ) {
			$marked = $fresh->locked(
				static function () use ( $id, $p ): bool {
					$again = Resource::find( $id );

					return $again && ! (int) $again->get( $p . '_admin_alerted' ) && $again->set( array( $p . '_admin_alerted' => time() ) );
				}
			);

			if ( true === $marked ) {
				Alerts::dns_watch_no_dns( $fresh, $f->label(), $f->domain( $fresh ), $days, $f->id(), $f->alert_lines( 'no_dns', $fresh ), $f->noun() );
				self::event( $f, 'slow_stop', $fresh, array( 'days' => $days ) );
			}
		}
	}

	/**
	 * The second read of a record that was found before and was not found by the daily look. Only if it is still gone is the loss real:
	 * the customer gets the first email and the staff are told. A record that is back is no event at all.
	 *
	 * @param DnsWatchFeature $f  Feature.
	 * @param int             $id Resource id.
	 */
	public static function confirm( DnsWatchFeature $f, int $id ): void {
		$r = Resource::find( $id );
		$p = $f->prefix();

		if ( ! $r || ! $r->is_live() || ! self::waiting( $f, $r ) || (int) $r->get( $p . '_lost_pending' ) <= 0 ) {
			return;
		}

		$domain = $f->domain( $r );
		$user   = $f->zone_user( $r );
		$ours   = $f->on_our_servers( $r );
		$keys   = DnsWatchState::lost_keys( $f, $r );
		$rows   = $f->rows( $r );
		$tally  = array( 'total' => 0, 'bad' => 0 );

		foreach ( $rows as $i => $row ) {
			if ( ! is_array( $row ) || ! in_array( (string) ( $row['key'] ?? '' ), $keys, true ) || 'ok' === ( $row['status'] ?? '' ) ) {
				continue;
			}

			if ( $ours ) {
				$result = $f->publish( $user, $domain, $row );

				if ( ! is_wp_error( $result ) ) {
					$rows[ $i ] = self::with_result( $rows[ $i ], $result );
				}

				continue;
			}

			list( $read ) = self::read( $f, $row, $domain, $tally );

			if ( null !== $read && 0 === $tally['bad'] ) {
				$rows[ $i ] = $read;
			}
		}

		// A lookup that failed confirms nothing: the loss stays pending, is looked at again later, and no mail goes out on it.
		if ( $tally['bad'] > 0 ) {
			self::confirm_unknown( $f, $r, $domain );

			return;
		}

		$still = array_values( array_intersect( $keys, array_diff( $f->needed(), DnsWatchState::keys_ok( $rows ) ) ) );
		$clear = array( $p . '_lost_pending' => 0, $p . '_unknown_tries' => 0, $p . '_unknown_alerted' => 0 );

		if ( array() === $still || self::complete( $f, $rows ) ) {
			// It is back: nobody was told, nobody needs to be.
			$meta = array_merge( $clear, array( $f->rows_key() => $rows, $p => $f->status_in( 'ok' ), $p . '_lost_keys' => array(), $p . '_checked' => time() ) );

			if ( self::store( $f, $id, $meta, null ) ) {
				self::event( $f, 'changed', Resource::find( $id ) ?? $r );
			}

			return;
		}

		// A loss right after a lost or found mail (a record that flaps) is not mailed again: the page and the hold follow the real state,
		// and lost_reminders() tells the customer if it lasts past the gap.
		$quiet = self::within_gap( $f, (int) $r->get( $p . '_last_mail' ) );
		$meta  = array_merge( $clear, array( $f->rows_key() => $rows, $p . '_lost_keys' => $still, $p . '_lost_at' => time(), $p . '_lost_mails' => $quiet ? 0 : 1 ) );

		if ( ! $quiet ) {
			$meta[ $p . '_last_mail' ] = time();
		}

		if ( ! self::store( $f, $id, $meta, null ) ) {
			DnsWatchJobs::confirm( $f, $id, 60 );
			return;
		}

		$fresh = Resource::find( $id ) ?? $r;

		self::event( $f, 'changed', $fresh );

		if ( ! $quiet ) {
			DnsWatchMail::send( 'lost', $f, $fresh );
		}

		Alerts::dns_watch_lost( $fresh, $f->label(), $domain, $still, DnsWatchSettings::admin_throttle( $f->id() ), $f->id(), $f->alert_lines( 'lost', $fresh ) );
		self::event( $f, 'lost_confirmed', $fresh, array( 'keys' => $still, 'mailed' => ! $quiet ) );
	}

	/**
	 * The second read of a loss could not be made. The try is counted and the read is queued again after the confirm minutes; after the
	 * setting's number of tries the staff hear once (the loss stays unconfirmed, nobody is mailed) and the reads go on every hour.
	 *
	 * @param DnsWatchFeature $f      Feature.
	 * @param Resource        $r      Resource.
	 * @param string          $domain Domain.
	 */
	private static function confirm_unknown( DnsWatchFeature $f, Resource $r, string $domain ): void {
		$p     = $f->prefix();
		$id    = $r->id();
		$tries = (int) $r->get( $p . '_unknown_tries' ) + 1;
		$cap   = DnsWatchSettings::unknown_retries( $f->id() );

		if ( ! self::store( $f, $id, array( $p . '_unknown_tries' => $tries ), null ) ) {
			DnsWatchJobs::confirm( $f, $id, 60 );

			return;
		}

		if ( $tries > $cap ) {
			$fresh  = Resource::find( $id ) ?? $r;
			$marked = $fresh->locked(
				static function () use ( $id, $p ): bool {
					$again = Resource::find( $id );

					return $again && ! (int) $again->get( $p . '_unknown_alerted' ) && $again->set( array( $p . '_unknown_alerted' => time() ) );
				}
			);

			if ( true === $marked ) {
				Alerts::dns_lookups_failing( $fresh, $f->label(), $domain, $f->id() );
			}
		}

		DnsWatchJobs::confirm( $f, $id, $tries > $cap ? HOUR_IN_SECONDS : max( 60, DnsWatchSettings::confirm_seconds( $f->id() ) ) );
	}

	/**
	 * Is a time inside the gap after which a new lost mail may go out?
	 *
	 * @param DnsWatchFeature $f  Feature.
	 * @param int             $at Time of the last lost or found mail, 0 for none.
	 */
	private static function within_gap( DnsWatchFeature $f, int $at ): bool {
		$gap = DnsWatchSettings::mail_gap( $f->id() );

		return $gap > 0 && $at > 0 && time() < $at + $gap;
	}

	/**
	 * The reminders of a loss that goes on: one email on each day the setting names, counted from the day the loss was confirmed, never
	 * more than the days named plus the first email. The count is stored under the resource lock before the mail goes out, so a retry
	 * never mails twice.
	 *
	 * @param DnsWatchFeature $f  Feature.
	 * @param int             $id Resource id.
	 */
	public static function lost_reminders( DnsWatchFeature $f, int $id ): void {
		$r = Resource::find( $id );

		if ( ! $r || ! $r->is_live() || ! self::waiting( $f, $r ) ) {
			return;
		}

		$p     = $f->prefix();
		$at    = (int) $r->get( $p . '_lost_at' );
		$sent  = (int) $r->get( $p . '_lost_mails' );
		$days  = DnsWatchSettings::remind_days( $f->id() );
		$index = $sent - 1;

		if ( $at <= 0 ) {
			return;
		}

		// A loss that was quiet (a flapping record): the first mail goes out once the gap since the last mail is over.
		if ( 0 === $sent ) {
			if ( self::within_gap( $f, (int) $r->get( $p . '_last_mail' ) ) ) {
				return;
			}
		} elseif ( $index >= count( $days ) || time() < $at + $days[ $index ] * DAY_IN_SECONDS ) {
			return;
		}

		$marked = $r->locked(
			function () use ( $f, $id, $sent, $p ): bool {
				$fresh = Resource::find( $id );

				return $fresh && self::waiting( $f, $fresh ) && (int) $fresh->get( $p . '_lost_mails' ) === $sent && $fresh->set( array( $p . '_lost_mails' => $sent + 1, $p . '_last_mail' => time() ) );
			}
		);

		if ( true === $marked ) {
			DnsWatchMail::send( 'lost', $f, Resource::find( $id ) ?? $r );
		}
	}

	/**
	 * The needed records are found again. When the customer was told that some were lost, they are told that it is over, once; every
	 * mark of the loss is cleared either way.
	 *
	 * @param DnsWatchFeature $f  Feature.
	 * @param int             $id Resource id.
	 */
	public static function after_found( DnsWatchFeature $f, int $id ): void {
		$r = Resource::find( $id );
		$p = $f->prefix();

		if ( ! $r || 'ok' !== self::status( $f, $r ) ) {
			return;
		}

		// Told that records were lost, and no found mail went out inside the gap: a flapping record gets one found mail.
		$gap   = DnsWatchSettings::mail_gap( $f->id() );
		$told  = (int) $r->get( $p . '_lost_at' ) > 0 && (int) $r->get( $p . '_lost_mails' ) > 0 && ! ( $gap > 0 && time() < (int) $r->get( $p . '_back_at' ) + $gap );
		$marks = array( $p . '_lost_at', $p . '_lost_keys', $p . '_lost_mails', $p . '_lost_pending', $p . '_moved', $p . '_timeout_mailed', $p . '_admin_alerted', $p . '_unknown_tries', $p . '_unknown_alerted' );
		$set   = false;

		foreach ( $marks as $mark ) {
			$value = $r->get( $mark );
			$set   = $set || ( is_array( $value ) ? array() !== $value : (int) $value > 0 );
		}

		if ( ! $set ) {
			return;
		}

		$cleared = $r->locked(
			static function () use ( $f, $id, $marks, $told, $p ): bool {
				$fresh = Resource::find( $id );
				$meta  = array();

				foreach ( $marks as $mark ) {
					$meta[ $mark ] = $p . '_lost_keys' === $mark ? array() : 0;
				}

				if ( $told ) {
					$meta[ $p . '_back_at' ]   = time();
					$meta[ $p . '_last_mail' ] = time();
				}

				return $fresh && 'ok' === self::status( $f, $fresh ) && $fresh->set( $meta );
			}
		);

		if ( true === $cleared ) {
			$fresh = Resource::find( $id ) ?? $r;

			if ( $told ) {
				DnsWatchMail::send( 'back', $f, $fresh );
			}

			if ( $told ) {
				self::event( $f, 'back', $fresh );
			}
		}
	}

	/**
	 * The look stopped after the wait days: the customer is told once. The mark is stored under the resource lock before the mail goes.
	 *
	 * @param DnsWatchFeature $f  Feature.
	 * @param int             $id Resource id.
	 */
	public static function timeout_notice( DnsWatchFeature $f, int $id ): void {
		$r = Resource::find( $id );
		$p = $f->prefix();

		if ( ! $r || ! $r->is_live() || 'timeout' !== (string) $r->get( $p ) || (int) $r->get( $p . '_timeout_mailed' ) > 0 ) {
			return;
		}

		$marked = $r->locked(
			static function () use ( $id, $p ): bool {
				$fresh = Resource::find( $id );

				return $fresh && 'timeout' === (string) $fresh->get( $p ) && ! (int) $fresh->get( $p . '_timeout_mailed' ) && $fresh->set( array( $p . '_timeout_mailed' => time() ) );
			}
		);

		if ( true === $marked ) {
			DnsWatchMail::send( 'timeout', $f, Resource::find( $id ) ?? $r );
		}
	}

	/**
	 * The name is on our name servers now and the records were made for it elsewhere: every record is written into the new zone (or
	 * reported as a conflict), by the short look.
	 *
	 * @param DnsWatchFeature $f Feature.
	 * @param Resource        $r Resource.
	 */
	public static function adopt_zone( DnsWatchFeature $f, Resource $r ): void {
		$id   = $r->id();
		$p    = $f->prefix();
		$rows = array();

		foreach ( $f->rows( $r ) as $row ) {
			if ( is_array( $row ) ) {
				$row['status'] = 'missing';
				$row['reason'] = '';
				unset( $row['seen'], $row['merged'] );
				$rows[] = $row;
			}
		}

		$done = $r->locked(
			static function () use ( $f, $id, $rows, $p ): bool {
				$fresh = Resource::find( $id );

				return $fresh && $fresh->set(
					array(
						$f->mode_key()  => 'ours',
						$f->rows_key()  => $rows,
						$p              => 'waiting',
						$p . '_since'   => time(),
						$p . '_checked' => null,
						$p . '_reminded' => time(),
						$p . '_moved'   => 0,
					)
				);
			}
		);

		if ( true === $done ) {
			DnsWatchJobs::look( $f, $id, 0 );
			self::event( $f, 'moved_here', Resource::find( $id ) ?? $r );
		}
	}

	/**
	 * One reminder, once, when needed records of a domain hosted elsewhere are still missing after the first-reminder hours. The mark is
	 * stored under the resource lock before the mail is sent, so a retry never mails twice.
	 *
	 * @param DnsWatchFeature $f  Feature.
	 * @param int             $id Resource id.
	 */
	public static function remind( DnsWatchFeature $f, int $id ): void {
		$r = Resource::find( $id );
		$p = $f->prefix();

		if ( ! $r || ! $r->is_live() || ! self::waiting( $f, $r ) || 'external' !== (string) $r->get( $f->mode_key() ) || (int) $r->get( $p . '_reminded' ) > 0 ) {
			return;
		}

		$since = (int) $r->get( $p . '_since' );

		if ( $since <= 0 || time() < $since + DnsWatchSettings::first_remind_seconds( $f->id() ) || array() === $f->missing_rows( $r, true ) ) {
			return;
		}

		$marked = $r->locked(
			static function () use ( $f, $id, $p ): bool {
				$fresh = Resource::find( $id );

				return $fresh && self::waiting( $f, $fresh ) && ! (int) $fresh->get( $p . '_reminded' ) && $fresh->set( array( $p . '_reminded' => time() ) );
			}
		);

		if ( true === $marked ) {
			DnsWatchMail::send( 'reminder', $f, Resource::find( $id ) ?? $r );
		}
	}

	// -- Name servers moved away ----------------------------------------------------------------

	/**
	 * The DNS hosting service that holds a name for its customer.
	 *
	 * @param string $domain Domain.
	 */
	public static function service( string $domain ): ?Resource {
		if ( ! DnsSchema::ready() ) {
			return null;
		}

		$row = DnsAssignments::get( $domain );

		if ( null === $row || 'active' !== $row['status'] || (int) $row['resource_id'] <= 0 ) {
			return null;
		}

		$r = Resource::find( (int) $row['resource_id'] );

		return $r && 'dns' === $r->get( 'driver' ) ? $r : null;
	}

	/**
	 * Does the parent zone of the name list other name servers than ours? Read from what the DNS service stored, never from the network.
	 *
	 * @param string $domain Domain.
	 */
	public static function away( string $domain ): bool {
		$service = self::service( $domain );

		return null !== $service && 'other' === (string) $service->get( 'delegation' );
	}

	/**
	 * Is the domain a zone of this customer on our name servers right now? A zone whose delegation says other does not count.
	 *
	 * @param string $domain  Domain.
	 * @param int    $user_id Customer.
	 */
	public static function on_our_servers( string $domain, int $user_id ): bool {
		return in_array( $domain, ZoneAccess::zones( $user_id ), true ) && ! self::away( $domain );
	}

	/**
	 * Reads the delegation of the domain's DNS service again when the days between two checks have passed.
	 *
	 * @param string $domain Domain.
	 */
	public static function watch_delegation( string $domain ): void {
		$service = self::service( $domain );

		if ( null === $service || ! $service->is_live() ) {
			return;
		}

		$days = max( 1, (int) \BeltoftServices\Settings::get( 'dns_delegation_recheck_days' ) );

		if ( time() - (int) $service->get( 'delegation_checked' ) >= $days * DAY_IN_SECONDS - HOUR_IN_SECONDS ) {
			Delegation::refresh( $service );
		}
	}
}
