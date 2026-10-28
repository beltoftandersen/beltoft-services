<?php
/**
 * Switch for DNS hosting of domains registered elsewhere.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Domains;

use BeltoftServices\Settings;

defined( 'ABSPATH' ) || exit;

final class DnsClaimsFeature {

	/**
	 * On only when the setting is on and the schema was verified.
	 */
	public static function enabled(): bool {
		return (bool) Settings::get( 'domain_dns_claims_enabled' ) && DnsSchema::ready();
	}
}
