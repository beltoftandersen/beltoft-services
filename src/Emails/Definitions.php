<?php
/**
 * The customer emails' titles, descriptions, default subjects and headings, and their template paths.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Emails;

defined( 'ABSPATH' ) || exit;

/**
 * One table for every service email, so a class needs only its id. The text is built in a function,
 * not a constant, so the translations are looked up when it is read.
 *
 * The templates follow the id: beltoft_services_domain_ready is emails/beltoft-services-domain-ready.php
 * and emails/plain/beltoft-services-domain-ready.php.
 */
final class Definitions {

	/**
	 * The ids of the emails, in the order they are listed.
	 *
	 * @return string[]
	 */
	public static function ids(): array {
		return array_keys( self::table() );
	}

	/**
	 * What an email says about itself.
	 *
	 * @param string $id Email id.
	 * @return array{title: string, description: string, subject: string, heading: string, html: string, plain: string}
	 */
	public static function get( string $id ): array {
		$row = self::table()[ $id ] ?? null;

		if ( null === $row ) {
			throw new \InvalidArgumentException( sprintf( 'No such service email: %s', $id ) );
		}

		$html = 'emails/beltoft-services-' . str_replace( '_', '-', substr( $id, strlen( 'beltoft_services_' ) ) ) . '.php';

		return $row + array(
			'html'  => $html,
			'plain' => 'emails/plain/' . basename( $html ),
		);
	}

	/**
	 * The table. The subject and heading of an email whose subject follows what it is about (the
	 * monitoring alert, the notice) are the ones it has before anything is sent; its class
	 * overrides them for a send.
	 *
	 * @return array<string, array<string, string>>
	 */
	private static function table(): array {
		return array(
			'beltoft_services_ready' => array(
				'title'       => __( 'Service ready', 'beltoft-services' ),
				'description' => __( 'Sent to the customer when their service is ready to use.', 'beltoft-services' ),
				'subject'     => __( 'Your {product_name} is ready', 'beltoft-services' ),
				'heading'     => __( 'Your service is ready', 'beltoft-services' ),
			),
			'beltoft_services_paused' => array(
				'title'       => __( 'Service paused', 'beltoft-services' ),
				'description' => __( 'Sent to the customer when their service is paused.', 'beltoft-services' ),
				'subject'     => __( 'Your {product_name} is paused', 'beltoft-services' ),
				'heading'     => __( 'Your service is paused', 'beltoft-services' ),
			),
			'beltoft_services_deletion_warning' => array(
				'title'       => __( 'Service deletion warning', 'beltoft-services' ),
				'description' => __( 'Sent to the customer before a paused service is deleted after its retention period.', 'beltoft-services' ),
				'subject'     => __( 'Your {product_name} will be deleted on {deletion_date}', 'beltoft-services' ),
				'heading'     => __( 'Your service will be deleted', 'beltoft-services' ),
			),
			'beltoft_services_deleted' => array(
				'title'       => __( 'Service deleted', 'beltoft-services' ),
				'description' => __( 'Sent to the customer when their service has been deleted.', 'beltoft-services' ),
				'subject'     => __( 'Your {product_name} has been deleted', 'beltoft-services' ),
				'heading'     => __( 'Your service has been deleted', 'beltoft-services' ),
			),
			'beltoft_services_limit_exceeded' => array(
				'title'       => __( 'Service limit exceeded', 'beltoft-services' ),
				'description' => __( 'Sent to the customer when the daily usage check finds their service over its limit.', 'beltoft-services' ),
				'subject'     => __( 'Your {product_name} is over its limit', 'beltoft-services' ),
				'heading'     => __( 'Your service is over its limit', 'beltoft-services' ),
			),
			'beltoft_services_provision_delay' => array(
				'title'       => __( 'Service setup delayed', 'beltoft-services' ),
				'description' => __( 'Sent once to the customer when setting up their server, domain, DNS hosting or website hosting failed and needs attention.', 'beltoft-services' ),
				'subject'     => __( 'A delay setting up your {product_name}', 'beltoft-services' ),
				'heading'     => __( 'There is a delay setting up your service', 'beltoft-services' ),
			),
			'beltoft_services_domain_action_needed' => array(
				'title'       => __( 'Domain action needed', 'beltoft-services' ),
				'description' => __( 'Sent when a registration or transfer needs a customer approval.', 'beltoft-services' ),
				'subject'     => __( 'Action needed for your domain', 'beltoft-services' ),
				'heading'     => __( 'Your domain needs a confirmation', 'beltoft-services' ),
			),
			'beltoft_services_domain_transfer_fix' => array(
				'title'       => __( 'Domain transfer needs a fix', 'beltoft-services' ),
				'description' => __( 'Sent once to the customer when a transfer waits for a new transfer code or for the domain to be unlocked.', 'beltoft-services' ),
				'subject'     => __( 'Your transfer of {domain_name} needs your action', 'beltoft-services' ),
				'heading'     => __( 'Your transfer needs your action', 'beltoft-services' ),
			),
			'beltoft_services_monitor_alert' => array(
				'title'       => __( 'Server monitoring alert', 'beltoft-services' ),
				'description' => __( 'Sent when a server is down unexpectedly, its disk is nearly full or its CPU stays high, or when a database does not answer or its disk is nearly full.', 'beltoft-services' ),
				'subject'     => __( 'Check your server', 'beltoft-services' ),
				'heading'     => __( 'Your server needs attention', 'beltoft-services' ),
			),
			'beltoft_services_domain_transferred' => array(
				'title'       => __( 'Domain transferred', 'beltoft-services' ),
				'description' => __( 'Sent to the customer once when a domain transfer has completed.', 'beltoft-services' ),
				'subject'     => __( 'Your domain {domain_name} is now with us', 'beltoft-services' ),
				'heading'     => __( 'Your domain is now with us', 'beltoft-services' ),
			),
			'beltoft_services_domain_registered' => array(
				'title'       => __( 'Domain registered', 'beltoft-services' ),
				'description' => __( 'Sent to the customer once when a new domain registration has completed.', 'beltoft-services' ),
				'subject'     => __( 'Your domain {domain_name} is registered', 'beltoft-services' ),
				'heading'     => __( 'Your domain is registered', 'beltoft-services' ),
			),
			'beltoft_services_domain_security' => array(
				'title'       => __( 'Domain security notice', 'beltoft-services' ),
				'description' => __( 'Sent to the customer when a domain is unlocked, its transfer code is shown, its name servers change or it is locked again.', 'beltoft-services' ),
				'subject'     => __( 'Security notice for your domain {domain_name}', 'beltoft-services' ),
				'heading'     => __( 'A change was made to your domain', 'beltoft-services' ),
			),
			'beltoft_services_snapshot_notice' => array(
				'title'       => __( 'Snapshot notice', 'beltoft-services' ),
				'description' => __( 'Sent when snapshots of a server are deleted with a removed disk, are shortened because paid snapshot slots ended, or (when the setting is on) have expired.', 'beltoft-services' ),
				'subject'     => __( 'About your server snapshots', 'beltoft-services' ),
				'heading'     => __( 'Your snapshots changed', 'beltoft-services' ),
			),
			'beltoft_services_quarantine' => array(
				'title'       => __( 'Outgoing traffic blocked', 'beltoft-services' ),
				'description' => __( 'Sent when the outgoing network traffic of a server is blocked because it scanned other servers or sent a flood of connections.', 'beltoft-services' ),
				'subject'     => __( 'Outgoing traffic from your server is blocked', 'beltoft-services' ),
				'heading'     => __( 'Outgoing traffic is blocked', 'beltoft-services' ),
			),
			'beltoft_services_quarantine_lifted' => array(
				'title'       => __( 'Outgoing traffic open again', 'beltoft-services' ),
				'description' => __( 'Sent when the block on the outgoing network traffic of a server is lifted.', 'beltoft-services' ),
				'subject'     => __( 'Outgoing traffic from your server is open again', 'beltoft-services' ),
				'heading'     => __( 'Outgoing traffic is open again', 'beltoft-services' ),
			),
			'beltoft_services_app_domain_live' => array(
				'title'       => __( 'App live on your own domain', 'beltoft-services' ),
				'description' => __( 'Sent when an app on a server is installed and reachable on a domain the customer registered elsewhere.', 'beltoft-services' ),
				'subject'     => __( 'Your app is live on your domain', 'beltoft-services' ),
				'heading'     => __( 'Your app is live', 'beltoft-services' ),
			),
			'beltoft_services_notice' => array(
				'title'       => __( 'Service notice', 'beltoft-services' ),
				'description' => __( 'Sent when the access list of a database changes, when a connected app showed a password, or when a restore of a server from a backup or a rollback to a snapshot did not finish.', 'beltoft-services' ),
				'subject'     => __( 'The access list of your database changed', 'beltoft-services' ),
				'heading'     => __( 'Database access list changed', 'beltoft-services' ),
			),
			'beltoft_services_mail_ready' => array(
				'title'       => __( 'Email domain ready', 'beltoft-services' ),
				'description' => __( 'Sent when a Business email domain is set up. It lists the DNS records the customer must add when the domain is hosted elsewhere. It never holds a password.', 'beltoft-services' ),
				'subject'     => __( 'Your email domain {domain_name} is ready', 'beltoft-services' ),
				'heading'     => __( 'Your email domain is ready', 'beltoft-services' ),
			),
			'beltoft_services_mail_dns_reminder' => array(
				'title'       => __( 'Email DNS records missing', 'beltoft-services' ),
				'description' => __( 'Sent once, a day after an email domain hosted elsewhere was set up, when DNS records are still missing.', 'beltoft-services' ),
				'subject'     => __( 'DNS records are still missing for {domain_name}', 'beltoft-services' ),
				'heading'     => __( 'DNS records are still missing', 'beltoft-services' ),
			),
			'beltoft_services_hosting_ready' => array(
				'title'       => __( 'Hosting ready', 'beltoft-services' ),
				'description' => __( 'Sent when a WordPress hosting account is set up. It has the address of the site, the WordPress admin link, the SFTP details and the DNS records an external domain needs. It never holds a password.', 'beltoft-services' ),
				'subject'     => __( 'Your hosting {product_name} is ready', 'beltoft-services' ),
				'heading'     => __( 'Your hosting is ready', 'beltoft-services' ),
			),
			'beltoft_services_hosting_notice' => array(
				'title'       => __( 'Hosting notice', 'beltoft-services' ),
				'description' => __( 'Sent when a hosting account is close to or over its storage, and before and when a PHP version loses security support.', 'beltoft-services' ),
				'subject'     => __( 'About your hosting {product_name}', 'beltoft-services' ),
				'heading'     => __( 'About your hosting', 'beltoft-services' ),
			),
			'beltoft_services_hosting_restore_done' => array(
				'title'       => __( 'Hosting restore finished', 'beltoft-services' ),
				'description' => __( 'Sent when a restore or an undo of a hosting account has finished or has failed. It says what was restored and until when it can be undone.', 'beltoft-services' ),
				'subject'     => __( 'Your hosting restore has finished', 'beltoft-services' ),
				'heading'     => __( 'Your restore has finished', 'beltoft-services' ),
			),
			'beltoft_services_renewal_reminder' => array(
				'title'       => __( 'Renewal reminder', 'beltoft-services' ),
				'description' => __( 'Sent a product-set number of days before a long renewal (for example 30 days before a yearly hosting renewal): the plan, the date, the full price and how to cancel.', 'beltoft-services' ),
				'subject'     => __( 'Your {product_name} renews on {renewal_date}', 'beltoft-services' ),
				'heading'     => __( 'Your {product_name} renews on {renewal_date}', 'beltoft-services' ),
			),
			'beltoft_services_ssl_issued' => array(
				'title'       => __( 'SSL certificate issued', 'beltoft-services' ),
				'description' => __( 'Sent when a certificate is issued. It links to the page where the certificate and the private key are downloaded. It never holds a key.', 'beltoft-services' ),
				'subject'     => __( 'Your SSL certificate for {domain_name} is ready', 'beltoft-services' ),
				'heading'     => __( 'Your SSL certificate is ready', 'beltoft-services' ),
			),
			'beltoft_services_ssl_action_needed' => array(
				'title'       => __( 'SSL validation needed', 'beltoft-services' ),
				'description' => __( 'Sent when the customer has to do something to validate a domain: add a DNS record, put a file on the website or approve an email.', 'beltoft-services' ),
				'subject'     => __( 'Action needed to validate {domain_name}', 'beltoft-services' ),
				'heading'     => __( 'Your certificate needs a validation step', 'beltoft-services' ),
			),
			'beltoft_services_ssl_reissued' => array(
				'title'       => __( 'SSL certificate reissued', 'beltoft-services' ),
				'description' => __( 'Sent when a new certificate for an existing order is ready to install: reissued by the authority or at the customer request.', 'beltoft-services' ),
				'subject'     => __( 'A new certificate for {domain_name} is ready to install', 'beltoft-services' ),
				'heading'     => __( 'A new certificate is ready', 'beltoft-services' ),
			),
			'beltoft_services_ssl_expiring' => array(
				'title'       => __( 'SSL certificate ending', 'beltoft-services' ),
				'description' => __( 'Sent when the current certificate ends within days and no replacement has been issued yet.', 'beltoft-services' ),
				'subject'     => __( 'Your certificate for {domain_name} ends soon', 'beltoft-services' ),
				'heading'     => __( 'Your certificate ends soon', 'beltoft-services' ),
			),
		);
	}
}
