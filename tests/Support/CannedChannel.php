<?php
/** A DnsChannel for tests: answers from a script and records every send. @package BeltoftServices */
namespace BeltoftServices\Tests\Support;

use BeltoftServices\Domains\DnsChannel;

class CannedChannel implements DnsChannel {
	/** @var array<int, array{0: string, 1: int, 2: string, 3: string}> host, port, wire, protocol. */
	public array $sent = array();

	/** @param array<string, string|\WP_Error> $answers protocol => packet or error. */
	public function __construct( private array $answers ) {}

	public function send( string $host, int $port, string $wire, string $protocol ): string|\WP_Error {
		$this->sent[] = array( $host, $port, $wire, $protocol );
		$answer       = $this->answers[ $protocol ] ?? new \WP_Error( 'dns_unreachable', 'No scripted answer.' );
		if ( is_string( $answer ) && strlen( $answer ) >= 2 ) {
			// Echo the id of the question, like a server does.
			$answer = substr( $wire, 0, 2 ) . substr( $answer, 2 );
		}
		return $answer;
	}

	/** @return string[] */
	public function protocols(): array {
		return array_column( $this->sent, 3 );
	}
}
