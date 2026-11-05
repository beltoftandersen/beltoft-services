# Hosting driver on the real test node (2026-11-05)

Plugin on staging (staging.beltoft.net) against the disposable nested Proxmox VE 9.2 node at 10.10.10.20 (agent with
mutual TLS, template 9002, db-shared CT 101, edge CT 100). Staging DNS stays on FakeDns, mail on fakes, the node is the
only live system. Nothing else was contacted.

## Setup

- `define( 'BELTOFT_SERVICES_HOSTING_LIVE', true );` in the staging wp-config (backup in `private/staging.beltoft.net/wp-config.php.bak-hosting-live`). The site tag was already set; the VPS driver's settings were not touched.
- Node record `test1` (group `test`): API https://10.10.10.20:8006 with user `svc-hosting@pve`, token `wp` (privilege separated), role
  `HostingSvc` (VM.Allocate, VM.Clone, VM.Audit, VM.PowerMgmt, VM.Backup, VM.Snapshot, VM.Snapshot.Rollback, VM.Config.*, VM.Console,
  Datastore.AllocateSpace, Datastore.Audit, Pool.Allocate, SDN.Use, Sys.Audit, Sys.Modify) on `/pool/hosting-pool`, `/storage/hostingpool`,
  `/storage/local`, `/vms/9002`, `/sdn/zones/localnetwork`, `/nodes/hosting-node-test`, given to the user AND the token (a privilege separated token
  has the intersection of both). Pin of the API certificate's public key. Agent https://10.10.10.20:8765, tenant `test-node`, pinned, client
  certificate from the agent's test CA, HMAC key from the agent config. Pool `hosting-pool`, storage `hostingpool`, bridge `hbr0`, gateway
  10.200.0.1, `10.200.0.0/16` from host 10, template 9002, vmids from 1100, db host 10.200.0.3, edge 10.200.0.2, backup storage `local` (a
  directory storage, no PBS), safety `vzdump`.
- On the node: pool `hosting-pool`, the role, user and token above, `10.10.10.2` added to the agent's `allowed_ips` (the staging server's address).
- Preview zone `preview.hosting-test.example` in the FakeDns of staging. Plans: the variations of product 1070 got `node_group = test`.
- Orders are made with a script (completed order plus an active subscription for the test customer, site details on the line like the
  checkout field does); the reconciler is driven with `Jobs::run`.

## What ran end to end (all verified on the node)

| Step | Result |
|---|---|
| `hosting node check` | passes (needs the polling fix below) |
| Provision Start (1071) and Shop (1075, WooCommerce) | ready in about 1 minute each; CT in the pool, firewall group, SFTP-only for Start, shell for Shop, WordPress and WooCommerce installed |
| Site through the edge | HTTP 200 with the Host header from the node, on-demand certificate from the edge's internal issuer |
| PHP 8.3 to 8.4 and 8.5, cache purge, workers, one-time login link | ok |
| Add a site (Shop), add an external domain, remove a site | ok after the database fix |
| Backup now | vzdump zstd into the directory storage, listed with size |
| Restore: one site's database, one site's files, the whole account, each with Undo | ok after the restore fixes (restore of the database and files go through the agent and its vzdump shim, the account through the node with a protected safety vzdump) |
| Upgrade Start to Pro | cores, memory, disk grown, SSH enabled for `web`, dump mount grown |
| Pause and resume | CT stopped and the edge shows 503; started again and 200 |
| Delete | edge routes, DNS, databases, backups, container and volumes gone; the child Email lite resource deleted |

## Bugs found and fixed (each with a test)

1. The container had no firewall rules: with `policy_in` and `policy_out` DROP the edge could not reach nginx (502). It now joins the node's security group (node field `fw_group`, default `hosting-ct`) and drops outbound.
2. The dump mount was `/mnt/dump`; the agent and the template use `/var/backups/db`.
3. vzdump ran uncompressed (1 GB); now `compress=zstd`.
4. Backups on a directory storage (`local:backup/vzdump-lxc-<vmid>-<date>.tar.zst`) were not listed: only the Backup Server volume format matched.
5. A customer action returned "busy" for a queued job; the actions now wait up to 8 seconds (filter `beltoft_services_hosting_action_wait`), and `node check` polls the job.
6. The same submit sent twice in one second has the same signature: the agent answers 409 "already received". The live client now reads the job instead.
7. Adding a website after provisioning never created its database (the agent refuses the install). Fixed, and a failed add leaves no half-made site. FakeAgent now models both.
8. A container id that was used by a deleted account kept its old backups, which the new account could list and restore (data leak). The listing now requires the notes to name the account.
9. The account restore used the node's default storage (`local`), which holds no container disks: HTTP 400. It now names storage and pool. A failed account restore starts the container again (it was left stopped).
10. The dump mount was never grown with the plan.
11. The delete listed the backups after the container was gone; the node hides them from a narrow token then. The purge now comes first.

## Open

- Agent: the agent keeps its `cts`, `accounts` and `sites` rows after a container is deleted, so a reused vmid fails `ct.prepare` ("container belongs to another account"). The plugin reuses vmids. Needs an agent release on delete (or the plugin could stop reusing vmids). On the test node the rows were deleted by hand.
- Agent: `backup.restore_test` is not implemented (unknown_op); the weekly restore test stays off (node field `scratch_vmid` 0).
- The clone carries `features: nesting=1` from the template; the plan says nesting off. Decide whether the template needs it.
- The mail child of a hosting account (Email lite) was only exercised on fakes.
- Real PBS, ACME, public address and IPv6 are not covered by this node.
