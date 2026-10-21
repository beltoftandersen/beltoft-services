=== Beltoft Services for WooCommerce ===
Contributors: beltoft
Tags: services, provisioning, woocommerce, subscriptions, license
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Sell services with WooCommerce Subscriptions. A license is the entitlement, and a driver provisions, pauses, resumes and removes the service.

== Description ==

Built on Beltoft Licenses. It needs WooCommerce, WooCommerce Subscriptions and Beltoft Licenses 0.7.0 or newer; if one is missing it shows a notice and does nothing else.

A service product is a licensed product with a service driver. When its license becomes active, the driver creates the service. When the license is suspended, revoked or expires, the service is paused, and when the license is active again, it is resumed. A plan change (a subscription switch or a new site limit) updates the service's plan. Billing, renewals, retries and their emails stay with WooCommerce Subscriptions: this plugin only follows the license.

* **Driven by the license.** It listens only to Beltoft Licenses' actions (`beltoft_licenses_license_created`, `_status_changed`, `_license_updated` and `_expiry_changed`). While a Beltoft Licenses import is running, it does nothing.
* **All work in background jobs.** Every driver call runs in an Action Scheduler job (group `beltoft-services`), never during checkout or a page load. A database lock per service makes sure two job runners never act on the same service at once. A failed call is retried after 1, 5 and 15 minutes, 1 hour and 4 hours; then the service is marked failed and the admin gets an email.
* **An HTTP API driver** that calls your own service with signed requests. Each connection (Settings → Services → Connections) has a base URL, HMAC or static header authentication, an endpoint (method and path) for each operation, a JSON body template, the response field that holds the service's id, optional usage fields, and an optional "Open" link with a short-lived sign-in token. Other plugins can add drivers with the `beltoft_services_drivers` filter.
* **A Service tab on licensed products:** the driver, the connection, the driver's plan fields (for HTTP, a limit and plan attributes; variations can set their own), a retention period, and whether a free trial needs a payment method. A free trial of a service is limited to one per customer (matched by account and billing email), in the classic and the block checkout. Renewals, switches and resubscribes are not affected.
* **My Account:** each service license shows its service's state and usage, the actions the driver offers, and the Open button. Customers only see and act on their own services.
* **Admin:** WooCommerce → Services lists every service with its state. Each license screen gets a Service panel with retry, pause, resume, delete (with a typed confirmation), protect, and "Mark deleted" for a service that was removed by hand.
* **Emails** (WooCommerce → Settings → Emails): service ready, paused, deletion warning, deleted and limit exceeded. Customers are not told about a service they never heard of.
* **Deletion after a retention period** is off by default. It can also report only, or delete.
* **Kill switch:** "Pause all driver activity" stops every driver call at once.
* **WP-CLI:** `wp beltoft-services link-http <license> --remote-id=<id> [--dry-run]` links a license to a service that already exists on the remote side (for a migration), and `wp beltoft-services status <license>` shows its service.

Connection secrets are encrypted with a key from `wp-config.php`, and they are never shown, logged or emailed.

= Hooks =

* Actions: `beltoft_services_state_changed( $resource, $old_state, $new_state )` after each state change; `beltoft_services_deletion_warning( $resource, $deletes_at )` when a customer is warned before a deletion; `beltoft_services_resource_locked( $license_id )` when a service is busy in another request; `beltoft_services_settings_tab_<tab>` renders a settings tab added by a plugin.
* Filters: `beltoft_services_drivers` (add a driver), `beltoft_services_connection` (supply or change an HTTP connection), `beltoft_services_http_unit_label` (the usage line, for example "%1$d of %2$d dashboards"), `beltoft_services_key` (the secrets key), `beltoft_services_settings_tabs`, `_settings_sections` and `_settings_fields`, `beltoft_services_account_css` (turn off the account stylesheet), `beltoft_services_rest_rate_limit`, `beltoft_services_usage_refresh_interval`, `beltoft_services_lock_timeout`, `beltoft_services_desire_lock_wait`, `beltoft_services_dependencies` and `beltoft_services_database_server_info`.

== Installation ==

1. Install and activate WooCommerce, WooCommerce Subscriptions and Beltoft Licenses 0.7.0 or newer, then this plugin.
2. Add the secrets key to `wp-config.php` (see the FAQ).
3. Go to Settings → Services, check the alert address, and add a connection under Connections.
4. On a licensed product, open the Service tab, choose the driver and the connection, and fill in the plan.
5. In Beltoft Licenses' settings, turn on "Check expiry every hour".

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

= Does deleting the plugin delete data? =

The settings, connections and email settings are deleted only if "Delete the settings when the plugin is deleted" is ticked. Uninstalling never deletes a service on the remote side.

== Changelog ==

= 0.1.0 =
* First version: services that follow Beltoft Licenses, run in Action Scheduler jobs with a lock per service, retries with backoff and admin alerts.
* HTTP API driver: signed requests (HMAC-SHA256), idempotency keys, connections with encrypted secrets, usage and an Open link.
* Service tab on products, one free trial per customer, and a My Account panel with status, actions and Open.
* WooCommerce → Services, a Service panel on each license, and customer emails.
* Kill switch, and deletion after a retention period (off, report only or on).
* WP-CLI: `link-http` and `status`.
