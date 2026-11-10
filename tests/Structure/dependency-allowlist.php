<?php
/**
 * Today's forbidden dependencies (see DependencyDirectionTest). Remove entries as leaks are fixed; never add.
 * 2026-11 widening (FEATURES += Hosting, Ssl, Licences, Credit, Card, Admin, Cli): no new violations, so nothing was added.
 * 2026-11-01: 11 entries (FirewallRoute, ThankYou) were already there but hidden by a namespace parsing bug, now fixed.
 */
return array(
	'LineNames.php -> Proxmox\DiskDriver',
	'LineNames.php -> Proxmox\Vm\ServerName',
	'LineNames.php -> Usage\Account',
	'LineNames.php -> Usage\Rates',
	'Product.php -> Http\Connections',
	'Product.php -> Usage\Rates',
	'Product.php -> Proxmox\Vm\ProxmoxDriver', // The Proxmox-only fields of the product form: was the bare literal, now the constant.
	'Proxmox/FirewallRoute.php -> Mcp\Endpoint',
	'ThankYou.php -> Account\Panel',
	'ThankYou.php -> Account\ServerLinks',
	'ThankYou.php -> Api\Routes',
);
