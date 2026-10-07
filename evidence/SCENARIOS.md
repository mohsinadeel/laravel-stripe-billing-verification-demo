# Scenario evidence

Date: 5 October 2026 (Asia/Karachi). Code commit: `09d3f8264974d5549f1bddefeaa4f789be544acb` (local only).

| ID | Status | Evidence type | Current observation and limit |
| --- | --- | --- | --- |
| S01 successful initial payment | Verified against Stripe test environment | Stripe sandbox, Stripe CLI local forwarding, Laravel/Cashier app | Actual $10.00 test Checkout was paid; `invoice.payment_succeeded` reached the app with HTTP 200; invoice line period and local paid period agree to the date, and the home state reports access allowed through the stored expiry. Details and limits below. |
| S02 incomplete checkout | Not run | Stripe test environment | No actual incomplete Checkout run. |
| S03 successful renewal | Not run | Stripe test environment | No test-clock renewal run. |
| S04 failed renewal | Not run | Stripe test environment | No failure or expiry boundary run. |
| S05 period-end cancellation | Not run | Stripe test environment | No cancellation lifecycle run. |
| S06 repeated and concurrent delivery | Not run | Stripe test environment and automated concurrency | Sequential duplicate fixture passed; concurrent delivery and signed Stripe replay remain unrun. |
| S07 invalid signature | Not run | Stripe test environment | Local invalid-signature fixture passed; actual delivery/replay remains unrun. |
| S08 processing failure and retry | Not run | Simulated fault | Not implemented. |
| S09 mismatch and correction | Not run | Simulated drift | Not implemented. |
| S10 stale or related event order | Not run | Local fixture and integration | Not implemented. |

S01 is verified against one Stripe test-environment payment. S02–S10 remain Not run. Keep each future run tied to its starting state, exact action, expected and observed result, date/time, commit, environment, evidence and remaining limits.

## S01 successful initial payment

Date/time: 5 October 2026, 03:46:18–03:46:19 Asia/Karachi (Stripe invoice activity: 4 October 2026, 22:47 UTC). Billing implementation under test: `09d3f8264974d5549f1bddefeaa4f789be544acb`; post-run home-page confirmation/documentation commit: `2315e6114bbf9536760355f91565e41e333aeb5c` (local only).

Starting conditions: Stripe sandbox test keys and one recurring test price were set in the ignored local `.env`; the local Docker app and Stripe CLI listener were running. Prior subscription `sub_1UMxkuKBr84CUJwoaV8RdLAu` was cancelled in the Stripe test sandbox at 4 October 2026, 22:44 UTC (Dashboard status Cancelled / ended). The app accepted the new checkout; a separate pre-checkout screenshot/reset record was not captured. The synthetic user is `demo@example.test`. Secret values are intentionally omitted.

Exact action: From the local home page, complete one hosted Stripe Checkout using a Stripe test card while `stripe.cmd listen` forwarded to `http://localhost:8000/stripe/webhook`. The listener output showed `customer.subscription.created` (`evt_1UMyBhKBr84CUJwon0Uv2DBp`), `invoice.payment_succeeded` (`evt_1UMyBhKBr84CUJwovErcmE5X`), and `customer.subscription.updated` (`evt_1UMyBiKBr84CUJwodH741AwB`); each POST to the local webhook returned HTTP 200.

Expected: a positive paid invoice for the configured monthly plan should create/update the Cashier subscription, record the actual subscription invoice-line service period, and grant access only through that paid period. The Checkout return itself must not grant access.

Observed: Stripe test Dashboard invoice `in_1UMyBfKBr84CUJwo1eKGhnoH` (`DATZ31GN-0002`) is Paid for US$10.00. Its line is `Demo Monthly Plan`, quantity 1, unit price US$10.00, service period 4 October 2026–4 November 2026, no tax. The app home reports subscription `sub_1UMyBgKBr84CUJwoql9MsIb6`, Cashier state `active`, the same paid invoice ID, a stored paid-period expiry of `2026-11-04 22:47:18 UTC`, latest receipt `invoice.payment_succeeded / completed`, and protected access `Allowed`. After the home-page banner change, a browser refresh showed “Payment confirmed” and that expiry. The invoice's line period is displayed by Stripe at date precision; the app stores the exact expiry instant from the invoice-line data. A direct HTTP request to `/protected` was not separately captured during this run.

Limits: This verifies one initial payment only in this Stripe sandbox. It does not verify renewals, failed payment, expiry boundary, cancellation lifecycle, replay/concurrency, retry recovery, or production behavior. Dashboard UI and CLI output were observed live; a raw invoice/event JSON export and saved screenshot are not included. The unrelated Stripe setup flow does not establish overall launch readiness.

## Local verification record

Date/time: 5 October 2026 (Asia/Karachi). Code commit: `09d3f8264974d5549f1bddefeaa4f789be544acb`; checks ran immediately before this local commit.

1. `docker compose build --quiet`. Starting condition: Laravel/Cashier source and Dockerfile in the local checkout. Expected: image with PHP 8.4, SQLite PDO, bcmath and locked dependencies. Observed: image built successfully. Limit: image build does not prove Stripe integration.
2. `docker compose run --rm --no-deps -e APP_ENV=testing -e DB_DATABASE=:memory: app php artisan test --no-ansi --do-not-cache-result --display-warnings`. Starting condition: in-memory SQLite, synthetic `cus_fixture_1`, local signed invoice fixture, no paid period. Expected: valid paid invoice permits access until expiry, repeated delivery leaves one paid period, invalid signature is rejected. Observed: 2 tests passed, 8 assertions, no warnings. Limit: signatures and invoice payload are locally generated fixtures; no Stripe delivery or concurrent replay.
3. `docker compose up -d --force-recreate`, then GET `/` and GET `/protected`. Starting condition: migrated and seeded local Docker volume, no paid invoice or Stripe configuration. Expected: state page loads and protected feature is denied. Observed: HTTP 200 and HTTP 403 respectively; page names the synthetic subscriber. Limit: no Checkout or actual payment executed.

## Sail/MySQL conversion — 7 October 2026

Starting revision: `49f97afb1b0fa24c83ced64ce795751cdb367939`; conversion is an uncommitted working-tree change, with no push or deployment.

Starting conditions: previous SQLite stack stopped; its volume preserved. New isolated Compose project `stripe-billing-demo`, PHP 8.4 Sail image, MySQL 8.4 volume, app database `billing_demo` and test database `testing`. No historical billing rows imported.

Actions and observed results:
- `composer require laravel/sail --dev --no-interaction --no-progress`: Sail 1.68.0 installed; existing framework/Cashier dependencies unchanged. `composer validate --no-check-publish` passed.
- `docker compose up -d --build --wait`: Sail image built; app started and MySQL healthy.
- `docker compose exec -T laravel.test php artisan migrate --seed --no-interaction`: all migrations completed and synthetic subscriber seeded.
- `docker compose exec -T laravel.test php artisan test --no-ansi --do-not-cache-result`: MySQL fixture checks passed, 2 tests and 8 assertions, including paid access, expiry, sequential duplicate and invalid signature.
- HTTP GET `http://localhost:8081/`: 200; GET `/protected`: 403 for the fresh unpaid app database.
- Stopped demo stack: main at port 8080 still returned 200. Restarted demo and stopped main stack: demo at port 8081 still returned 200. Both stacks restored afterwards.
- `vendor/bin/pint --dirty`: passed for modified PHP configuration. `git diff --check`: passed.

Limits: This verifies the local Sail/MySQL runtime and fixtures only. The historical S01 Stripe payment remains evidence for the old SQLite environment; no real Checkout or signed Stripe delivery has been rerun after conversion. S02–S10 remain Not run. Apache subpath routing, public access controls and production hosting remain unverified.

## Deployment preparation — 7 October 2026

Uncommitted changes based on HEAD 49f97afb1b0fa24c83ced64ce795751cdb367939. Both independent manual deployment workflows parsed as YAML; all 13 shell blocks passed bash -n. Extracted symlink logic executed in an ephemeral container: missing target deferred, creation and repeat passed, wrong target and directory collision rejected. Demo diff check passed. These checks validate deployment script syntax and filesystem logic only; Apache routing, PHP execution through the server symlink and public URLs remain Not run. No workflow dispatch or deployment occurred. Historical Stripe S01 has not been repeated on MySQL.

## Commit references — 7 October 2026

The Sail/MySQL conversion is now committed locally as `69339fd`; deployment preparation is committed as `a2c6e9d`. The verification records above describe checks performed before these commits against the same implementation. Creating commits did not rerun or extend the verified scenarios. No push or deployment has occurred; real Stripe S01 on MySQL and Apache routing remain unverified.

## Shared users and prefixed demo tables — 8 October 2026

Verification before implementation commit: main Sail/PHP 8.4/MySQL composer ci:check passed (Pint, PHPStan, 34 tests/83 assertions). Main migrated the Cashier customer fields on users. Demo migrated into the same local MySQL database with DB_TABLE_PREFIX=stripe_, retaining the shared unprefixed users table and creating stripe_migrations and stripe-owned tables. Initial seven regression tests passed against the main-created shared schema. The final demo suite passed against a fresh disposable MySQL database using its test-only shared-user fixture: 9 tests/40 assertions. It covers signed invoice entitlement/expiry, repeated invoice delivery, invalid signature, login/logout, invalid credentials, guest redirects, two-factor bypass refusal, hosted Checkout refusal, prefixed Cashier relations and another user's entitlement isolation. Demo CI YAML and shell syntax passed. No actual Stripe integration scenario was rerun.

Main owns users and Cashier customer columns; demo migrations no longer create/drop them. Receipts are now associated with their user. There is no cross-connection foreign-key constraint or account-deletion cleanup in this slice. Separate keys/cookies/file sessions are configured through server environment values. Live subpath login/cookie behaviour and the new deployment have not yet been verified.

Mohsin confirmed the server database is disposable and cleared its tables for this transition. Deploy the new main revision first, then the new demo revision; do not rerun an old deployment attempt pinned to earlier commits. Create users through main, then sign in to the demo separately.

## Saved setup and dashboard layout — 8 October 2026

Added per-user price settings, authenticated/CSRF-protected saving and a responsive modal/sidebar layout. Local MySQL migrations and Pint passed. Full fixture suite passed: 14 tests and 63 assertions, covering saved-price persistence/update/user scoping, invalid input, subscription lock and invoice verification using a saved price alongside previous authentication and billing tests. Stripe price existence/mode is not verified by the save form; no external Stripe Checkout was executed. Hosted Checkout remains restricted to local environments pending the user's separate preference.

## Hosted Checkout preparation — 8 October 2026

Base commit 47c2d1bbdda2e4f64b50c6b08d13022f759816f4; implementation commit follows this record. Local Sail/PHP 8.4/MySQL dedicated testing database. Added hosted test-key guards, same-sandbox active recurring price retrieval on save and Checkout, safe API failure messages and Checkout return non-entitlement checks. Full command `docker compose exec -T laravel.test php artisan test --compact --no-ansi --do-not-cache-result` passed: 21 tests / 103 assertions. Pint passed. Installed Cashier 16.8.0 resolves Stripe API 2026-08-26.dahlia. Route list confirms POST stripe/webhook. SDK HTTP responses are mocked; no real payment or hosted webhook has been verified. Earlier test failures were missing test CSRF token and missing customer mock; both corrected before passing. An incomplete subscription blocks another Checkout (409); use a separate synthetic run rather than deleting hosted shared records. Browser sandbox destination list currently shows no registered destination. Hosted S01 and S02-S10 integration remain unexecuted.

Webhook provisioning: 8 October 2026, base 246a202. ConfigureStripeWebhookTest passed as part of 7 affected tests / 41 assertions, using fake Stripe SDK HTTP and a temporary synthetic .env. It asserts exact endpoint URL/API version, required failure event, private file save and repeat reuse without secret change. Actual server provisioning is a separate deployment action; fixture success alone does not prove signed delivery. An initial empty generated migration was picked up during tests before its implementation; completed additive receipt migration was renamed and applied in testing, without resets.
