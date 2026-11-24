<?php
/**
 * Adds the hosting emails to the service emails.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Hosting;

use BeltoftServices\Hosting\Emails\HostingMalwareFound;
use BeltoftServices\Hosting\Emails\HostingNotice;
use BeltoftServices\Hosting\Emails\HostingReady;
use BeltoftServices\Hosting\Emails\HostingRestoreDone;

defined( 'ABSPATH' ) || exit;

/**
 * The core's email registry knows no hosting email: it learns the classes, the definitions and the empty template values
 * through the filters below. Without this class registered, no hosting email exists and nothing is sent.
 */
final class EmailRegistry {

	/**
	 * Hooks.
	 */
	public static function register(): void {
		add_filter( 'beltoft_services_email_classes', array( __CLASS__, 'classes' ) );
		add_filter( 'beltoft_services_email_definitions', array( __CLASS__, 'definitions' ) );
		add_filter( 'beltoft_services_email_value_defaults', array( __CLASS__, 'value_defaults' ) );
	}

	/**
	 * The email classes.
	 *
	 * @param mixed $classes Classes so far.
	 * @return mixed
	 */
	public static function classes( $classes ) {
		if ( ! is_array( $classes ) ) {
			return $classes;
		}

		return $classes + array(
			'BeltoftServices_HostingReady'        => HostingReady::class,
			'BeltoftServices_HostingNotice'       => HostingNotice::class,
			'BeltoftServices_HostingRestoreDone'  => HostingRestoreDone::class,
			'BeltoftServices_HostingMalwareFound' => HostingMalwareFound::class,
		);
	}

	/**
	 * The definitions (built here, so the translations resolve late).
	 *
	 * @param mixed $rows Rows so far.
	 * @return mixed
	 */
	public static function definitions( $rows ) {
		if ( ! is_array( $rows ) ) {
			return $rows;
		}

		return $rows + array(
			'beltoft_services_hosting_ready' => array(
				'title'       => __( 'Hosting ready', 'beltoft-services' ),
				'description' => __( 'Sent when a hosting account is set up. It has the address of the site, the WordPress admin link (for a website with WordPress), the SFTP details and the DNS records an external domain needs. It never holds a password.', 'beltoft-services' ),
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
			'beltoft_services_hosting_malware_found' => array(
				'title'       => __( 'Hosting malware found', 'beltoft-services' ),
				'description' => __( 'Sent when the nightly scan of a hosting account finds files that match known malware. It names the websites and the files and says what to do. Nothing is removed or changed, and the websites stay online.', 'beltoft-services' ),
				'subject'     => __( 'Malware found on your hosting {product_name}', 'beltoft-services' ),
				'heading'     => __( 'Malware found on your website', 'beltoft-services' ),
			),
		);
	}

	/**
	 * The empty values of the hosting template keys.
	 *
	 * @param mixed $defaults Defaults so far.
	 * @return mixed
	 */
	public static function value_defaults( $defaults ) {
		if ( ! is_array( $defaults ) ) {
			return $defaults;
		}

		return $defaults + array(
			'hosting'               => array( 'site_name' => '', 'site_url' => '', 'admin_url' => '', 'admin_user' => '', 'admin_email' => '', 'sftp_host' => '', 'sftp_port' => 0, 'sftp_user' => 'web', 'ssh' => false, 'domains' => array(), 'dns_records' => array(), 'has_email' => false ),
			'hosting_notice'        => array( 'kind' => 'storage_warning', 'percent' => 0, 'used' => 0, 'limit' => 0, 'days' => 0, 'php' => '', 'php_default' => '', 'php_end' => '', 'site' => '' ),
			'hosting_restore'       => array( 'kind' => 'account', 'ok' => true, 'until' => '', 'site' => '' ),
			'hosting_malware'       => array( 'before' => '', 'sites' => array() ),
		);
	}
}
