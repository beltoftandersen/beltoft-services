<?php
/**
 * Today's forbidden dependencies (see DependencyDirectionTest). Remove entries as leaks are fixed; never add.
 * 2026-11-01: 11 entries (FirewallRoute, ThankYou) were already there but hidden by a namespace parsing bug, now fixed.
 */
return array(
	'LineNames.php -> Proxmox\DiskDriver',
	'LineNames.php -> Proxmox\ServerName',
	'LineNames.php -> Usage\Account',
	'LineNames.php -> Usage\Rates',
	'Product.php -> Http\Connections',
	'Product.php -> Usage\Rates',
	'Product.php -> Proxmox\ProxmoxDriver', // The Proxmox-only fields of the product form: was the bare literal, now the constant.
	'Proxmox/FirewallRoute.php -> Mcp\Endpoint',
	'ThankYou.php -> Account\Panel',
	'ThankYou.php -> Domains\HostName',
	'ThankYou.php -> Account\ServerLinks',
	'ThankYou.php -> Api\Routes',
	'ThankYou.php -> Domains\ThankYou',
	'ThankYou.php -> Proxmox\DiskDriver',
	'ThankYou.php -> Proxmox\DiskGuide',
	'ThankYou.php -> Proxmox\ServerName',
	'ThankYou.php -> Proxmox\SnapshotDriver',
	'ThankYou.php -> Proxmox\SnapshotMeta',
	'ThankYou.php -> Proxmox\ProxmoxDriver',
);
