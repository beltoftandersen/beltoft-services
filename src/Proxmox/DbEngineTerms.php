<?php
/**
 * An engine whose licence the customer must accept before a database is made.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Proxmox;

defined( 'ABSPATH' ) || exit;

/**
 * Implemented by an engine on top of DbEngine when the software's maker asks for the acceptance of its terms (SQL Server
 * Express). Checkout shows a required tick box with a link (DbTermsField) and the guest is told it was accepted.
 */
interface DbEngineTerms {

	/**
	 * Where the terms are.
	 */
	public function terms_url(): string;

	/**
	 * The text of the tick box, with %s for the link.
	 */
	public function terms_label(): string;
}
