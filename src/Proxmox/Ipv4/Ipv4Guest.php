<?php
/**
 * The extra IPv4 addresses of a server, set up inside the guest through the guest agent.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Proxmox\Ipv4;

use BeltoftLicenses\Events;
use BeltoftServices\Apps\Apps;
use BeltoftServices\Drivers\StepResult;
use BeltoftServices\Jobs;
use BeltoftServices\Proxmox\Pve\Client;
use BeltoftServices\Resource;
use BeltoftServices\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Writes two files into the guest (agent file-write): the apply script (`data/ipv4-extra-apply.script`) and the wanted list
 * (JSON), runs the script once (agent exec, then exec-status until it ended) and reads its status file back (agent file-read).
 * The script finds the network stack and the card from the guest itself, adds or removes only the extra /32 addresses, live and
 * persistently, and never touches the main address (see the header of the script). The result is kept in the resource meta
 * `ipv4_guest`; it is never a failure of the add-on: a guest without a working agent, an exec the guest refuses or a script that
 * reports a problem leaves the extra addresses "not set up", and the page shows the lines for the customer to put in by hand.
 *
 * Endpoints (API viewer, https://pve.proxmox.com/pve-docs/api-viewer/index.html#/nodes/{node}/qemu/{vmid}/agent): POST
 * agent/file-write (`file`, `content`), POST agent/exec (`command` as a list, answer `pid`), GET agent/exec-status (`pid`,
 * answer `exited`, `exitcode`), GET agent/file-read (`file`).
 */
final class Ipv4Guest {

	/** The customer action "Apply again" (an Ipv4Server action; the same run as the sync's guest phase). */
	public const ACTION = Ipv4Server::GUEST;

	/** Meta: the outcome, see state(). */
	public const META = 'ipv4_guest';

	/** Meta: when a build stops waiting for the guest agent. */
	public const UNTIL = 'ipv4_guest_until';

	public const DIR    = '/var/lib/beltoft-app';
	public const SCRIPT = self::DIR . '/ipv4-extra.sh';
	public const WANT   = self::DIR . '/ipv4-extra.json';
	public const STATUS = self::DIR . '/ipv4-extra.status.json';

	/** Seconds a build waits for the guest agent before the addresses are left to the customer. */
	public const BUILD_WAIT = 300;

	/** The stacks the script may report. */
	private const STACKS = array( 'netplan', 'networkmanager', 'ifupdown', 'none' );

	/**
	 * @var Resource
	 */
	private $r;

	/**
	 * @var Client
	 */
	private $client;

	/**
	 * @param Resource $r      Server.
	 * @param Client   $client Client of its cluster.
	 */
	public function __construct( Resource $r, Client $client ) {
		$this->r      = $r;
		$this->client = $client;
	}

	/**
	 * Does a build or rebuild of the server have extra addresses to put in the guest? A database never has.
	 *
	 * @param Resource $r Resource.
	 */
	public static function wanted_at_build( Resource $r ): bool {
		return Ipv4Meta::is_parent( $r ) && ! Ipv4Meta::is_db( $r ) && array() !== Ipv4Meta::extra_addresses( $r );
	}

	/**
	 * The customer action "Apply again", offered for a ready server that has extra addresses.
	 *
	 * @param Resource $r Server.
	 * @return array<string, string> Action => label.
	 */
	public static function offered( Resource $r ): array {
		if ( ! self::wanted_at_build( $r ) || 'ready' !== $r->get( 'state' ) ) {
			return array();
		}

		return array( self::ACTION => __( 'Apply again', 'beltoft-services' ) );
	}

	/**
	 * Why the action cannot be accepted, in words for the customer; null when it can.
	 *
	 * @param Resource $r Server.
	 */
	public static function check( Resource $r ): ?string {
		if ( ! self::wanted_at_build( $r ) ) {
			return __( 'This server has no extra IPv4 addresses.', 'beltoft-services' );
		}

		return '' !== Ipv4Server::working( $r ) && Ipv4Server::GUEST !== Ipv4Server::working( $r ) ? __( 'An IPv4 address is being changed. Please try again in a few minutes.', 'beltoft-services' ) : null;
	}

	/**
	 * The stored outcome, with every key present.
	 *
	 * @param Resource $r Server.
	 * @return array{state: string, at: int, nic: string, stack: string, profile: string, persisted: bool, applied: string[], note: string}
	 */
	public static function state( Resource $r ): array {
		$meta = $r->get( self::META );
		$meta = is_array( $meta ) ? $meta : array();

		return array(
			'state'     => in_array( $meta['state'] ?? '', array( 'applied', 'not_applied', 'agent_missing', 'error' ), true ) ? (string) $meta['state'] : '',
			'at'        => (int) ( $meta['at'] ?? 0 ),
			'nic'       => self::clean_nic( (string) ( $meta['nic'] ?? '' ) ),
			'stack'     => in_array( $meta['stack'] ?? '', self::STACKS, true ) ? (string) $meta['stack'] : '',
			'profile'   => self::clean_text( (string) ( $meta['profile'] ?? '' ), 80 ),
			'persisted' => ! empty( $meta['persisted'] ),
			'applied'   => array_values( array_filter( array_map( 'strval', (array) ( $meta['applied'] ?? array() ) ), static fn( $a ) => false !== filter_var( $a, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) ),
			'note'      => self::clean_text( (string) ( $meta['note'] ?? '' ), 200 ),
		);
	}

	/**
	 * Build step `extra_ips`, after the start: waits (up to BUILD_WAIT seconds) for the guest agent, then sets the addresses up.
	 * Never fails the build.
	 *
	 * @param array<string, mixed> $record VM record.
	 * @param array<string, mixed> $v      Verified VM.
	 * @return array<string, mixed>|StepResult
	 */
	public function at_build( array $record, array $v ) {
		$meta = array( self::UNTIL => null );

		if ( ! self::wanted_at_build( $this->r ) ) {
			return $meta;
		}

		if ( 'running' === (string) ( $v['status'] ?? '' ) && is_wp_error( $this->client->request( 'POST', self::base( $record, $v ) . '/agent/ping' ) ) ) {
			$until = (int) $this->r->get( self::UNTIL );

			if ( 0 === $until ) {
				$until = Jobs::now() + self::BUILD_WAIT;
				$this->r->set( array( self::UNTIL => $until ) );
			}

			if ( Jobs::now() < $until ) {
				return StepResult::poll( 10, 'extra ipv4: waiting for the guest agent', 900 );
			}
		}

		$this->apply( $record, $v );

		return $meta;
	}

	/**
	 * Sets the extra addresses up now (or takes the ones that are gone away) and stores the outcome. Never fails.
	 *
	 * @param array<string, mixed> $record VM record.
	 * @param array<string, mixed> $v      Verified VM: node, status.
	 * @return array<string, mixed>|null The stored outcome, or null when there was nothing to do.
	 */
	public function apply( array $record, array $v ): ?array {
		if ( Ipv4Meta::is_db( $this->r ) ) {
			return null;
		}

		$fresh  = Resource::find( $this->r->id() ) ?? $this->r;
		$extras = Ipv4Meta::extra_addresses( $fresh );
		$before = self::state( $fresh );

		// Nothing wanted, and nothing was ever set up (or it is all gone): leave the guest alone.
		if ( array() === $extras && array() === $before['applied'] ) {
			if ( '' !== $before['state'] ) {
				$this->r->set( array( self::META => null ) );
			}

			return null;
		}

		if ( Settings::get( 'pause_all' ) ) {
			return $this->store( 'not_applied', $before['applied'], 'all driver activity is paused', $before );
		}

		if ( 'running' !== (string) ( $v['status'] ?? '' ) ) {
			return $this->store( 'not_applied', $before['applied'], 'the server is not running', $before );
		}

		$base  = self::base( $record, $v );
		$token = wp_generate_password( 16, false );
		$want  = wp_json_encode(
			array(
				'v'      => 1,
				'run'    => $token,
				'main'   => $this->main( $fresh ),
				'extras' => $extras,
			)
		);

		$script = self::script();

		if ( '' === $script || false === $want ) {
			return $this->store( 'error', $before['applied'], 'the apply script is missing from the plugin', $before );
		}

		foreach ( array( self::SCRIPT => $script, self::WANT => $want ) as $file => $content ) {
			$wrote = $this->client->request( 'POST', $base . '/agent/file-write', array( 'file' => $file, 'content' => $content ) );

			if ( is_wp_error( $wrote ) ) {
				return $this->store( 'agent_missing', $before['applied'], 'the guest agent did not take the file: ' . self::clean_text( $wrote->get_error_message(), 120 ), $before );
			}
		}

		$exec = $this->client->request( 'POST', $base . '/agent/exec', array( 'command' => array( '/bin/sh', self::SCRIPT ) ) );

		if ( is_wp_error( $exec ) || ! is_array( $exec ) || ! isset( $exec['pid'] ) ) {
			return $this->store( 'not_applied', $before['applied'], 'the guest refused to run the script' . ( is_wp_error( $exec ) ? ': ' . self::clean_text( $exec->get_error_message(), 120 ) : '' ), $before );
		}

		$exited = $this->wait_for( $base, (int) $exec['pid'] );

		if ( ! $exited ) {
			return $this->store( 'error', $before['applied'], 'the script did not finish in time', $before );
		}

		$raw    = Apps::read_file( $this->client, (string) $v['node'], (int) $record['vmid'], self::STATUS );
		$status = null === $raw ? null : self::parse_status( $raw, $token, $extras );

		if ( null === $status ) {
			return $this->store( 'error', $before['applied'], 'the script left no readable status', $before );
		}

		$state = $status['ok'] && count( $status['applied'] ) === count( $extras ) ? 'applied' : ( array() === $status['applied'] && array() !== $extras ? 'not_applied' : 'error' );

		if ( array() === $extras && $status['ok'] ) {
			$this->r->set( array( self::META => null ) );

			return null;
		}

		return $this->store( $state, $status['applied'], implode( '; ', $status['errors'] ), $before, $status );
	}

	/**
	 * Polls exec-status until the script ended.
	 *
	 * @param string $base VM path.
	 * @param int    $pid  Process id.
	 */
	private function wait_for( string $base, int $pid ): bool {
		for ( $try = 0; $try < 20; $try++ ) {
			$answer = $this->client->request( 'GET', $base . '/agent/exec-status', array( 'pid' => $pid ) );

			if ( is_array( $answer ) && ! empty( $answer['exited'] ) ) {
				return true;
			}

			if ( is_wp_error( $answer ) ) {
				return false;
			}

			/**
			 * Seconds between two looks at the script (tests set 0).
			 *
			 * @param int $seconds Seconds.
			 */
			$seconds = max( 0, (int) apply_filters( 'beltoft_services_ipv4_guest_wait', 1 ) );

			if ( $seconds > 0 ) {
				sleep( $seconds );
			}
		}

		return false;
	}

	/**
	 * Stores the outcome, and says so in the licence log when the addresses are not all set up.
	 *
	 * @param string                    $state   applied, not_applied, agent_missing or error.
	 * @param string[]                  $applied Extra addresses that are on the card.
	 * @param string                    $note    Why, short.
	 * @param array<string, mixed>      $before  The outcome before.
	 * @param array<string, mixed>|null $status  The script's status.
	 * @return array<string, mixed>
	 */
	private function store( string $state, array $applied, string $note, array $before, ?array $status = null ): array {
		$new = array(
			'state'     => $state,
			'at'        => Jobs::now(),
			'nic'       => null === $status ? $before['nic'] : $status['nic'],
			'stack'     => null === $status ? $before['stack'] : $status['stack'],
			'profile'   => null === $status ? $before['profile'] : $status['profile'],
			'persisted' => null === $status ? false : $status['persisted'],
			'applied'   => $applied,
			'note'      => self::clean_text( $note, 200 ),
		);

		$this->r->set( array( self::META => $new ) );

		if ( 'applied' !== $state ) {
			Events::add( (int) ( $this->r->license()['id'] ?? 0 ), 'service', 'Extra IPv4 addresses not set up in the server (' . $state . ')' . ( '' !== $new['note'] ? ': ' . $new['note'] : '' ) );
		}

		return $new;
	}

	/**
	 * The main address the script checks the card by.
	 *
	 * @param Resource $server Server.
	 */
	private function main( Resource $server ): string {
		$ip = (string) $server->get( 'ip' );

		return false !== filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ? $ip : '';
	}

	/**
	 * The apply script.
	 */
	public static function script(): string {
		$file = BELTOFT_SERVICES_PATH . 'data/ipv4-extra-apply.script';
		$text = is_readable( $file ) ? file_get_contents( $file ) : false; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a plugin file.

		return is_string( $text ) ? $text : '';
	}

	/**
	 * What the script reported, checked: null when it is not a status of this run.
	 *
	 * @param string   $raw    The status file.
	 * @param string   $token  The run token that was written into the wanted list.
	 * @param string[] $wanted The extra addresses asked for.
	 * @return array{ok: bool, nic: string, stack: string, profile: string, persisted: bool, applied: string[], errors: string[]}|null
	 */
	public static function parse_status( string $raw, string $token, array $wanted ): ?array {
		$data = json_decode( $raw, true );

		if ( ! is_array( $data ) || 1 !== (int) ( $data['v'] ?? 0 ) || '' === $token || (string) ( $data['run'] ?? '' ) !== $token ) {
			return null;
		}

		$applied = array();

		foreach ( (array) ( $data['applied'] ?? array() ) as $address ) {
			if ( is_string( $address ) && in_array( $address, $wanted, true ) ) {
				$applied[] = $address;
			}
		}

		$errors = array();

		foreach ( array_slice( (array) ( $data['errors'] ?? array() ), 0, 3 ) as $error ) {
			if ( is_string( $error ) && '' !== self::clean_text( $error, 160 ) ) {
				$errors[] = self::clean_text( $error, 160 );
			}
		}

		return array(
			'ok'        => true === ( $data['ok'] ?? false ) && array() === $errors,
			'nic'       => self::clean_nic( (string) ( $data['nic'] ?? '' ) ),
			'stack'     => in_array( $data['stack'] ?? '', self::STACKS, true ) ? (string) $data['stack'] : '',
			'profile'   => self::clean_text( (string) ( $data['profile'] ?? '' ), 80 ),
			'persisted' => true === ( $data['persisted'] ?? false ),
			'applied'   => array_values( array_unique( $applied ) ),
			'errors'    => $errors,
		);
	}

	/**
	 * A network card name as the guest shows it, or ''.
	 *
	 * @param string $nic Name.
	 */
	public static function clean_nic( string $nic ): string {
		return 1 === preg_match( '/^[A-Za-z0-9_.:@-]{1,32}$/D', $nic ) ? $nic : '';
	}

	/**
	 * Text from the guest or the client for a note: one line, no markup.
	 *
	 * @param string $text   Text.
	 * @param int    $length Most characters.
	 */
	private static function clean_text( string $text, int $length ): string {
		return mb_substr( trim( (string) preg_replace( '/\s+/', ' ', wp_strip_all_tags( wp_check_invalid_utf8( $text, true ) ) ) ), 0, $length );
	}

	/**
	 * The VM's path.
	 *
	 * @param array<string, mixed> $record VM record.
	 * @param array<string, mixed> $v      Verified VM.
	 */
	private static function base( array $record, array $v ): string {
		return '/nodes/' . rawurlencode( (string) $v['node'] ) . '/qemu/' . $record['vmid'];
	}
}
