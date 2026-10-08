# Laravel Stripe billing verification demo

This is a self-directed working demonstration in a synthetic Laravel application. The initial paid-subscription path was verified once in Stripe test mode (S01): a US$10 invoice was paid, the signed `invoice.payment_succeeded` webhook was accepted, and the app recorded the paid period and reported access through its expiry. See `evidence/SCENARIOS.md` for actions, observed results and limits. Hosted Checkout cancellation (S02) is verified; S03–S10 have bounded local checks, with actual recurring Stripe lifecycle and replay results still pending. This is not a client case study or evidence of production readiness.

## Access policy

- A confirmed positive subscription invoice payment grants access until the paid service period ends. The expiry instant itself is excluded.
- A later paid renewal can extend the entitlement. A failed renewal cannot extend it; there is no extra grace period.
- Period-end cancellation preserves access only through the already paid period.
- Incomplete or uncertain checkout does not grant access. Returning from Stripe Checkout does not by itself change entitlement.

Renewal, failed payment, and cancellation lifecycle runs remain **Not run**. Refunds, disputes, immediate cancellation, trials, plan changes, and zero-amount invoices are outside this slice.

## Design choice

Cashier 16.8.0 owns Stripe Checkout and subscription record syncing. The application extends its documented webhook controller on `/stripe/webhook` with mandatory signature validation, durable receipts and serialised processing. Subscription events retrieve current Stripe state before invoking Cashier syncing. A synchronous `WebhookHandled` listener records the paid service period from a matching `invoice.payment_succeeded` subscription line. The invoice's overall `period_end` is deliberately not used for entitlement. Local access reads the maximum recorded paid-period end, independently of Cashier's `subscribed()` status, because that status can remain true during a cancellation period and is not proof of payment. This is smaller than replacing Cashier with a direct Stripe SDK handler, while making the payment-to-access rule inspectable.

The invoice listener records positive test-mode payments. The controller retains failed processing attempts and serialises concurrent events; subscription event syncing uses current Stripe state. Reconciliation is read-only by default, with repair restricted to disposable local testing MySQL. These features have local fixture evidence; complete Stripe lifecycle proof remains pending. A duplicate paid invoice is constrained by its unique invoice ID, but exactly-once processing is **not** claimed.

## Versions and sources

- Laravel framework 13.34.0; PHP target 8.4; Cashier 16.8.0; Stripe PHP SDK 21.3.2. Exact dependency versions are in `composer.lock`.
- Local runtime: Laravel Sail 1.68.0, PHP 8.4 and MySQL 8.4. The historical S01 run used the previous SQLite runtime; it has not been repeated on MySQL.
- Official guidance checked 5 October 2026: [Laravel 13 release notes](https://laravel.com/docs/13.x/releases), [Cashier billing](https://laravel.com/docs/13.x/billing), [Stripe invoice object](https://docs.stripe.com/api/invoices/object), [Stripe invoice line object](https://docs.stripe.com/api/invoice-line-item/object), and [Stripe test clocks](https://docs.stripe.com/api/test_clocks).
- The installed Cashier 16.8.0 / Stripe SDK 21.3.2 resolves its runtime API version to `2026-08-26.dahlia`; the hosted snapshot endpoint uses that version. S01 verified one actual test-mode invoice line: one monthly plan line with quantity 1 and a service period that matches the app record to the date; the stored expiry includes the exact timestamp. See `evidence/SCENARIOS.md`; this single run does not verify other invoice shapes or subscription lifecycle scenarios.

## Local setup

Prerequisites: Docker Desktop with a working Linux engine, Composer, a Stripe test-mode account, one recurring test price, and Stripe CLI. The Sail shell wrapper on Windows requires WSL2; the equivalent Docker Compose commands below work from PowerShell. See [Sail documentation](https://laravel.com/framework/docs/sail).

Run these commands from this repository, after starting and migrating the main website against the shared MySQL database:

1. Run `composer install`, then copy `.env.example` to `.env` if it does not already exist. Keep `APP_URL=http://localhost:8081`, `APP_PORT=8081`, `DB_HOST=mysql` and `DB_CONNECTION=mysql`.
2. Run `docker compose up -d --build --wait` to build Sail and start its app and MySQL services.
3. Run `docker compose exec laravel.test php artisan key:generate` only for a new `.env`. Run `docker compose exec laravel.test php artisan migrate` after the main application has migrated the shared users schema.
4. Set the Stripe test keys and recurring price in the ignored `.env`. Run `stripe listen --forward-to http://localhost:8081/stripe/webhook`, put its signing secret in `.env`, then run `docker compose exec laravel.test php artisan config:clear`. Sail mounts the source and `.env`; an app-container recreation is not required for these Laravel settings.
5. Open `http://localhost:8081`, start test Checkout, and pay with an [official Stripe test card](https://docs.stripe.com/testing). The Checkout return stays pending until the signed paid invoice is handled.

Compose project `stripe-billing-demo` owns its network and MySQL volume. App port 8081, optional Vite port 5174 and MySQL host port 3308 bind only to localhost. Main-site ports remain separate. MySQL internally uses port 3306. The session cookie is `stripe_billing_demo_session`.

The demo requires a separate login using the main application account. Main owns the shared users table; this app owns prefixed billing records and a separate session. Public access controls and deployment under the proposed URL prefix remain to verify before hosting.

## Checks and evidence

Run `docker compose exec laravel.test php artisan test --no-ansi --do-not-cache-result`. PHPUnit uses MySQL database `testing`, created by Sail's initialisation script, independently of the app database `billing_demo`. Never point fixture tests at the application database. The signed webhook fixtures do not prove actual Stripe delivery.

Use `docker compose stop` to stop only this demo and `docker compose up -d` to restart it. Database records persist in `stripe-billing-demo_sail-mysql`. `docker compose down` preserves the volume; adding `-v` deletes the demo data and is only appropriate for an intentional reset.

The previous SQLite volume is preserved, but is not mounted by this runtime. Its billing records have not been imported; the new MySQL database starts with only synthetic seeded data. Historical S01 evidence remains scoped to the earlier runtime.

## Proposed hosted deployment

The manual `.github/workflows/deploy.yml` deploys only this repository into `/home6/inceptio/demo.mohsinadeel.dev/laravel-stripe-billing-verification-demo`. The private main repository deploys into the sibling `main/` directory. Both workflows create the symlink `main/public/laravel-stripe-billing-verification-demo -> ../../laravel-stripe-billing-verification-demo/public` when both targets exist. Unexpected existing paths fail the deployment instead of being replaced.

Configure this repository's GitHub `production` environment separately: variables `DEPLOY_PATH` (the demo path above) and `SSH_HOST`; secrets `OPENVPN_CONFIG`, `VPN_USERNAME`, `VPN_PASSWORD`, `SSH_USERNAME`, `SSH_PORT` and `SSH_PRIVATE_KEY`. The workflow retains the main workflow's approved SSH options; it does not verify the server host key.

Before running it, provide a server-only `.env` inside the demo repository directory with:
- `APP_ENV=production`, `APP_DEBUG=false`, a unique `APP_KEY` and `APP_URL=https://demo.mohsinadeel.dev/laravel-stripe-billing-verification-demo`.
- `DB_CONNECTION=mysql` and the hosting account's database host, database name, username and password; do not use the local Docker hostname `mysql` on shared hosting.
- `SESSION_COOKIE=stripe_billing_demo_session`, `SESSION_PATH=/laravel-stripe-billing-verification-demo`, `SESSION_SECURE_COOKIE=true`, `SESSION_DOMAIN=null`.
- Separate Stripe test-mode settings only if needed for an authorised hosted verification. Never configure live payment keys for this working demonstration.

The demo seeder does not create users; create accounts through the main application. Hosted Checkout accepts server test keys only; keep APP_ENV=production. Prices are checked against Stripe before saving and before Checkout. The report displays only the signed-in user's billing state.

Live verification is pending: main `/`, demo prefix with and without a trailing slash, demo `/up`, `/protected`, a missing route, POST webhook signature rejection, generated links and session cookie paths. Requests to demo `/.env`, `/composer.json` and `/vendor/autoload.php` must not expose files. A local Sail check does not validate Apache `.htaccess` or symlink handling.

## Shared database and separate logins — 8 October 2026

This agreement supersedes the earlier standalone database setup. Main and demo use identical MySQL credentials and database name. Main owns unprefixed `users`, `migrations` and Cashier customer columns (`stripe_id`, payment-method fields and trial expiry). The demo's `shared_users` connection ignores the prefix, while its default MySQL connection uses `DB_TABLE_PREFIX=stripe_`. Custom Cashier subscription/item models explicitly use the prefixed connection. The demo neither creates nor drops users or customer columns. User references are indexed IDs; there is currently no cross-connection foreign-key constraint or account-deletion cleanup.

Main server `.env`: `DB_TABLE_PREFIX=` and `DB_MIGRATIONS_TABLE=migrations`. Demo server `.env`: `DB_TABLE_PREFIX=stripe_` and `DB_MIGRATIONS_TABLE=migrations` (resulting table `stripe_migrations`). Both use `SESSION_DRIVER=file`, separate `APP_KEY` values and separate session cookies. Main uses `SESSION_COOKIE=demos_session` and `SESSION_PATH=/`; demo uses `SESSION_COOKIE=stripe_demo_session` and `SESSION_PATH=/laravel-stripe-billing-verification-demo`. Both use HTTPS-only cookies on the server. Local HTTP uses `SESSION_PATH=/` and HTTPS-only cookies disabled.

Deploy main first, then demo. With the previously confirmed disposable database reset, main creates the users schema and demo creates only prefixed tables. Do not change prefixes on populated databases or run `migrate:fresh` against the shared database. Demo rollback leaves shared users and customer fields intact.

For local shared operation, the demo's ignored `.env` connects to the main Sail MySQL service through `DB_HOST=host.docker.internal`, `DB_PORT=3307`, and the main app database credentials. Its old MySQL volume is preserved. The main MySQL service must remain running; the main web container need not remain running.

Create an account through main, then sign in separately to the demo. This initial demo login rejects accounts with two-factor enabled until a corresponding challenge is implemented. Signing out of the demo does not invalidate main's session; changing the shared password affects credentials in both apps. Hosted Checkout now uses test-key and Stripe price validation.

CI is manual-only via workflow_dispatch using Sail/MySQL. Relevant local checks must pass before code changes are pushed. It creates a minimal shared-user fixture only in database `testing`, migrates the demo's prefixed tables, and uses database transactions for test cleanup. It does not call Stripe APIs or claim a new Stripe integration result.
## Saved Stripe price setup

Signed-in users can open Add Stripe setup and save a recurring Stripe test price ID. Values persist in the prefixed demo_settings table (stripe_demo_settings with the current prefix), scoped to the user. Checkout and invoice verification use the saved price, falling back to STRIPE_PRICE_ID when no saved value exists. A price cannot be changed once the user's default subscription exists. The form validates the ID format and retrieves the price with the server test key, requiring an active recurring test price in the same sandbox. API keys and webhook signing secrets remain in the server environment. Hosted test Checkout is authorised and uses the same validation again immediately before creating the session.

The How to test sidebar sits beside the billing report on wide screens and moves below it on smaller screens. Setup validation errors reopen the modal; successful saves return a confirmation message.

## Hosted sandbox Checkout — 8 October 2026

Keep APP_ENV=production and APP_DEBUG=false. Configure STRIPE_KEY (pk_test_), STRIPE_SECRET (sk_test_) and STRIPE_WEBHOOK_SECRET (whsec_) privately on the server. Live keys are refused. A saved or fallback price must be active, recurring and have livemode=false; retrieving it with the server key establishes sandbox ownership. Stripe failures return safe messages rather than raw API exceptions. A subscription record, including an incomplete one, blocks another Checkout: inspect the sandbox subscription before retrying; use a separate synthetic account for S02. Cancellation before subscription creation permits another attempt.

In the same Stripe sandbox as the server keys, open Workbench → Webhooks → Create an event destination. Select Your account, Snapshot payloads and API version **2026-08-26.dahlia**, resolved from installed Cashier 16.8.0 / Stripe SDK 21.3.2. Select:

- customer.subscription.created
- customer.subscription.updated
- customer.subscription.deleted
- customer.updated
- customer.deleted
- payment_method.automatically_updated
- invoice.payment_action_required
- invoice.payment_succeeded
- invoice.payment_failed (needed for failed-renewal observation; no entitlement extension)

Choose Webhook endpoint and enter exactly `https://demo.mohsinadeel.dev/laravel-stripe-billing-verification-demo/stripe/webhook` (no trailing slash). Store that destination's signing secret in the hosted STRIPE_WEBHOOK_SECRET. A local Stripe CLI listener has a different secret. Refresh server configuration with `php artisan config:cache` using the server's PHP 8.4 binary; keep routes uncached (`php artisan route:clear`). The deployment workflow already does this. Never paste secrets into chat, commits or evidence.

S01: sign in with a synthetic account with no subscription or paid period, save its sandbox price, start Checkout, use 4242 4242 4242 4242 with future expiry and any three-digit CVC. Check actual endpoint delivery of invoice.payment_succeeded, the stored invoice/period, and GET /protected. A returned Checkout URL alone creates no paid entitlement. S02 uses a different unpaid account; cancel Checkout or use 4000 0000 0000 9995. Inspect any incomplete subscription before another attempt.

Sources checked 8 October 2026: [price retrieval](https://docs.stripe.com/api/prices/retrieve), [webhook registration and signatures](https://docs.stripe.com/webhooks), [test cards](https://docs.stripe.com/testing), [test clocks](https://docs.stripe.com/billing/testing/test-clocks). Test-clock time does not advance Laravel time; controlled local expiry checks must be recorded separately. Local mocked Stripe API tests are regression evidence, not actual sandbox Checkout proof.

The owner-authorised `stripe:configure-webhook` command provisions the hosted sandbox destination using the server test key and saves its returned secret directly to the existing environment file. It emits safe status messages only. Existing matching endpoints are reused only when their saved endpoint ID and signing-secret entry are present; otherwise the command refuses to replace them. Run only through an explicitly requested setup: deployment input configure_stripe_webhook defaults to false. The release refreshes config afterwards and keeps routes uncached.

## Processing and retry boundary

Signed known-user events now have durable receipt states and attempts. Per-user MySQL GET_LOCK serialises local effects across concurrent deliveries; lock timeout returns 503. Failed processing returns 500 with a safe persisted failure summary; retry processes the event again. Completed duplicates return 200. Invoice effects and completion commit together on the prefixed MySQL connection. Shared users/customer-field updates are outside that transaction; this is not universal exactly-once processing. Unmatched customers return 200 without a receipt; live events are rejected.

For S08 use the dedicated local MySQL fixture test (`--filter=PaidInvoiceWebhookTest`). The deliberate after-processing failure flag is accepted only in local/testing application environments, with no public HTTP toggle; production ignores it. The test rolls back invoice work, retains a failed receipt, retries and checks one completed effect. Actual Stripe retry/resend evidence must be recorded separately.

## Read-only reconciliation (S09)

Run `docker compose exec -T laravel.test php artisan stripe:reconcile USER_ID` to compare all positive paid subscription invoices for that user's Stripe sandbox customer against local paid periods. It retrieves current Stripe subscription ownership/status and paginates invoices/lines; the same invoice-period validator is used by webhooks. Output shows expected/local UTC expiry and a mismatch count. Diagnosis is read-only; a mismatch returns a failing exit code. It does not synchronise Cashier status or delete unexpected periods, and refuses ambiguous invoice lines/ownership conflicts.

Explicit `--repair` is permitted only with APP_ENV local/testing and MySQL database named testing. It repairs missing/incorrect paid-period rows and leaves shared users and webhook receipts alone. It is refused on hosted production, even if invoked through CLI. `--filter=ReconcileStripeBillingTest` demonstrates controlled drift, no diagnosis mutation, repair and stable repeat on disposable MySQL with mocked Stripe responses. No hosted drift was introduced and no Stripe-backed reconciliation result is claimed yet. Refunds, disputes and historical plan changes remain outside this policy.

## Current subscription state and ordering (S10)

For subscription created/updated/deleted events the controller retrieves the latest Stripe subscription under the per-user processing lock, verifies test mode/customer ownership, and passes that current object to Cashier. The event's older snapshot cannot reactivate a cancelled subscription or overwrite an active subscription with an older past_due snapshot. Lookup failures return 500 with a failed receipt for retry. Paid invoice rows remain additive and access uses the latest paid-period end; an older invoice cannot reduce it.

This covers the fixed single-plan demonstration. Actual Stripe out-of-order delivery remains unexecuted. Customer deletion/recreation, concurrent Dashboard changes after a lookup, historical plan changes, refunds/disputes and universal ordering guarantees remain unsupported. Stripe test clocks advance Stripe objects, not Laravel time: renewal/cancellation fixture checks use controlled application time and exact expiry boundaries; they are not test-clock integration evidence.

## Automatic confirmation after subscription Checkout

The pending Checkout-return page polls the authenticated GET /checkout/status endpoint every two seconds for up to 90 seconds. It reloads only when the server confirms a current paid period. Responses are private/no-store and scoped to the signed-in user. Expired sessions stop polling; transient failures retry within the bounded window. No subscription or access record is created by polling. Browser timing/state checks: node --test tests/checkout-status.test.cjs.
