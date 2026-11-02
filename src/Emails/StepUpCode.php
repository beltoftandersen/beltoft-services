<?php
/**
 * Customer email: the one-time code of the second check (StepUp).
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Emails;

use BeltoftServices\StepUp;

defined( 'ABSPATH' ) || exit;

/**
 * Sent to the account address on request. It always goes out: switching it off would lock customers out of the check.
 */
final class StepUpCode extends \WC_Email {

	/**
	 * Template values for the current send.
	 *
	 * @var array<string, mixed>
	 */
	private array $values = array();

	/**
	 * Set up.
	 */
	public function __construct() {
		$this->id             = 'beltoft_services_step_up_code';
		$this->title          = __( 'Confirmation code', 'beltoft-services' );
		$this->description    = __( 'Sent to the customer when they ask for a code to confirm a sensitive step, such as making a Full access API token or showing a domain transfer code. It is always sent.', 'beltoft-services' );
		$this->customer_email = true;
		$this->template_base  = BELTOFT_SERVICES_PATH . 'templates/';
		$this->template_html  = 'emails/beltoft-services-step-up-code.php';
		$this->template_plain = 'emails/plain/beltoft-services-step-up-code.php';

		parent::__construct();
	}

	/**
	 * Always on.
	 */
	public function is_enabled() {
		return true;
	}

	/**
	 * Default subject. The code is not in it, so it does not show on a lock screen.
	 */
	public function get_default_subject() {
		return __( 'Your confirmation code', 'beltoft-services' );
	}

	/**
	 * Default heading.
	 */
	public function get_default_heading() {
		return __( 'Your confirmation code', 'beltoft-services' );
	}

	/**
	 * What the code is for, in words.
	 *
	 * @param string $purpose One of StepUp::PURPOSES.
	 */
	public static function purpose_text( string $purpose ): string {
		return StepUp::TRANSFER_CODE === $purpose
			? __( 'showing a domain transfer code', 'beltoft-services' )
			: __( 'making a Full access API token', 'beltoft-services' );
	}

	/**
	 * Sends the code.
	 *
	 * @param \WP_User $user    Customer.
	 * @param string   $purpose One of StepUp::PURPOSES.
	 * @param string   $code    The code.
	 */
	public function trigger( \WP_User $user, string $purpose, string $code ): bool {
		$this->setup_locale();
		$sent = false;

		try {
			$this->object    = $user;
			$this->recipient = (string) $user->user_email;
			$this->values    = array(
				'customer_name' => (string) ( $user->first_name ? $user->first_name : $user->display_name ),
				'code'          => $code,
				'purpose_text'  => self::purpose_text( $purpose ),
				'minutes'       => StepUp::TTL_MINUTES,
				'site_name'     => wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES ),
			);

			if ( $this->get_recipient() ) {
				$sent = (bool) $this->send( $this->get_recipient(), $this->get_subject(), $this->get_content(), $this->get_headers(), $this->get_attachments() );
			}
		} finally {
			$this->restore_locale();
		}

		return $sent;
	}

	/**
	 * Template arguments.
	 *
	 * @param bool $plain_text Plain text.
	 * @return array<string, mixed>
	 */
	private function template_args( bool $plain_text ): array {
		return array_merge(
			$this->values,
			array(
				'email_heading'      => $this->get_heading(),
				'additional_content' => $this->get_additional_content(),
				'sent_to_admin'      => false,
				'plain_text'         => $plain_text,
				'email'              => $this,
			)
		);
	}

	/**
	 * HTML content.
	 */
	public function get_content_html() {
		return wc_get_template_html( $this->template_html, $this->template_args( false ), '', $this->template_base );
	}

	/**
	 * Plain text content.
	 */
	public function get_content_plain() {
		return wc_get_template_html( $this->template_plain, $this->template_args( true ), '', $this->template_base );
	}
}
