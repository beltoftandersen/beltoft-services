<?php
/**
 * The scripts and the markup of the monitoring charts, shared by the server page and the hosting page.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Monitoring;

defined( 'ABSPATH' ) || exit;

final class Charts {

	/**
	 * Loads assets/metrics.js and assets/metrics.css and gives the script its words.
	 *
	 * @param string               $parent  Handle of the generic panel script (the REST root and nonce).
	 * @param array<string, string> $strings Words to replace for this kind of page (alertsOn, alertsOff).
	 */
	public static function enqueue( string $parent, array $strings = array() ): void {
		wp_enqueue_script( 'beltoft-services-metrics', plugins_url( 'assets/metrics.js', BELTOFT_SERVICES_FILE ), array( $parent, 'beltoft-services-status', \BeltoftServices\Status::API_HANDLE ), (string) filemtime( BELTOFT_SERVICES_PATH . 'assets/metrics.js' ), true );
		wp_enqueue_style( 'beltoft-services-metrics', plugins_url( 'assets/metrics.css', BELTOFT_SERVICES_FILE ), array(), (string) filemtime( BELTOFT_SERVICES_PATH . 'assets/metrics.css' ) );
		wp_localize_script( 'beltoft-services-metrics', 'beltoftServicesMetrics', array( 'strings' => array_merge( self::strings(), $strings ) ) );
	}

	/**
	 * The words of the chart script.
	 *
	 * @return array<string, string>
	 */
	public static function strings(): array {
		return array(
			'loading' => __( 'Loading usage…', 'beltoft-services' ),
			'empty' => __( 'No data yet.', 'beltoft-services' ),
			'unavailable' => __( 'Usage is unavailable. Please try again later.', 'beltoft-services' ),
			'cpu' => __( 'CPU', 'beltoft-services' ),
			'in' => __( 'In', 'beltoft-services' ),
			'out' => __( 'Out', 'beltoft-services' ),
			'read' => __( 'Read', 'beltoft-services' ),
			'write' => __( 'Write', 'beltoft-services' ),
			'graph' => __( 'usage graph', 'beltoft-services' ),
			/* translators: %s: the highest value in the period, such as 31% */
			'peak' => __( 'peak %s', 'beltoft-services' ),
			'noReading' => __( 'No reading', 'beltoft-services' ),
			'memory' => __( 'Memory', 'beltoft-services' ),
			/* translators: 1: memory in use in GB, 2: total memory in GB. */
			'memOf' => __( '%1$s of %2$s GB', 'beltoft-services' ),
			'total' => __( 'Total', 'beltoft-services' ),
			'agentHint' => GuestMemory::agent_hint(),
			'alertsOn' => __( 'Usage emails are on for this server.', 'beltoft-services' ),
			'alertsOff' => __( 'Usage emails are off for this server.', 'beltoft-services' ),
			'alertsSaving' => __( 'Saving the usage email setting…', 'beltoft-services' ),
			'alertsFailed' => __( 'Could not save. Please try again.', 'beltoft-services' ),
		);
	}

	/**
	 * Prints the monitoring panel (templates/account/monitoring.php).
	 *
	 * @param array<string, mixed> $monitoring states, alerts_shown, alerts_on, alerts_label and the disk block (title, pct, aria, line, note).
	 */
	public static function panel( array $monitoring ): void {
		include BELTOFT_SERVICES_PATH . 'templates/account/monitoring.php';
	}
}
