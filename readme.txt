=== Beltoft Services for WooCommerce ===
Contributors: beltoft
Tags: services, provisioning, woocommerce, subscriptions, license
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 0.25.5
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Sell services with WooCommerce Subscriptions. A license is the entitlement, and a driver provisions, pauses, resumes and removes the service.

== Description ==

Built on Beltoft Licenses. The `Requires Plugins` header depends on the plugin folder names `woocommerce`, `woocommerce-subscriptions` and `beltoft-licenses`; a copy installed under another folder name is not recognised by WordPress as the dependency. It needs WooCommerce, WooCommerce Subscriptions and Beltoft Licenses 0.8.5 or newer; if one is missing it shows a notice and does nothing else.

A service product is a licensed product with a service driver. When its license becomes active, the driver creates the service. When the license is suspended, revoked or expires, the service is paused, and when the license is active again, it is resumed. A plan change (a subscription switch or a new site limit) updates the service's plan. Billing, renewals, retries and their emails stay with WooCommerce Subscriptions: this plugin only follows the license.

* **Driven by the license.** It listens only to Beltoft Licenses' actions (`beltoft_licenses_license_created`, `_status_changed`, `_license_updated` and `_expiry_changed`). While a Beltoft Licenses import is running, it does nothing.
* **All work in background jobs.** Every driver call runs in an Action Scheduler job (group `beltoft-services`), never during checkout or a page load. A database lock per service makes sure two job runners never act on the same service at once. A failed call is retried after 1, 5 and 15 minutes, 1 hour and 4 hours; then the service is marked failed and the admin gets an email.
* **An HTTP API driver** that calls your own service with signed requests. Each connection (Settings → Services → Connections) has a base URL, HMAC or static header authentication, an endpoint (method and path) for each operation, a JSON body template, the response field that holds the service's id, optional usage fields, and an optional "Open" link with a short-lived sign-in token. Other plugins can add drivers with the `beltoft_services_drivers` filter.
* **A Proxmox VE driver (0.2.0)** that sells virtual machines: a VM is cloned from a template, given an address from your IP pool and a login, and started. See the FAQ, starting at "What does the Proxmox driver do?". Version 0.3.0 adds daily backups with a customer restore ("How do backups work?").
* **Domain and DNS hosting drivers (0.4.0):** domain search, registration and transfer, customer DNS records and name servers, DNSSEC, paid registry renewals, and DNS hosting for a domain registered elsewhere. From 0.7.0 the customer proves control of that domain with a TXT record, and can import the current records. Fake mode is the default. Live registrar and DNS access require `BELTOFT_SERVICES_DOMAINS_LIVE` in `wp-config.php`.
* **VPS monitoring (0.5.0):** included usage graphs in My Account, cached root filesystem usage when the guest agent answers, and optional customer alerts for unexpected downtime and a nearly full disk. Customer emails are off by default.
* **Extra disks (0.6.0):** a separate variable subscription, license and resource per disk, offered with a new server at classic checkout or from My Account. A disk can grow but cannot shrink, and is excluded from daily backups.
* **A Service tab on licensed products:** the driver, the connection, the driver's plan fields (for HTTP, a limit and plan attributes; variations can set their own), a retention period, and whether a free trial needs a payment method. A free trial of a service is limited to one per customer (matched by account and billing email), in the classic and the block checkout. Renewals, switches and resubscribes are not affected.
* **My Account:** each service license shows its service's state and usage, the actions the driver offers, and the Open button. Customers only see and act on their own services.
* **Admin:** WooCommerce → Services lists every service with its state. Each license screen gets a Service panel with retry, pause, resume, delete (with a typed confirmation), protect, and "Mark deleted" for a service that was removed by hand.
* **Emails** (WooCommerce → Settings → Emails): service ready, paused, deletion warning, deleted, limit exceeded, and optional server monitoring alerts. Customers are not told about a service they never heard of.
* **Deletion after a retention period** is off by default. It can also report only, or delete.
* **Kill switch:** "Pause all driver activity" stops every driver call at once.
* **WP-CLI:** `wp beltoft-services link-http <license> --remote-id=<id> [--dry-run]` links a license to a service that already exists on the remote side (for a migration), `link-vm` adopts a Proxmox VM, and `link-domain` adopts a domain registration or existing DNS zone. `domain-prices`, `domain-balance` and `dns-check` manage domain pricing and health. `ip-pool-add`, `ip-pool-block`, `ip-pool-release` and `ip-pool-list` manage the address pool, and `status <license>` shows a service. `run-due` runs the plugin's due jobs every few seconds (see Faster steps below).
  `link-disk <disk-license> <server-license> <scsiN> --dry-run` previews adoption of a disk already attached to a managed VM; actual adoption records it as protected and makes no VM change.

Connection secrets are encrypted with a key from `wp-config.php`, and they are never shown, logged or emailed.

= Hooks =

* Actions: `beltoft_services_state_changed( $resource, $old_state, $new_state )` after each state change; `beltoft_services_deletion_warning( $resource, $deletes_at )` when a customer is warned before a deletion; `beltoft_services_resource_locked( $license_id )` when a service is busy in another request; `beltoft_services_settings_tab_<tab>` renders a settings tab added by a plugin.
* Filters: `beltoft_services_drivers` (add a driver), `beltoft_services_connection` (supply or change an HTTP connection), `beltoft_services_http_unit_label` (the usage line, for example "%1$d of %2$d dashboards"), `beltoft_services_key` (the secrets key), `beltoft_services_settings_tabs`, `_settings_sections` and `_settings_fields`, `beltoft_services_account_css` (turn off the account stylesheet), `beltoft_services_rest_rate_limit`, `beltoft_services_usage_refresh_interval`, `beltoft_services_lock_timeout`, `beltoft_services_desire_lock_wait`, `beltoft_services_dependencies` and `beltoft_services_database_server_info`.

= Developer API =

* `BeltoftServices\RateLimit::exceeded( int $user_id, string $key, int $limit, int $window = MINUTE_IN_SECONDS ): bool` counts one call for a user and key in a fixed window and returns true when it is over the limit. A limit of 0 or less turns it off. Counters use the persistent object cache when there is one, otherwise transients. Best-effort without a persistent object cache (transient path is read-then-write); the window is fixed, not sliding; namespace `$key` with your plugin slug, e.g. `odoowoo-portal:verify`.

= Credits =

* This product includes GeoLite2 data created by MaxMind, available from https://www.maxmind.com

== Installation ==

1. Install and activate WooCommerce, WooCommerce Subscriptions and Beltoft Licenses 0.8.5 or newer, then this plugin.
2. Add the secrets key to `wp-config.php` (see the FAQ).
3. Go to Settings → Services, check the alert address, and add a connection under Connections.
4. On a licensed product, open the Service tab, choose the driver and the connection, and fill in the plan.
5. In Beltoft Licenses' settings, turn on "Check expiry every hour".
6. For virtual machines, follow "How do I set up Proxmox?" in the FAQ.

== Frequently Asked Questions ==

= More than one currency =

For a site builder. The plugin never converts a price. It reads prices from WooCommerce and amounts from its own settings, in the currency of the customer, and it asks the site (through filters) for everything that differs per currency. A plugin that gives WooCommerce a second currency connects to these hooks in a small site file.

* `beltoft_services_currency_runner`: returns a callable `function ( string $code, callable $fn )` that runs `$fn` with that currency in force and puts the old one back in a `finally`. Without it only the shop's own currency can be worked in, and a call in another currency (a meter run, an API call, a reminder mail) is refused or skipped with an admin alert.
* `beltoft_services_user_currency( $code, $user_id )`: the currency a customer is in. Run at priority 5 by your connection; the plugin adds the currency of the customer's gift cards at 11 and their usage account at 20.
* `beltoft_services_amount_setting( $value, $key, $currency )`: the amount of a setting in a currency (`usage_minimum`, `usage_threshold`, `usage_threshold_new`, `domain_price_overrides`). `$value` is null for a currency other than the shop's, and null back means "no amount": no early bill, no minimum, the domain extension is not sold.
* `beltoft_services_guard_rate( $rate, $code )`, or the setting Guard rates (lines such as `DKK=7.60`, a little above the real rate): how many units of the currency are worth one euro. Used only to check that an order covers what a supplier is paid in euro. No rate: the order is refused and the admin is mailed once.
* `beltoft_services_promo_credit_line( $allowed, $item, $order )`: whether free gift cards (promotional credit) may pay this product line of an order (default: the product's box "Promotional credit can pay orders and renewals").
* `beltoft_services_currencies( $codes )`: the currencies of the shop.
* `beltoft_services_price_html( $html, $amount, $currency, $context, $render )`: every catalogue price printed as markup passes it; `$render( 'incl' | 'excl' | '' )` renders the same price in a tax display, so a site can show both figures.
* `beltoft_services_app_text( $text, $app_id, $field )`: the summary and getting-started text of a one-click app, after translation.
* `beltoft_services_checkout_field_heading( $show, $kind )`: a checkout with its own step heading leaves the fields' heading out.
* Action `beltoft_services_usage_account_created( $user_id, $account )`: a usage account was made with no order behind it; fix the customer's currency here.
* The cart item key `beltoft_services_price_currency` (`Currency::CART_KEY`) holds the currency a runtime price (a domain line) was worked out in. A site that approves runtime prices in another currency must approve a line only when this key equals the cart's currency.

What the plugin never does: convert an amount for a customer, show a price in a currency other than the customer's, or put one customer's money in two currencies (the gift cards of a customer are in one currency, and usage rows in another currency are never billed on an order of the first).

What must be set for each currency: the price of every product and variation (also 0 for a pay as you go variation), the pay as you go monthly prices the rates come from, the minimum, the early billing thresholds, the top-up limits, a domain price line per extension, and a guard rate. A currency with no value for one of them simply does not get that feature.

The order to switch a currency on: 1. add the prices and amounts above, 2. add the guard rate, 3. connect the hooks in the site file, 4. try an order, a pay as you go server, a top-up and a domain on a staging site in the new currency, 5. only then show the currency to visitors.

= How do I set the secrets key? =

Add a random key of 32 bytes, base64 encoded, to `wp-config.php` above the line "That's all, stop editing":

`define( 'BELTOFT_SERVICES_KEY', '...' );`

You can make one with `php -r "echo base64_encode( random_bytes( 32 ) ), PHP_EOL;"`. While no key is set, Settings → Services shows a notice with an example line, and connection secrets cannot be saved. Secrets are encrypted with this key (libsodium). Keep the key: if it changes, every connection secret must be entered again.

= How are the HTTP requests signed? =

With HMAC authentication, every request carries three headers. Their names can be changed for each connection.

* `X-Beltoft-Timestamp`: the time, in unix seconds.
* `X-Beltoft-Tenant`: the service's identifier on this site (a UUID). It is also the `{resource_id}` placeholder.
* `X-Beltoft-Signature`: the hex HMAC-SHA256 of the canonical string, keyed with the connection secret.

The canonical string is five lines joined with a newline (`\n`), with no newline at the end:

`METHOD\npath?query\ntimestamp\ntenant\nsha256_hex(body)`

* METHOD is in upper case, for example `POST`.
* path?query is the request path, plus `?` and the query only when there is one, for example `/api/tenants/42?force=1`.
* timestamp and tenant are exactly as sent in the headers.
* sha256_hex(body) is the lowercase hex SHA-256 of the request body exactly as sent. For a request without a body, it is the SHA-256 of the empty string.

Your service should compute the signature again, compare it in constant time, and refuse timestamps more than a few minutes away from its own clock.

With static header authentication, the secret is sent as it is in the header you name, together with the tenant header.

Requests send and accept JSON (`Content-Type: application/json`, `Accept: application/json`). Redirects are not followed.

= What answers does the service have to give? =

* **2xx:** done. A provision answer must be a JSON object with the service's id in the connection's "remote id field" (`id` by default; a dotted path such as `data.id` works). Without the id, the call is retried and then fails: a service never counts as ready without its id.
* **409 on provision**, with the id in the body: done (the service exists already). Without an id, it fails, and the provision stays open: the service may hold the tenant, so pausing or deleting that resource later needs an admin (it fails and the admin is alerted) and never counts as done.
* **404 on delete:** done (the service is gone already).
* **408, 429 and 5xx**, and network errors: retried later. A `Retry-After` header (seconds or an HTTP date) is honoured, but the wait is never shorter than the current backoff step (1, 5 and 15 minutes, 1 hour, 4 hours) and never longer than 4 hours.
* **Any other 4xx:** final. The service is marked failed and the admin gets an email.

Provision, pause, update plan and delete need an endpoint. A missing resume endpoint counts as done.

= What is the Idempotency-Key header? =

Every request carries `Idempotency-Key: <tenant>:<operation>:<sequence>`. A retry of the same request sends the same key, also when an admin retries after the automatic retries ran out. A service that did the work already should give the same answer again instead of doing the work twice. The sequence goes up when the operation runs afresh, or when a retry has a different body (a newer plan). A provision keeps its key until it gets a final answer, so a tenant is never created twice.

= What does the Proxmox driver do? =

The Proxmox driver clones a template into a new VM for each licensed unit, sets its cores, memory, boot disk, network, login and cloud-init data, starts it, and reports it ready with the address and a one-time password reveal. Pausing is a clean ACPI shutdown (a hard stop only if the VM is still running after the shutdown timeout), resuming starts it, a plan change resizes it, and deleting removes it. Customers can start, shut down, reboot, reset the password, replace the SSH key and reinstall from My Account.

**Proxmox is a production system. Nothing in this plugin talks to a real Proxmox until you ask for it** (see "How does a cluster go live?"). Test clusters use a built-in fake that models the API, so you can set everything up and try it without a Proxmox.

= How do I set up Proxmox? =

1. **A dedicated pool and a privilege-separated token.** Give this plugin its own pool and an API token that can act only on that pool, the templates, the storage and the bridge. With `pveum` (names from the PVE 9.2 documentation; check the privilege names against your own version, and the "pveum" chapter of the Proxmox VE Administration Guide):

`pveum pool add beltoft-customers`
`pveum role add BeltoftServices --privs "VM.Allocate VM.Clone VM.Audit VM.PowerMgmt VM.Config.CPU VM.Config.Memory VM.Config.Disk VM.Config.Network VM.Config.Cloudinit VM.Config.Options VM.Config.HWType VM.Config.CDROM VM.GuestAgent.Audit VM.GuestAgent.FileWrite VM.GuestAgent.Unrestricted Pool.Allocate Pool.Audit Sys.Audit VM.Backup"`
`pveum user add svc@pve`
`pveum user token add svc@pve beltoft --privsep 1`

A privilege-separated token only gets what is granted to the token itself, and never more than its user has, so grant the same role to both, on the same paths:

* `/pool/beltoft-customers`: role `BeltoftServices`, propagated. New VMs are created inside this pool, so every call on them is covered here.
* `/vms/<template id>` for each template: a role with `VM.Clone` and `VM.Audit` (the role above has both).
* `/storage/<disk storage>` and `/storage/<cloud-init storage>`: `PVEDatastoreUser` (`Datastore.AllocateSpace`, `Datastore.Audit`).
* `/storage/<backup storage>`: `PVEDatastoreUser`, only when you sell backups (the role above has `VM.Backup`, which vzdump needs on the VM).
* `/sdn/zones/localnetwork` (or the zone of your bridge): `PVESDNUser` (`SDN.Use`) on PVE 8 and newer.
* `/nodes/<node>`: `Sys.Audit`, to read the node list for capacity and the status of tasks.

Setting the pool in the cluster form is what puts the new VMs into it. Never use the root account or a token that can reach other virtual machines: the plugin only ever changes machines that carry its own ownership tag, but a narrow token is the second safeguard.

2. **The site tag.** Settings → Services → Proxmox: a short name for this site. It is written into the description of every VM the site creates (`beltoft-services:<site tag>:<service id>`). A VM is only ever stopped, resized, changed or deleted when its description has this exact line, its VMID is in the customer range and the VMID is recorded for that service. A cluster cannot go live until the tag is set explicitly, and it cannot be changed while VM records exist (the settings screen refuses, with an explanation): every existing VM would stop matching it.

3. **The cluster.** Settings → Services → Proxmox clusters: API URL (`https://pve.example.com:8006`), token id (`USER@REALM!TOKENID`) and secret, node names, disk storage and cloud-init storage, bridge and optional VLAN, the pool, the boot disk key of the templates (`scsi0` by default), the templates (one `VMID = label` per line, and a default), the customer VMID range, the nameservers, the network prefix and the gateway. A template must not sit inside the customer VMID range. The pool form (`pools` or the older `pools_id`) depends on your PVE version.

4. **The IP pool.** Settings → Services → IP pool, or `wp beltoft-services ip-pool-add 192.0.2.160/29`. Only IPv4 is supported in 0.2.0: a cluster that needs IPv6 cannot be used yet.

5. **A product.** On a licensed product, the Service tab: driver "Proxmox virtual machine", the cluster, the template (the fallback when no choice was made), cores, memory and disk. A product is out of stock when you set its WooCommerce stock status to out of stock, and while the pool has no free address (the plugin writes that one). Room on a node never closes a product.

= How does a cluster go live? =

A cluster is created as "fake". To let the plugin talk to a real Proxmox, two things are needed, and both are in your hands:

1. `define( 'BELTOFT_SERVICES_PROXMOX_LIVE', true );` in `wp-config.php`.
2. The cluster's client set to "live" (only possible once the constant is true and the site tag is set).

There is no filter, option or setting that can switch it on without the constant. Without the constant, a cluster stored as live behaves as a fake one. With the constant but without a site tag, a live cluster is left alone: provisioning and every action wait, the admin gets one alert a day and a notice, and nothing is faked. Do not put the constant on a staging site that shares a database or credentials with production.

= Which database versions are supported? =

MySQL 5.7.5 or newer, or MariaDB 10.0.2 or newer. WordPress itself still runs on MySQL 5.5.5, but the plugin takes one named lock while it holds another (to give out VM ids and addresses without two orders getting the same one), and older servers release the first lock as soon as the second is taken.

= How is the Proxmox TLS certificate checked? =

Certificates are verified. A cluster with a self-signed or private-CA certificate needs one of:

* **The CA file:** an absolute path to the PEM file of the CA (or the certificate itself), readable by the web server.
* **A SHA-256 pin of the public key:** the base64 hash, made with `openssl x509 -in pve-ssl.pem -pubkey -noout | openssl pkey -pubin -outform der | openssl dgst -sha256 -binary | openssl enc -base64`. A pin needs PHP curl with pinned public key support; a request is refused, and its answer discarded, when the pin cannot be applied. With a pin and no CA file, the pin replaces the certificate chain and host name check: the connection is accepted only if the server's public key matches the pin (this is what lets a self-signed certificate work). With a CA file as well, the full chain and host name verification stays on and the pin is added to it.

With a CA file, the certificate must name the host used in the API URL (as its common name or a subject alternative name): connect with the host name the certificate carries, not an IP address it does not list. With a pin alone, the host name is not checked, only the key.

= How is the operating system chosen? =

A cluster lists its templates (the VMID is the choice, the label is what the customer sees) and marks one as the default. At checkout, a required "Operating system" select offers the templates of the cluster; the default is preselected in the classic checkout and listed first in the block checkout. The customer chooses again when they reinstall, because a reinstall wipes the VM. When a cart holds products of several clusters, only a template that every one of them lists is accepted. The product's own template is the fallback for an order made before the field existed, or a license issued by an admin.

= How is the network configured? =

The address is set with cloud-init as `ip=<address>/<prefix>,gw=<gateway>`, from the cluster's prefix and gateway. For a routed setup, where each customer address is a host route behind the Proxmox host, use prefix 32 and the gateway of the host: it does not have to be inside the pool's network (for example a pool of `192.0.2.160/29` with the gateway `198.51.100.1`). A gateway stored on a pool row must lie inside that row's address and the cluster prefix. An outgoing SMTP block (port 25) is added to the VM's firewall unless it is switched off.

= How is capacity decided? =

Capacity never stops a sale. Memory is counted as promised, not as used: the memory of the VMs of this site on a node (the configured memory of each, including a VM that was recorded but is not listed yet) plus the "memory kept free on each node" setting (default 4 GB) is compared with the node's memory, and that decides where a new server goes: the online node with the most room, or, when none has room, the one with the most room left. VMs this site did not create are not counted, only the reserve covers them. A product is sold out only when you set its WooCommerce stock status to out of stock, or while no public address is free, or while its plan is wrongly set up (it names a cluster that is not saved, or an extra disk product has no size); the plugin writes those two and reopens them when the cause is gone, and a product you closed by hand, also by saving it as out of stock in the product form, is never reopened. A renewal, switch or resubscribe of a subscription the logged-in customer owns and that holds the product is never blocked by a stored out of stock (a resubscribe still waits when the pool has no address, because it builds a new server). Three jobs do wait for real room, because they cannot be done without it: making an extra disk, making an extra disk or a database data disk larger (they wait while the storage's real free space is under the size plus the other waiting disks plus the "Storage kept free on each node (GB)" reserve, or while there is no storage reading younger than two hours), and a server clone while the capacity cache is missing. The admin gets one mail the first time such a job waits, naming the storage, the size asked and the free space; the job tries again every hour. A pay as you go disk is billed by the size it really has until its grow has finished. The numbers are read hourly and when a cluster is saved, never during a page load. When a node passes its budget the admin is mailed at 100 percent and at each further 10 percent (once per step), and each night the real free memory and disk of a node are compared with what the plugin counts as committed, with a mail when the real free share is under the settings "Alert when real free memory is under" and "Alert when real free disk is under" (default 10 percent).

= What about deletion, abuse, breach and disputes? =

* **Deletion** (Settings → Services, General) is off by default. "Report only" writes what would be deleted to the license history. "On" deletes a paused VM after its product's retention (default 3 days for a VM product; 0 never) and warns the customer first. A cancelled subscription follows the same retention as an expired one. A deletion needs the VM to carry our tag, a VMID in the customer range and a record for that service, and then removes the VM with its disks. A VM that is gone already counts as deleted only when its VMID is free.
* **Abuse** (Settings → Services → Proxmox): "Sustained high CPU" is off, report only (default) or pause. A VM above the CPU percentage for the set number of hours is reported to the admin; in pause mode the service is also asked to pause, and a renewal asks for it again. A VM linked with `link-vm` is never reinstalled, never resized by a plan change (the admin is alerted to do it by hand) and never paused by the abuse check (it is only reported). It is paused like any other VM when its license lapses (shut down, then stopped if it does not stop), and its customer can start, shut down and reboot it.
* **Breach:** "Suspend for breach" on the license screen pauses the VM and holds it: the customer cannot start it, renew it back to life or reinstall it. It is deleted after the breach days (default 14, setting "Delete a service suspended for breach after this many days") when deletion is On, otherwise you get an alert. The license stays marked after the VM is gone, so no renewal creates a new one; use "Create the service" on the license screen and type CLEAR BREACH to override. Billing is cancelled by you.
* **Disputes and chargebacks are manual.** WooCommerce, WooCommerce Subscriptions and their bundled gateways fire no dispute action. Put the subscription on hold or cancel it, or suspend the license for breach.

= How do passwords and SSH keys work? =

A customer can add an SSH public key at checkout (RSA, ECDSA or ed25519), otherwise a password is generated and shown once, on the account page, to the license owner. It is encrypted at rest, never emailed, and a reveal is single-shot: if it is lost, the customer resets it (when the VM's guest agent works) or reinstalls. An order of several units shares the one key. Orders from a switch or an upgrade carry no key; the customer adds one later in My Account. Replacing a key writes it into the running server through the guest agent (privilege `VM.GuestAgent.FileWrite` or `VM.GuestAgent.Unrestricted`), so the old key stops working at once; on a server that is off the new key applies at its next start.

= How do backups work? =

Backups are sold as a second attribute on the server product ("Backups": None or Daily), so every plan has a sibling variation whose plan field "Backup days" is above zero. Buying, adding and removing backups are ordinary WooCommerce Subscriptions variation switches: nothing about billing is built here. At checkout a "Daily backups" box (classic checkout) swaps the cart's server for the sibling variation; in My Account → the server, the panel offers "Add daily backups" or "Remove backups" as a switch link to the checkout. A server on a cluster without a backup storage never offers them.

The plugin runs the backups itself. Set a **backup storage** per cluster (Settings → Services → Proxmox clusters), the id of a Proxmox Backup Server storage; empty turns backups off for the cluster. A nightly job (03:30) backs up the servers that are due, one at a time, with `POST /nodes/{node}/vzdump`, then prunes to the plan's days with `DELETE /nodes/{node}/storage/{storage}/prunebackups` (`keep-daily`). It does not use a Proxmox backup job, because editing one needs `Sys.Modify` on `/`. A failed night is recorded and shown in the admin panel; after three nights in a row the admin gets an email. The customer is never emailed, and the server is not affected. Removing backups from a plan deletes the server's backups at the next nightly run, and deleting the server deletes them first.

A customer can list the backups and restore the server's configuration and boot disk to one of them (typing `RESTORE`): the server is shut down, restored over the same VMID, its plan is applied again (cores, memory and a larger boot disk come back if the backup predates an upgrade; a disk is never shrunk) and it is started. Extra disks are excluded from the backup. The password and the SSH key go back to the backup's. A restore is not offered for an adopted (linked) server. A deleted server's VMID can be reused, so the lists, restores and clean-ups never include backups taken before the server's record was created.

The token needs `VM.Backup` (in the role above) and `PVEDatastoreUser` on `/storage/<backup storage>`; nothing else on the backup storage.

= How do I adopt an existing VM? =

`wp beltoft-services link-vm <license-id> --cluster=<id> --node=<node> --vmid=<vmid> --ip=<ip>` links a license to a VM that already exists. It only does bookkeeping: it records the VM as ready and protected, adds the ownership tag to its description, and adds it to the cluster's pool if it is in none. It starts, stops, resizes and reconfigures nothing, and makes two reads and one or two writes. `--dry-run` lists the calls and makes none. A machine on another network, such as an internal one, that is not in the IP pool is adopted with `--outside-pool` (nothing about its network is touched). On a live cluster the command needs `--confirm=LINK:<vmid>`. The VMID must be in the cluster's customer range, so a VM outside it cannot be adopted.

= Other Proxmox notes =

* Shrinking the customer VMID range below VMs that already exist leaves those VMs outside the plugin's reach: they are not touched or deleted. Widen the range again to manage them.
* A plan downgrade changes cores and memory and keeps the disk (a disk is never shrunk); switching to a plan with a smaller disk is refused before payment.
* The Ready email carries the address, the login, the operating system and the size, never the password.

= How do I stop all driver calls? =

Tick "Pause all driver activity" under Settings → Services. No driver is called while it is on. Queued jobs keep their place and run when it is turned off again. Use it while the service is under maintenance, or when something goes wrong.

= When are services deleted? =

Choose under Settings → Services, "Delete paused services after their retention period":

* **Off** (the default): paused services are never deleted.
* **Report only:** the daily job writes in the license history what it would delete, and deletes nothing.
* **On:** a service that has been paused for its product's retention period (Service tab, in days; 0 never deletes) is deleted. The customer is warned the set number of days before, and a deletion never comes before that warning has been given. With "Warn customers this many days before deletion" set to 0, no warning is sent. A protected service, or one whose license is active again, is never deleted.

An admin can also delete a service from the license screen.

= Why turn on "Check expiry every hour"? =

Services follow the license status, and Beltoft Licenses checks for expired licenses once a day by default, so a service can keep running for up to a day after its license ends. Turn on "Check expiry every hour" in Beltoft Licenses' settings. Settings → Services shows a notice while it is off.

= What happens while the plugin is deactivated? =

Nothing is provisioned, paused, resumed or deleted: licenses change without the services following. While it is deactivated, the license keys of service licenses are no longer hidden from the public license API, so a service license key validates like any software license key. Activate the plugin again and run "Check expiry every hour" or the 15-minute sweep to bring services back in line with their licenses.

= How does VPS monitoring work? =

The My Account server panel shows CPU, memory, network and disk activity over the last hour, day or 30 days. Graphs are included with every VPS and use the VM's read-only usage history, cached for one minute. If the guest agent reports the root filesystem, the panel also shows used and total disk space; otherwise it shows the plan's disk size.

In Settings → Services → Proxmox, Customer monitoring emails are off by default. An admin can enable them and choose the disk threshold (90% by default). A server that stays stopped unexpectedly for about 30 minutes, or a root filesystem at or above the threshold, sends one email per problem. A shutdown from My Account does not count as unexpected. The disk alert resets after usage drops five percentage points below the threshold. The existing power job checks every 15 minutes; disk readings run every six hours. The Proxmox role needs `VM.Audit` for usage graphs and `VM.GuestAgent.Audit` for filesystem readings. `wp beltoft-services vm-metrics <license-id> [--range=hour|day|month] [--disk]` prints the latest shaped data; `--disk` is the only option that asks the guest agent.

= Does deleting the plugin delete data? =

The settings, connections, clusters, IP pool, VM records, breach marks, DNS hosting claims and email settings are deleted only if "Delete the settings when the plugin is deleted" is ticked. Uninstalling never deletes a service or a virtual machine on the remote side. The DNS assignments table stays while any name is still active or waiting for release, because a name may still point at our servers. The list of service product slugs and the queued Action Scheduler actions are left in place on purpose.

== Changelog ==

= 0.25.5 =
* For invoices in an accounting system: usage lines carry their hours, the price of one hour of the whole item (no more per GB and hour) and a line "Usage October 2026"; subscription lines carry the period they pay for; domain lines carry the domain among their options (filter beltoft_services_line_options). New core class OrderLanguage.

= 0.25.4 =
* Usage invoice lines name the server by its fixed reference (vm-xxxxxxxx), not the name a customer can change, and are written with the order's currency in force (amounts read "0,0397 kr."). The kept options of an order line carry their attribute key.

= 0.25.3 =
* Each order line keeps the options the customer chose, in the words and language they saw (order item meta _beltoft_services_options), for an accounting system to print on the invoice. A translated "None" for backups is hidden like the English one.

= 0.25.2 =
* Usage invoice lines are named by product, plan and server ("VPS hosting Performance (web1), October 2026"), with the hours and the rate on a second line, in the order's language (new setting usage_order_language_meta); the amount is unchanged, so an accounting system shows the same figures to the cent.

= 0.25.1 =
* Hosting account page: domain names as rows with their state and a Remove button; connecting a domain, the WordPress address, the main address, the Files (SFTP) details, password and SSH keys, backups, the first-visit list and moving a website as rows with their buttons at the right edge; small buttons for row actions. Prices of extra mailbox storage in the customer's tax display.

= 0.25.0 =
* The old account credit ledger code is removed (src/Credit/): credit is gift cards only, and the setting gift_credit stays. The settings Account credit, Account credit pays renewals and orders at checkout, and the top-up amounts are gone (the add-on has its own), and so are the credit codes, the credit coupon type, the admin credit grant and the command wp beltoft-services credit. The table bs_credit stays in the database, unused, until it is dropped by hand.
* The names the site calls stay: BeltoftServices\Credit\CheckoutCredit and Credit\Topup are aliases of the gift card forms.
* Removed hooks: beltoft_services_credit_applies_to_order, beltoft_services_credit_currency_set, beltoft_services_balance_lock_wait.

= 0.24.0 =
* Account credit as gift cards (setting gift_credit, with Beltoft Gift Cards for WooCommerce and its add-on Beltoft Gift Cards Account Credit): paid cards are a payment, free cards a discount before VAT; they pay checkout orders, renewals and usage invoices under today's rules (promotional box, saved card, usage minimum). The credit page shows the cards.
* wp beltoft-services credit move-to-cards moves account credit to gift cards.

= 0.23.10 =
* New alias: the domain's mailboxes to tick, and a field for other addresses where forwarding outside the domain is allowed.
* The mail page says where the sender name is set (webmail identities or the mail program).

= 0.23.9 =
* A button that starts work shows its message once, under the button; the state line at the top is only for work no button started.
* The credit history names what an invoice paid with credit was for and links to it; the coupon wording matches the checkout; the usage box says it counts servers and databases.
* The plan cards of the server and database pickers keep their column width and take the account corners.

= 0.23.8 =
* Hosting sections show facts as label and value rows, and each action sits beside the text that says what it does (cache, PHP, login, files and database, staging, country block).
* The subscription list names what each subscription is for; the overview shows a repeated activity line once; the domain overview says Subscription for its renewal line.

= 0.23.7 =
* Progress steps have round markers; the certificate page has room under its headings.

= 0.23.6 =
* Server tabs: the tab card, forms, tiles and command blocks take the account corners; the firewall start choice is two option cards.

= 0.23.5 =
* Mail page: the new mailbox form is a card with the domain next to the name field; the domains of a plan are rows; account notices take the control corner.

= 0.23.4 =
* Server page: the facts wrap without empty cells, the cards under it and the monitoring charts and range switch take the account corners.

= 0.23.3 =
* An empty list (no domains, databases, licences, email domains) is one card with its line and buttons; hosting sections have room under their row.

= 0.23.2 =
* Delete a server or a database is a closed danger section. The WordPress login section shows the user and its email address as facts and says what the button does.

= 0.23.1 =
* Dashboard UI: the boxes on the overview, the service lists, the server facts and the credit page take the card corner.

= 0.23.0 =
* Dashboard UI, part 2: the hosting page starts with an overview (websites, PHP workers, storage, links to the sections); each website has a row of main actions and sections that open one at a time and show their current value; rounded cards and controls; round state dots.

= 0.22.0 =
* Dashboard UI, part 1: one set of buttons, corners, rows, status labels and usage bars for every account page; every action says what it is doing and what it did right under the button; the mail page lists mailboxes and aliases as rows with status and storage.

= 0.21.0 =
* Hosting: a nightly malware scan. The node agent scans the files of every website, a staging copy included, with Linux Malware Detect (read only: nothing is moved, cleaned or removed, and nothing leaves the node). A daily job (07:15) reads the last result for each hosting account (`site.scan_results`). A file found since the customer was last told is told once: one email to the customer ("Hosting malware found", with the websites, the files and what to do) and one mail to the alert address. A file the node saw before and that did not change is not told again, a file that changed and still matches is. The website stays online. The hosting page shows a Malware scan row under the website ("Malware found on <date>: N files. <list>" until a scan finds nothing, or "Scanned on <date>. Nothing found."). New WP-CLI command `wp beltoft-services hosting scan <resource> [--now]` (`--now` asks the node to scan the account's websites first, operation `site.scan_now`). Needs the node agent with the operations `site.scan_results` and `site.scan_now` and its scan switched on; an older agent is left alone.

= 0.20.3 =
* Staging copy: the page says right under the button that the copy is being made, and once ready says where it is and what Open staging does.

= 0.20.2 =
* Clearer notice when the introductory price does not apply because the customer has had the product before.

= 0.20.1 =
* Mail page: one notice with one link to the DNS records guide while sending is held; each mailbox says "Sending on hold"; the mailbox buttons line up.

= 0.20.0 =
* Hosting: a staging copy for each website, in a new Staging row of the hosting page. "Create staging copy" copies the files and the database of the website to a temporary address of its own (the website's name and `-stg`, in the preview zone) and moves WordPress in the copy to that address. The copy sends no email, is not shown to search engines (nginx header and WordPress setting), runs no scheduled tasks and has no page cache. It does not count as a website. The plan field Staging sets one copy for the account (`one`) or one for each website (`per_site`); no copy is made when more than half of the plan's storage is used. Buttons: "Open staging" (a one-time WordPress sign-in link for the copy), "Push to live", "Refresh from live" and "Delete staging copy". A push is "Files and database" (with a warning that orders, customers and comments made on the live website since the copy was made are replaced) or "Files only" (the database and the media library of the live website stay). Before every push the node makes a safety point of the live website (its files as hard links, and a dump of its database for a push with the database), WordPress goes back to the live address after the push, and "Undo last push" is offered for 7 days. A push that fails is put back by the node. Every step runs in the background and the page shows where it is. New routes under `hosting/sites/<slug>/staging` (GET, POST, DELETE, `refresh`, `push`, `undo`, `login`); needs the node agent with the operations `site.staging_create`, `site.staging_refresh`, `site.staging_push`, `site.staging_undo` and `site.staging_delete`.
* The temporary address of a staging copy is held for it, so no website can take it.

= 0.19.0 =
* Hosting: when a live outside name of a hosting account goes back to waiting (it no longer points at our address, or the ownership proof is not found), the admin gets one mail a day for that name with the host, the hosting resource, the customer, the part that failed and the ownership check's answer. No token or secret is in it.
* Hosting: after an account restore, its undo, or a restore of one website's files, the stored country block of each website is sent to the node again (`site.country_block`) by a background job (`beltoft_services_hosting_country_resend`). The restore is never held by it; a failure is tried again after 5 minutes, 30 minutes, 2 hours and 6 hours, then the admin is told. A website whose block was turned off is sent as off.
* Email DNS: a daily look (`beltoft_services_mail_dns_recheck_all`, one look per domain hosted elsewhere whose records were found, spread over the hours after 03:40) reads all six records once. When MX, SPF or DKIM is no longer found (confirmed by a second read), the domain goes back to waiting, sending is held again when SPF or DKIM is missing, and the customer gets the existing missing-records email once. When the records return, the ten minute look lifts the hold as before. A failed lookup changes nothing; a domain on our name servers is never looked at this way.

= 0.18.0 =
* Hosting: a country block for each website, in a new Protection row of the hosting page. Off by default. The customer picks countries (names from WooCommerce's list, two ready lists: sanctioned countries and high-risk for logins, with a filter) and where it applies: the whole website, or the login and admin only (wp-login.php, xmlrpc.php, the admin area except admin-ajax.php and admin-post.php, the one-time login address, the file manager and the database tool). A blocked visitor gets a plain 403 from nginx in the website's container, before the page cache is read or written. The page warns and asks for a confirmation when the customer's own country is on the list (the country comes from the filter `beltoft_services_visitor_country`, the server variable BELTOFT_VISITOR_COUNTRY or WooCommerce's own geolocation database; nothing is sent to an outside service). New route `hosting/sites/<slug>/country-block` (GET and POST) and the filter `beltoft_services_hosting_country_lists`. Needs the node agent with the operation `site.country_block` and the country database on the node.
* This product includes GeoLite2 data created by MaxMind, available from https://www.maxmind.com

= 0.17.1 =
* Fix: the domain ownership check passed its list of accepted proof values on as the word "Array", so a hosting domain proven by its TXT record was set back to waiting at its next check (0.15.0 to 0.17.0).

= 0.17.0 =
* Hosting: a file manager and a database tool for every website, opened from the hosting page with one click ("Open file manager", "Open database") in a new tab on the website's own address. No password is typed or shown. The link works once for 60 seconds, the tab ends after 30 minutes without use (8 hours at most), and the tools are not reachable without it. New routes `hosting/sites/<slug>/files-link` and `hosting/sites/<slug>/database-link` (dashboard only, not for API tokens or the MCP server); needs the node agent with the operation `site.tool_link`.

= 0.16.0 =
* Email DNS: MX, SPF and DKIM are needed; DMARC, autoconfig and autodiscover are recommended. The email domain's DNS counts as set up when the needed three are found. The recommended records are still checked and shown, never block, and are not named in the reminder email.
* Outgoing mail of an email domain whose DNS is elsewhere is held until SPF and DKIM are found: mailboxes can still be made and can receive. The hold is the mail server's per-mailbox `smtp_access` attribute (new client call `set_smtp_access`), set on every mailbox of that domain only, lifted by the DNS check or Check now. A domain on our name servers is never held. The Email lite website mailbox follows the same rule. The email overview, each mailbox row and the new mailbox form say so.

= 0.15.0 =
* Domain ownership: one check and one proof record for hosting, email and DNS hosting. A customer adds one TXT record at `_beltoft-verify.<domain>` and it proves the domain for all their services here. The value is made from the customer and the domain (`beltoft-owner=...`); older values still work. A proof counts only for the same customer, for the domain and the names below it. Removes the 0.12.4 filter `beltoft_services_mail_domain_proven`; new filter `beltoft_services_domain_proven`.
* One DNS records guide for hosting, email and certificate validation: what the records achieve, numbered steps, records grouped Needed and Recommended with a Copy button for the whole value, a status per record, notes for an existing SPF record or MX records of another provider, and Check now. It collapses to one line when everything is found.
* The Email panel of a hosting account and the email overview say that mail does not arrive until the DNS records are in place, with a link to the guide.

= 0.14.2 =
* Hosting account page: the move panel says the move is free when the setting "The website move is free of charge" is on (replaces the free text setting).

= 0.14.1 =
* Hosting account page: a "Move a website here" panel with a button to the shop's request page (settings "Website move request page" and "Website move text", filter `beltoft_services_hosting_move_url`).

= 0.14.0 =
* Hosting: WordPress itself moves to the customer's own domain when it goes live. The agent copies the website's database first, replaces the temporary address in every table (links, serialized data and JSON, GUIDs left alone), sets the WordPress and site address, clears the caches, and puts the copy back when anything fails. The copy is kept for 7 days. It moves by itself when the first domain of a website is live (both names of a pair, and the DNS shows our address) and WordPress is still on the temporary address, and when the other name of a pair is chosen as the main address. A website whose WordPress address the customer set to something else is left alone.
* The temporary address redirects to the main address once WordPress is on it, and stops when the domain stops pointing at the hosting. The sign-in and password links follow the WordPress address. A domain WordPress is on cannot be removed by the customer until another address is the WordPress address.
* The website panel says which address WordPress uses and has the buttons "Make ... the WordPress address" and "Go back to ..." (for 7 days). Needs the node agent of this release; an older agent is left alone and nothing is shown.

= 0.13.0 =
* Hosting: the page cache is controlled from WordPress. A website is cached by its server only when its WordPress marks a page as cacheable, so with the page cache plugin deactivated, deleted or switched off nothing is cached. The My Account page shows, per website, whether the page cache and the object cache are on, and where to switch the page cache off.
* New node agent operations are used when the agent has them: site.cache_status (read only, shown on the page; REST GET hosting/sites/<slug>/cache) and site.cache_setup. A node whose agent does not have them keeps working: the Clear cache button is unchanged and the cache line stays hidden.
* A new website on a plan that preselects a shop and has an object cache gets the object cache switched on, as a best-effort step that never stops the website.
* WP-CLI: `wp beltoft-services hosting cache setup [--dry-run] [--account=<id>]` sets up the caches of every website of every account (safe to repeat; a plugin the customer removed stays removed).

= 0.12.4 =
* Email lite: the mail domain of a hosting account needs no second ownership record when the account's website already runs on the domain (filter `beltoft_services_mail_domain_proven`).

= 0.12.3 =
* Security: the mailbox list of a mail domain holds only that domain's mailboxes, also when the mail server answers with every mailbox it has.

= 0.12.2 =
* Hosting: the DNS records for a domain are shown as one table for the name with and without www, each value with a copy button and what the last check found, with a Check now button. The Email panel lists the steps.

= 0.12.1 =
* Hosting page: Enter in a field does what its button does, Escape closes an opened form, an opened form takes the focus, and an empty field that is needed is marked.

= 0.12.0 =
* WordPress hosting page: every website is a panel with its main address, PHP version (with a Change control), WordPress login, cache and addresses; the sections Get started, Add a website, Files (SFTP), Backups and Email are panels with headings. Buttons follow one hierarchy and fields are as wide as their content.
* A domain is connected with and without www. A control chooses the main address; the other one redirects to it (301). The redirect needs the node agent of this release and starts when both names point at the hosting. The WordPress address of the website is not changed.
* The WordPress login says which administrator it signs in as. "Set a WordPress password" opens WordPress's own screen for choosing a password, through a one-time address. Neither address is stored or logged. The hosting ready email and the order page say the same.
* Files (SFTP): host, port and user name with Copy, a password (generated or your own, shown once, emailed code first) and public SSH keys (emailed code first to add one).
* The names of a website are now sent to its container (before, only the edge knew a connected domain).
* New DNS records of a website are written with a TTL of one hour instead of five minutes.
* REST: POST hosting/sites/<slug>/password, POST hosting/domains/<host>/main, POST hosting/files/password, POST and DELETE hosting/files/keys.

= 0.11.2 =
* API tokens: the access dropdown keeps the arrow a theme draws.

= 0.11.1 =
* Setup progress: the steps say what is being done ("Starting your container"), not an instruction.

= 0.11.0 =
* Account credit can pay the first order at checkout (setting "Account credit pays orders at checkout", off by default). The checkout shows the credit used and the amount left for the card before the customer pays; the card form and the wallet sheets show the same amount. Credit a customer paid for pays any order; promotional credit pays only products with the box "Promotional credit can pay orders and renewals" (the old label "Promotional credit can pay renewals"). A card always needs to be there: the checkout still asks for a payment method, and an order with nothing that saves a card uses credit only when the customer already has a saved card. Top-ups and plan switches are never paid from credit. Credit is given back when the payment fails or the order is cancelled, and a new attempt takes it again.

= 0.10.8 =
* Firewall: the note about a firewall inside the server says when it matters.

= 0.10.7 =
* Account pages: font sizes and weights follow one scale; inputs on phones are 16px.

= 0.10.6 =
* Firewall: the copy button for the command inside the server is removed.

= 0.10.5 =
* A connected app that is set up shows "Active" in the account, not "Ready".

= 0.10.4 =
* Credit page: the notes under the balance rows are small and soft, lined up with the rows, with room before the next row.

= 0.10.3 =
* Connecting an app with Full access needs the same emailed code as making a Full access API token (a new purpose, `oauth_full`). The consent page has a code field and a Send code button, and the server refuses Full without a valid code. Read and actions are unchanged. Apps already connected with Full access keep working and refresh without a code.

= 0.10.2 =
* Hosting order form: a website name posted together with the temporary address is ignored (the name is generated); the form stopped offering the field in 0.10.1 but the server still took it.

= 0.10.1 =
* The temporary address of a new hosting website is made for the customer, from the website title, the company, the name or the email name, with two random characters (for example `myfirm-k7`), instead of being typed at the checkout. The same goes for a website added in My Account without a name. A name that is given (REST) is checked as before. New filter `beltoft_services_hosting_generated_name`.

= 0.10.0 =
* Account credit can pay the renewal orders of subscriptions (setting "Account credit pays renewals", off by default). Credit a customer paid for pays any renewal, before the card is charged and only for the part the card would otherwise pay; promotional credit (credit codes and grants) pays pay as you go usage and the renewals of products with the box "Promotional credit can pay renewals". A renewal whose rest for the card would be under the usage minimum is paid by the card in full. The credit page says how much of the balance is promotional.
* New filters `beltoft_services_credit_applies_to_order` and `beltoft_services_promo_credit_line`.

= 0.9.5 =
* Account pages: headings keep room above the block under them (server Access, Reinstall and Delete sections, API tokens and MCP, Credit).

= 0.9.4 =
* Usage amounts in My Account follow the price display: including VAT (what the invoice comes to) when prices are shown including VAT, else excluding, with the matching label. REST and MCP answers stay net.
* The credit page shows this month's usage and what is left of the balance after it, as an estimate. Nothing is booked until the invoice is made.

= 0.9.3 =
* Firewall: a background refresh that is refused (too many requests) or fails is tried again later without a failure line; before, it read as if the customer's own action had failed.
* The status line wraps a failure and never widens the page; the memory bar's label has room in the server list.
* Server page: space above the Open console and Start again buttons, the SSH command no longer breaks inside the address, graph times follow the page language, the disk card says the size once, the port example is translatable.

= 0.9.2 =
* Snapshots: the slots in use are a bar under the count, the two buttons of a snapshot have space between them and Delete is the outlined button.

= 0.9.1 =
* Server page: the "Copy SSH command" button among the power buttons is gone; a small Copy sits beside the command on the Log in line.

= 0.9.0 =
* Free transfers (setting `domain_free_transfers`, off by default): for an extension the registrar moves without a year, a transfer costs nothing at checkout and the first yearly payment falls on the domain's own renewal date. A payment method is still required. No year is bought at the registrar before that payment. The price refresh now records whether the registrar stated a transfer price (a missing price is not free). A domain search result of a free transfer carries `free_transfer` true (no key otherwise); the price tag `{beltoft_domain_transfer:tld}` prints "Free" for such an extension. Filters `beltoft_services_domain_free_transfer` and `beltoft_services_trial_cart_item`.
* An unknown registry expiry no longer dates the next payment one hour from now: the step retries. A subscription trial end later than the new payment date moves with it.

= 0.8.5 =
* Credit codes: the card check reads the card's fingerprint through the Stripe plugin's gateway client. Before, the lookup failed on current versions of that plugin and every card was refused with "This card cannot be used to redeem a code".

= 0.8.4 =
* WordPress hosting order form: the website language offered first is the language of the page the customer orders on (a Danish checkout offers Dansk) when the list has it. Filter `beltoft_services_hosting_default_language`.

= 0.8.3 =
* A domain price tag is worked out once per request: a page that repeats a figure, or is read more than once in a request (an SEO plugin building a description from the content), no longer computes it each time.

= 0.8.2 =
* A WordPress hosting tab in My Account for every customer, with an empty state and an Order hosting button. The button goes to the setting "Hosting order page", else the first hosting product, or what the filter `beltoft_services_hosting_add_url` returns.
* A customer's first credit row (a credit code, a top-up or an admin grant) fixes their currency for good. The new action `beltoft_services_credit_currency_set( $user_id, $currency )` tells the site, so a shop side that offers more than one currency can fix the customer to it and no credit is left that cannot be applied.
* An error or a confirmation in My Account is shown once, in the status bar at the top, and is no longer repeated under the field it belongs to: the field is marked invalid and focused. The hosting panel uses the status bar too. A notice that a form left behind and nothing printed is handed to the status bar.

= 0.8.1 =
* The prices on pages a cache may keep (the Bricks usage tags, the domain tags, the domain search results and the Bricks domain rows) are renderers that work the figure out again for the currency in force when a listener of `beltoft_services_price_html` calls `$render`, and give an empty answer where there is no price in that currency. A site that prints every currency calls `$render` once per currency and display and prints no variant for an empty answer. For such a renderer `$amount` is null and `$currency` is the currency of the first render. With no listener and one currency the output is as in 0.8.0. The domain rows keep their memo per currency.
* Domain price override lines take a dot and up to two decimals (`dk=95.20`), so a price that ends in 9 including 25 percent tax can be written. A line that cannot be read stops the save, the old value stays and the screen names the lines. New filter `beltoft_services_setting_bad_lines` for a feature to do the same for another text setting.

= 0.8.0 =
* More than one shop currency. A customer is priced, invoiced and charged in one currency, and nothing is ever converted for a customer: an amount with no value in a currency is not offered in it. See "More than one currency" in the FAQ.
* Pay as you go usage, credit, top-ups, the minimum and the early billing threshold work per currency. Hours that could not be metered (no price in the account's currency yet) are caught up from the first unmetered hour, up to 45 days.
* Hosting add-ons are quoted, ordered and billed in the subscription's currency. Plan, backup, disk and add-on prices shown for an existing service are read in its subscription's currency, and a switch is refused from a page in another currency.
* Domains, certificates and licence keys can be sold in a second currency: a price per extension per currency, and a guard rate (setting `currency_guard_rates`) so an order is only placed with the supplier when what was paid covers its cost in euro. Without a guard rate such an order is refused and the admin is mailed.
* The MCP endpoint, the API routes that return prices and the renewal reminder use the currency of the token owner or of the subscription. A call that cannot be answered in that currency is refused; amounts stay excluding tax and carry their currency code.
* The credit box on a user profile names the currency, credit codes have a currency, and the Currencies settings section explains the fields.
* New hooks: `beltoft_services_guard_rate`, `beltoft_services_guard_rate_bounds` (a guard rate must be from 1 to 1000 unless this says otherwise; a line that is not a plain number with a dot is refused on save), `beltoft_services_currencies` and `beltoft_services_app_text`. Pinned for a theme: `Usage\Rates::in_cart()`.
* A usage account in another currency than the shop no longer stops the MCP endpoint: only the tools that return or move money are refused (with a tool error), the others work. A token request is counted once by the rate limit.
* A domain, certificate or licence key in a currency with no guard rate is refused in the cart, before payment.

= 0.7.2 =
* Groundwork for a second shop currency, with no change on a shop with one currency: pay as you go usage is metered, invoiced and capped in the currency of the customer's usage account, account credit and top-ups have one currency per customer, and amount settings can be given per currency through filters. Nothing is ever converted: an amount with no value in a currency is not offered in it.
* Every catalogue price the plugin prints follows WooCommerce's tax display and can be re-rendered in the other display through the `beltoft_services_price_html` filter. Invoices, order lines, the API and MCP stay excluding tax.
* The domain search and the Bricks domain rows print the shop's currency format. New Bricks tags print a domain's register, renew and transfer price.
* A checkout that shows the setup fields in a step with its own heading can leave the fields' heading out (`beltoft_services_checkout_field_heading`).
* New hooks: `beltoft_services_currency_runner`, `beltoft_services_user_currency`, `beltoft_services_amount_setting`, `beltoft_services_price_html` and the action `beltoft_services_usage_account_created`.

= 0.7.1 =
* The pay as you go ledger and the credit ledger get a currency column. Existing rows are set to the shop's currency in batches on the first request after the update, and a row with no currency counts as the shop's currency. Nothing else changes for customers.
* Capacity never stops a sale, a placement or an upgrade. Hosting, servers, databases and apps are on sale whatever the room on the nodes; sold out is WooCommerce's own stock status, set by hand (a plan is also closed while no public address is free). New services go on the node with the most room, or the one least over its budget when none has room.
* The admin is mailed when a node passes its committed budget (at 100 percent and at each further 10 percent), and each night when the real free memory or disk of a node is under a threshold (two new settings). The promised-storage setting is retired.

= 0.7.0 =
* DNS hosting for a domain registered elsewhere is verified with a TXT record before a zone is served. It is off by default; the setting is on the Domains tab.
* Import of the current records: a scan of common names, or a pasted zone file.
* TXT records can be up to 2048 characters.
* A cancelled DNS hosting subscription stops answering at once. The domain stays locked until it no longer points at our name servers.
* A registration or transfer by the same customer takes over the existing zone.
* WP-CLI: `wp beltoft-services dns-claims` lists, shows, assigns, releases and approves DNS hosting claims and zones.

= 0.6.0 =
* My Account has separate Domains and VPS tabs (Beltoft Licenses 0.8.0 or newer); the Licenses tab keeps the plugin keys, and empty tabs are hidden.
* Domains: the card heading is the domain name; name servers, an always-open DNS records table editor, and one Transfer section (new domains start locked; unlock shows the transfer code in a read-only field with a Copy button). Actions show Updating and finish in seconds instead of waiting for the cron.
* Domain and server carts require the full billing details at checkout; an incomplete domain order waits and alerts the admin instead of failing. The setup-delay email now also covers domains and DNS hosting.
* Domain search caches availability for five minutes, caps registrar lookups per hour, and limits each visitor; an order always checks again.
* Hardening: DNS records refuse control characters and over-long names, record lists are size capped, and assets are versioned by file time.
* Added extra disks as separate subscriptions linked to managed servers, with checkout and My Account controls, capacity checks, online growth, and retention-based removal. Storage capacity is read only when an extra disk product exists (filter beltoft_services_disk_capacity_enabled).

= 0.5.0 =
* Added owner-only CPU, memory, network and disk activity graphs for VPS customers, with hour, day and 30-day views.
* Added cached root filesystem usage from the guest agent, read in six-hour batches without making a guest-agent call during a customer page load.
* Added optional once-per-problem emails for unexpected downtime and high disk use, with admin settings and a read-only metrics CLI command.

= 0.4.0 =
* Domain registration and transfer through a guarded registrar client; DNS hosting for domains registered elsewhere, with a separate yearly subscription and license per domain.
* Public domain search, per-domain cart pricing, customer record and name-server controls, transfer lock and an owner-only transfer-code route.
* DNSSEC with authoritative name-server readiness checks, paid subscription renewals, daily registrar and delegation sync, low-balance alerts and DNS health checks.
* Fake registrar and DNS models for staging, a sandbox API mode, domain CLI commands and a pending-action customer email.
* .dk ordering remains gated until its separate registrant agreement wording is approved and enabled; the prepared classic checkout records acceptance per domain.

= 0.3.1 =
* The admin is mailed on the first failed backup night (and again after three), not only after three. A failed prune (for example a backup server that refuses deletes) now counts as a failed night and is alerted by the same rule, once per run of failures.
* Fix: restoring a backup no longer shuts the restored server down again right after it was started. The restore leaves the server off, and the queued plan change applies the plan and starts it once.
* Fix: the account panel's "Add backups" and "Remove backups" links no longer disappear for an existing server when the cluster is sold out. Only the checkout box for a new server still needs the sibling plan to be in stock.
* Fix: backups bought again after they were removed are taken at the next run, not up to 20 hours later.

= 0.3.0 =
* Daily server backups as a sellable add-on. A second attribute on the server product ("Backups": None or Daily) gives each plan a sibling variation with the new plan field "Backup days"; adding and removing backups are WooCommerce Subscriptions switches. Setting: "Backup storage" per cluster (a Proxmox Backup Server storage; empty turns backups off).
* A nightly job (03:30) backs up the due servers one at a time with vzdump, prunes to the plan's days, records failures and alerts the admin after three in a row. A deleted server's backups are deleted before the VM; removing backups from the plan deletes them at the next run.
* Customers can list their backups and restore the server configuration and boot disk to one (typed confirmation `RESTORE`, rate limited); extra disks are excluded and the plan is applied again afterwards. The account panel gets a Backups block (add, list, restore, remove) and the admin panel a Backups row. A "Daily backups" box at the classic checkout swaps the cart's server for the sibling variation.
* The fake cluster models vzdump, storage content, prune, restore and locked VMs.
* The Proxmox role needs `VM.Backup` and the token `PVEDatastoreUser` on the backup storage.

= 0.2.6 =
* Fix: replacing the SSH key now takes effect at once. On a running server with the guest agent, `/root/.ssh/authorized_keys` is replaced (not appended to), so the new key works immediately and the old one stops; the cloud-init key is updated as before, for a reinstall. A stopped server, or one without the agent, still gets the key only at its next start, and the old key stays until a reinstall; the admin history says so. This needs the token privilege `VM.GuestAgent.FileWrite` (or `VM.GuestAgent.Unrestricted`, which the documented role already has) on the pool; without it the admin is alerted with the privilege name.
* Fix: the account panel, the emails and the admin panel show the disk of the current plan after an upgrade (the larger of the recorded disk and the plan's), not the size from when the server was built. The admin VM table has a Size row.

= 0.2.5 =
* Fix: a service is always paused before it is deleted (a refund, a revocation or an admin delete of an active service). A remote service that only deletes a paused one (HTTP 409) no longer leaves the delete failing. A pause that fails stops the delete, which retries as usual. The Proxmox driver stops the VM as part of its delete and is unchanged.

= 0.2.4 =
* Fix: paying a renewal (also an early renewal) or a switch no longer asks for an operating system or an SSH key, in the classic and the block checkout; nothing is validated or saved for it. A resubscribe still asks, as it can build a new server. Themes can call `\BeltoftServices\Proxmox\OsField::cart_needs_choice()`.
* A switch to a plan with less disk, fewer cores or less memory than the server has is refused before payment ("Servers can't be downgraded"); it used to be refused only for a smaller disk.
* Fix: the usage shown in the account panel is refreshed after a plan change, so the old limit is not shown for up to an hour.

= 0.2.3 =
* A VM whose license never chose an operating system (issued by an admin or imported) gets the cluster's default template instead of failing.

= 0.2.2 =
* Fix: a service is no longer built or started for a license that has ended since the job was queued (a chargeback or refund right after payment, an expiry that passed a moment before the job ran). The license is read again before provisioning and before a resume, and before the clone and the start of a VM; the service is then paused with a history entry and nothing is created or started. A VM that was already cloned stays off. A license with a future expiry (a subscription license waiting for its subscription to activate) is active and provisions as usual.

= 0.2.1 =
* A virtual machine product's availability is now its WooCommerce stock status. It is written (`instock` or `outofstock`, only when it changes) after each capacity run, each placement and each change of the IP pool, so WooCommerce's own out-of-stock display and `woocommerce_product_set_stock_status` / `woocommerce_variation_set_stock_status` hooks (page cache purges) work as usual. The status is also refreshed when a product or variation is saved and when a cluster is saved or deleted. A status you change by hand is never overridden: a product you set to out of stock is not reopened, and products that manage stock are left alone.
* When the capacity cache is gone (for example after an object cache flush) products are no longer closed: the stored status stands and a capacity run is queued.
* Renewals, switches and resubscribes still go through when the stored status is out of stock.

= Faster steps (unreleased) =
* A step that waits for something still running (a Proxmox task, a server that is shutting down, a start that is not listed yet) no longer counts as a failed try with a one-minute floor: it looks again in about 5 seconds and shows as "Waiting: ..." in the admin panel instead of a last error. A wait has a budget (shutdown or stop: the shutdown timeout plus 60 seconds; a start check: 60 seconds; other tasks: 30 minutes). After it, the normal counted retries and the admin alert apply. New `StepResult::poll()` for drivers; reserved meta `waiting`, `wait_since`, `act_wait_since`; filters `beltoft_services_poll_budget_max`, `beltoft_services_run_budget` and `beltoft_services_power_settle`.
* One run (the work of one job while it holds a service's lock) lasts at most 45 seconds in cron or WP-CLI and 10 seconds in a web request. A job that finds the lock held tries again after 5 seconds instead of 60.
* A stale last error is cleared when the operation that wrote it is over.
* New `wp beltoft-services run-due [--seconds=55] [--interval=3]` runs this plugin's due jobs every few seconds, so a step does not wait for the once-a-minute cron tick. Optional. Put it in cron next to WordPress cron, for example `* * * * * www-data flock -n /run/lock/beltoft-services-runner.lock wp beltoft-services run-due --seconds=55 --path=/path/to/wordpress --quiet`. It honours the kill switch and the locks; `flock -n` is required (one runner at a time). No job is started after `--seconds`.

= 0.2.0 =
* New Proxmox VE driver: virtual machines cloned from templates, with an IP pool, a login (SSH key or one-time password), shutdown-before-stop pausing, plan changes, deletion and reinstall.
* Safe by default: a built-in fake cluster for testing, live calls only with `BELTOFT_SERVICES_PROXMOX_LIVE` in `wp-config.php` and an explicit site tag, an ownership tag in every VM's description, and TLS verification with an optional CA file or public key pin.
* Operating system choice at checkout and on reinstall, with a default per cluster.
* Capacity from committed memory plus a host reserve; products go out of stock when the pool or the nodes are full.
* Customer actions (start, shut down, reboot, reset password, replace SSH key, reinstall), the usage and abuse jobs (off, report or pause), and emails with the address and size.
* Suspend for breach with a per-license breach mark, retention for cancelled subscriptions, and a delay notice when a provision fails.
* WP-CLI: `link-vm`, `ip-pool-add`, `ip-pool-block`, `ip-pool-release` and `ip-pool-list`.
* Changes for every driver, not only Proxmox: the paused reason shown to customers (screen and email) is now a fixed, translated sentence per cause, also for HTTP services (it was the raw history text such as "License expired"); a customer action that is dropped because it is no longer available now leaves a line in the license history; a driver can report progress with a retry (`StepResult::retry()` has a fourth argument, `$progress`) so long operations made of many short steps are not cut off by the five-retry budget, at most 10 times per operation (reserved meta `progress_resets`); drivers can add initial meta (filter `beltoft_services_initial_meta`), email details (`beltoft_services_email_details`), account panel buttons, assets and action refusals (`beltoft_services_account_buttons`, `beltoft_services_account_assets`, `beltoft_services_action_refusal`), and a default retention per service type (`beltoft_services_default_retention`; empty product retention now means 3 days for virtual machines and never for others); `Routes::guard()` is public; `Locks::with()` nests named locks; the customer panel shows "Suspended" and "Delayed" states; the "Service setup delayed" customer email and the breach mark (Suspend for breach, with its retention days setting) apply to every driver; and uninstall removes every transient of the plugin.
* Known limits: IPv4 only; disputes are manual; the Proxmox privilege names are taken from the PVE 9.2 documentation.

= 0.1.1 =
* New public rate limiter, `BeltoftServices\RateLimit::exceeded()`, for plugins built on top. The customer REST routes use it, with unchanged behaviour.

= 0.1.0 =
* First version: services that follow Beltoft Licenses, run in Action Scheduler jobs with a lock per service, retries with backoff and admin alerts.
* HTTP API driver: signed requests (HMAC-SHA256), idempotency keys, connections with encrypted secrets, usage and an Open link.
* Service tab on products, one free trial per customer, and a My Account panel with status, actions and Open.
* WooCommerce → Services, a Service panel on each license, and customer emails.
* Kill switch, and deletion after a retention period (off, report only or on).
* WP-CLI: `link-http` and `status`.
* Known limit: the Services list in the admin loads every live service for each view, which is fine for 0.1.0 and will be paged later.
