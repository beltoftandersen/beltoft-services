# Beltoft Services for WooCommerce

Sell services with WooCommerce Subscriptions. A license (from Beltoft Licenses) is the entitlement; drivers provision, pause, resume, update and remove the service in Action Scheduler jobs.

Requires WooCommerce, WooCommerce Subscriptions and Beltoft Licenses 0.7.0 or newer, PHP 8.1 and MySQL 5.7.5 or MariaDB 10.0.2 or newer.

## Features

- Services follow the license: active → provisioned or resumed, suspended, revoked or expired → paused, plan changes → updated. Driven only by Beltoft Licenses' actions.
- Every driver call runs in an Action Scheduler job (group `beltoft-services`) under a lock per service, with retries (1 min, 5 min, 15 min, 1 h, 4 h) and admin alerts.
- An HTTP API driver: signed requests, idempotency keys, a JSON body template, usage, and an "Open" sign-in link. More drivers through the `beltoft_services_drivers` filter.
- A Service tab on products, one free trial per customer, a My Account panel, WooCommerce → Services, a Service panel on each license, customer emails.
- A kill switch, and deletion after a retention period (off by default, report only, or on).
- WP-CLI: `wp beltoft-services link-http` and `wp beltoft-services status`.

## Setup

1. Add a secrets key to `wp-config.php`: `define( 'BELTOFT_SERVICES_KEY', '<base64 of 32 random bytes>' );`
2. Settings → Services: the alert address and a connection.
3. On a licensed product, the Service tab: driver, connection and plan.
4. In Beltoft Licenses' settings, turn on "Check expiry every hour".

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

WooCommerce, WooCommerce Subscriptions and the gateways bundled with them fire no dispute or chargeback action (checked against WooCommerce 11.2.1 and Subscriptions 9.3.0: the only mention is a render hook, `woocommerce_admin_order_data_after_payment_info`, that extensions may print a notice in). Beltoft Services therefore does not react to a dispute by itself. When a gateway tells you about one: cancel or put the subscription on hold in WooCommerce Subscriptions (the service pauses), or use "Suspend for breach" on the license screen to hold the service paused whatever billing does. A gateway plugin that does fire a dispute action can be wired up with a few lines on the `beltoft_services_*` API.

## Developer API

- `BeltoftServices\RateLimit::exceeded( int $user_id, string $key, int $limit, int $window = MINUTE_IN_SECONDS ): bool`: counts one call for a user and key in a fixed window; true when over the limit (0 or less turns it off). Persistent object cache when there is one, otherwise transients. Best-effort without a persistent object cache (transient path is read-then-write); the window is fixed, not sliding; namespace `$key` with your plugin slug, e.g. `odoowoo-portal:verify`.

## Development

Tests: `/root/plugin-tests/run.sh /path/to/beltoft-services`

License: GPL-2.0-or-later.
