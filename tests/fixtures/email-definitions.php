<?php
// Word-for-word snapshot of every email's title, description, default subject and heading, and template paths.
return array (
  'beltoft_services_ready' => 
  array (
    'title' => 'Service ready',
    'description' => 'Sent to the customer when their service is ready to use.',
    'subject' => 'Your {product_name} is ready',
    'heading' => 'Your service is ready',
    'html' => 'emails/beltoft-services-ready.php',
    'plain' => 'emails/plain/beltoft-services-ready.php',
  ),
  'beltoft_services_paused' => 
  array (
    'title' => 'Service paused',
    'description' => 'Sent to the customer when their service is paused.',
    'subject' => 'Your {product_name} is paused',
    'heading' => 'Your service is paused',
    'html' => 'emails/beltoft-services-paused.php',
    'plain' => 'emails/plain/beltoft-services-paused.php',
  ),
  'beltoft_services_deletion_warning' => 
  array (
    'title' => 'Service deletion warning',
    'description' => 'Sent to the customer before a paused service is deleted after its retention period.',
    'subject' => 'Your {product_name} will be deleted on {deletion_date}',
    'heading' => 'Your service will be deleted',
    'html' => 'emails/beltoft-services-deletion-warning.php',
    'plain' => 'emails/plain/beltoft-services-deletion-warning.php',
  ),
  'beltoft_services_deleted' => 
  array (
    'title' => 'Service deleted',
    'description' => 'Sent to the customer when their service has been deleted.',
    'subject' => 'Your {product_name} has been deleted',
    'heading' => 'Your service has been deleted',
    'html' => 'emails/beltoft-services-deleted.php',
    'plain' => 'emails/plain/beltoft-services-deleted.php',
  ),
  'beltoft_services_limit_exceeded' => 
  array (
    'title' => 'Service limit exceeded',
    'description' => 'Sent to the customer when the daily usage check finds their service over its limit.',
    'subject' => 'Your {product_name} is over its limit',
    'heading' => 'Your service is over its limit',
    'html' => 'emails/beltoft-services-limit-exceeded.php',
    'plain' => 'emails/plain/beltoft-services-limit-exceeded.php',
  ),
  'beltoft_services_provision_delay' => 
  array (
    'title' => 'Service setup delayed',
    'description' => 'Sent once to the customer when setting up their server, domain, DNS hosting or website hosting failed and needs attention.',
    'subject' => 'A delay setting up your {product_name}',
    'heading' => 'There is a delay setting up your service',
    'html' => 'emails/beltoft-services-provision-delay.php',
    'plain' => 'emails/plain/beltoft-services-provision-delay.php',
  ),
  'beltoft_services_domain_action_needed' => 
  array (
    'title' => 'Domain action needed',
    'description' => 'Sent when a registration or transfer needs a customer approval.',
    'subject' => 'Action needed for your domain',
    'heading' => 'Your domain needs a confirmation',
    'html' => 'emails/beltoft-services-domain-action-needed.php',
    'plain' => 'emails/plain/beltoft-services-domain-action-needed.php',
  ),
  'beltoft_services_domain_transfer_fix' => 
  array (
    'title' => 'Domain transfer needs a fix',
    'description' => 'Sent once to the customer when a transfer waits for a new transfer code or for the domain to be unlocked.',
    'subject' => 'Your transfer of {domain_name} needs your action',
    'heading' => 'Your transfer needs your action',
    'html' => 'emails/beltoft-services-domain-transfer-fix.php',
    'plain' => 'emails/plain/beltoft-services-domain-transfer-fix.php',
  ),
  'beltoft_services_monitor_alert' => 
  array (
    'title' => 'Server monitoring alert',
    'description' => 'Sent when a server is down unexpectedly, its disk is nearly full or its CPU stays high, or when a database does not answer or its disk is nearly full.',
    'subject' => 'Check your server',
    'heading' => 'Your server needs attention',
    'html' => 'emails/beltoft-services-monitor-alert.php',
    'plain' => 'emails/plain/beltoft-services-monitor-alert.php',
  ),
  'beltoft_services_domain_transferred' => 
  array (
    'title' => 'Domain transferred',
    'description' => 'Sent to the customer once when a domain transfer has completed.',
    'subject' => 'Your domain {domain_name} is now with us',
    'heading' => 'Your domain is now with us',
    'html' => 'emails/beltoft-services-domain-transferred.php',
    'plain' => 'emails/plain/beltoft-services-domain-transferred.php',
  ),
  'beltoft_services_domain_registered' => 
  array (
    'title' => 'Domain registered',
    'description' => 'Sent to the customer once when a new domain registration has completed.',
    'subject' => 'Your domain {domain_name} is registered',
    'heading' => 'Your domain is registered',
    'html' => 'emails/beltoft-services-domain-registered.php',
    'plain' => 'emails/plain/beltoft-services-domain-registered.php',
  ),
  'beltoft_services_domain_security' => 
  array (
    'title' => 'Domain security notice',
    'description' => 'Sent to the customer when a domain is unlocked, its transfer code is shown, its name servers change or it is locked again.',
    'subject' => 'Security notice for your domain {domain_name}',
    'heading' => 'A change was made to your domain',
    'html' => 'emails/beltoft-services-domain-security.php',
    'plain' => 'emails/plain/beltoft-services-domain-security.php',
  ),
  'beltoft_services_snapshot_notice' => 
  array (
    'title' => 'Snapshot notice',
    'description' => 'Sent when snapshots of a server are deleted with a removed disk, are shortened because paid snapshot slots ended, or (when the setting is on) have expired.',
    'subject' => 'About your server snapshots',
    'heading' => 'Your snapshots changed',
    'html' => 'emails/beltoft-services-snapshot-notice.php',
    'plain' => 'emails/plain/beltoft-services-snapshot-notice.php',
  ),
  'beltoft_services_quarantine' => 
  array (
    'title' => 'Outgoing traffic blocked',
    'description' => 'Sent when the outgoing network traffic of a server is blocked because it scanned other servers or sent a flood of connections.',
    'subject' => 'Outgoing traffic from your server is blocked',
    'heading' => 'Outgoing traffic is blocked',
    'html' => 'emails/beltoft-services-quarantine.php',
    'plain' => 'emails/plain/beltoft-services-quarantine.php',
  ),
  'beltoft_services_quarantine_lifted' => 
  array (
    'title' => 'Outgoing traffic open again',
    'description' => 'Sent when the block on the outgoing network traffic of a server is lifted.',
    'subject' => 'Outgoing traffic from your server is open again',
    'heading' => 'Outgoing traffic is open again',
    'html' => 'emails/beltoft-services-quarantine-lifted.php',
    'plain' => 'emails/plain/beltoft-services-quarantine-lifted.php',
  ),
  'beltoft_services_app_domain_live' => 
  array (
    'title' => 'App live on your own domain',
    'description' => 'Sent when an app on a server is installed and reachable on a domain the customer registered elsewhere.',
    'subject' => 'Your app is live on your domain',
    'heading' => 'Your app is live',
    'html' => 'emails/beltoft-services-app-domain-live.php',
    'plain' => 'emails/plain/beltoft-services-app-domain-live.php',
  ),
  'beltoft_services_notice' => 
  array (
    'title' => 'Service notice',
    'description' => 'Sent when the access list of a database changes, when a connected app showed a password, or when a restore of a server from a backup or a rollback to a snapshot did not finish.',
    'subject' => 'The access list of your database changed',
    'heading' => 'Database access list changed',
    'html' => 'emails/beltoft-services-notice.php',
    'plain' => 'emails/plain/beltoft-services-notice.php',
  ),
  'beltoft_services_mail_ready' => 
  array (
    'title' => 'Email domain ready',
    'description' => 'Sent when a Business email domain is set up. It lists the DNS records the customer must add when the domain is hosted elsewhere. It never holds a password.',
    'subject' => 'Your email domain {domain_name} is ready',
    'heading' => 'Your email domain is ready',
    'html' => 'emails/beltoft-services-mail-ready.php',
    'plain' => 'emails/plain/beltoft-services-mail-ready.php',
  ),
  'beltoft_services_mail_dns_reminder' => 
  array (
    'title' => 'Email DNS records missing',
    'description' => 'Sent once, a day after an email domain hosted elsewhere was set up, when DNS records are still missing.',
    'subject' => 'DNS records are still missing for {domain_name}',
    'heading' => 'DNS records are still missing',
    'html' => 'emails/beltoft-services-mail-dns-reminder.php',
    'plain' => 'emails/plain/beltoft-services-mail-dns-reminder.php',
  ),
  'beltoft_services_hosting_ready' => 
  array (
    'title' => 'Hosting ready',
    'description' => 'Sent when a WordPress hosting account is set up. It has the address of the site, the WordPress admin link, the SFTP details and the DNS records an external domain needs. It never holds a password.',
    'subject' => 'Your hosting {product_name} is ready',
    'heading' => 'Your hosting is ready',
    'html' => 'emails/beltoft-services-hosting-ready.php',
    'plain' => 'emails/plain/beltoft-services-hosting-ready.php',
  ),
  'beltoft_services_hosting_notice' => 
  array (
    'title' => 'Hosting notice',
    'description' => 'Sent when a hosting account is close to or over its storage, and before and when a PHP version loses security support.',
    'subject' => 'About your hosting {product_name}',
    'heading' => 'About your hosting',
    'html' => 'emails/beltoft-services-hosting-notice.php',
    'plain' => 'emails/plain/beltoft-services-hosting-notice.php',
  ),
  'beltoft_services_hosting_restore_done' => 
  array (
    'title' => 'Hosting restore finished',
    'description' => 'Sent when a restore or an undo of a hosting account has finished or has failed. It says what was restored and until when it can be undone.',
    'subject' => 'Your hosting restore has finished',
    'heading' => 'Your restore has finished',
    'html' => 'emails/beltoft-services-hosting-restore-done.php',
    'plain' => 'emails/plain/beltoft-services-hosting-restore-done.php',
  ),
  'beltoft_services_hosting_malware_found' =>
  array (
    'title' => 'Hosting malware found',
    'description' => 'Sent when the nightly scan of a hosting account finds files that match known malware. It names the websites and the files and says what to do. Nothing is removed or changed, and the websites stay online.',
    'subject' => 'Malware found on your hosting {product_name}',
    'heading' => 'Malware found on your website',
    'html' => 'emails/beltoft-services-hosting-malware-found.php',
    'plain' => 'emails/plain/beltoft-services-hosting-malware-found.php',
  ),
  'beltoft_services_renewal_reminder' => 
  array (
    'title' => 'Renewal reminder',
    'description' => 'Sent a product-set number of days before a long renewal (for example 30 days before a yearly hosting renewal): the plan, the date, the full price and how to cancel.',
    'subject' => 'Your {product_name} renews on {renewal_date}',
    'heading' => 'Your {product_name} renews on {renewal_date}',
    'html' => 'emails/beltoft-services-renewal-reminder.php',
    'plain' => 'emails/plain/beltoft-services-renewal-reminder.php',
  ),
  'beltoft_services_ssl_issued' => 
  array (
    'title' => 'SSL certificate issued',
    'description' => 'Sent when a certificate is issued. It links to the page where the certificate and the private key are downloaded. It never holds a key.',
    'subject' => 'Your SSL certificate for {domain_name} is ready',
    'heading' => 'Your SSL certificate is ready',
    'html' => 'emails/beltoft-services-ssl-issued.php',
    'plain' => 'emails/plain/beltoft-services-ssl-issued.php',
  ),
  'beltoft_services_ssl_action_needed' => 
  array (
    'title' => 'SSL validation needed',
    'description' => 'Sent when the customer has to do something to validate a domain: add a DNS record, put a file on the website or approve an email.',
    'subject' => 'Action needed to validate {domain_name}',
    'heading' => 'Your certificate needs a validation step',
    'html' => 'emails/beltoft-services-ssl-action-needed.php',
    'plain' => 'emails/plain/beltoft-services-ssl-action-needed.php',
  ),
  'beltoft_services_ssl_reissued' => 
  array (
    'title' => 'SSL certificate reissued',
    'description' => 'Sent when a new certificate for an existing order is ready to install: reissued by the authority or at the customer request.',
    'subject' => 'A new certificate for {domain_name} is ready to install',
    'heading' => 'A new certificate is ready',
    'html' => 'emails/beltoft-services-ssl-reissued.php',
    'plain' => 'emails/plain/beltoft-services-ssl-reissued.php',
  ),
  'beltoft_services_ssl_expiring' => 
  array (
    'title' => 'SSL certificate ending',
    'description' => 'Sent when the current certificate ends within days and no replacement has been issued yet.',
    'subject' => 'Your certificate for {domain_name} ends soon',
    'heading' => 'Your certificate ends soon',
    'html' => 'emails/beltoft-services-ssl-expiring.php',
    'plain' => 'emails/plain/beltoft-services-ssl-expiring.php',
  ),
);
