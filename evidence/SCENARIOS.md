# Scenario evidence

Date: 5 October 2026 (Asia/Karachi). Commit: none yet; local working tree only.

| ID | Status | Evidence type | Current observation and limit |
| --- | --- | --- | --- |
| S01 successful initial payment | Not run | Stripe test environment | Test price, signing secret, CLI delivery and actual Checkout payment are not configured. |
| S02 incomplete checkout | Not run | Stripe test environment | No actual incomplete Checkout run. |
| S03 successful renewal | Not run | Stripe test environment | No test-clock renewal run. |
| S04 failed renewal | Not run | Stripe test environment | No failure or expiry boundary run. |
| S05 period-end cancellation | Not run | Stripe test environment | No cancellation lifecycle run. |
| S06 repeated and concurrent delivery | Not run | Stripe test environment and automated concurrency | Sequential duplicate fixture passed; concurrent delivery and signed Stripe replay remain unrun. |
| S07 invalid signature | Not run | Stripe test environment | Local invalid-signature fixture passed; actual delivery/replay remains unrun. |
| S08 processing failure and retry | Not run | Simulated fault | Not implemented. |
| S09 mismatch and correction | Not run | Simulated drift | Not implemented. |
| S10 stale or related event order | Not run | Local fixture and integration | Not implemented. |

No scenario is marked integration-verified. Record each future run with starting state, exact action, expected and observed result, date/time, commit, environment, evidence file and remaining limits.

## Local verification record

Date/time: 5 October 2026 (Asia/Karachi). Commit: pending local commit; these commands ran against the working tree.

1. `docker compose build --quiet`. Starting condition: Laravel/Cashier source and Dockerfile in the local checkout. Expected: image with PHP 8.4, SQLite PDO, bcmath and locked dependencies. Observed: image built successfully. Limit: image build does not prove Stripe integration.
2. `docker compose run --rm --no-deps -e APP_ENV=testing -e DB_DATABASE=:memory: app php artisan test --no-ansi --do-not-cache-result --display-warnings`. Starting condition: in-memory SQLite, synthetic `cus_fixture_1`, local signed invoice fixture, no paid period. Expected: valid paid invoice permits access until expiry, repeated delivery leaves one paid period, invalid signature is rejected. Observed: 2 tests passed, 8 assertions, no warnings. Limit: signatures and invoice payload are locally generated fixtures; no Stripe delivery or concurrent replay.
3. `docker compose up -d --force-recreate`, then GET `/` and GET `/protected`. Starting condition: migrated and seeded local Docker volume, no paid invoice or Stripe configuration. Expected: state page loads and protected feature is denied. Observed: HTTP 200 and HTTP 403 respectively; page names the synthetic subscriber. Limit: no Checkout or actual payment executed.
