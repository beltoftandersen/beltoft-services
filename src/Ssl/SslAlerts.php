<?php
/**
 * Staff alerts of the certificate feature.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Ssl;

use BeltoftServices\Alerts;
use BeltoftServices\Resource;

defined( 'ABSPATH' ) || exit;

/**
 * The core Alerts class names no feature (rule 1): the certificate's own alerts live here and send through the generic Alerts::notify().
 */
final class SslAlerts {

	/**
	 * Tells the staff that the certificate authority reissued a certificate by itself and the new one waits for domain validation, so
	 * someone can check the validation record before the running certificate ends. Once per order (the caller keeps the mark too).
	 *
	 * @param Resource $r      Certificate resource.
	 * @param string   $domain Domain name.
	 */
	public static function reissue_waits( Resource $r, string $domain ): bool {
		$license = $r->license();

		return Alerts::notify(
			sprintf( 'SSL renewal waits for validation: %s', $domain ),
			array(
				sprintf( 'Domain: %s', $domain ),
				sprintf( 'Resource: %d', $r->id() ),
				sprintf( 'Order id: %s', (string) $r->get( 'remote_id' ) ),
				sprintf( 'Customer: %d', (int) ( $license['user_id'] ?? 0 ) ),
				'The certificate authority reissued this certificate by itself and the new one waits for domain validation. The running certificate keeps working until it ends.',
				'Check: the validation CNAME is in DNS (the DNS watch status of the order), and the order at the registrar is not stuck. Nothing is ordered, reissued or cancelled automatically.',
			),
			$r,
			'beltoft_services_ssl_reissue_waits_' . $r->id(),
			array( 'ttl' => 7 * DAY_IN_SECONDS )
		);
	}
}
