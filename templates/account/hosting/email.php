<?php
/**
 * The Email panel: the Email lite line and, once it exists, the extra storage of its mailboxes.
 *
 * Variables: $v: HostingView::email() (state is lite, waiting or setup).
 *
 * @package BeltoftServices
 */

defined( 'ABSPATH' ) || exit;
?>
<section class="beltoft-hosting-panel beltoft-hosting-mail" id="beltoft-hosting-email">
	<header class="beltoft-hosting-panel__head"><h3 class="beltoft-hosting-panel__title"><?php esc_html_e( 'Email', 'beltoft-services' ); ?></h3></header>
	<?php if ( 'lite' === $v['state'] ) : ?>
		<p><?php echo esc_html( $v['line'] ); ?> <?php echo esc_html( $v['stays'] ); ?></p>
		<?php if ( '' !== $v['help'] ) : ?>
			<p class="beltoft-hosting-help"><?php echo esc_html( $v['help'] ); ?><?php echo $v['held'] ? ' ' . esc_html__( 'Until the SPF and DKIM records are found, your mailboxes and your websites cannot send mail.', 'beltoft-services' ) : ''; ?> <a class="beltoft-hosting-link" href="<?php echo esc_url( $v['dns_url'] ); ?>"><?php esc_html_e( 'Open the DNS records guide', 'beltoft-services' ); ?></a></p>
		<?php endif; ?>
		<?php if ( '' !== $v['manage_url'] ) : ?>
			<p><a class="beltoft-hosting-link" href="<?php echo esc_url( $v['manage_url'] ); ?>"><?php esc_html_e( 'Manage mailboxes', 'beltoft-services' ); ?></a></p>
		<?php endif; ?>
		<?php if ( null !== $v['packs'] ) : ?>
			<?php \BeltoftServices\Hosting\HostingView::render( 'packs', $v['packs'] ); ?>
		<?php endif; ?>
	<?php elseif ( 'waiting' === $v['state'] ) : ?>
		<p><?php esc_html_e( 'Email lite gives you mailboxes on your own domain, for example info@example.com. It is included with your plan.', 'beltoft-services' ); ?></p>
		<ol class="beltoft-hosting-steps">
			<li><?php echo esc_html( $v['first_step'] ); ?><?php if ( '' !== $v['connect_link'] ) : ?> <a class="beltoft-hosting-link" href="<?php echo esc_url( $v['connect_link'] ); ?>"><?php esc_html_e( 'Connect a domain', 'beltoft-services' ); ?></a><?php endif; ?></li>
			<li><?php esc_html_e( 'When the domain is live, the button "Set up email" shows here. Press it.', 'beltoft-services' ); ?></li>
			<li><?php esc_html_e( 'Create your mailboxes under Email in your account.', 'beltoft-services' ); ?></li>
		</ol>
	<?php else : ?>
		<p><?php echo esc_html( $v['line'] ); ?></p>
		<?php if ( '' !== $v['external'] ) : ?>
			<p class="beltoft-hosting-help"><?php echo esc_html( $v['external'] ); ?></p>
		<?php endif; ?>
		<?php if ( $v['ready'] ) : ?>
			<p><button type="button" class="beltoft-hosting-btn beltoft-hosting-btn--primary" data-hosting-action="enable_mail"><?php esc_html_e( 'Set up email', 'beltoft-services' ); ?></button></p>
		<?php endif; ?>
	<?php endif; ?>
</section>
