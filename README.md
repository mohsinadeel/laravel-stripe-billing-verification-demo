# Laravel Stripe billing verification demo

This is a self-directed working demonstration in a synthetic Laravel application. The initial paid-subscription path was verified once in Stripe test mode (S01): a US$10 invoice was paid, the signed `invoice.payment_succeeded` webhook was accepted, and the app recorded the paid period and reported access through its expiry. See `evidence/SCENARIOS.md` for actions, observed results and limits. S02–S10 remain Not run. This is not a client case study or evidence of production readiness.

## Access policy

- A confirmed positive subscription invoice payment grants access until the paid service period ends. The expiry instant itself is excluded.
- A later paid renewal can extend the entitlement. A failed renewal cannot extend it; there is no extra grace period.
- Period-end cancellation preserves access only through the already paid period.
- Incomplete or uncertain checkout does not grant access. Returning from Stripe Checkout does not by itself change entitlement.

Renewal, failed payment, and cancellation lifecycle runs remain **Not run**. Refunds, disputes, immediate cancellation, trials, plan changes, and zero-amount invoices are outside this slice.

## Design choice

Cashier 16.8.0 owns Stripe Checkout and subscription record syncing. Its documented signed webhook controller remains the handler; this app registers that controller on `/stripe/webhook` with mandatory signing-secret validation. A synchronous `WebhookHandled` listener records the paid service period from a matching `invoice.payment_succeeded` subscription line. The invoice's overall `period_end` is deliberately not used for entitlement. Local access reads the maximum recorded paid-period end, independently of Cashier's `subscribed()` status, because that status can remain true during a cancellation period and is not proof of payment. This is smaller than replacing Cashier with a direct Stripe SDK handler, while making the payment-to-access rule inspectable.

The current listener only records completed positive test-mode payments. It does not yet provide durable failed-attempt records, concurrency guarantees, stale-event reconciliation, or all the failure and cancellation scenarios in the project matrix. A duplicate paid invoice is constrained by its unique invoice ID, but exactly-once processing is **not** claimed.

## Versions and sources

- Laravel framework 13.34.0; PHP target 8.4; Cashier 16.8.0; Stripe PHP SDK 21.3.2. Exact dependency versions are in `composer.lock`.
- Local runtime: Laravel Sail 1.68.0, PHP 8.4 and MySQL 8.4. The historical S01 run used the previous SQLite runtime; it has not been repeated on MySQL.
- Official guidance checked 5 October 2026: [Laravel 13 release notes](https://laravel.com/docs/13.x/releases), [Cashier billing](https://laravel.com/docs/13.x/billing), [Stripe invoice object](https://docs.stripe.com/api/invoices/object), [Stripe invoice line object](https://docs.stripe.com/api/invoice-line-item/object), and [Stripe test clocks](https://docs.stripe.com/api/test_clocks).
- Cashier 16 uses Stripe API version `2025-06-30.basil` per its documentation. S01 verified one actual test-mode invoice line: one monthly plan line with quantity 1 and a service period that matches the app record to the date; the stored expiry includes the exact timestamp. See `evidence/SCENARIOS.md`; this single run does not verify other invoice shapes or subscription lifecycle scenarios.

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

The demo seeder does not create users; create accounts through the main application. Hosted Checkout remains disabled because it is currently restricted to `APP_ENV=local`; do not set production to local to bypass that restriction. The report displays only the signed-in user's billing state.

Live verification is pending: main `/`, demo prefix with and without a trailing slash, demo `/up`, `/protected`, a missing route, POST webhook signature rejection, generated links and session cookie paths. Requests to demo `/.env`, `/composer.json` and `/vendor/autoload.php` must not expose files. A local Sail check does not validate Apache `.htaccess` or symlink handling.

## Shared database and separate logins — 8 October 2026

This agreement supersedes the earlier standalone database setup. Main and demo use identical MySQL credentials and database name. Main owns unprefixed `users`, `migrations` and Cashier customer columns (`stripe_id`, payment-method fields and trial expiry). The demo's `shared_users` connection ignores the prefix, while its default MySQL connection uses `DB_TABLE_PREFIX=stripe_`. Custom Cashier subscription/item models explicitly use the prefixed connection. The demo neither creates nor drops users or customer columns. User references are indexed IDs; there is currently no cross-connection foreign-key constraint or account-deletion cleanup.

Main server `.env`: `DB_TABLE_PREFIX=` and `DB_MIGRATIONS_TABLE=migrations`. Demo server `.env`: `DB_TABLE_PREFIX=stripe_` and `DB_MIGRATIONS_TABLE=migrations` (resulting table `stripe_migrations`). Both use `SESSION_DRIVER=file`, separate `APP_KEY` values and separate session cookies. Main uses `SESSION_COOKIE=demos_session` and `SESSION_PATH=/`; demo uses `SESSION_COOKIE=stripe_demo_session` and `SESSION_PATH=/laravel-stripe-billing-verification-demo`. Both use HTTPS-only cookies on the server. Local HTTP uses `SESSION_PATH=/` and HTTPS-only cookies disabled.

Deploy main first, then demo. With the previously confirmed disposable database reset, main creates the users schema and demo creates only prefixed tables. Do not change prefixes on populated databases or run `migrate:fresh` against the shared database. Demo rollback leaves shared users and customer fields intact.

For local shared operation, the demo's ignored `.env` connects to the main Sail MySQL service through `DB_HOST=host.docker.internal`, `DB_PORT=3307`, and the main app database credentials. Its old MySQL volume is preserved. The main MySQL service must remain running; the main web container need not remain running.

Create an account through main, then sign in separately to the demo. This initial demo login rejects accounts with two-factor enabled until a corresponding challenge is implemented. Signing out of the demo does not invalidate main's session; changing the shared password affects credentials in both apps. Hosted Checkout remains disabled by the existing local-only guard.

CI runs on pushes and pull requests using Sail/MySQL. It creates a minimal shared-user fixture only in database `testing`, migrates the demo's prefixed tables, and uses database transactions for test cleanup. It does not call Stripe APIs or claim a new Stripe integration result.