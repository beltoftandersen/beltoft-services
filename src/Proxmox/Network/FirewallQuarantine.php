<?php
/**
 * Network quarantine of a server: outgoing policy DROP with the rules that keep the control path open, and lifting it.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Proxmox\Network;

use BeltoftServices\Drivers\StepResult;
use BeltoftServices\Resource;

defined( 'ABSPATH' ) || exit;

/**
 * Part of Firewall (used by it only): shares its client, write budget and result classification.
 */
trait FirewallQuarantine {

	/**
	 * The outgoing policy this server should have: DROP while it is quarantined, otherwise ACCEPT. Every reconcile
	 * path takes it from here, so none of them can undo a quarantine.
	 */
	private function policy_out(): string {
		return Quarantine::active( $this->r ) ? 'DROP' : 'ACCEPT';
	}

	/**
	 * The outgoing rules of a quarantine, by comment: DNS (udp and tcp 53) to each name server of the cluster. Answers
	 * to incoming connections need no rule: the firewall accepts established and related packets before its rules.
	 *
	 * @param array<string, mixed> $v Verified VM.
	 * @return array<string, array<string, mixed>>
	 */
	private function quarantine_rules( array $v ): array {
		$rules = array();

		// The private network stays open: the quarantine is about the internet.
		if ( self::has_private_nic( $v ) ) {
			$rules[ self::QUARANTINE_TAG . '-private' ] = array( 'action' => 'ACCEPT', 'type' => 'out', 'iface' => 'net1' );
		}

		foreach ( array_values( array_unique( (array) ( $this->cluster['nameservers'] ?? array() ) ) ) as $ns ) {
			foreach ( array( 'udp', 'tcp' ) as $proto ) {
				$rules[ self::QUARANTINE_TAG . '-dns-' . $proto . '-' . $ns ] = array( 'action' => 'ACCEPT', 'type' => 'out', 'proto' => $proto, 'dport' => '53', 'dest' => (string) $ns, 'iface' => 'net0' );
			}
		}

		return $rules;
	}

	/**
	 * Adds the quarantine rules that are missing.
	 *
	 * @param string            $base  Firewall path.
	 * @param array<int, mixed> $rules Listing.
	 * @param array<string, mixed> $v Verified VM.
	 * @return true|StepResult
	 */
	private function add_quarantine_rules( string $base, array $rules, array $v ) {
		foreach ( $this->quarantine_rules( $v ) as $comment => $params ) {
			$done = $this->add_rule_once( $base, $rules, $comment, $params );

			if ( $done instanceof StepResult ) {
				return $done;
			}
		}

		return true;
	}

	/**
	 * Blocks the server's outgoing traffic: the DNS rules first, then `policy_out` DROP (so DNS is never cut), read back.
	 * Inbound is not changed; a firewall that was off is switched on with an explicit inbound ACCEPT, so nothing new closes.
	 *
	 * @param array<string, mixed> $record VM record.
	 * @param array<string, mixed> $v      Verified VM.
	 */
	private function quarantine( array $record, array $v ): StepResult {
		$flag = $this->r->get( Quarantine::META );

		if ( ! is_array( $flag ) ) {
			return StepResult::done( array(), 'Network quarantine refused: the server is not quarantined any more' );
		}

		$base    = self::base( $record, $v );
		$options = $this->read_list( $base . '/options' );
		$rules   = is_wp_error( $options ) ? $options : $this->read_list( $base . '/rules' );

		if ( is_wp_error( $options ) || is_wp_error( $rules ) ) {
			return $this->stop( is_wp_error( $options ) ? $options : $rules );
		}

		$done = $this->add_quarantine_rules( $base, $rules, $v );

		if ( $done instanceof StepResult ) {
			return $done;
		}

		$change = array();

		if ( 1 !== (int) ( $options['enable'] ?? 0 ) ) {
			$change['enable'] = 1;

			if ( '' === (string) ( $options['policy_in'] ?? '' ) ) {
				$change['policy_in'] = 'ACCEPT';
			}
		}

		if ( 'DROP' !== (string) ( $options['policy_out'] ?? '' ) ) {
			$change['policy_out'] = 'DROP';
		}

		if ( $change ) {
			$answer = $this->write( 'PUT', $base . '/options', $change );

			if ( is_wp_error( $answer ) ) {
				return $this->stop( $answer );
			}
		}

		$options = $this->read_list( $base . '/options' );
		$rules   = is_wp_error( $options ) ? $options : $this->read_list( $base . '/rules' );

		if ( is_wp_error( $options ) || is_wp_error( $rules ) ) {
			return $this->stop( is_wp_error( $options ) ? $options : $rules );
		}

		$comments = array_map( static fn( $rule ) => (string) ( is_array( $rule ) ? ( $rule['comment'] ?? '' ) : '' ), $rules );
		$missing  = array_diff( array_keys( $this->quarantine_rules( $v ) ), $comments );

		if ( 1 !== (int) ( $options['enable'] ?? 0 ) || 'DROP' !== (string) ( $options['policy_out'] ?? '' ) || $missing ) {
			return StepResult::retry( 0, 'firewall: the quarantine read back from Proxmox differs from the one written' );
		}

		return StepResult::done( array( Quarantine::META => Quarantine::notify( $this->r ) ), 'Network quarantine applied: outgoing traffic blocked' );
	}

	/**
	 * Lifts a quarantine: `policy_out` ACCEPT first, then the quarantine rules are removed, read back; the flag goes
	 * only when Proxmox shows it (until then every reconcile keeps the quarantine).
	 *
	 * @param array<string, mixed> $record VM record.
	 * @param array<string, mixed> $v      Verified VM.
	 */
	private function lift( array $record, array $v ): StepResult {
		$base    = self::base( $record, $v );
		$options = $this->read_list( $base . '/options' );

		if ( is_wp_error( $options ) ) {
			return $this->stop( $options );
		}

		if ( 'ACCEPT' !== (string) ( $options['policy_out'] ?? 'ACCEPT' ) ) {
			$answer = $this->write( 'PUT', $base . '/options', array( 'policy_out' => 'ACCEPT' ) );

			if ( is_wp_error( $answer ) ) {
				return $this->stop( $answer );
			}
		}

		for ( $guard = 0; $guard < self::MAX_CEILING; $guard++ ) {
			$rules = $this->read_list( $base . '/rules' );

			if ( is_wp_error( $rules ) ) {
				return $this->stop( $rules );
			}

			$listed = self::tagged( $rules, self::QUARANTINE_TAG );

			if ( ! $listed ) {
				break;
			}

			$target = $listed[0];
			$digest = (string) ( $target['digest'] ?? '' );
			$answer = $this->write( 'DELETE', $base . '/rules/' . (int) ( $target['pos'] ?? 0 ), '' !== $digest ? array( 'digest' => $digest ) : array() );

			if ( is_wp_error( $answer ) ) {
				return $this->stop( $answer );
			}
		}

		$options = $this->read_list( $base . '/options' );
		$rules   = is_wp_error( $options ) ? $options : $this->read_list( $base . '/rules' );

		if ( is_wp_error( $options ) || is_wp_error( $rules ) ) {
			return $this->stop( is_wp_error( $options ) ? $options : $rules );
		}

		if ( 'ACCEPT' !== (string) ( $options['policy_out'] ?? 'ACCEPT' ) || self::tagged( $rules, self::QUARANTINE_TAG ) ) {
			return StepResult::retry( 0, 'firewall: the lifted quarantine read back from Proxmox is not gone' );
		}

		if ( Quarantine::active( $this->r ) ) {
			Quarantine::notify_lifted( $this->r );
		}

		return StepResult::done( array( Quarantine::META => null ), 'Network quarantine lifted: outgoing traffic allowed again' );
	}
}
