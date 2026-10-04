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
