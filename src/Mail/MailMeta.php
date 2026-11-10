<?php
/**
 * Names of the meta keys the mail driver reads on an Email resource, also written by Hosting for Email lite.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Mail;

defined( 'ABSPATH' ) || exit;

/**
 * Plain constants, so no writer of these keys has to repeat the strings.
 */
final class MailMeta {

	/** The address of the system mailbox the hosting account keeps on its Email lite domain. */
	public const SYSTEM = 'mail_system';

	/** A per-resource rate limit (minute, day) that wins over the global setting. */
	public const RATE_LIMIT = 'mail_rl';

	/** Extra storage packs of each mailbox (address => packs). */
	public const PACKS = 'mail_packs';
}
