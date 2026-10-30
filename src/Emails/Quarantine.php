<?php
/** Customer email: outgoing traffic of a server was blocked. @package BeltoftServices */
namespace BeltoftServices\Emails;

defined( 'ABSPATH' ) || exit;

final class Quarantine extends ServiceEmail {
	public function __construct() {
		$this->id = 'beltoft_services_quarantine';
		$this->title = __( 'Outgoing traffic blocked', 'beltoft-services' );
		$this->description = __( 'Sent when the outgoing network traffic of a server is blocked because it scanned other servers or sent a flood of connections.', 'beltoft-services' );
		$this->template_html = 'emails/beltoft-services-quarantine.php';
		$this->template_plain = 'emails/plain/beltoft-services-quarantine.php';
		parent::__construct();
	}
	public function get_default_subject() { return __( 'Outgoing traffic from your server is blocked', 'beltoft-services' ); }
	public function get_default_heading() { return __( 'Outgoing traffic is blocked', 'beltoft-services' ); }

	/**
	 * What was seen, as label => number lines in words for the customer.
	 *
	 * @param string             $signal scan, syn or udp.
	 * @param array<string, int> $counts Counts of the minute it was seen.
	 * @return array<string, int>
	 */
	public static function lines( string $signal, array $counts ): array {
		$lines = array();
		if ( 'syn' === $signal ) {
			$lines[ __( 'Connection attempts that were never answered', 'beltoft-services' ) ] = (int) ( $counts['unreplied'] ?? 0 );
		} elseif ( 'udp' === $signal ) {
			$lines[ __( 'Different addresses that received UDP traffic', 'beltoft-services' ) ] = (int) ( $counts['udp_dsts'] ?? 0 );
		} else {
			$lines[ __( 'Different addresses contacted', 'beltoft-services' ) ] = (int) ( $counts['dsts'] ?? 0 );
			$lines[ __( 'Different ports contacted', 'beltoft-services' ) ] = (int) ( $counts['dports'] ?? 0 );
		}
		return $lines;
	}
}
