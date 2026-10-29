=== Beltoft Services for WooCommerce ===
Contributors: beltoft
Tags: services, provisioning, woocommerce, subscriptions, license
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 0.7.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Sell services with WooCommerce Subscriptions. A license is the entitlement, and a driver provisions, pauses, resumes and removes the service.

== Description ==

Built on Beltoft Licenses. The `Requires Plugins` header depends on the plugin folder names `woocommerce`, `woocommerce-subscriptions` and `beltoft-licenses`; a copy installed under another folder name is not recognised by WordPress as the dependency. It needs WooCommerce, WooCommerce Subscriptions and Beltoft Licenses 0.8.0 or newer; if one is missing it shows a notice and does nothing else.

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

== Installation ==

1. Install and activate WooCommerce, WooCommerce Subscriptions and Beltoft Licenses 0.8.0 or newer, then this plugin.
2. Add the secrets key to `wp-config.php` (see the FAQ).
3. Go to Settings → Services, check the alert address, and add a connection under Connections.
4. On a licensed product, open the Service tab, choose the driver and the connection, and fill in the plan.
5. In Beltoft Licenses' settings, turn on "Check expiry every hour".
6. For virtual machines, follow "How do I set up Proxmox?" in the FAQ.

== Frequently Asked Questions ==

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

5. **A product.** On a licensed product, the Service tab: driver "Proxmox virtual machine", the cluster, the template (the fallback when no choice was made), cores, memory and disk. A product is out of stock when the pool has no free address or no node has room; that is written as its WooCommerce stock status.

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

A node counts as full when the memory of the VMs of this site on it (the configured memory of each, including a VM that was recorded but is not listed yet) plus the "memory kept free on each node" setting (default 4 GB) reaches the node's memory. Memory is counted as promised, not as used. VMs this site did not create are not counted, only the reserve covers them: raise the reserve if you run other machines on the same nodes. A product is out of stock when no node has room for its plan (its WooCommerce stock status is set after each run; one you mark out of stock yourself stays so). If the cache is lost, products stay as they are and a run is queued. The numbers are read hourly and when a cluster is saved, never during a page load. If an order slips through while the nodes are full, the service waits and is retried; if it still cannot be placed it fails, the admin gets an alert, and the customer gets a short "delay" email and a notice in My Account without details.

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
* New `wp beltoft-services run-due [--seconds=55] [--interval=3]` runs this plugin's due jobs every few seconds, so a step does not wait for the once-a-minute cron tick. Optional. Put it in cron next to WordPress cron, for example `* * * * * www-data flock -n /run/beltoft-services-runner.lock wp beltoft-services run-due --seconds=55 --path=/path/to/wordpress --quiet`. It honours the kill switch and the locks; one runner at a time.

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
