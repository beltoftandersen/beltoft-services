<?php
/**
 * Today's forbidden dependencies (see DependencyDirectionTest). Remove entries as leaks are fixed; never add.
 */
return array(
	'LineNames.php -> Proxmox\DiskDriver',
	'LineNames.php -> Proxmox\ServerName',
	'LineNames.php -> Usage\Account',
	'LineNames.php -> Usage\Rates',
	'Mail/MailAccess.php -> Proxmox\Access',
	'Mail/MailDns.php -> Proxmox\AppDomains',
	'Mail/MailDomain.php -> Proxmox\AppDomains',
	'Mail/MailDomain.php -> Proxmox\AppOwnDomain',
	'Mail/MailField.php -> Proxmox\AppDomains',
	'Product.php -> Http\Connections',
	'Product.php -> Usage\Rates',
	'Progress.php -> Proxmox\AppProgress',
	'Progress.php -> Proxmox\AppTab',
	'Progress.php -> Proxmox\DbDriver',
	'Progress.php -> Proxmox\DiskDriver',
	'Progress.php -> Proxmox\SshKeyField',
	'Settings.php -> Mcp\Access',
);
