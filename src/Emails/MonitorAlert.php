<?php
/** Customer email for server down, disk nearly full or CPU high, and for a database that does not answer or whose disk is nearly full. @package BeltoftServices */
namespace BeltoftServices\Emails;

defined( 'ABSPATH' ) || exit;

final class MonitorAlert extends ServiceEmail {
	public function __construct() {
		$this->id = 'beltoft_services_monitor_alert';
		$this->title = __( 'Server monitoring alert', 'beltoft-services' );
		$this->description = __( 'Sent when a server is down unexpectedly, its disk is nearly full or its CPU stays high, or when a database does not answer or its disk is nearly full.', 'beltoft-services' );
		$this->template_html = 'emails/beltoft-services-monitor-alert.php';
		$this->template_plain = 'emails/plain/beltoft-services-monitor-alert.php';
		parent::__construct();
	}
	/** The subject follows the reason unless the shop owner saved their own. */
	public function get_subject() {
		$saved = get_option( 'woocommerce_' . $this->id . '_settings', array() );
		if ( is_array( $saved ) && '' !== trim( (string) ( $saved['subject'] ?? '' ) ) ) {
			return parent::get_subject();
		}
		return apply_filters( 'woocommerce_email_subject_' . $this->id, $this->format_string( $this->get_default_subject() ), $this->object, $this ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce email hook.
	}
	/** The heading follows the reason unless the shop owner saved their own (the form default is fixed at construct time). */
	public function get_heading() {
		$saved = get_option( 'woocommerce_' . $this->id . '_settings', array() );
		if ( is_array( $saved ) && '' !== trim( (string) ( $saved['heading'] ?? '' ) ) ) {
			return parent::get_heading();
		}
		return apply_filters( 'woocommerce_email_heading_' . $this->id, $this->format_string( $this->get_default_heading() ), $this->object, $this ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce email hook.
	}
	public function get_default_subject() {
		$reason = (string) ( $this->values['monitor_reason'] ?? 'down' );
		if ( 'disk' === $reason ) {
			$total = (int) ( $this->values['monitor_disk_total'] ?? 0 );
			$pct = $total > 0 ? (int) floor( 100 * (int) $this->values['monitor_disk_used'] / $total ) : 0;
			/* translators: %d: percent of the disk in use. */
			return sprintf( __( 'Your server disk is %d percent full', 'beltoft-services' ), $pct );
		}
		if ( 'disk_urgent' === $reason ) { return __( 'Your server disk is almost completely full', 'beltoft-services' ); }
		if ( 'cpu' === $reason ) {
			/* translators: %d: number of hours. */
			return sprintf( _n( 'Your server has used almost all of its CPU for %d hour', 'Your server has used almost all of its CPU for %d hours', (int) ( $this->values['monitor_cpu_hours'] ?? 0 ), 'beltoft-services' ), (int) ( $this->values['monitor_cpu_hours'] ?? 0 ) );
		}
		if ( 'db_disk' === $reason ) {
			/* translators: %d: percent of the disk in use. */
			return sprintf( __( 'Your database disk is %d percent full', 'beltoft-services' ), (int) ( $this->values['monitor_disk_percent'] ?? 0 ) );
		}
		if ( 'db_down' === $reason ) { return __( 'Your database is not answering', 'beltoft-services' ); }
		return __( 'Check your server', 'beltoft-services' );
	}
	public function get_default_heading() {
		return in_array( (string) ( $this->values['monitor_reason'] ?? '' ), array( 'db_down', 'db_disk' ), true ) ? __( 'Your database needs attention', 'beltoft-services' ) : __( 'Your server needs attention', 'beltoft-services' );
	}
}
