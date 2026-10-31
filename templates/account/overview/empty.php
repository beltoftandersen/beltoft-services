<?php
/**
 * My Account → Overview: a service area the customer has nothing in yet, with its create button.
 *
 * Override by copying to yourtheme/beltoft-services/account/overview/empty.php.
 *
 * @package BeltoftServices
 *
 * @var string                            $class   Section name (servers, databases, domains, mail).
 * @var string                            $heading Heading.
 * @var string                            $empty   Text of the empty state.
 * @var array<int, array<string, string>> $buttons Buttons: label, url. The last one is the main one. May be empty.
 */

defined( 'ABSPATH' ) || exit;
?>
<section class="beltoft-services-ov__section beltoft-services-ov__<?php echo esc_attr( $class ); ?> is-empty">
	<div class="beltoft-services-ov__head">
		<h2><?php echo esc_html( $heading ); ?></h2>
	</div>
	<p class="beltoft-licenses__empty"><?php echo esc_html( $empty ); ?></p>
	<?php \BeltoftServices\Account\ServiceArea::actions( $buttons, true ); ?>
</section>
