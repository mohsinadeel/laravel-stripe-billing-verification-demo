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
- Container base: `php:8.4-cli-alpine`; SQLite through `pdo_sqlite`.
- Official guidance checked 5 October 2026: [Laravel 13 release notes](https://laravel.com/docs/13.x/releases), [Cashier billing](https://laravel.com/docs/13.x/billing), [Stripe invoice object](https://docs.stripe.com/api/invoices/object), [Stripe invoice line object](https://docs.stripe.com/api/invoice-line-item/object), and [Stripe test clocks](https://docs.stripe.com/api/test_clocks).
- Cashier 16 uses Stripe API version `2025-06-30.basil` per its documentation. S01 verified one actual test-mode invoice line: one monthly plan line with quantity 1 and a service period that matches the app record to the date; the stored expiry includes the exact timestamp. See `evidence/SCENARIOS.md`; this single run does not verify other invoice shapes or subscription lifecycle scenarios.

## Local setup

Prerequisites: Docker Desktop with a working engine, a Stripe test-mode account, one recurring test price, and Stripe CLI for local webhook forwarding. Do not use live keys or real customer data.

1. Copy `.env.example` to `.env`. Generate an app key with `php artisan key:generate` if local PHP and Composer are installed, or insert a generated Laravel `APP_KEY` in `.env` before starting the container.
2. Set `STRIPE_KEY`, `STRIPE_SECRET`, and `STRIPE_PRICE_ID` in the ignored `.env`, using **test-mode** values only. Keep `APP_URL=http://localhost:8000`.
3. Run `docker compose up --build`. The container creates a SQLite file in the named volume, runs migrations, seeds one synthetic user, and serves the app at `http://localhost:8000`.
4. In another terminal, run `stripe listen --forward-to http://localhost:8000/stripe/webhook`. Copy its `whsec_...` signing secret into `STRIPE_WEBHOOK_SECRET` in `.env`, then, from the repository folder, run `docker compose up -d --force-recreate app` so Docker loads the new value. A simple `docker compose restart app` does not reload values from `.env`.
5. Open the local page, start test Checkout, and pay with an [official Stripe test card](https://docs.stripe.com/testing). Inspect the invoice, Cashier subscription row, paid-period row, and protected link. The Checkout return stays pending until the signed paid invoice is handled, then confirms payment and shows the current paid-access expiry.

The demo has no login and uses one synthetic user. It binds to `127.0.0.1` and is intended only for local verification. Do not deploy this interface as-is.

## Checks and evidence

Run `docker compose run --rm --no-deps -e APP_ENV=testing -e DB_DATABASE=:memory: app php artisan test --do-not-cache-result` after building the image. The automated test uses a locally generated Stripe-compatible signature and a fixture invoice; it does **not** prove actual Stripe delivery. See `evidence/SCENARIOS.md` for the dated results and the distinction between fixture, simulated and Stripe test-environment evidence.

The database persists in the `billing-data` Docker volume. To reset the synthetic demo, stop the stack and explicitly remove its volume with `docker compose down -v`; that deletes local demo records. Do this only when a clean start is intended.


