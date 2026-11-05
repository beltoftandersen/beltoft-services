<?php
/** The customer's page and the admin box of a certificate. @package BeltoftServices */
namespace BeltoftServices\Ssl;

use BeltoftServices\Account\Panel;
use BeltoftServices\Resource;

defined( 'ABSPATH' ) || exit;

/**
 * The page shows what the certificate is, what validation waits for (with the exact values to copy), the downloads of the
 * public parts, the private key once when we made it, and the actions. Nothing secret is printed: the key is fetched by a
 * button, once, by a route that wipes it before it answers.
 */
final class Panels {
	public const HANDLE = 'beltoft-services-ssl';

	/** Hooks. */
	public static function register(): void {
		add_action( 'beltoft_services_account_assets', array( __CLASS__, 'early' ), 20, 2 );
		add_filter( 'beltoft_services_account_buttons', array( __CLASS__, 'buttons' ), 10, 2 );
	}

	/**
	 * The page draws its own buttons and forms, so the generic buttons of this driver are not printed.
	 *
	 * @param mixed $buttons  Buttons.
	 * @param mixed $resource Resource.
	 * @return mixed
	 */
	public static function buttons( $buttons, $resource ) {
		if ( is_array( $buttons ) && $resource instanceof Resource && SslDriver::ID === $resource->get( 'driver' ) ) {
			return array();
		}

		return $buttons;
	}

	/**
	 * The script and style of the page.
	 */
	public static function assets(): void {
		wp_enqueue_style( self::HANDLE, plugins_url( 'assets/ssl.css', BELTOFT_SERVICES_FILE ), array(), \BeltoftServices\Assets::version( 'assets/ssl.css' ) );
		wp_enqueue_script( self::HANDLE, plugins_url( 'assets/ssl.js', BELTOFT_SERVICES_FILE ), array( Panel::HANDLE, \BeltoftServices\Status::HANDLE ), \BeltoftServices\Assets::version( 'assets/ssl.js' ), true );
		wp_localize_script(
			self::HANDLE,
			'beltoftServicesSsl',
			array(
				'strings' => array(
					'failed'    => __( 'Something went wrong. Please try again.', 'beltoft-services' ),
					'limit'     => __( 'Too many requests. Please wait and try again later.', 'beltoft-services' ),
					'keyGone'   => __( 'The key has already been taken or is no longer available.', 'beltoft-services' ),
					'keyOnce'   => __( 'The key is saved on your device. It is shown one time only and we keep no copy.', 'beltoft-services' ),
					'copied'    => __( 'Copied', 'beltoft-services' ),
					'working'   => __( 'Working...', 'beltoft-services' ),
					'queued'    => __( 'Your request is on its way. This can take a minute.', 'beltoft-services' ),
					'confirm'   => __( 'Type CANCEL to cancel this certificate. The certificate stops working.', 'beltoft-services' ),
				),
			)
		);
	}

	/**
	 * Listener of beltoft_services_account_assets: on the certificates endpoint.
	 *
	 * @param mixed $parent   Handle of the panel script.
	 * @param mixed $endpoint The My Account endpoint being shown.
	 */
	public static function early( $parent = null, $endpoint = null ): void {
		unset( $parent );

		if ( is_string( $endpoint ) && \BeltoftServices\Account\ServiceArea::get( \BeltoftServices\Account\Ssl::class )->slug() === $endpoint && is_user_logged_in() ) {
			self::assets();
		}
	}

	/**
	 * A customer word for the state of the certificate.
	 *
	 * @param Resource $r Resource.
	 */
	public static function status_text( Resource $r ): string {
		$status = (string) $r->get( 'ssl_status' );

		if ( $r->get( 'ssl_cancelled' ) || in_array( $status, array( 'REJ', 'FAI' ), true ) ) {
			return __( 'Cancelled', 'beltoft-services' );
		}

		if ( 'EXP' === $status ) {
			return __( 'Expired', 'beltoft-services' );
		}

		if ( 'ACT' === $status ) {
			if ( $r->get( 'ssl_reissue_pending' ) ) {
				return __( 'Reissue in progress', 'beltoft-services' );
			}

			$to = (int) strtotime( (string) $r->get( 'ssl_valid_to' ) . ' UTC' );

			if ( $to > 0 ) {
				$days = (int) floor( ( $to - time() ) / DAY_IN_SECONDS );
				/* translators: %d: days. */
				return $days >= 0 ? sprintf( _n( 'Active, expires in %d day', 'Active, expires in %d days', $days, 'beltoft-services' ), $days ) : __( 'Expired', 'beltoft-services' );
			}

			return __( 'Active', 'beltoft-services' );
		}

		if ( 'REQ' === $status ) {
			return array() !== (array) $r->get( 'ssl_validation_rows' ) && 'sent' !== ( ( (array) $r->get( 'ssl_validation_rows' ) )[0]['status'] ?? '' ) && 'published' !== ( ( (array) $r->get( 'ssl_validation_rows' ) )[0]['status'] ?? '' ) ? __( 'Waiting for validation', 'beltoft-services' ) : __( 'Waiting for the certificate authority', 'beltoft-services' );
		}

		return __( 'Being set up', 'beltoft-services' );
	}

	/**
	 * Prints the customer's page.
	 *
	 * @param Resource $r Resource.
	 */
	public static function account( Resource $r ): void {
		self::assets();

		$name    = (string) $r->get( 'ssl_name' );
		$status  = (string) $r->get( 'ssl_status' );
		$rows    = array_values( array_filter( (array) $r->get( 'ssl_validation_rows' ), 'is_array' ) );
		$has     = '' !== (string) $r->get( 'ssl_cert' );
		$fmt     = static fn( string $utc ): string => '' === $utc ? '' : wp_date( get_option( 'date_format' ), (int) strtotime( $utc . ' UTC' ) );
		$actions = Operations::offered( $r );

		echo '<div class="beltoft-services-ssl" data-resource="' . esc_attr( (string) $r->id() ) . '" data-name="' . esc_attr( $name ) . '">';
		echo '<dl class="beltoft-services-ssl__facts">';
		self::fact( __( 'Name', 'beltoft-services' ), $name );
		self::fact( __( 'Status', 'beltoft-services' ), self::status_text( $r ) );

		if ( $has ) {
			self::fact( __( 'Valid from', 'beltoft-services' ), $fmt( (string) $r->get( 'ssl_valid_from' ) ) );
			self::fact( __( 'Valid to', 'beltoft-services' ), $fmt( (string) $r->get( 'ssl_valid_to' ) ) );
			self::fact( __( 'Yearly service ends', 'beltoft-services' ), $fmt( (string) $r->get( 'ssl_order_expires' ) ) );
		}

		echo '</dl>';

		if ( 'REQ' === $status && array() !== $rows ) {
			self::validation( $r, $rows );
		}

		if ( $has ) {
			self::downloads( $r );
		}

		echo '<p class="beltoft-services-ssl__note">' . esc_html__( 'Certificates are valid for at most 199 days now and 100 days from March 2027. Your yearly order is reissued during the year, and each new certificate has to be installed. We email you when a new one is ready, and the pull address above always holds the current one.', 'beltoft-services' ) . '</p>';

		if ( array() !== $actions && '' !== $r->get( 'remote_id' ) ) {
			self::actions( $r, $actions );
		}

		echo '<div class="beltoft-services-ssl__notice" role="status" aria-live="polite" hidden></div>';
		echo '</div>';
	}

	/**
	 * One fact.
	 *
	 * @param string $label Label.
	 * @param string $value Value.
	 */
	private static function fact( string $label, string $value ): void {
		if ( '' !== $value ) {
			echo '<div><dt>' . esc_html( $label ) . '</dt><dd>' . esc_html( $value ) . '</dd></div>';
		}
	}

	/**
	 * What validation waits for.
	 *
	 * @param Resource                          $r    Resource.
	 * @param array<int, array<string, string>> $rows Rows.
	 */
	private static function validation( Resource $r, array $rows ): void {
		$kind = (string) $r->get( 'ssl_validation' );

		echo '<h3>' . esc_html__( 'Validation', 'beltoft-services' ) . '</h3>';

		if ( 'email' === $kind ) {
			/* translators: %s: approval email address. */
			echo '<p>' . esc_html( sprintf( __( 'The certificate authority sent an email to %s. Open it and approve the request.', 'beltoft-services' ), (string) ( $rows[0]['value'] ?? '' ) ) ) . '</p>';

			return;
		}

		if ( ! empty( $r->get( 'ssl_validation_auto' ) ) ) {
			echo '<p>' . esc_html__( 'We added the validation record to your DNS. The certificate is issued when the authority has seen it.', 'beltoft-services' ) . '</p>';

			return;
		}

		echo '<p>' . esc_html( 'file' === $kind ? __( 'Put a file with this content on your website, at this address:', 'beltoft-services' ) : __( 'Add this CNAME record where your DNS is hosted:', 'beltoft-services' ) ) . '</p>';
		echo '<table class="beltoft-services-ssl__rows"><tbody>';

		foreach ( $rows as $row ) {
			echo '<tr><th scope="row">' . esc_html( 'file' === (string) ( $row['type'] ?? '' ) ? __( 'Address', 'beltoft-services' ) : __( 'Name', 'beltoft-services' ) ) . '</th><td><code>' . esc_html( (string) ( $row['name'] ?? '' ) ) . '</code></td></tr>';
			echo '<tr><th scope="row">' . esc_html( 'file' === (string) ( $row['type'] ?? '' ) ? __( 'Content', 'beltoft-services' ) : __( 'Points to', 'beltoft-services' ) ) . '</th><td><code style="white-space:pre-wrap;word-break:break-all">' . esc_html( (string) ( $row['value'] ?? '' ) ) . '</code></td></tr>';
		}

		echo '</tbody></table>';
		echo '<p>' . esc_html__( 'Leave the record in place after the certificate is issued. It lets later reissues validate without you.', 'beltoft-services' ) . '</p>';
	}

	/**
	 * Downloads, the pull address and the key.
	 *
	 * @param Resource $r Resource.
	 */
	private static function downloads( Resource $r ): void {
		echo '<h3>' . esc_html__( 'Your certificate', 'beltoft-services' ) . '</h3><p class="beltoft-services-ssl__downloads">';

		foreach ( array( 'fullchain' => __( 'Certificate with chain', 'beltoft-services' ), 'cert' => __( 'Certificate only', 'beltoft-services' ), 'chain' => __( 'Chain only', 'beltoft-services' ) ) as $part => $label ) {
			echo '<button type="button" class="button woocommerce-button" data-ssl-download="' . esc_attr( $part ) . '">' . esc_html( $label ) . '</button> ';
		}

		echo '</p>';
		echo '<p class="beltoft-services-ssl__pull">' . esc_html__( 'To fetch the current certificate from a script, read this address with an API token (read access is enough). It never holds a key.', 'beltoft-services' ) . '<br><code>' . esc_html( rest_url( \BeltoftServices\Api\Routes::NAMESPACE . '/resources/' . $r->id() . '/ssl-certificate' ) ) . '</code></p>';

		if ( Keys::revealable( $r ) ) {
			echo '<p><button type="button" class="button woocommerce-button" data-ssl-key>' . esc_html__( 'Take the private key', 'beltoft-services' ) . '</button> ';
			echo '<span class="description">' . esc_html__( 'We made the key for you. You can take it once in the next days, then we delete it. If you miss it, reissue the certificate for free to get a new key.', 'beltoft-services' ) . '</span></p>';
		} elseif ( 'generate' === (string) $r->get( 'ssl_key_mode' ) ) {
			echo '<p class="description">' . esc_html__( 'The private key has been taken or has been deleted. We keep no copy. Reissue the certificate for free to get a new key.', 'beltoft-services' ) . '</p>';
		}
	}

	/**
	 * The actions.
	 *
	 * @param Resource              $r       Resource.
	 * @param array<string, string> $actions Offered actions.
	 */
	private static function actions( Resource $r, array $actions ): void {
		echo '<h3>' . esc_html__( 'Manage', 'beltoft-services' ) . '</h3>';

		if ( isset( $actions['ssl_reissue'] ) ) {
			echo '<form class="beltoft-services-ssl__form" data-ssl-action="ssl_reissue"><p><label>' . esc_html__( 'Certificate request (CSR), or leave empty and we make a new key for you', 'beltoft-services' ) . '<br><textarea name="csr" rows="4" cols="60" spellcheck="false"></textarea></label></p>';
			echo '<p><button type="submit" class="button woocommerce-button">' . esc_html( $actions['ssl_reissue'] ) . '</button></p></form>';
		}

		if ( isset( $actions['ssl_change_validation'] ) ) {
			echo '<form class="beltoft-services-ssl__form" data-ssl-action="ssl_change_validation"><p><label>' . esc_html__( 'Validation method', 'beltoft-services' ) . ' <select name="method">';
			$wild = '*.' === substr( (string) $r->get( 'ssl_name' ), 0, 2 );

			foreach ( array( 'dns' => __( 'DNS record I add myself', 'beltoft-services' ), 'file' => __( 'File on my website', 'beltoft-services' ), 'email' => __( 'Email to an approval address', 'beltoft-services' ) ) as $value => $label ) {
				if ( ! ( $wild && 'file' === $value ) ) {
					echo '<option value="' . esc_attr( $value ) . '">' . esc_html( $label ) . '</option>';
				}
			}

			echo '</select></label> <label>' . esc_html__( 'Approval address', 'beltoft-services' ) . ' <select name="approver">';

			foreach ( SslNames::APPROVERS as $prefix ) {
				echo '<option value="' . esc_attr( $prefix ) . '">' . esc_html( $prefix . '@' . (string) $r->get( 'ssl_domain' ) ) . '</option>';
			}

			echo '</select></label> <button type="submit" class="button woocommerce-button">' . esc_html( $actions['ssl_change_validation'] ) . '</button></p></form>';
		}

		echo '<p>';

		if ( isset( $actions['ssl_resend_email'] ) ) {
			echo '<button type="button" class="button woocommerce-button" data-ssl-action="ssl_resend_email">' . esc_html( $actions['ssl_resend_email'] ) . '</button> ';
		}

		if ( '' !== $r->get( 'remote_id' ) ) {
			echo '<button type="button" class="button woocommerce-button" data-ssl-panel>' . esc_html__( 'Open the certificate authority page', 'beltoft-services' ) . '</button> ';
		}

		if ( isset( $actions['ssl_cancel'] ) ) {
			/* translators: %d: days. */
			$note = '' !== (string) $r->get( 'ssl_issued_at' ) && (int) $r->get( 'ssl_issued_at' ) > 0 ? sprintf( __( 'You can cancel for a refund until %d days after the certificate was issued.', 'beltoft-services' ), SslSettings::refund_days() ) : '';
			echo '<button type="button" class="button woocommerce-button" data-ssl-cancel>' . esc_html( $actions['ssl_cancel'] ) . '</button> <span class="description">' . esc_html( $note ) . '</span>';
		}

		echo '</p>';
	}

	/**
	 * The admin box on the license screen.
	 *
	 * @param Resource $r Resource.
	 */
	public static function admin( Resource $r ): void {
		$details = array_filter(
			array(
				'Name'               => $r->get( 'ssl_name' ),
				'Registrar order'    => $r->get( 'remote_id' ),
				'Registrar product'  => ( (array) $r->get( 'plan' ) )['ssl_product'] ?? '',
				'Status'             => $r->get( 'ssl_status' ),
				'Validation'         => $r->get( 'ssl_validation' ),
				'Key'                => $r->get( 'ssl_key_mode' ),
				'Serial'             => $r->get( 'ssl_serial' ),
				'Valid to'           => $r->get( 'ssl_valid_to' ),
				'Service ends'       => $r->get( 'ssl_order_expires' ),
				'Reissue pending'    => $r->get( 'ssl_reissue_pending' ) ? 'yes' : '',
				'Cancelled'          => $r->get( 'ssl_cancelled' ) ? 'yes' : '',
			)
		);

		$cost = SslCatalog::cost( (int) ( ( (array) $r->get( 'plan' ) )['ssl_product'] ?? 0 ) );

		if ( null !== $cost ) {
			$details['Registrar cost per year (EUR)'] = number_format( $cost, 2 );
		}

		foreach ( $details as $label => $value ) {
			printf( '<p><strong>%s:</strong> %s</p>', esc_html( $label ), esc_html( (string) $value ) );
		}

		$subscription = (int) ( $r->license()['subscription_id'] ?? 0 );

		if ( $subscription ) {
			printf( '<p><a href="%s">%s</a></p>', esc_url( (string) get_edit_post_link( $subscription ) ), esc_html__( 'Subscription', 'beltoft-services' ) );
		}
	}
}
