<?php
/**
 * The rate limit paragraph.
 *
 * Expects $beltoft_services_cls (the layout's class prefix) and $rate (requests a minute, 0 for no limit).
 *
 * @package BeltoftServices
 */

defined( 'ABSPATH' ) || exit;
?>
<p>
	<?php
	echo esc_html(
		$rate > 0
			/* translators: %d: requests per minute. */
			? sprintf( __( 'Rate limit: %d requests a minute for each token. Some requests have lower limits of their own. A reply of 429 means wait a minute and try again.', 'beltoft-services' ), $rate )
			: __( 'Some requests have rate limits of their own. A reply of 429 means wait a minute and try again.', 'beltoft-services' )
	);
	?>
</p>
