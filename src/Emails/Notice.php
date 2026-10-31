<?php
/** Customer email for a short notice about a service: its database access changed, or a restore or rollback did not finish. @package BeltoftServices */
namespace BeltoftServices\Emails;

defined( 'ABSPATH' ) || exit;

final class Notice extends ServiceEmail {
	public const KINDS = array( 'db_access', 'db_world', 'restore_failed', 'rollback_failed' );

	public function __construct() {
		$this->id = 'beltoft_services_notice';
		$this->title = __( 'Service notice', 'beltoft-services' );
		$this->description = __( 'Sent when the access list of a database changes, or when a restore of a server from a backup or a rollback to a snapshot did not finish.', 'beltoft-services' );
		$this->template_html = 'emails/beltoft-services-notice.php';
		$this->template_plain = 'emails/plain/beltoft-services-notice.php';
		parent::__construct();
	}

	/**
	 * The subject and heading of a kind.
	 *
	 * @param string $kind One of KINDS.
	 * @return array{0: string, 1: string}
	 */
	public static function titles( string $kind ): array {
		$titles = array(
			'db_access'       => array( __( 'The access list of your database changed', 'beltoft-services' ), __( 'Database access list changed', 'beltoft-services' ) ),
			'db_world'        => array( __( 'Your database is open to everyone', 'beltoft-services' ), __( 'Your database is open to everyone', 'beltoft-services' ) ),
			'restore_failed'  => array( __( 'Your server restore did not finish', 'beltoft-services' ), __( 'Your server restore did not finish', 'beltoft-services' ) ),
			'rollback_failed' => array( __( 'Your server rollback did not finish', 'beltoft-services' ), __( 'Your server rollback did not finish', 'beltoft-services' ) ),
		);

		return $titles[ $kind ] ?? $titles['db_access'];
	}

	/** The subject follows the kind unless the shop owner saved their own. */
	public function get_subject() {
		$saved = get_option( 'woocommerce_' . $this->id . '_settings', array() );
		if ( is_array( $saved ) && '' !== trim( (string) ( $saved['subject'] ?? '' ) ) ) {
			return parent::get_subject();
		}
		return apply_filters( 'woocommerce_email_subject_' . $this->id, $this->format_string( $this->get_default_subject() ), $this->object, $this ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce email hook.
	}
	public function get_heading() {
		$saved = get_option( 'woocommerce_' . $this->id . '_settings', array() );
		if ( is_array( $saved ) && '' !== trim( (string) ( $saved['heading'] ?? '' ) ) ) {
			return parent::get_heading();
		}
		return apply_filters( 'woocommerce_email_heading_' . $this->id, $this->format_string( $this->get_default_heading() ), $this->object, $this ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce email hook.
	}
	public function get_default_subject() { return self::titles( (string) ( $this->values['notice_kind'] ?? '' ) )[0]; }
	public function get_default_heading() { return self::titles( (string) ( $this->values['notice_kind'] ?? '' ) )[1]; }

	/**
	 * The paragraphs of a kind.
	 *
	 * @param string $kind Kind.
	 * @param string $name What the customer calls the service.
	 * @return string[]
	 */
	public static function lines( string $kind, string $name ): array {
		switch ( $kind ) {
			case 'db_world':
				/* translators: %s: database name. */
				return array(
					sprintf( __( 'You opened your %s to everyone. Anyone on the internet can now try to log in to it. Open it only for as long as you need to.', 'beltoft-services' ), $name ),
					__( 'You can limit access to a list of addresses again in your account, under Databases, on the Access tab.', 'beltoft-services' ),
				);
			case 'restore_failed':
				/* translators: %s: server name. */
				return array(
					sprintf( __( 'The restore of your server %s from a backup did not finish.', 'beltoft-services' ), $name ),
					__( 'We have been told and will look into it. You do not need to do anything. If the server stays off, please contact support.', 'beltoft-services' ),
				);
			case 'rollback_failed':
				/* translators: %s: server name. */
				return array(
					sprintf( __( 'The rollback of your server %s to a snapshot did not finish.', 'beltoft-services' ), $name ),
					__( 'We have been told and will look into it. You do not need to do anything. If the server stays off, please contact support.', 'beltoft-services' ),
				);
			default:
				/* translators: %s: database name. */
				return array(
					sprintf( __( 'The list of addresses that may connect to your %s over its public IP address was changed in your account.', 'beltoft-services' ), $name ),
					__( 'If you did not do this, change your password and contact us.', 'beltoft-services' ),
				);
		}
	}
}
