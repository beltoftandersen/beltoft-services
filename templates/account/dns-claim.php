<?php
/**
 * My Account → Domains: add a domain for DNS hosting. The script fills the list and the steps.
 * A theme can override this file as beltoft-services/account/dns-claim.php.
 *
 * @package BeltoftServices
 */

defined( 'ABSPATH' ) || exit;
?>
<section class="beltoft-dns-claim" aria-labelledby="beltoft-dns-claim-title">
	<h3 class="beltoft-dns-claim__title" id="beltoft-dns-claim-title"><?php esc_html_e( 'Add a domain for DNS hosting', 'beltoft-services' ); ?></h3>
	<p class="beltoft-dns-claim__intro"><?php esc_html_e( 'Keep your domain where it is registered and host its DNS here. You add one TXT record to prove the domain is yours, bring your existing records, then change the name servers at your registrar.', 'beltoft-services' ); ?></p>
	<form class="beltoft-dns-claim__form" data-dns-claim-add novalidate>
		<label for="beltoft-dns-claim-domain"><?php esc_html_e( 'Domain name', 'beltoft-services' ); ?></label>
		<span class="beltoft-dns-claim__field">
			<input type="text" id="beltoft-dns-claim-domain" name="domain" placeholder="example.com" autocomplete="off" autocapitalize="off" spellcheck="false" required>
			<button type="submit" class="button woocommerce-button"><?php esc_html_e( 'Add domain', 'beltoft-services' ); ?></button>
		</span>
		<p class="beltoft-dns-claim__error" data-dns-claim-error role="alert" hidden></p>
	</form>
	<div class="beltoft-dns-claim__list" data-dns-claim-list aria-live="polite"></div>
</section>
