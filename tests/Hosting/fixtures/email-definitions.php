<?php
// The hosting emails: word-for-word snapshot of title, description, default subject and heading, and template paths.
return array (
  'beltoft_services_hosting_ready' => 
  array (
    'title' => 'Hosting ready',
    'description' => 'Sent when a hosting account is set up. It has the address of the site, the WordPress admin link (for a website with WordPress), the SFTP details and the DNS records an external domain needs. It never holds a password.',
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
);
