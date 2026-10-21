<?php
/**
 * Customer email: Service deletion warning.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Emails;

defined( 'ABSPATH' ) || exit;

/**
 * Sent to the customer before a paused service is deleted after its retention period.
 */
final class DeletionWarning extends ServiceEmail {

	/**
	 * Set up.
	 */
	public function __construct() {
		$this->id             = 'beltoft_services_deletion_warning';
		$this->title          = __( 'Service deletion warning', 'beltoft-services' );
		$this->description    = __( 'Sent to the customer before a paused service is deleted after its retention period.', 'beltoft-services' );
		$this->template_html  = 'emails/beltoft-services-deletion-warning.php';
		$this->template_plain = 'emails/plain/beltoft-services-deletion-warning.php';

		parent::__construct();
	}

	/**
	 * Default subject.
	 */
	public function get_default_subject() {
		return __( 'Your {product_name} will be deleted on {deletion_date}', 'beltoft-services' );
	}

	/**
	 * Default heading.
	 */
	public function get_default_heading() {
		return __( 'Your service will be deleted', 'beltoft-services' );
	}
}
