# Beltoft Services for WooCommerce

Sell services with WooCommerce Subscriptions. A license (from Beltoft Licenses) is the entitlement; drivers provision, pause, resume, update and remove the service in Action Scheduler jobs.

Requires WooCommerce, WooCommerce Subscriptions and Beltoft Licenses 0.8.0 or newer, PHP 8.1 and MySQL 5.7.5 or MariaDB 10.0.2 or newer (more than WordPress itself needs: the plugin nests named locks, which older servers release early).

## Features

- Services follow the license: active → provisioned or resumed, suspended, revoked or expired → paused, plan changes → updated. Driven only by Beltoft Licenses' actions.
- Every driver call runs in an Action Scheduler job (group `beltoft-services`) under a lock per service, with retries (1 min, 5 min, 15 min, 1 h, 4 h) and admin alerts.
- An HTTP API driver: signed requests, idempotency keys, a JSON body template, usage, and an "Open" sign-in link. More drivers through the `beltoft_services_drivers` filter.
- A Service tab on products, one free trial per customer, a My Account panel, WooCommerce → Services, a Service panel on each license, customer emails.
- A kill switch, and deletion after a retention period (off by default, report only, or on).
- A Proxmox VE driver (0.2.0): virtual machines cloned from templates, with an IP pool, SSH key or one-time password, an operating system choice, capacity limits, customer actions and abuse, breach and deletion modes. A built-in fake cluster is used until you switch a cluster live.
- Domain registration, transfer and DNS hosting drivers (0.4.0), with a public search, one domain per subscription, customer DNS records and name servers, DNSSEC, paid renewals and daily health checks.
- VPS monitoring (0.5.0): owner-only usage graphs, cached root disk usage, and optional customer emails for unexpected downtime or a nearly full disk.
- Extra disks (0.6.0): one variable subscription variation per size, with one license and resource per disk. Disks attach to a managed VPS without a restart, can grow, and are excluded from daily backups.
- WP-CLI: `wp beltoft-services link-http`, `link-vm`, `vm-metrics`, `ip-pool-add`, `ip-pool-block`, `ip-pool-release`, `ip-pool-list`, `status` and `run-due`.

## Faster steps (optional runner)

Waits for something that is still running are polls, not errors: the next look is about 5 seconds away and the admin panel shows "Waiting: ...". Action Scheduler is normally started once a minute by cron, so a step that is 5 seconds away would still wait for the next tick. `wp beltoft-services run-due` runs this plugin's due jobs (reconcile and customer actions only) every 3 seconds for 55 seconds. Add one cron line next to the WordPress cron line:

```
* * * * * www-data flock -n /run/lock/beltoft-services-runner.lock wp beltoft-services run-due --seconds=55 --path=/path/to/wordpress --quiet
```

It honours the kill switch and the per-service lock; the `flock -n` in the line is required, it keeps a slow minute from stacking a second runner. No job is started after `--seconds`, so a run ends within that plus one run budget. Without it everything still works, only slower. One run lasts at most 45 seconds (10 in a web request); filter `beltoft_services_run_budget`.

## Setup

1. Add a secrets key to `wp-config.php`: `define( 'BELTOFT_SERVICES_KEY', '<base64 of 32 random bytes>' );`
2. Settings → Services: the alert address and a connection.
3. On a licensed product, the Service tab: driver, connection and plan.
4. In Beltoft Licenses' settings, turn on "Check expiry every hour".

## Domains and DNS hosting

1. In Settings → Services → Domains, enter at least two authoritative name servers, the registrar account, and the DNS API key. The DNS API accepts only a loopback HTTP URL. Start in `fake` mode; `sandbox` uses Openprovider's sandbox and keeps DNS fake. Live registrar and DNS access require `define( 'BELTOFT_SERVICES_DOMAINS_LIVE', true );` in `wp-config.php` and an appropriate API mode.
   On this VM, set Primary DNS query address to `10.10.10.2`: the public address of `ns1.beltoft.net` does not hairpin from the VM. Other installations can leave it blank if the public address is reachable locally.
2. Run `wp beltoft-services domain-prices` to load EUR costs and apply the markup, minimum margin, and any per-TLD price overrides. The table is also refreshed daily. A TLD without a usable price is not sold.
3. Create a yearly WooCommerce Subscriptions product with the `domain` service driver. Create another yearly product with the `dns` driver for DNS hosting; its price is the product price and can be zero. Put `[beltoft_domain_search]` on the sales page. Each domain goes on its own cart line and subscription.
4. Registration and transfer create a resource from the paid license. The registrar's automatic renewal stays off. A paid subscription renewal is checked against its renewal order before the registry is asked to renew. DNS hosting never changes the registrar and leaves the zone served during a payment hold.
5. When a zone is removed, PowerDNS autosecondary keeps its copy on ns2. The resource waits for staff to remove that copy with `pdnsutil delete-zone <domain>` on ns2, then retries. The DNS runbook has the removal order and recovery steps.

`wp beltoft-services link-domain <license-id> <domain> --registrar-id=<id>` adopts an existing registration without buying it. Add `--dns-only` instead for an existing DNS zone. `wp beltoft-services domain-balance` reads the registrar funds and `wp beltoft-services dns-check` checks the configured authoritative servers. The DNS check reports missing or orphan zones, SOA serial drift and near-expiry RRSIG records. Keep the live constant off during setup and use a fake license for checkout tests.

.dk registration and transfers stay unavailable until the separate Punktum dk checkout agreement has been reviewed and `BELTOFT_SERVICES_DK_AGREEMENT_APPROVED` is explicitly set to `true`. The prepared classic checkout flow displays the applicant and requested names, requires a checkbox for each name, and records the acceptance time and terms version on the order. Store API checkout refuses .dk orders because it does not display that agreement. This constant is independent of the live registrar/DNS constant.

## Proxmox VE

**Proxmox is production: nothing talks to a real one until you enable it.** Clusters are "fake" (a built-in model of the API) until `define( 'BELTOFT_SERVICES_PROXMOX_LIVE', true );` is in `wp-config.php`, the site tag is set, and the cluster's client is set to "live". No filter or setting replaces the constant. A stored live cluster with no site tag is left alone (jobs wait, one alert a day), never faked.

### Setup

1. A dedicated pool and a privilege-separated token. Grant the same role to the user and the token (a privsep token gets the intersection), on the pool, the templates, the storages, the bridge's SDN zone and the nodes. Privilege names are from the PVE 9.2 docs; check them against your version.

   ```
   pveum pool add beltoft-customers
   pveum role add BeltoftServices --privs "VM.Allocate VM.Clone VM.Audit VM.PowerMgmt VM.Config.CPU VM.Config.Memory VM.Config.Disk VM.Config.Network VM.Config.Cloudinit VM.Config.Options VM.Config.HWType VM.Config.CDROM VM.GuestAgent.Audit VM.GuestAgent.FileWrite VM.GuestAgent.Unrestricted Pool.Allocate Pool.Audit Sys.Audit VM.Backup"
   pveum user add svc@pve
   pveum user token add svc@pve beltoft --privsep 1
   pveum acl modify /pool/beltoft-customers --users svc@pve --tokens 'svc@pve!beltoft' --roles BeltoftServices
   pveum acl modify /vms/<template id> --users svc@pve --tokens 'svc@pve!beltoft' --roles BeltoftServices
   pveum acl modify /storage/<storage id> --users svc@pve --tokens 'svc@pve!beltoft' --roles PVEDatastoreUser
   pveum acl modify /storage/<backup storage> --users svc@pve --tokens 'svc@pve!beltoft' --roles PVEDatastoreUser   # only when you sell backups
   pveum acl modify /sdn/zones/localnetwork --users svc@pve --tokens 'svc@pve!beltoft' --roles PVESDNUser
   pveum acl modify /nodes/<node> --users svc@pve --tokens 'svc@pve!beltoft' --roles BeltoftServices
   ```

2. Settings → Services → Proxmox: the site tag (written into every VM's description as `beltoft-services:<tag>:<service id>`; only tagged VMs in the customer VMID range that are recorded for the service are ever changed or deleted).
3. Settings → Services → Proxmox clusters: API URL, token, nodes, storages, bridge, pool, boot disk, templates (`VMID = label`, one default), VMID range, nameservers, prefix, gateway.
4. Settings → Services → IP pool, or `wp beltoft-services ip-pool-add 192.0.2.160/29`. IPv4 only in 0.2.0.
5. A licensed product, Service tab: driver Proxmox, cluster, template, cores, memory, disk.

### TLS

Verified by default. For a self-signed or private-CA certificate give the **CA file** (absolute path) or a **SHA-256 public key pin** (`openssl x509 -in pve-ssl.pem -pubkey -noout | openssl pkey -pubin -outform der | openssl dgst -sha256 -binary | openssl enc -base64`; needs PHP curl pinning, and a request is refused, and its answer discarded, if it cannot be applied). With a CA file the full chain and host name verification stays on (and the pin is added if set); the certificate must name the host used in the API URL. With a pin and no CA file the pin replaces the chain and host name check for that connection, so a self-signed certificate works and only the key is checked.

### Operating system

Each cluster lists its templates and a default (for example Ubuntu 26.04). A required "Operating system" select at checkout (classic and block) and again on reinstall chooses one; a cart with several clusters accepts only templates all of them list. The product's template is the fallback.

### Network

`ip=<address>/<cluster prefix>,gw=<cluster gateway>`. Routed set-up: prefix 32 and the host's address as the gateway, which need not be inside the pool (pool `192.0.2.160/29`, gateway `198.51.100.1`). A gateway on a pool row must be inside that row's network.

### Capacity

A node is full when the configured memory of this site's VMs on it (including recorded VMs not yet listed) plus the host reserve ("Memory kept free on each node", default 4 GB) reaches the node's memory. Other machines on the node are covered only by the reserve. Counted hourly, on cluster save and on activation; products show as out of stock when nothing fits (the plugin writes the WooCommerce stock status, only when it changes, and never reopens a product you set out of stock by hand; with no cache the stored status stands and a run is queued). An order that slips through retries, then fails with an admin alert and a "delay" email and notice to the customer.

### Usage graphs and alerts

The customer's VPS panel in My Account shows CPU, memory, network and disk activity for the last hour, day or 30 days. These are read-only VM usage samples, cached for one minute per server and range. When the guest agent reports its root filesystem, the panel also shows used and total disk space; without a reading it shows the plan's disk size. Graphs are included with the VPS product.

Settings → Services → Proxmox has **Customer monitoring emails** (off by default) and a disk-used threshold (90% by default). When enabled, a server that stays stopped unexpectedly for about 30 minutes or whose root filesystem reaches the threshold sends one email per problem. A customer shutdown does not trigger a down email. The disk alert rearms after usage falls five percentage points below the threshold. The existing power sweep reads down state every 15 minutes; a separate disk sweep reads running VMs every six hours. `wp beltoft-services vm-metrics <license-id> [--range=hour|day|month] [--disk]` prints the shaped readings; `--disk` explicitly asks the guest agent.

### Extra disks

Create a licensed **variable subscription** product with the `proxmox-disk` driver. Set `disk_gb` and the recurring price on each variation. A customer can choose one disk alongside a new server at classic checkout, or add disks to a ready server in My Account. Each disk has its own subscription, license, and resource. The default limit is four extra disks per server; it can be changed under Settings → Services → Proxmox.

The hourly capacity job reads each node's configured disk storage. Disk purchases require a recent storage reading, the configured free-space reserve (200 GB by default), and room for the full promised capacity (100% by default). A disk is attached as a separate SCSI device and excluded from the server's nightly backups. The customer must find the device in the guest, create a filesystem, mount it, and grow the filesystem after a disk-size upgrade. A disk can grow through a subscription switch; it cannot shrink. Reinstall and restore are unavailable while extra disks remain attached.

Cancellation follows the product's retention period (three days by default). The disk stays attached while deletion mode is off or report-only. With deletion mode on, the retention job detaches and removes only the volume recorded for that disk. The live allocation, hot-plug, resize, and detach behavior must be verified on a disposable VM before selling the product on a live cluster. The token needs `VM.Config.Disk` on customer VMs and `Datastore.AllocateSpace` on the disk storage, in addition to the existing server rights.

`wp beltoft-services link-disk <disk-license-id> <server-license-id> <scsiN> --dry-run` previews adoption of an existing disk. Without `--dry-run`, it reads and verifies the VM and creates a protected, linked resource; it never changes the VM. A live cluster also requires `--confirm=LINK-DISK:<scsiN>` and the per-step authorization in the internal runbook.

### Deletion, abuse and breach

- Deletion: off (default), report only, or on; a VM product defaults to 3 days retention, cancelled subscriptions follow it.
- Abuse (sustained high CPU): off, report only (default) or pause. A linked VM is never paused by the abuse check (it is only reported).
- Suspend for breach: holds the VM paused (no customer start, renewal resume or reinstall), deleted after 14 days by default when deletion is on; the license keeps a breach mark, cleared only by "Create the service" with `CLEAR BREACH`.
- Disputes and chargebacks are manual (see below).

### link-vm

`wp beltoft-services link-vm <license-id> --cluster=<id> --node=<node> --vmid=<vmid> --ip=<ip> [--outside-pool] [--dry-run] [--confirm=LINK:<vmid>]` records an existing VM as the license's ready, protected service and adds the ownership tag. It starts, stops, resizes and reconfigures nothing. Afterwards a linked VM is never reinstalled and never resized by a plan change (the admin is alerted to do it by hand), but it IS paused like any other VM when its license lapses (shut down, then stopped), and its customer can start, shut down and reboot it. `--outside-pool` adopts a machine on another network whose address is not in the pool. `--dry-run` prints the calls and makes none; a live cluster needs `--confirm=LINK:<vmid>`.

## HTTP signature

Headers `X-Beltoft-Timestamp` (unix seconds), `X-Beltoft-Tenant` (the service's UUID) and `X-Beltoft-Signature`: the hex HMAC-SHA256, keyed with the connection secret, of

```
METHOD\npath?query\ntimestamp\ntenant\nsha256_hex(body)
```

Every request also carries `Idempotency-Key: <tenant>:<operation>:<sequence>`, the same on retries.

Answers: 2xx is done (provision needs the id in the remote id field); 409 on provision with an id is done; 404 on delete is done; 408, 429 and 5xx are retried, honouring `Retry-After` (never longer than 4 hours); any other 4xx is final. See `readme.txt` for the details.

## Proxmox deletion notes

- A delete never removes a VM that is not provably ours (customer-range VMID, recorded for this service, tag line in the description).
- A half-built service (the clone never finished) whose recorded VMID now holds a foreign VM releases only our IP address and our record; the foreign VM is not touched.
- A service whose VM record is missing although its clone was recorded fails for an admin ("VM record missing; check Proxmox manually") and releases nothing.

## Disputes and chargebacks (manual)

WooCommerce, WooCommerce Subscriptions and the gateways bundled with them fire no dispute or chargeback action (checked against WooCommerce 11.2.1 and Subscriptions 9.3.0: the only mention is a render hook, `woocommerce_admin_order_data_after_payment_info`, that extensions may print a notice in). Beltoft Services therefore does not react to a dispute by itself. When a gateway tells you about one: cancel or put the subscription on hold in WooCommerce Subscriptions (the service pauses), or use "Suspend for breach" on the license screen to hold the service paused whatever billing does. The bundled PayPal IPN handler does react to a reversal, but only by setting the order on hold (`class-wc-gateway-paypal-ipn-handler.php:316-320`): the subscription and license follow the normal path and the service is paused as for any on-hold payment, not held for breach. A gateway plugin that does fire a dispute action can be wired up with a few lines on the `beltoft_services_*` API.

## Developer API

- `BeltoftServices\RateLimit::exceeded( int $user_id, string $key, int $limit, int $window = MINUTE_IN_SECONDS ): bool`: counts one call for a user and key in a fixed window; true when over the limit (0 or less turns it off). Persistent object cache when there is one, otherwise transients. Best-effort without a persistent object cache (transient path is read-then-write); the window is fixed, not sliding; namespace `$key` with your plugin slug, e.g. `odoowoo-portal:verify`.

## Development

Tests: `/root/plugin-tests/run.sh /path/to/beltoft-services`

License: GPL-2.0-or-later.
