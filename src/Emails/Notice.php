<?php
/** Customer email for a short notice about a service: its database access changed, or a restore or rollback did not finish. @package BeltoftServices */
namespace BeltoftServices\Emails;

defined( 'ABSPATH' ) || exit;

final class Notice extends ServiceEmail {
	public const KINDS = array( 'db_access', 'db_world', 'restore_failed', 'rollback_failed', 'reveal_server', 'reveal_database', 'reveal_app', 'reveal_mailbox', 'app_action' );

	/**
	 * Kinds that are about what a connected app did: sent whatever the shop's switch for this e-mail says, because they are the
	 * customer's way to learn that something was done in their name.
	 */
	public const ALWAYS = array( 'reveal_server', 'reveal_database', 'reveal_app', 'reveal_mailbox', 'app_action' );

	/**
	 * Is it sent? The kinds about a connected app are, always.
	 */
	public function is_enabled() {
		return in_array( (string) ( $this->values['notice_kind'] ?? '' ), self::ALWAYS, true ) || parent::is_enabled();
	}

	public function __construct() {
		$this->id = 'beltoft_services_notice';
		$this->title = __( 'Service notice', 'beltoft-services' );
		$this->description = __( 'Sent when the access list of a database changes, when a connected app showed a password, or when a restore of a server from a backup or a rollback to a snapshot did not finish.', 'beltoft-services' );
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
			'app_action'      => array( __( 'A connected app changed one of your services', 'beltoft-services' ), __( 'A connected app changed one of your services', 'beltoft-services' ) ),
			'reveal_server'   => array( __( 'A connected app showed your server password', 'beltoft-services' ), __( 'A connected app showed your server password', 'beltoft-services' ) ),
			'reveal_database' => array( __( 'A connected app showed your database password', 'beltoft-services' ), __( 'A connected app showed your database password', 'beltoft-services' ) ),
			'reveal_app'      => array( __( 'A connected app showed your app login', 'beltoft-services' ), __( 'A connected app showed your app login', 'beltoft-services' ) ),
			'reveal_mailbox'  => array( __( 'A connected app showed a mailbox password', 'beltoft-services' ), __( 'A connected app showed a mailbox password', 'beltoft-services' ) ),
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
	 * Is the notice about a database (else about a server)?
	 *
	 * @param string $kind One of KINDS.
	 */
	public static function is_database( string $kind ): bool {
		return in_array( $kind, array( 'db_access', 'db_world', 'reveal_database' ), true );
	}

	/**
	 * The words of the link at the end of the mail.
	 *
	 * @param string $kind One of KINDS.
	 */
	public static function link_text( string $kind ): string {
		if ( 'app_action' === $kind ) {
			return __( 'View your services in your account', 'beltoft-services' );
		}

		if ( 'reveal_mailbox' === $kind ) {
			return __( 'View your email in your account', 'beltoft-services' );
		}

		return self::is_database( $kind ) ? __( 'View your database in your account', 'beltoft-services' ) : __( 'View your server in your account', 'beltoft-services' );
	}

	/**
	 * The paragraphs of a kind.
	 *
	 * @param string $kind Kind.
	 * @param string $name What the customer calls the service.
	 * @param string $app  The connected app that did it, when the kind is about one.
	 * @return string[]
	 */
	public static function lines( string $kind, string $name, string $app = '', string $what = '' ): array {
		switch ( $kind ) {
			case 'app_action':
				return array(
					'' !== $app
						/* translators: 1: name of the connected app, 2: name of the service, 3: what it did, for example reinstalled the server. */
						? sprintf( __( 'An app you connected to your account (%1$s) changed %2$s at your request: it %3$s.', 'beltoft-services' ), $app, $name, $what )
						/* translators: 1: name of the service, 2: what it did, for example reinstalled the server. */
						: sprintf( __( 'An app you connected to your account changed %1$s at your request: it %2$s.', 'beltoft-services' ), $name, $what ),
					__( 'If this was not you, disconnect the app under Account details, Security, and change your account password.', 'beltoft-services' ),
				);

			case 'reveal_server':
			case 'reveal_database':
			case 'reveal_app':
			case 'reveal_mailbox':
				$what = array(
					/* translators: %s: server name. */
					'reveal_server'   => __( 'the password of your server %s', 'beltoft-services' ),
					/* translators: %s: database name. */
					'reveal_database' => __( 'the password of your database %s', 'beltoft-services' ),
					/* translators: %s: server name. */
					'reveal_app'      => __( 'the first login of the app on your server %s', 'beltoft-services' ),
					/* translators: %s: email domain. */
					'reveal_mailbox'  => __( 'the password of a mailbox of your email domain %s', 'beltoft-services' ),
				)[ $kind ];
				$what = sprintf( $what, $name );

				return array(
					'' !== $app
						/* translators: 1: name of the connected app, 2: what it showed, for example the password of your server web. */
						? sprintf( __( 'An app you connected to your account (%1$s) showed %2$s, at your request. It is now in your conversation with the app.', 'beltoft-services' ), $app, $what )
						/* translators: %s: what it showed, for example the password of your server web. */
						: sprintf( __( 'An app you connected to your account showed %s, at your request. It is now in your conversation with the app.', 'beltoft-services' ), $what ),
					'reveal_database' === $kind
						? __( 'If this was not you, disconnect the app under Account details, Security, change your account password, and make a new database password in your account. You can also change the password whenever you like.', 'beltoft-services' )
						: ( 'reveal_mailbox' === $kind
							? __( 'If this was not you, disconnect the app under Account details, Security, change your account password, and make a new password for the mailbox in your account.', 'beltoft-services' )
							: __( 'If this was not you, disconnect the app under Account details, Security, change your account password, and make a new password for the service in your account. You can also change the password whenever you like.', 'beltoft-services' ) ),
				);
			case 'db_world':
				return array(
					/* translators: %s: database name. */
					sprintf( __( 'You opened your %s to everyone. Anyone on the internet can now try to log in to it. Open it only for as long as you need to.', 'beltoft-services' ), $name ),
					__( 'You can limit access to a list of addresses again in your account, under Databases, on the Access tab.', 'beltoft-services' ),
				);
			case 'restore_failed':
				return array(
					/* translators: %s: server name. */
					sprintf( __( 'The restore of your server %s from a backup did not finish.', 'beltoft-services' ), $name ),
					__( 'We have been told and will look into it. You do not need to do anything. If the server stays off, please contact support.', 'beltoft-services' ),
				);
			case 'rollback_failed':
				return array(
					/* translators: %s: server name. */
					sprintf( __( 'The rollback of your server %s to a snapshot did not finish.', 'beltoft-services' ), $name ),
					__( 'We have been told and will look into it. You do not need to do anything. If the server stays off, please contact support.', 'beltoft-services' ),
				);
			default:
				return array(
					/* translators: %s: database name. */
					sprintf( __( 'The list of addresses that may connect to your %s over its public IP address was changed in your account.', 'beltoft-services' ), $name ),
					__( 'If you did not do this, change your password and contact us.', 'beltoft-services' ),
				);
		}
	}
}
