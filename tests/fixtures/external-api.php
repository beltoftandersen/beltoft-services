<?php
/**
 * Public classes, methods and constants that code outside this plugin calls (site themes, add-on plugins and system scripts, for example the ones in /usr/local/lib/beltoft/).
 * A name listed here may not be removed, renamed or made private: see tests/Structure/ExternalApiTest.php.
 * Methods end in (), everything else is a class constant. An empty list pins the class name alone.
 *
 * @package BeltoftServices
 */

return array(
	'Account\\Billing' => array( 'current_tab()' ),
	'Account\\ServerPicker' => array( 'FIELD', 'NONCE' ),
	'Account\\Servers' => array( 'requested()', 'slug()' ),
	'Account\\ServiceArea' => array( 'tab_nav()', 'url_for_license()' ),
	'BillingRequirements' => array( 'cart_needs_billing()' ),
	'Credit\\CheckoutCredit' => array( 'preview()' ),
	'Credit\\Topup' => array( 'CART_KEY', 'available()', 'is_product()', 'max()', 'min()' ),
	'Domains\\Prices' => array( 'price()' ),
	'Hosting\\SiteField' => array( 'is_hosting_product()' ),
	'Http\\Connections' => array( 'all()', 'defaults()', 'scheme_allowed()' ),
	'Http\\HttpDriver' => array( 'uses_safe_request()' ),
	'Http\\Signer' => array( 'headers()' ),
	'LineNames' => array( 'value()' ),
	'Locks' => array( 'name()', 'with()' ),
	'Mail\\MailField' => array( 'cart_lines()', 'is_mail_product()' ),
	'Product' => array( 'driver_for()', 'plan()' ),
	'Proxmox\\AppField' => array( 'POST_APP', 'POST_TIER', 'POST_VERSION', 'offer_for()' ),
	'Proxmox\\AppLicence' => array( 'tier()' ),
	'Proxmox\\Backups' => array( 'sibling()' ),
	'Proxmox\\Clients' => array( 'for()' ),
	'Proxmox\\Clusters' => array( 'OPTION', 'get()' ),
	'Proxmox\\DbIpField' => array( 'cart_has_new_database()' ),
	'Proxmox\\DiskField' => array( 'cart_server()', 'server_swap_in_progress()' ),
	'Proxmox\\OsField' => array( 'cart_choice()', 'cart_needs_choice()' ),
	'Proxmox\\Picked' => array( 'KEY', 'clean()' ),
	'Proxmox\\ServerName' => array( 'display()' ),
	'Proxmox\\SshKeyField' => array( 'cart_needs_key()' ),
	'RateLimit' => array( 'exceeded()' ),
	'Settings' => array( 'get()' ),
	'Resource' => array( 'for_license()' ),
	'Usage\\Account' => array( 'ESTABLISHED', 'for_user()' ),
	'Usage\\Breakdown' => array( 'parts()' ),
	'Usage\\Cart' => array( 'usage_units()' ),
	'Usage\\Rates' => array( 'billed_note()', 'cap_money()', 'for_usage()', 'in_cart()', 'is_usage()', 'money()' ),
	'Usage\\Summary' => array(),
);
