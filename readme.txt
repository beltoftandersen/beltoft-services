=== Beltoft Services for WooCommerce ===
Contributors: beltoft
Tags: services, provisioning, woocommerce, subscriptions, license
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 0.54.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Sell services with WooCommerce Subscriptions. A license is the entitlement, and a driver provisions, pauses, resumes and removes the service.

== Description ==

Built on Beltoft Licenses. The `Requires Plugins` header depends on the plugin folder names `woocommerce`, `woocommerce-subscriptions` and `beltoft-licenses`; a copy installed under another folder name is not recognised by WordPress as the dependency. It needs WooCommerce, WooCommerce Subscriptions and Beltoft Licenses 0.8.8 or newer; if one is missing it shows a notice and does nothing else.

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

1. Install and activate WooCommerce, WooCommerce Subscriptions and Beltoft Licenses 0.8.8 or newer, then this plugin.
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

= 0.54.0 =
* Databases are built like a VPS Basic while database IPv6 is on (setting db_ipv6): a public network card with the database's own IPv6 address, closed until the customer allows addresses. An IPv4 address (the add-on) is then added and removed like a server's: on the same card, one restart.
* Databases have the servers' Network tab (the same code): the private network, the private address and what else is on it. A database built from the IPv6 templates can be moved to another of the customer's private networks (one restart; the guest's new `private` job).
* The one reboot after a network change is shared by the IPv4 change and the database move.

= 0.53.0 =
* One rule for every VM: whether a VPS, a VPS Basic or a database wants a main IPv4 address is answered in one place (the plan includes it, or an IPv4 add-on holds it). The old database plan field "Public IP" is removed (no database used it).
* A database's "disk nearly full" email follows the same setting as a server's (Monitoring: disk percent, re-armed five points below), not a fixed 85 percent.
* Account overview: a finished order is one compact card with a named button per service; while something is being set up, each service shows only its current step.

= 0.52.0 =
* A VPS Basic or a database ordered together with an IPv4 address is built with that address, the same way a VPS gets its own: it starts with it, and the add-on finds it in use (no second restart, about a minute sooner). When the pool is empty it is built without IPv4, as before. An address added later works as before.

= 0.51.4 =
* Private network choice on a server page says what is on each network: "Network 1 (10.64.0.32/28): 2 servers and 1 database", or "empty" (it said "0 servers, database").

= 0.51.3 =
* Account: no price inside a button. Add an IPv4 address, Add managed server, Add daily backups and Make larger show the price on a line beside the button (a long label made the server page wider than a phone).
* Server page: the IPv4 block lists the server's own address as its main address (it said the server had no IPv4 address).

= 0.51.2 =
* Account overview: the rows of databases, hosting, apps and mail also stop saying they are being set up when the new order shows them ready (until now only server rows did).

= 0.51.1 =
* Priority support section: the price is printed as the shop prints prices (one price, following the VAT display), not as escaped text.

= 0.51.0 =
* Account overview: a Priority support section. A customer without it sees what it promises, the price and an Add priority support button; a customer with it sees that it is active. Shortcode `[beltoft_services_support]`.

= 0.50.2 =
* New order on the account overview: the button that opens the new service has white text again (the theme's link colour made it dark on dark), and the server's row below stops saying Working when the order is ready.

= 0.50.1 =
* Product check: a pay as you go product that is a variable subscription (the converted server, database and disk products) passes the type check.

= 0.50.0 =
* Pay as you go is its own product: servers, databases and disks are sold pay as you go, with no Monthly or Pay as you go choice at checkout. The monthly price each hourly rate is taken from is typed on the plan itself, per currency. Existing products are converted with `wp beltoft-services payg split` (dry run by default, `--apply`, `--rollback`, `--keep-monthly=<ids>`); running monthly subscriptions renew as before.
* Product editor: a Billing choice per product (Monthly, Pay as you go by the hour, Pay per unit) and the monthly price per currency on each plan, with a preview of the hourly rate and the monthly cap.
* After checkout the customer lands on the new service in their account.

= 0.49.0 =
* IPv4 address add-on: one add-on for servers and databases, taken from the site's own address pool. A server can have extra addresses; a server with IPv6 only gets its main address from the add-on; a database gets its public address from it. A mail goes out when the pool runs low.
* VPS Basic: servers with IPv6 only. Managed server add-on per server and Priority support per account.
* Hosting ordered with a new domain: the thank-you page and the welcome email say the website starts on a temporary address and the domain is connected by itself when it is registered.
* Customers can add or remove IPv6 on a server (one restart). Databases can get IPv6 behind the setting db_ipv6 (off).

= 0.48.1 =
* Domain parking: the list of parked names reaches the hosting nodes again. The job read the nodes with their certificates still encrypted, so every push failed.

= 0.48.0 =
* Admin: the license list has a Service column that names the domain, server, database, website, mailbox domain, certificate, app licence, key or dedicated server of each license (needs beltoft-licenses 0.8.12; on an older version the column is not shown).
* Domains: unpaid renewals, phases 1 and 2. The registrar's renewal date and restore facts are read and kept, staff are alerted about domains near deletion, and the reminders N1 to N7, the reactivation fee and the domain page lines are built behind the setting domain_unpaid_notices (off by default). The fee in kroner uses the fixed rate in domain_fee_dkk_rate.
* Domains: the daily price sync keeps the DNSSEC and WHOIS privacy facts read one TLD at a time, and each refresh reads up to 150 more TLDs (missing or older than 30 days first), so every TLD gets them within a few weeks.

= 0.47.1 =
* Domains: a customer can switch DNSSEC on or off for a domain on our name servers and add or remove external DNSSEC keys (needs the emailed code, purpose domain_dnssec; our own cap of 8 keys applies when the registrar maximum is 0).
* Domains: WHOIS privacy is free, ticked by default at checkout where the TLD allows it, and can be switched on the domain page. The TLD facts (DNSSEC and WHOIS privacy support) come from the registrar's TLD list.
* Admin: WooCommerce > Services search finds a service by IP (also the IP pool and the holding log), name, customer, domain or number, with an option to include deleted services.

= 0.47.0 =
* IPv6 for new servers when the cluster has an IPv6 prefix (off by default). A cluster gets two fields, the /64 and the gateway; each new server gets one address in it, made from its VMID (nothing is allocated, a reinstall or a restore keeps it). Existing servers and databases are never given an address.
* Firewall: customers can name IPv6 addresses and networks as sources; one rule is one Proxmox rule per address family, and a ping rule gets an IPv6 ping twin; neighbour discovery is written on and router advertisements from the guest off; the anti-spoofing set holds both addresses.
* Databases: no IPv6 for now; the access list takes IPv4 only.
* Abuse monitoring: the host counts are accepted for IPv6 sources too (mapped to the server by prefix and VMID), the lookup `wp beltoft-services who` takes IPv6, a log row `public6` records who held an address.
* The server page, the ready email, the MCP details and the admin panel show the IPv6 address. The REST list of services is unchanged.
* Domain parking (off until a parking address is set in the domain settings: `parking_ipv4`, `parking_ipv6`): a domain on our name servers that has no A, AAAA, CNAME or ALIAS record at the root and at www gets A and AAAA records (TTL 300) at both names, written through the zone writer when the zone is made (registration, DNS hosting, a transfer that keeps our DNS) and by `wp beltoft-services parking add` (dry run unless `--apply`; `parking list` shows the parked domains). The records are marked by a stored copy and are never listed in the record editor. They are removed in the same DNS write by whatever puts an address, alias or CNAME at the root or at www (a hosting website, an app, the record editor, the zone file import); a record of the customer is never touched or mixed with them. Actions `beltoft_services_parked` and `beltoft_services_unparked`.
* Servers and databases: a new password (reset_password) needs the emailed code, like the other sensitive changes.
* Credit: the e-mail of a new card says credit added to your account, with no gift card wording (0.46.3, released with this version).

= 0.46.3 =
* Credit: the e-mail of a new card says credit added to your account, with no gift card wording.

= 0.46.2 =
* Databases: backups are kept 30 days when a plan names no days.
* Credit page: the code field says Code, with no gift card wording; no gift card e-mail is sent while gift credit is on.

= 0.46.1 =
* Pay as you go: an account without a card takes the customer's saved default card when a server is ordered, instead of asking for a card.
* Credit page: one field for gift card and campaign codes again; the coupon field at checkout takes them too.

= 0.46.0 =
* Customer controls for dedicated servers: restart and power, rescue system, reverse DNS, firewall and reinstall, with REST and MCP tools, and system choices taken from the supplier lists.

= 0.45.0 =
* Dedicated servers sold pay as you go and ordered automatically: a new driver (`manual-server`, kind `dedicated`). After payment the server is ordered through the supplier's Robot webservice (Server ordering: POST /order/server/transaction with the product, location, an IPv4 address, a generated root password and an optional distribution), followed until the transaction is ready, then delivered with the meter started, the service ready email and a card in My Account. Cancel posts the documented cancellation "now". A call whose outcome is unknown is never sent again; the by-hand "Mark ready" stays as the fallback, and is the whole flow while "Order dedicated servers automatically" is off.
* The optional SSH public key of the VPS checkout is asked for dedicated servers too (the same field and checks, shared through the marker `TakesSshKey`). A given key is registered at the supplier (POST /key; an identical key that already exists is found with GET /key) and ordered as `authorized_key[]`; the documentation allows a key or a password, so then no root password exists.
* Safety: live calls need the setting Live, a web service login stored encrypted, and the constant BELTOFT_SERVICES_DEDICATED_LIVE; the fake client and a test server mode (which refuses the real host) never spend money. A spend guard checks cost, paid setup fee and caps (servers, monthly cost, new orders a day; all 0 until set). `wp beltoft-services dedicated test-order` sends one order with the documented test flag.
* Billing is the usage machinery of the servers: hours from the hour it is ready, the monthly price as the cap (product premium 0), the one-time setup fee is the price of the pay as you go variation and is charged at checkout, the new-account limits and thresholds apply as for a VPS.
* My Account: a Dedicated servers tab with a card (name, specs, addresses, state), the root password behind the emailed code (stored encrypted, 5 requests a minute, never in a mail), and a Cancel server block with a typed confirmation that says the data is deleted at once and billing stops. No assistant (MCP) tool cancels one.
* Plan fields per variation: processor, memory, storage and country shown, and the supplier product, location code, optional distribution and costs, which only staff see. The delivery promise is a setting in minutes (default 10); staff are mailed once when a server is later.

= 0.44.1 =
* Server order and reinstall forms: one tile per system family (Ubuntu, Debian, AlmaLinux) with a Version select; the posted value is unchanged.
* Trusted pay as you go customers: a shop manager lifts the new-account service limit and early billing on the user profile; the limit message says to contact us.

= 0.44.0 =
* Counted services (OdooWoo per order): the plugin pulls the orders a service processed, keeps them as events, gives a 7 day free intro, prices them in steps per calendar month, bills them in the usage ledger and shows the customer a Billed orders list. Off until the setting "Bill counted services" is ticked; with it off events are pulled and judged but nothing is billed.

= 0.43.6 =
* Account and order forms: one type scale (14, 16, 20) and one set of colour tokens; card padding, phone meta line and overview width fixed; no "Expires 00 /" for wallet methods; API reference on the scale; hosting label translatable.

= 0.43.5 =
* Backup storage: host names are a running count (backup1, backup2, ...), not the service id; the account tab and its texts say Online backup.
* Jobs: the site runners also run hooks the site adds through beltoft_services_job_hooks when they belong to another plugin's group (emailed codes are sent at once, not at the next cron minute).

= 0.43.4 =
* Backup storage: the account page reads the snapshot list at most once a minute per box (it was half a second on every load); taking, deleting or restoring a snapshot shows the new list at once.

= 0.43.3 =
* Backup storage: sizes count in steps of 1024 like the provider sells them (the 1 TB box no longer reads "1.1 TB"), and the default country is translated (Danish: Tyskland).

= 0.43.2 =
* Backup storage: a box made from an order records the order line that paid for it, so the spend guard can check it (the first live order stopped at "Checking the order"; nothing was bought).

= 0.43.1 =
* Checkout: a customer whose account credit pays the whole order and who has a usable default saved method (card, Google Pay or Apple Pay) is no longer asked for a card; the subscriptions of that order carry the saved method, so renewals are charged automatically. The checkout then shows one line instead of the card form, and what is decided at submit is read from the real order (an order with a total above 0 still goes to the gateway). A part payment, a customer without a usable saved method, or a method that stops working in between asks for a card as before.

= 0.43.0 =
* Backup storage (off by default): a backup box per subscription at a storage provider, with a spend guard (cost table, caps), host name alias as one DNS record per box, connection details, password behind an emailed code, snapshots and their plan, upgrade only. Nothing is bought until the cost table, the caps, the token and the live switches are set.

= 0.42.4 =
* Checkout: the phone row reload happens at most once per cart, then a short note; a slow checkout is read once more before an error, and a domain that is in the order is always shown as added; the remove action is bound to the visitor's session; a kept domain line ends with its session and is dropped at login.
* Hosting: the last runs of scheduled jobs are read at most once a minute per website, and a page load waits at most 3 s for the node.

= 0.42.3 =
* Checkout: a domain line kept for the customer is only put back into the customer's own cart, never into a recurring cart that WooCommerce Subscriptions builds for another billing group (it could join the hosting subscription).

= 0.42.2 =
* Checkout: a domain just added to the order cannot be lost to a parallel checkout refresh that writes the session last. The new lines are also kept for two days outside the session (`Domains\PendingLines`), and a missing line is put back when the cart loads, before totals and when the session is written, after the local checks (not held, still priced, same currency) and never with a registrar call.

= 0.42.1 =
* .dk: under the registration agreement a short note says what happens after the order (an email from Punktum dk, log in with MitID, a time limit), in our own words and outside the prescribed text.

= 0.42.0 =
* Checkout: the address of a website and the domain of Business email are chosen with tiles: register a new domain, transfer a domain, use my own domain, a domain in my account, and for a website decide later. The domain search and the transfer are in the checkout and add the domain to the same order (`Domains\OrderDomain`); a domain that stays in the order while another choice is made is announced with a remove link.
* Domain search: `domains/check` takes `tax` so prices can follow the display of the checkout.
* Account overview: Recent activity is a log of everything the customer did or is told about, by the service's own name, grouped by day, with repeats counted and a Show more button (`Account\ActivityMap`, route `GET activity`). History texts still reach the customer only through fixed sentences.

= 0.41.1 =
* Hosting: a website's card keeps its own state (Online) while the account is working; what it is doing is said in the progress line only.

= 0.41.0 =
* Hosting: a website without WordPress can be ordered and added ("No WordPress"); its page shows no WordPress parts. The node decides whether a website has WordPress (agent operation `site.facts`).
* Hosting: scheduled jobs for every website (a PHP file of the website or an address of the website, at most every 5 minutes; plan field `cron_jobs`, default 5). For a WordPress website a server cron can be switched on; it runs WordPress's due tasks and switches WordPress's own cron off while it is on. Needs the agent operations `site.cron_set` and `site.cron_status`; with an older agent the section says it is not available yet.
* Hosting: the database name, user and host of a website are shown; the password is shown once after the emailed code.
* Hosting: the product name in the account comes from the setting `hosting_label` (default "Hosting"); order links can preselect an empty website with `site_install=empty`.
* Choice tiles: the selected tile is white with a soft line and shadow and its logo keeps its colours.
* Monitoring: the CPU, memory and storage cards keep one height; memory shows the share large and the amount beside it.
* Progress line: a site can give it a place in its own bar (an element with `data-bs-loader-host`); it is then shown there on every screen with its moving line along the bottom edge of the bar, nothing sticks or floats (on a phone the text takes the place of the element marked `data-bs-loader-yield`), and the box at the bottom of a phone is only for a site without such a place. The hosting page no longer prints its own wait line beside it.

= 0.40.2 =
* Account lists: the state stands above the name in every list (servers, hosting, databases, domains, email, SSL, licences, apps); the renewal date is no longer in the server and domain rows.

= 0.40.1 =
* Logos: the operating system, app and database tiles show the logos in their brand colours (white on the selected tile), the database engines have a logo too. Every logo file is in `assets/logos/`; `Tiles::logo_url()` gives the URL of one, filter `beltoft_services_engine_logo`.

= 0.40.0 =
* Backups: a customer can delete one of their own backups on a server, a managed database and a hosting account (Delete in the backups list, one confirm dialog). The only backup may be deleted; the dialog says so. The safety copy of a hosting restore cannot be deleted. Refused while a backup or a restore runs; at most 10 deletes an hour per service. No MCP tool.

= 0.39.0 =
* Email: the customer chooses the password of a mailbox, when making it and when changing it (10 to 128 characters, a letter and a digit, not the address; shortest length by filter `beltoft_services_mail_password_min`). A chosen password is never shown again. A change still needs the emailed code. The REST routes take an optional `password`; without it a password is generated and shown once, as before.

= 0.38.1 =
* Email page: the Delete button of a mailbox sits level with the button beside it.

= 0.38.0 =
* Server licences (Plesk editions, CloudLinux, add-on keys): the server address of a licence can be changed on its page in My Account, to one of the customer's own servers or a typed address. Nothing is charged and no new licence is ordered. One change per licence in 24 hours (filter `beltoft_services_licence_moves_per_day`).
* An add-on under a Plesk licence follows that licence when it moves, also when a kept app licence is used on a new server.
* One shared move sequence and one hardware id reset for every kind of licence (`LicenceVendor::move()`, `reset_hardware()`).
* `BeltoftServices\CartCompanion::keeps()` and the filter `beltoft_services_cart_companion`: a site's one-product cart rule asks the plugin which lines belong beside each other (a server with its disk or app licence, a domain with its plan, the usage account line).
* The generic action route answers with the action name and where its outcome is read.
* Checkout: a service that needs the customer's phone number says so (`Drivers\NeedsPhone`: domain, SSL certificate) and `BillingRequirements::cart_needs_phone()` answers for a cart, so a site that hides the phone field shows it for those orders. An SSL order no longer waits for a phone number after it is paid.
* Faster page renders: the pay as you go price tags work each answer out once per request.

= 0.37.0 =
* App licences (Plesk and others): a paid licence with its own subscription is kept when its server is deleted or reinstalled without the app. It stays on the account until the end of the paid month, is not renewed while it is not in use, and can be used on a new server or in the reinstall form at no charge. When it is used, its subscription runs on with the same payment date and the vendor licence is moved to the new server.
* The Servers page lists a kept licence with the date it is paid until. The order form and the reinstall form offer it beside the tiers.
* A kept licence that is not used ends at the end of the paid month, and the vendor licence is released then.

= 0.36.0 =
* App licences (Plesk and others): a paid licence is a monthly subscription on every server, also a pay as you go one. A whole month is charged when it is ordered and again each month it is kept; the server itself stays billed by the hour. Licences already sold by the hour keep their hourly billing.
* The order form, the reinstall form, the App tab (price and next payment) and the delete panel say how the licence is charged and that a started month is not refunded.
* A cart saved with the old hourly licence line gets the monthly line; the server stays in the cart.
* Checkout note: when something is due today next to pay as you go lines, the note says that amount is charged now.

= 0.35.2 =
* My Account: every page has the same content width; the lists of servers and domains and the Overview no longer run wider than the pages of one service.
* An ended add-on or service with nothing left behind it (an expired extra disk or snapshot pack, a removed domain whose licence has ended) is no longer listed. A service that lapsed but still exists stays listed, so it can be paid for and resumed.
* The number beside a menu item counts what the page lists.
* Monitoring: the memory card is titled Memory.

= 0.35.1 =
* Domain page: when a change such as lock, unlock or a name server change does not go through, the page keeps the message and frees the buttons instead of reloading without a word.

= 0.35.0 =
* Licence keys and Plesk app licences share one vendor layer (`Domains\LicenceVendor` with a policy per kind); the existing settings, hooks and stored data are unchanged. A Plesk edition key may be up to 40 characters; the email about a failed Plesk licence deletion uses the same wording as for keys.
* A licence whose first order failed before anything was bought at the vendor cancels its subscription. Not after a failed resume, plan change or pause, not when a vendor licence exists, and not when another live licence shares the subscription.
* Domains are a service area like servers and hosting (`Account\Domains` extends `ServiceArea`); every address of the domain pages works as before. The old card for a domain outside its own page is one "Manage" link.
* New filter `beltoft_services_product_url`: a site can say where the "order" links of the account pages point.
* DNS: an answer to the DS record check must match the question asked; a name with a label over 63 characters is refused before a query is built; three lookups that left out the trailing dot now go through one function. Reads from the own name servers use one strict packet parser.
* Job lookups use the search of Action Scheduler instead of reading every job; the mail mode (own name servers or elsewhere) is worked out live, and when a mail domain's zone leaves the own name servers the daily check reads its records again, holds outgoing mail only if SPF and DKIM are gone, and tells the administrator.

= 0.34.1 =
* The buttons of the hosting pages have the same height as the other account pages (40 px).
* Database page: when the status of a running change cannot be read any more, the page says so, frees the buttons and asks to reload, instead of staying locked.

= 0.34.0 =
* Fourth round from the November review: three decisions of the shop owner, the hosting database limit, and the larger clean-ups.
* Domain renewals: a paid renewal whose price is below the registrar's cost is bought when the loss is at most the new setting "Renewal loss cap" (5 by default, 0 keeps the old refusal), and the administrator gets one email with the domain, the price, the cost and the loss. Above the cap the renewal waits for the staff and the customer sees a plain sentence. Registrations and transfers are unchanged.
* Mailbox passwords and SSL private keys: resetting or showing a mailbox password and showing a private key or its .p12 file ask for the emailed code, as other secrets do. The two assistant (MCP) tools for mailbox passwords are removed; both actions stay on the account page.
* A switch to a longer billing period charges the add-on lines of the same subscription for the extra months in the switch order (with Beltoft Subscription Switching 0.3.0).
* Hosting: the plan's database size limit is sent to the node (with a hosting agent that knows it; an older agent is left alone), and node health reports low free space on the node and on the shared database server and a tripped database guard.
* Account pages: follow-up requests pause while the browser tab is hidden; the Overview reads its services in one query (with Beltoft Licenses 0.8.10); the DNS zone is read only on the DNS tab.
* One rule for who owns a resource (`Resource::owned_by`), one rate limit helper, one refusal result for drivers; shared email template parts, one API reference script, one copy helper; unused server usage and private network code removed.
* A deleted child service has its licence revoked; who holds a domain name is one indexed lookup.

= 0.33.1 =
* Third round from the November review: the smaller findings, each with a test.
* Security: a step-up check runs under a lock and allows 30 tries in 10 minutes over all codes; lookups of registry data use the safe request function; transfer names are normalised before any lookup; the transfer DNS answer is cached for 5 minutes; a new connected app is not pushed out while it is younger than 30 minutes and the token limit counts per address; the Network tab lists other servers only for the network's owner; removing the plugin with its data also removes the registrar login option and the memory table.
* Smaller corrections in the reconciler, lifecycle, apps, hosting, domains, mail, servers and the account scripts (see the review report for the list); an empty price list from the registrar keeps the saved prices; a child service whose link cannot be stored is closed.
* Accessibility: keyboard focus returns to the button after an action; copy rows and the console have proper roles and focus.
* Less code: unused styles, constants and methods removed; one host name rule in the address helper.
* Speed: fewer database reads on the mail and network pages; expired checkout name holds are deleted daily.
* Tests: boundary tests for usage billing, tests that asserted nothing now assert, a missing sibling plugin is a visible skip (or a failure with BELTOFT_TESTS_STRICT=1).
* Requires Beltoft Licenses 0.8.8 and WooCommerce 9.0.

= 0.33.0 =
* Second round of fixes from the November review, each with a test.
* Licence keys: the vendor licence is deleted as soon as the licence expires (not while a payment is being retried); a later payment orders a new key. A refused Plesk edition change is tried again, a failed key refetch no longer leaves the old key in place, and key fetch and deletion count their tries apart.
* Money: a domain transfer paid in full with account credit is no longer treated as a free transfer; a paid early usage bill marks the account as charged; a usage invoice cannot be cancelled; a usage line shows the average hourly price when the price changed during the month; an early renewal order keeps ending add-on lines; a refunded usage invoice does not cancel the pay as you go account; the trial rule compares the normalised email address.
* Domains: a hosting name that was added without proof gives way to the account that proves ownership, and the refusal no longer says who holds a name; one rule decides who holds a name (failed and lapsed services do not); a registrar set to live without its constant refuses and alerts instead of using the test registrar; the administrator is told about fixed prices below the registrar cost.
* Mail and SSL: the certificate cancel window follows the registrar refund period; the alias limit is shown as the plan total; the alias list of the mail server is read once per few seconds.
* API: the token gate judges the same cleaned arguments the route runs; a connection in use cannot be deleted; the services list drops deleted services before it caps the list; the API page says by access level what a connected assistant can do; OpenAPI lists each query parameter once.
* Hosting: a malware scan step no longer restarts on every poll and its notice is marked sent only after the mail went out; the nightly backup waits for a running restore; deleting an account also drops its staging copy databases; node health and the restore test read the result of a job that was still running; a domain whose DNS write failed once is tried again; saving a node keeps stored certificate pins and storage settings when a field is absent.
* Servers and databases: the address of a deleted server is held until the records made for its app domain are removed; an empty address pool after payment is a wait with an alert, not a failed server; a reinstall checks its template before anything is destroyed; a pause during a reinstall stops the old server; a public address cannot be added to a stopped database; a live cluster without its constant makes the job wait instead of using the test client.
* Jobs: a job whose process keeps dying is counted, slowed down and failed after five crashes; the deploy hold stops every job of the plugin; a failed database upgrade sends one email per version.
* Speed: on My Account pages the licence and resource rows are read once per request; the admin Services list reads only the page it shows.

= 0.32.2 =
* Fixes from the November review, each with a test.
* Account credit used on an order counts as paid in the registrar, SSL certificate and licence key cost checks, so an order paid partly with credit is no longer refused after payment.
* A step-up check by an authenticator app is refused for 15 minutes after five wrong codes.
* When the live mail server connection is chosen but cannot be built, mail jobs fail and retry and the administrator gets one email a day; the test client is never used in its place.
* A change to custom name servers on a domain with DNSSEC waits until the registry has removed the DS record (at most a day) before the name servers move.
* Hosting storage at 150 percent of the plan stops database writes at once and alerts the administrator.
* A licence key is deleted at the vendor only when the licence is gone for good (revoked, deleted, or a failed first order); a lapsed key is kept until its retention ends (3 days by default), and a failed plan change, pause or resume never deletes it. A Plesk app licence is kept through a lapse and ordered again on resume if it was deleted.
* Deleting a server removes the A and www records that were made for its app domain, while they are still the records we wrote.
* A hosting set-up step with two node calls no longer restarts its first call on every poll.
* An account restore skips websites made after the backup and imports each database once.
* A managed database restored from a backup gets its public network card, listen addresses and allow-list back.

= 0.32.1 =
* No change for customers: the shared job controller was split into `Jobs` (scheduling, runner, hold, sweep), `JobActions` (customer actions) and `JobFailures` (lost and failed jobs); every public `Jobs` method is unchanged.
* The hosting page has tabs like the server page: Overview (facts, live bars, first-visit list, monitoring), Websites (each website with its domains, PHP, cache, staging and country block, Add a website, Move a website here), Files (SFTP), Backups and Email. Each tab has its own address (`/hosting/<id>/websites`, `/files`, `/backups`, `/email`), so back and links work. Only the tab asked for is built.
* The hosting page markup moved from PHP strings to templates in `templates/account/hosting/`, fed by `Hosting\HostingView`; `HostingAccount` keeps the data and the dispatch. No change in what buttons, forms and texts do.
* A button with a very long domain name wraps on a phone instead of widening the page.

= 0.32.0 =
* WordPress hosting accounts have the same monitoring as a server: CPU, memory, network and disk activity graphs (1 hour, 1 day, 30 days), live CPU and memory bars on the hosting page (every 10 seconds while it is visible), the storage as a bar, and the usage emails (CPU sustained, memory high, storage at 90 percent) with the same "Usage emails" switch and settings. There is no PHP workers metric.
* Memory is memory in use without the file cache, read inside the container by the node agent (new operation `ct.memory`, needs the agent branch `hosting-memory`); the Proxmox figure is never used. Readings go to the same history table as a server's (`bs_guest_memory`), same retention.
* No usage email about memory in the first 15 minutes after a container started. The storage notices over the plan (110 percent) and the write restriction are always sent; only the 90 percent warning follows the switch.
* Shared code: the guest memory history, live bars, charts route and usage rules moved from `Proxmox\Network` to a neutral `Monitoring` folder; a server and a hosting account each plug in with a small `Monitoring\Guest` adapter added on the filter `beltoft_services_monitoring_guests`. The monitoring panel markup is one shared partial.
* REST: `GET /resources/{id}/metrics`, `GET /usage` and `POST /resources/{id}/usage-alerts` answer for hosting accounts too (`disk` is files plus databases against the plan storage, in bytes). `GET /resources/{id}/hosting/usage` also gives `memory_percent`, `memory_used_mb` and `memory_total_mb` (the newest stored reading). The MCP tool `server_usage` also takes a hosting account id, and `hosting_usage` shows the memory.
= 0.31.5 =
* The shared feedback of My Account is one progress line: a thin line and a short text. It moves while the end is not known and fills, with "(step n of m)", where the steps are known (restores, installs and other background work, the hosting task, DNS changes, email changes).
* It is shown in one place: on a wide screen in the page's state line at the top, on a phone fixed to the bottom of the screen above the browser's toolbar, so it never moves the page. Nothing is written under the clicked button any more.
* Done stays about 5 seconds and fades away. A failure stays until it is closed (the close button or the Escape key), and a field it names is still marked and focused.
* Scripts can call `beltoftStatus.progress( handle, fraction | { step, of } | progress payload, message )`; `beltoftApi.follow()` takes `handle` and moves the line by itself.

= 0.31.4 =
* Phone: the server page no longer grows wider than the screen. The memory chart's help sentence wraps inside its card, and long host names, addresses and commands in the facts and in the address tables break inside their own box.

= 0.31.3 =
* A code check that a site refuses for another reason (for example an unconfirmed email address) can now say so: the message goes through the filter `beltoft_services_step_up_refusal( $message, $user_id, $purpose )`.
* The hosting page follows a running change until the account has no task left, through the shared script helper, instead of its own polling.
* A hosting page that loads just after a change ended asks for the cache facts once more when the first answer is "not yet".
* The pay as you go usage account is shown with the same translatable name (Pay as you go account) in the subscription list and the Overview as on order and cart lines.

= 0.31.2 =
* Setting an SFTP password, adding an SSH key and asking for a transfer code now send the code request to the right address (it was a 404 from the hosting page and the domain page).
* The hosting page follows a running change more gently (a read every 5 seconds, slower after ten minutes, and it waits when the server asks it to), clears the step line of a website as soon as the change is over, and asks for the cache facts only when no change is running.
* The Overview has a WordPress hosting section like the other services.
* The email list, the page head and the Status row use the same words, and the waiting-for-proof text is printed once.
* "Check now" on an email domain says what it found, and the records are drawn again.
* The validity of a domain claim is shown in the site's date and time format and language.
* A hosting website shows the title the customer gave, without its address in brackets. Lists run as wide as the notices above them; the DNS hosting heading has a gap to its text.

= 0.31.1 =
* Hosting review fixes. A domain that became the account's included one (its paid line was marked as ending) is no longer disconnected at the renewal, and adding another name of it no longer makes it bill again.
* A paid domain that could not be connected, or an ended one that could not be taken off, because another change of the account was running, is now done by the domain sweep when the account is free.
* What is recorded as sent to the node (names and routes) is what the first run of the job sent, so a name that became active meanwhile is still sent by the sweep. Names that had to go without redirects are no longer sent again by every sweep.
* A request that cannot queue its change after all (another change won) undoes its local work: the new website, the staging address, the backup or restore round.
* An agent job id is never reused by a later change of the same name. A job that cannot read the SFTP password fails instead of making up another one.
* The hosting page follows the account's task until it is gone, also when a follow-up change replaced the last result, and no longer says "done" after a timeout.
* Shared action controller: a long action that its process lost is resumed while anything worked on it in the last 15 minutes (not 15 minutes after the click), and a resumed job never runs the action a second time when the first run finished. Starting an action is one step under the service's lock, so two clicks cannot both start; the firewall, database access and paid app reinstall routes start through the same path and are refused while another change is pending. A marker that lapsed is only closed from a fresh read; a failed or lapsed action tells features what it was about; a refusal is not announced as done; a driver's sentence is cleaned and kept to 300 characters.
* Background jobs: a hook is a resource hook only when it says so, ids from a list of what is due match the jobs already waiting (no duplicate every sweep), and the crash count is only looked at for declared hooks.
* A code from an email can be used only once even when two requests send it at the same moment; a refused start is answered 409 only when the service is busy.
* Domains: a switch to Beltoft DNS that stopped after the name servers were set goes on with the DS step when it is repeated, and is never reported done before the finishing data is stored. The DNS claim sweep reaches every claim over time.
* Saved cards: an old abandoned checkout no longer blocks deleting the last card; only subscription renewals that wait and orders of the last 14 days that would charge the card count. The list asks once per customer.
* Email: a lapsed mailbox creation takes the mailbox it made back; a mailbox made by someone else in the gap is never deleted; a repeat after the password was shown is done, not failed. Lists of due licence keys, Plesk keys, domain joins and sending holds read their rows in one query and stop listing finished ones.
* Usage graphs no longer fail on a page language the browser does not know; the own domain wait of an app backs off when its clock cannot be written.

= 0.31.0 =
* Hosting staging copies (make, refresh, push, undo, delete), the move of WordPress to another address, "Back up now", restores and undo are changes of the hosting account on the shared action controller. Each is checked and queued by the route (202 with the task), runs as one agent step at a time with its steps kept, and ends with the shared outcome. The loops of their own (retry counters, give-up rules, schedules and watchers) are gone: a node job that fails is not sent again by the plugin, a lost job goes on where it stopped, and a change nobody works on any more ends as failed with one staff mail.
* One change at a time: a staging push, a move of WordPress, a backup and a restore refuse each other and every other change of the account ("Wait until restoring from a backup is done.").
* A staging copy keeps its address, times and the way back of the last push (7 days); that a job runs, and what it found, are read from the account's task and the shared outcome. A copy that could not be made gives its address back; one that could not be deleted is a copy again. The way back and the safety point before a push are unchanged, and a push the node put back says so.
* WordPress address: the website keeps its address, the way back and the note that stops the automatic move after a failure or a choice of her own. The automatic move is queued through the same path after a change of the addresses and by the domain sweep.
* The domain sweep now looks at every hosting account like a reconciler: when the node is behind what the shop says about names or the edge map, it queues the change that sends them. It also attaches the domain of an order that waits, and looks at the address of WordPress. The domain checks and the DNS guide queue the same change instead of waiting for the node.
* After a restore or an undo the stored country blocks are sent again by a change of their own (it replaces the separate retry job). A paid domain add-on that ends removes every name of the domain in one change; when WordPress is on one of its names the removal is one job that moves WordPress back to the temporary address first.
* The page follows these changes with the shared follow (window.beltoftApi.follow) and the same status line, buttons off and step words as before. The staging state line and the WordPress address block show the state from the task; why a staging job did not work is the line at the top of the page.
* REST: the staging routes, the WordPress address route, "Back up now", restore and undo answer 202 with the task as the other hosting changes do. The staging state route no longer has note and failed. A change called outside the shared path is refused.
* Shared: the action `beltoft_services_action_done` ($resource, $action, $args) is fired when a customer action ended well, after its marker is gone (hosting uses it to queue the follow-up change).
* Hosting domains: the Connect field says that the plan includes one domain and all its subdomains, what each further domain costs a month (the customer's tax display, left out when no add-on is set up) and which domain is the included one.
* The extra domain dialog names the domain ("example.com is an extra domain. It costs ...").
* When the included domain is removed while extra domain add-ons exist, the oldest paid one becomes the included domain: its line ends at the next renewal (nothing is deleted or refunded) and the page says so. Removing an extra domain works as before.
* Background jobs have one shared mechanism: a feature declares each follow-up hook once (`Background::declare()`) and gets the site runners claiming it, a re-queue along the retry backoff when the job crashes (one staff mail per error, the action `beltoft_services_job_failed` for clean-up) and a watchdog on every sweep that queues a job that is missing. Used by the email sending hold, an email domain joining its plan, the email DNS look and its daily look, DNS ownership claim checks and scans, app install, status, own domain, www and record release, the Plesk and licence key jobs.
* DNS ownership claims: a claim check or scan is a background job of the shared mechanism (before: its own queueing); the page that polls a claim runs a waiting job through the shared helper.
* App record release retries along the shared backoff (before: its own list of delays).

= 0.30.0 =
* "Use Beltoft DNS": the switch of the name servers is checked at once and then done by a job (zone check, name servers, DS keys, the stored state), with the usual retries. The page shows that it is working and then the outcome. A registrar that answers late, or fails after the name servers were set, leaves nothing half stored: the page already shows our name servers while only the DS is missing, and the retry finishes it.
* Show transfer code: the emailed one-time code is used up only after the registrar has returned the code, and the re-lock clock restarts only then. A registrar that fails costs the customer no new code ("Your code is still valid, try again in a minute").
* New mailbox and new mailbox password: checked at once, then done by a job. The password is made at once and kept encrypted until the job has set it on the mail server (never in a job argument); it can be shown once when the job says done. A mailbox that the mail server already made for an earlier try of the job counts as made, one that was there before is refused and never touched, a mailbox that cannot be finished is removed again and the removal is checked (staff are mailed when it fails). The mail routes answer 202 with queued, the action and how to read the outcome (before: 201 or 200 with the result); GET mail has a `task` (working, last_result).
* Zone file import, Add these records: the previewed sets are stored and one job writes them one set at a time through the zone owner, keeping its place, so a stopped job goes on where it was. The report (added, replaced, not added with the reason) is the outcome shown on the page. The route answers 202 (before: 200 with the result).
* Mail "Check DNS now": the look publishes records into the customer's zone and changes the sending hold, so it is a job; the route answers 202. Every record is stored as it is published, also for the background look.
* The MCP tools create_mailbox, reset_mailbox_password and email_dns_check say that the change is queued and that email_domain_info shows the task. OpenAPI: the mailbox and DNS check routes answer 202.
* Shared action controller: `Jobs::start_action()` (busy check, preparation, marker, job) is the one way a route starts an action; a driver may give the customer its own sentence for an outcome (`Jobs::TEXT` in the result meta), and a refusal with a sentence is not mailed to staff; `StepUp::verify()` can keep a right code until `StepUp::consume()`; `Routes::queued()` is the 202 answer; the browser helper `beltoftApi.follow()` follows a queued action to its outcome.

= 0.29.1 =
* Fix: the guest memory history no longer misses a 5-minute point for a server a customer had open; the background read skips a server only when the current slot already has a reading.
* Fix: the monitoring chart shows numbers with the decimal sign of the page language, and memory in GB with one decimal.
* A saved card cannot be deleted while it is still needed. A card is needed when the customer has no other usable saved card and there is an unpaid order above 0, an active or on-hold subscription with a price that pays with the card, or a pay as you go account with a running service or unbilled usage that reaches the usage minimum. The Delete button is replaced by "In use" with the reason in words, and a crafted delete link is refused before WooCommerce deletes the card. Deleting one of two cards stays allowed. The filter `beltoft_services_card_delete_needed` can change the reason or allow the deletion.

= 0.29.0 =
* Hosting changes run in the background. Adding or removing a website, a PHP version, PHP workers, the cache purge, the country block, an address (added, removed, main address) and the SFTP password and SSH keys are checked at once, then done by a job: one step at a time, so a slow agent never cuts a change off. A new website shows at once as "Being set up" with its step (making the database, making the website, installing WordPress, setting up the address). A website is only removed again after the agent says it really failed, never because it was slow; the clean-up runs as steps too.
* One change of a hosting account at a time: a second change is refused with "Wait until ... is done" and its buttons are greyed. A change that nothing works on for 15 minutes ends as failed, staff are mailed once, and a half-made website is cleaned up. A change whose job was lost (a crash or a deploy) is queued again once and goes on where it stopped.
* The hosting routes that make a change answer 202 with status pending and the task (before: 200 or 201 with the result); GET hosting returns the task of the account and of each website, and the last failure. The MCP tools for these changes say that the change was queued and where to read it (hosting_info).
* The 60 second wait of the WordPress install in 0.27.11 is gone.
* Backup list: sizes in GB with one decimal, the column is "Data in backup", and one line says what a backup holds and that it does not use the disk.
* Shared action controller (servers, databases, hosting): a customer action that no job works on for 15 minutes now ends with a failed result and one staff mail (before: the marker lapsed silently); new filter `beltoft_services_action_resumable` and action `beltoft_services_action_failed`.

= 0.28.2 =
* The hover line round rows and tiles is a soft grey instead of black.

= 0.28.1 =
* Server list: the memory bar shows the percent only (the GB figures are in the server's monitoring); all bars share one set of columns, so they start at the same place and have the same length, also on a phone and in the Overview.
* One hover for rows and tiles that open something: a thin line round the row, following the card's corners.

= 0.28.0 =
* Guest memory: the server list and Overview always show a memory bar for a running server (the newest reading at once, "..." while the first one is on its way, "-" and a hint when the guest agent does not answer). Under the bar: used and total GB and how old the reading is. The figure is the memory in use without the file cache, as the guest reports it; no Proxmox figure is shown.
* Readings every 10 seconds while a page with servers is open (once a minute after 10 minutes without a page interaction); a background job reads every running server every 5 minutes.
* New table bs_guest_memory (database version 8): used and total MB per server, 5-minute points kept 2 days, then hourly average and peak kept 30 days; removed with the server.
* The monitoring tab has a memory graph in GB with the total as a line and the peak; the metrics route, the MCP tool server_usage and the API reference return it.
* Memory email: one email per episode when memory in use stays at or above the threshold (settings Memory alert threshold, default 90 percent, and Memory alert after this many minutes, default 15) for 3 readings in a row; not for database servers, not in the first 15 minutes after a start or reinstall.
= 0.27.11 =
* Adding a website: the WordPress install step waits up to 60 seconds (an install takes about 12); other steps keep 8. Before, the install was cut off at 8 seconds and the new website was removed again.

= 0.27.10 =
* The memory and disk bars of the server list are always printed and stay hidden until a guest reading exists, so a reading that arrives after the page load shows (they were missing when the first render had no cached reading).
* The MaxMind GeoLite2 attribution on the country block is translatable.

= 0.27.9 =
* No memory figure from Proxmox is shown anywhere: its reading includes the guest's file cache. The server list, the API and the MCP tools show the memory use the server itself reports (through the guest agent), and no figure when there is none. The memory graph is removed; the hosting usage route no longer returns memory_mb.

= 0.27.8 =
* Dashboard redesign, part 4: server tabs share one card, spacing and heading style, with shared buttons; database, domain and mail pages use the same cards and headings; Kredit balances in one card, top-up tiles, history amounts on one line.

= 0.27.7 =
* Account credit is never called a gift card to a customer: the API note on usage invoices and the credit page notes say credit.
* While account credit is on, the gift card plugin's My Account page (gift-cards) sends the visitor to the Credit page, and the add-on (0.3.1 or newer) words the plugin's cart and checkout texts as credit.

= 0.27.6 =
* The example domain in empty fields (domain search, mail and SSL checkout fields, DNS claim, app address) is translatable, so a language can show its own example.

= 0.27.5 =
* Country block: a new ready list, everything outside Europe (EU/EEA, Switzerland, UK), picks login and admin only when the website has no saved block. The high-risk for logins list drops Singapore and adds Russia, Ukraine, Brazil, Mexico, Argentina, Colombia and Bangladesh.

= 0.27.4 =
* Jobs are held while the file wp-content/.beltoft-services-hold exists (younger than 15 minutes): a deploy writes it while it replaces code. No site code is needed for the hold.

= 0.27.3 =
* The job runner watches for a fatal error in the job it runs (as Action Scheduler's own runner does), and the cleaner's timeout of a job that never finished is handled too: a customer action always gets a result, staff get one mail per error.
* A crashed build or reconcile job also mails staff (once per error).
* New filter `beltoft_services_jobs_hold`: holds every service job (queued again shortly) while a site replaces its code. The kill switch and the hold are read fresh by a long-running runner.

= 0.27.2 =
* A job that crashes (an error, or its PHP process ending, as during a deploy) no longer leaves the customer's page waiting: a customer action gets a failed result and staff are told; a build job is queued again in a minute.
* The job runner (`wp beltoft-services run-due`) ends between two jobs on a stop signal, so a service manager can stop or restart it without cutting a job.
* The status note under a button keeps its room above it in every panel.

= 0.27.1 =
* The "Open your server" link on the order page opens the server's overview, not its Disks tab; an app licence opens its server's App tab.

= 0.27.0 =
* A customer can have up to five private networks, free. The Network tab asks which network a server joins, or names a new one. Servers and databases keep working on the first network.

= 0.26.7 =
* A server whose guest agent did not answer at the end of the build is asked again every two minutes for the first hour (was hourly), so Reset password and the agent features appear minutes after the build.

= 0.26.6 =
* A new server can attach the private network before its guest agent was seen; the attach job asks the agent itself.
* Before attaching, the Network tab shows the customer's network and the servers on it, which Attach joins.
* The note under Attach and Detach has room above it; on a phone the server list shows one usage bar per line.

= 0.26.5 =
* The "Go to your dashboard" button on the order page has the rounded corners and height of the account buttons.

= 0.26.4 =
* One loader per click: a button with a note under it is only disabled while the note shows the work. Deleting a server asks for the name its page shows. Private network panel: label and value rows, the command on one line, room above the buttons, Detach outlined.

= 0.26.3 =
* My Account menu counts: Account\\MenuCounts gives the number of active or paused services per service tab for the theme (filter beltoft_services_account_menu_counts). SSL cancel is a danger block too; no script uses a browser prompt. The hosting overview no longer shows PHP workers.

= 0.26.2 =
* Removing a website has the same danger block as deleting a server: folded, what is deleted, the typed name and a red button (no browser prompt).

= 0.26.1 =
* Service emails build their values, the details table among them, in the language of the order the service came from (the table of "Your service is ready" was English in a Danish email).

= 0.26.0 =
* Invoice text kept simple: each order line keeps one line of text, the product's name with its main choice in the customer's language (meta _beltoft_services_invoice_text); usage lines keep their hours and price per hour. Removed again: the kept options, the product title meta, the period of a line (Billing\\LinePeriod) and the usage note line.

= 0.25.7 =
* The filter beltoft_services_line_title also gets the product, and usage lines use it too, so a usage line names the product as the order line does.

= 0.25.6 =
* Usage lines: the title alone on the first line, the month with the hours and rate on the second. Order lines keep the product name in the order's language (meta _beltoft_services_line_title, filter beltoft_services_line_title). The domain label of a line is in the order's language.

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
