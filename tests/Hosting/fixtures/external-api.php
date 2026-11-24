<?php
/**
 * Public hosting classes, methods and constants that code outside the plugin calls (the theme, system scripts).
 * Same format and rules as the list of the main plugin (tests/fixtures/external-api.php); checked by tests/Hosting/ExternalApiTest.php.
 *
 * @package BeltoftServices
 */

return array(
	'Hosting\\Config' => array( 'preview_zone()' ),
	'Hosting\\Nodes' => array( 'SECRETS' ),
	'Hosting\\SiteField' => array( 'is_hosting_product()' ),
);
