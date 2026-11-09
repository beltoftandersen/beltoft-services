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
	'Progress.php -> Proxmox\AppProgress',
	'Progress.php -> Proxmox\AppTab',
	'Progress.php -> Proxmox\DbDriver',
	'Progress.php -> Proxmox\DiskDriver',
	'Progress.php -> Proxmox\SshKeyField',
	'Proxmox/FirewallRoute.php -> Mcp\Endpoint',
	'Settings.php -> Mcp\Access',
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
	'ThankYou.php -> Proxmox\SshKeyField',
);
