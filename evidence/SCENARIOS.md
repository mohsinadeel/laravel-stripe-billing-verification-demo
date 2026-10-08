# Scenario evidence

Date: 5 October 2026 (Asia/Karachi). Code commit: `09d3f8264974d5549f1bddefeaa4f789be544acb` (local only).

| ID | Current status | Evidence and remaining limit |
| --- | --- | --- |
| S01 successful initial payment | Verified against hosted Stripe sandbox/shared MySQL | User submitted payment; signed invoice event HTTP200, matching invoice/period and protected feature independently observed. Historical SQLite record remains separate. |
| S02 incomplete checkout | Verified against Stripe sandbox: cancellation variant | Real hosted cancellation retained no subscription/paid period, protected route showed 403, another Checkout started. Decline/incomplete-subscription variant Not run. |
| S03 successful renewal | Locally verified policy with fixtures | Paid renewal extends expiry; exact expiry denied. Actual test-clock renewal Not run. |
| S04 failed renewal | Locally verified policy with fixtures | Signed failure observation leaves paid expiry unchanged; access stops at expiry. Actual Stripe failed renewal Not run. |
| S05 period-end cancellation | Locally verified policy with fixtures | Current cancellation end synced, paid access preserved before expiry and denied at expiry. Actual Stripe lifecycle Not run. |
| S06 repeated/concurrent delivery | Locally verified; actual signed sandbox resend observed | Two local PHP processes give one receipt/effect. Real resend returned HTTP200 Already processed with unchanged displayed period. Hosted database before/after counts were not queried. |
| S07 invalid signature | Locally verified; hosted negative request checked | Local before/after zero subscriptions/periods/receipts; hosted invalid-signature POST 403. No hosted DB before/after query. |
| S08 processing failure/retry | Locally verified simulated fault | Failed receipt retained, invoice transaction rolled back, retry completes once; production ignores injection. Stripe automatic retry Not run. |
| S09 mismatch/repair | Locally verified simulated drift | Read-only diagnosis unchanged row, explicit disposable-MySQL repair, stable repeat. Live Stripe comparison Not run. |
| S10 older/related event order | Locally verified with mocked latest Stripe state | Active/cancelled subscription does not regress; older paid invoice cannot reduce expiry. Actual Stripe delivery order Not run. |

Current snapshot: 8 October 2026 (Asia/Karachi). Latest local suite: 44 tests / 268 assertions plus 5 JS tests. Historical entries below describe their original runtime/commit; the follow-up entry establishes hosted S01. Every local test uses Sail/MySQL testing, not SQLite. Signed fixture payloads and mocked SDK calls are not Stripe integration evidence.

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

## Durable processing / S06–S08 local checks — 8 October 2026

Base ae1b322d77a28a58a011ba8aeec7af7c80b3bebb. Receipt states accepted/processing/failed/completed, attempt count and safe failure summary; MySQL advisory lock serialises each demo user's events. Lock timeout returns 503 for retry. Invoice effect and completion are one MySQL transaction; failed processing returns 500 and retains failed receipt. Completed duplicate returns 200 without another attempt. Unique event IDs and invoice IDs remain database constraints. Shared customer fields are on a separate connection and are outside this invoice-effect transaction guarantee.

Command: `docker compose exec -T laravel.test php artisan test --compact --filter='CheckoutTest|ConfigureStripeWebhookTest|ConcurrentWebhookTest|PaidInvoiceWebhookTest|DemoSettingsTest|SharedUsersTest' --no-ansi --do-not-cache-result`. Observed 26 tests / 141 assertions passed. Concurrency test creates only dedicated testing-database fixtures, holds the per-user lock while two independent PHP workers report readiness, releases it, observes both HTTP 200, one receipt/attempt and one invoice effect, then deletes its own fixtures. First worker readiness check was not observed in an earlier run; diagnostic rerun and combined suite passed. No hosted fixtures or resets.

S07: locally invalid signature returns 403 with zero subscriptions, paid periods and receipts. S08: local/testing-only fail_after_processing rolls back invoice effect, leaves failed receipt and HTTP 500; retry yields one effect, completed receipt and two attempts; repeated completed delivery remains stable. Production environment ignores the same injected setting. No HTTP switch exposes injection on hosted app. Stripe automatic retry remains Not run.

S04 local policy: a signed failed-invoice fixture produces a completed observation receipt, does not extend paid expiry, permits access before expiry and denies at the exact expiry. This is not actual failed renewal evidence. S03/S05 real lifecycle remain Not run. Actual signed Stripe resend remains Not run until initial payment is completed.

Hosted S02 cancellation at ae1b322: user-authorised account initially had no observed subscription/paid period. Started a real sandbox Checkout, followed Back to the cancellation URL, observed unchanged empty billing report, clicked protected link and observed 403 No current paid entitlement, then successfully started another Checkout. Cancellation variant Verified against Stripe sandbox; decline-card/incomplete-subscription variant Not run. Hosted S01 remains Not run: test card 4242 and synthetic cardholder are filled, final Subscribe rejected by browser approval review requiring human handoff even in sandbox.

## S09 local mismatch and correction — 8 October 2026

Base 164c5987379dd9ab3ea099490ba43978c5c06f2c. Implemented stripe:reconcile USER_ID, read-only by default, comparing authoritative mocked Stripe paid invoices/subscription ownership with prefixed paid periods. Shared PaidInvoicePeriod validator also drives webhook storage. Starting fixture: testing MySQL only, synthetic customer, invoice in_reconcile, stored expiry 2026-10-08 11:00:00 UTC versus expected 10:00:00. Command reports one mismatch and failing exit code; fresh before/after database row including timestamps is identical. Explicit --repair reports one correction; repeat reports zero, one invoice row remains, and no event receipt is fabricated. Production repair is refused. Initial comparison assertion was sensitive to array key order; fresh snapshots fixed it before passing. Affected command `php artisan test --compact --filter='ReconcileStripeBillingTest|PaidInvoiceWebhookTest|ConcurrentWebhookTest' --no-ansi --do-not-cache-result`: 8 tests / 52 assertions passed under Sail/MySQL. Pint passed. Stripe SDK is mocked; no actual Stripe-backed diagnosis/repair or hosted drift executed.

## S03/S05/S10 policy and ordering checks — 8 October 2026

Base 0d7a5e6b8b1aaa0873b9f889fef5e0276ff15f99. Subscription created/updated/deleted syncing retrieves authoritative current Stripe subscription under the MySQL lock, verifies test mode/customer and invokes Cashier. Starting synthetic active subscription: older past_due update and stale deleted event leave active state/items intact because mocked latest Stripe state remains active. Scheduled period-end cancellation syncs ends_at; a stored paid period still allows protected access before expiry and denies at the exact application-time expiry. Latest cancelled Stripe state followed by an old active event stays cancelled. Stripe lookup failure returns 500, preserves active local state and records failed processing for retry. Separate signed paid-invoice fixtures show renewal extending expiry; a different older-related event referring to the original invoice creates no duplicate effect and cannot reduce the maximum paid expiry.

Final command `docker compose exec -T laravel.test php artisan test --compact --no-ansi --do-not-cache-result`: 31 tests / 179 assertions passed. Pint/diff checks passed. No Stripe test clock used. Controlled Laravel test time drives expiry assertions; Stripe clock and server time were not conflated. Subscription SDK responses and invoice signatures are fixtures.

Concurrency harness correction: the earlier lock/readiness approach intermittently missed buffered READY output and held the test-owned lock until workers returned 503. Replaced it with persistent stdin streams: both workers boot on testing MySQL and report READY, then both receive GO before posting. Combined suite passes with two HTTP 200 responses, one receipt/attempt and one invoice effect. This is two-process local concurrency, not Stripe transport replay.

Hosted provisioning confirmed in Dashboard: destination we_1UO3EPKBr84CUJwolnuzf7JZ active at the exact webhook URL; API 2026-08-26.dahlia. Dashboard lists nine configured event names plus legacy payment_method.card_automatically_updated (10 shown). Signing secret remains hidden. No real event delivery yet. Independent HTTP smoke: GET webhook 405; invalid-signature POST 403. Secret storage/setup deployment 37695681779 succeeded; durable processing 37696599706 and reconciliation 37697046178 succeeded.

Final deployment record — 8 October 2026: ordering/lifecycle policy commit 16641a7b67a9b09e23093849f3de83d98222de5c deployed successfully in manual run 37697512158. All five feature deployments completed in sequence. Local tests remain 31 / 179; no real paid webhook, Stripe clock, resend or automatic retry was subsequently executed. Hosted cancellation and negative-signature checks retain their stated limits. Session walkthrough is in the private planning workspace, SESSION-WALKTHROUGH-2026-10-08.md.

## Automatic confirmation — 8 October 2026

Base 8d8955889ecf4b9f177bba9f961f469058636d2d. Added authenticated/private status JSON and bounded two-second polling on pending returned Checkout pages only. Node tests: 5 passed (pending/confirmed reload, transient failure, timeout, session expiry and no ordinary-page polling). Sail/MySQL PaymentStatusTest: 2 tests / 13 assertions passed, covering guest401, unpaidfalse, current-own-periodtrue, another-userfalse, expiryfalse, no-store and conditional script rendering. Pint passed. The user's payment submission is user-reported; agent independently observed hosted subscription sub_1UO9kcKBr84CUJwofxvxlqwY active, paid invoice in_1UO9kbKBr84CUJwosBdfsdMy, expiry 2026-11-08 05:20:17 UTC and allowed access in the billing report. Actual Stripe delivery details and direct protected response are not yet checked in this entry. Existing account cannot start another subscription Checkout merely by logging out/in; the code blocks whenever a default subscription record exists, even expired/cancelled.

## Repeatable one-time Checkout — 8 October 2026

Base 0479cac091f74e083f145b720511231c31041541 (automatic-confirmation deployment 37732401774 succeeded). User requested repeatable one-time demonstrations. Added separate fixed US$10 USD card-only payment-mode Checkout, prefixed per-attempt records, safe API failures, per-user status/attempt history and webhook-confirmed payment. Inline price is server-owned, independent of recurring saved price. Both recurring entitlement and subscription records remain unchanged. Added the two Checkout completion event names to private destination provisioning/reuse.

Local command docker compose exec -T laravel.test php artisan test --compact --no-ansi --do-not-cache-result: 44 tests / 268 assertions passed. Node polling tests: 5 passed. Pint and diff checks passed. Tests cover two repeat attempts with an existing subscription; ignoring request amount overrides; authentication/live-key refusal; own-order pending/paid/no-store/isolation; exact session/customer/amount/currency/test mode/completion/payment reference; unpaid sessions; duplicate/related event stability; safe API failure and failed-processing rollback/retry. Initial helper collided with Laravel session(); renamed. Initial view directive needed whitespace before @endif; SDK false boolean is serialised as the string false at the HTTP boundary, so the fixture assertion was corrected. No new feature was deployed before the full suite passed.

Actual one-time paid Checkout: Not run. Browser final payment submission requires the user. Creating a session/cancelling it and green fixture tests do not establish completed external payment proof. Abandoned/expired/refunded/disputed one-time outcomes remain unsupported. S03-S10 real recurring lifecycle limits remain unchanged.

## Hosted follow-up verification — 8 October 2026

Subscription S01: user completed Checkout. Agent independently inspected invoice.payment_succeeded evt_1UO9keKBr84CUJwoXwvTmEPj delivered automatically at 05:20:21 UTC, HTTP200, matching paid invoice in_1UO9kbKBr84CUJwosBdfsdMy / subscription sub_1UO9kcKBr84CUJwofxvxlqwY. Visible invoice amount_paid1000 USD; matching line period 1791436817–1794115217, expiry 2026-11-08 05:20:17 UTC, exactly matches the app report. Authenticated /protected displayed Synthetic protected feature is available. Signed-delivery and paid-period agreement are current hosted/MySQL evidence; actual renewal, failure and cancellation are not inferred. The user's initial submission is user-reported; these post-submission details were observed independently.

S06 real registered-endpoint resend: resent that invoice event through Dashboard. Manual attempt at 05:37:37 UTC returned HTTP200 with response Already processed. Billing report retained the same invoice/subscription/expiry and access. Hosted receipt/period database counts were not queried; one-row effect/count guarantees remain bounded by local MySQL tests and unique constraints. This does not establish Stripe automatic retry evidence.

One-time feature 62efd89 / deployment 37733455473 succeeded, including retaining the saved signing secret while adding completion event subscriptions. Agent opened a real US$10 one-time sandbox Checkout from the already subscribed account; page shows One-time demo payment and Pay, with no monthly recurring amount. Returned using Back (cancelled); pending attempt #1 is recorded, subscription period stays unchanged, and the one-time button remains available. Deliberately visiting that unpaid attempt's returned URL keeps its one-time confirmation pending despite current subscription access, with the poll script present. This is session-creation/cancellation/pending evidence, not a completed one-time payment. User must submit Pay for full one-time integration confirmation. Direct browser navigation to the JSON subscription status route was client-blocked; local API tests pass. Pending browser console checks and static asset delivery are separate checks.

Closure for follow-up: copy clarification50073e1 deployed successfully in run37733809904. Browser confirms both flow headings/instructions and repeatable one-time button. Pending-page console showed no error/warning; authenticated JSON route direct-navigation was client-blocked as previously noted, so no direct browser JSON result is claimed. Actual one-time session creation/cancellation succeeded; full one-time paid confirmation remains Not run. Final source checks remain44 tests/268 assertions plus5 JS tests; no code changed after those checks except public-facing copy. The test attempt was left pending after cancellation, consistent with the documented unsupported expiration handling.
