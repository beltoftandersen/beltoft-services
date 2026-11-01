<?php
/**
 * The managed-database part of the firewall: database port rules, world access and closing them.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Proxmox;

use BeltoftServices\Drivers\StepResult;
use BeltoftServices\Resource;

defined( 'ABSPATH' ) || exit;

/**
 * Part of Firewall (used by it only): shares its client, write budget and result classification.
 */
trait FirewallDatabase {

	/**
	 * The firewall of a managed database server (plugin-owned, never edited by the customer): the firewall on,
	 * inbound DROP, outgoing accepted, MAC filter on, and the rule that keeps the private NIC (net1) open. The
	 * rule goes first, so the policy never closes the private side; the options are read back.
	 *
	 * @param array<string, mixed> $record VM record.
	 * @param array<string, mixed> $v      Verified VM.
	 * @return array<string, mixed>|StepResult
	 */
	public function ensure_database( array $record, array $v ) {
		$base  = self::base( $record, $v );
		$rules = $this->read_list( $base . '/rules' );

		if ( is_wp_error( $rules ) ) {
			return $this->classify( $rules, 'configure' );
		}

		$done = $this->add_rule_once( $base, $rules, self::PRIVATE_RULE, array( 'action' => 'ACCEPT', 'type' => 'in', 'iface' => 'net1' ) );

		if ( $done instanceof StepResult ) {
			return $done;
		}

		$want = array( 'enable' => 1, 'policy_in' => 'DROP', 'policy_out' => $this->policy_out(), 'macfilter' => 1 );

		if ( ! empty( $this->cluster['fw_ipfilter'] ) ) {
			$ready = $this->ipfilter_sets( $base, $v );

			if ( $ready instanceof StepResult ) {
				return $ready;
			}

			if ( true === $ready ) {
				$want['ipfilter'] = 1;
			}
		}

		$options = $this->read_list( $base . '/options' );

		if ( is_wp_error( $options ) ) {
			return $this->classify( $options, 'configure' );
		}

		$change = array();

		foreach ( $want as $key => $value ) {
			if ( ! array_key_exists( $key, $options ) || (string) $options[ $key ] !== (string) $value ) {
				$change[ $key ] = $value;
			}
		}

		if ( $change ) {
			$answer = $this->write( 'PUT', $base . '/options', $change );

			if ( is_wp_error( $answer ) ) {
				return $this->stop( $answer );
			}
		}

		// The public NIC (net0, with the public IP add-on): the engine's port (5432 for PostgreSQL) from the allow-list, nothing else.
		$access = $this->sync_database_access( $record, $v, 'both' );

		return $access instanceof StepResult ? $access : array();
	}

	/**
	 * The rules of the allow-list on a database's public NIC: `ACCEPT tcp <engine port>` on net0 from each allowed source (or
	 * from anywhere when the database is open to everyone), tagged `bs-db:`. Rules of other tags are never touched.
	 * A VM without net0 wants none. `close` removes the rules that are no longer wanted, `open` adds the missing ones,
	 * `both` does the two in that order (a port is never opened before the old rules are gone).
	 *
	 * @param array<string, mixed> $record VM record.
	 * @param array<string, mixed> $v      Verified VM.
	 * @param string               $phase  close, open or both.
	 * @return true|StepResult
	 */
	public function sync_database_access( array $record, array $v, string $phase = 'both' ) {
		$base    = self::base( $record, $v );
		$desired = empty( $v['config']['net0'] ) ? array() : DbAccess::rules_wanted( $this->r );

		if ( in_array( $phase, array( 'close', 'both' ), true ) ) {
			$done = $this->close_database_rules( $base, $desired );

			if ( $done instanceof StepResult ) {
				return $done;
			}
		}

		if ( ! in_array( $phase, array( 'open', 'both' ), true ) ) {
			return true;
		}

		$rules = $this->read_list( $base . '/rules' );

		if ( is_wp_error( $rules ) ) {
			return $this->stop( $rules );
		}

		foreach ( $desired as $tag => $source ) {
			$params = self::database_rule( $tag, $source, DbEngines::for_resource( $this->r )->port() );
			$found  = false;

			foreach ( self::tagged( $rules, $tag ) as $listed ) {
				if ( $tag === (string) $listed['comment'] && self::same_database_rule( $listed, $params ) ) {
					$found = true;
					break;
				}
			}

			if ( $found ) {
				continue;
			}

			$answer = $this->write( 'POST', $base . '/rules', $params );

			if ( is_wp_error( $answer ) ) {
				return $this->stop( $answer );
			}
		}

		return true;
	}

	/**
	 * The PVE parameters of one allow-list rule.
	 *
	 * @param string      $tag    Comment.
	 * @param string|null $source Source address or range; null for anywhere.
	 * @param int         $port   The engine's port.
	 * @return array<string, mixed>
	 */
	private static function database_rule( string $tag, ?string $source, int $port ): array {
		$rule = array(
			'type'    => 'in',
			'action'  => 'ACCEPT',
			'proto'   => 'tcp',
			'dport'   => (string) $port,
			'iface'   => 'net0',
			'enable'  => 1,
			'comment' => $tag,
		);

		if ( null !== $source ) {
			$rule['source'] = $source;
		}

		return $rule;
	}

	/**
	 * Does a listed rule say what the allow-list rule says?
	 *
	 * @param array<string, mixed> $remote Listed rule.
	 * @param array<string, mixed> $rule   Wanted rule.
	 */
	private static function same_database_rule( array $remote, array $rule ): bool {
		$source = static fn( $value ): string => preg_replace( '#/32$#D', '', (string) $value );

		return 'in' === ( $remote['type'] ?? '' ) && 'ACCEPT' === ( $remote['action'] ?? '' ) && 'tcp' === ( $remote['proto'] ?? '' ) && 'net0' === ( $remote['iface'] ?? '' )
			&& (string) ( $rule['dport'] ?? '' ) === (string) ( $remote['dport'] ?? '' ) && 1 === (int) ( $remote['enable'] ?? 0 )
			&& $source( $remote['source'] ?? '' ) === $source( $rule['source'] ?? '' );
	}

	/**
	 * Removes the `bs-db:` rules that are not wanted (or not right), highest position first; the list is read again
	 * before each delete.
	 *
	 * @param string                    $base    Firewall path.
	 * @param array<string, string|null> $desired Wanted rules: tag => source.
	 * @return true|StepResult
	 */
	private function close_database_rules( string $base, array $desired ) {
		for ( $guard = 0; $guard < DbAccess::MAX_ENTRIES + 10; $guard++ ) {
			$rules = $this->read_list( $base . '/rules' );

			if ( is_wp_error( $rules ) ) {
				return $this->stop( $rules );
			}

			$seen   = array();
			$target = null;

			foreach ( $rules as $i => $listed ) {
				if ( ! is_array( $listed ) || 0 !== strpos( (string) ( $listed['comment'] ?? '' ), self::TAG_DB ) ) {
					continue;
				}

				$tag  = (string) $listed['comment'];
				$good = array_key_exists( $tag, $desired ) && ! isset( $seen[ $tag ] ) && self::same_database_rule( $listed, self::database_rule( $tag, $desired[ $tag ], DbEngines::for_resource( $this->r )->port() ) );

				if ( $good ) {
					$seen[ $tag ] = true;
					continue;
				}

				$target = array( 'pos' => (int) ( $listed['pos'] ?? $i ), 'digest' => (string) ( $listed['digest'] ?? '' ) );
			}

			if ( null === $target ) {
				return true;
			}

			$answer = $this->write( 'DELETE', $base . '/rules/' . $target['pos'], '' !== $target['digest'] ? array( 'digest' => $target['digest'] ) : array() );

			if ( is_wp_error( $answer ) ) {
				return $this->stop( $answer );
			}
		}

		return StepResult::retry( 0, 'firewall: the rule list kept changing' );
	}
}
