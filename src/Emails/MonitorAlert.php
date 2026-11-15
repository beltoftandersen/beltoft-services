<?php
/** Customer email for server down, disk nearly full, CPU high or memory high, and for a database that does not answer or whose disk is nearly full. @package BeltoftServices */
namespace BeltoftServices\Emails;

defined( 'ABSPATH' ) || exit;

final class MonitorAlert extends ServiceEmail {

	use SavedOrDefaultTexts;

	protected const ID = 'beltoft_services_monitor_alert';

	public function get_default_subject() {
		$reason = (string) ( $this->values['monitor_reason'] ?? 'down' );
		if ( 'disk' === $reason ) {
			$total = (int) ( $this->values['monitor_disk_total'] ?? 0 );
			$pct = $total > 0 ? (int) floor( 100 * (int) $this->values['monitor_disk_used'] / $total ) : 0;
			/* translators: %d: percent of the disk in use. */
			return sprintf( __( 'Your server disk is %d percent full', 'beltoft-services' ), $pct );
		}
		if ( 'disk_urgent' === $reason ) { return __( 'Your server disk is almost completely full', 'beltoft-services' ); }
		if ( ! empty( $this->values['monitor_hosting'] ) ) {
			if ( 'cpu' === $reason ) {
				/* translators: %d: number of hours. */
				return sprintf( _n( 'Your hosting has used almost all of its CPU for %d hour', 'Your hosting has used almost all of its CPU for %d hours', (int) ( $this->values['monitor_cpu_hours'] ?? 0 ), 'beltoft-services' ), (int) ( $this->values['monitor_cpu_hours'] ?? 0 ) );
			}
			return __( 'Your hosting is using most of its memory', 'beltoft-services' );
		}
		if ( 'cpu' === $reason ) {
			/* translators: %d: number of hours. */
			return sprintf( _n( 'Your server has used almost all of its CPU for %d hour', 'Your server has used almost all of its CPU for %d hours', (int) ( $this->values['monitor_cpu_hours'] ?? 0 ), 'beltoft-services' ), (int) ( $this->values['monitor_cpu_hours'] ?? 0 ) );
		}
		if ( 'memory' === $reason ) {
			return __( 'Your server is using most of its memory', 'beltoft-services' );
		}
		if ( 'db_disk' === $reason ) {
			/* translators: %d: percent of the disk in use. */
			return sprintf( __( 'Your database disk is %d percent full', 'beltoft-services' ), (int) ( $this->values['monitor_disk_percent'] ?? 0 ) );
		}
		if ( 'db_down' === $reason ) { return __( 'Your database is not answering', 'beltoft-services' ); }
		return __( 'Check your server', 'beltoft-services' );
	}
	public function get_default_heading() {
		if ( ! empty( $this->values['monitor_hosting'] ) ) { return __( 'Your hosting needs attention', 'beltoft-services' ); }
		return in_array( (string) ( $this->values['monitor_reason'] ?? '' ), array( 'db_down', 'db_disk' ), true ) ? __( 'Your database needs attention', 'beltoft-services' ) : __( 'Your server needs attention', 'beltoft-services' );
	}
}
