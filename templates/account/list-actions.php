<?php
/**
 * My Account → a list's action bar: the buttons over the rows, or under the empty line.
 *
 * Override by copying to yourtheme/beltoft-services/account/list-actions.php.
 *
 * @package BeltoftServices
 *
 * @var array<int, array<string, string>> $buttons Buttons: label, url. The last one is the main one.
 * @var bool                              $start   True under an empty line (left edge), false over the rows (right edge).
 */

defined( 'ABSPATH' ) || exit;

if ( ! $buttons ) {
	return;
}
?>
<div class="beltoft-services-list-actions<?php echo $start ? ' is-start' : ''; ?>">
	<?php foreach ( $buttons as $beltoft_services_i => $beltoft_services_button ) : ?>
		<a class="beltoft-services-list-btn<?php echo count( $buttons ) - 1 === $beltoft_services_i ? '' : ' is-ghost'; ?>" href="<?php echo esc_url( $beltoft_services_button['url'] ); ?>"><?php echo esc_html( $beltoft_services_button['label'] ); ?></a>
	<?php endforeach; ?>
</div>
