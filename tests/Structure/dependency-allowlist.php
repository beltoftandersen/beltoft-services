<?php
/**
 * Today's forbidden dependencies (see DependencyDirectionTest). Remove entries as leaks are fixed; never add.
 * 2026-11 widening (FEATURES += Hosting, Ssl, Licences, Credit, Card, Admin, Cli): no new violations, so nothing was added.
 * 2026-11-01: 11 entries (FirewallRoute, ThankYou) were already there but hidden by a namespace parsing bug, now fixed.
 * 2026-11-10: the core source set widened to Drivers, Billing, Checkout and Emails; the four imports below are known and deferred
 * (final review M5; Drivers\LiveGate no longer names a feature, its site tag is passed in).
 */
return array(
	'Billing/PaymentMethods.php -> Account\Cards', // Known and deferred, 2026-11-10.
	'Billing/Subscriptions.php -> Usage\Rates', // Known and deferred, 2026-11-10.
	'Emails/ServiceEmail.php -> Account\Licences', // Known and deferred, 2026-11-10 (the literal 'licence-key').
	'Emails/ServiceEmail.php -> Account\ServiceArea', // Known and deferred, 2026-11-10.
	'LineNames.php -> Proxmox\Disk\DiskDriver',
	'LineNames.php -> Proxmox\Vm\ServerName',
	'LineNames.php -> Usage\Account',
	'LineNames.php -> Usage\Rates',
);
