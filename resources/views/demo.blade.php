<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Billing verification demo</title>
    <style>
        body { font: 16px/1.5 system-ui, sans-serif; margin: 0; background: #f5f5f1; color: #1d2927; }
        main { max-width: 800px; margin: 4rem auto; padding: 0 1.5rem; }
        h1 { font-size: 2rem; margin-bottom: .3rem; }
        .muted { color: #54615d; }
        .card { background: white; border: 1px solid #cbd3ce; border-radius: 8px; padding: 1.5rem; margin: 1rem 0; }
        dl { display: grid; grid-template-columns: 13rem 1fr; gap: .6rem 1rem; }
        dt { color: #52605c; } dd { margin: 0; overflow-wrap: anywhere; }
        .yes { color: #086647; font-weight: 700; } .no { color: #a1432b; font-weight: 700; }
        button, a.button { background: #173f39; color: white; border: 0; border-radius: 4px; padding: .7rem 1rem; font: inherit; cursor: pointer; text-decoration: none; }
        .steps { padding-left: 1.35rem; }
        .steps li { padding-left: .25rem; margin: .75rem 0; }
        code { background: #eef1ed; border-radius: 3px; padding: .1rem .3rem; overflow-wrap: anywhere; }
        .notice { border-left: 4px solid #b18a35; padding-left: .8rem; } .success { border-left: 4px solid #086647; }
        @media (max-width: 550px) { dl { grid-template-columns: 1fr; gap: .1rem; } dd { margin-bottom: .8rem; } }
    </style>
</head>
<body><main>
    <h1>Stripe billing verification</h1>
    <p class="muted">Self-directed working demonstration. Synthetic user and test-mode payments only.</p>

    @if (request('checkout') === 'returned')
        @if ($hasAccess && $latestPeriod?->stripe_invoice_id)
            <div class="card success" role="status">Payment confirmed. A paid invoice has been processed, and protected access is active until {{ $paidUntil }} UTC.</div>
        @else
            <div class="card notice" role="status">Checkout returned. Access remains pending until a signed paid-invoice webhook is processed.</div>
        @endif
    @elseif (request('checkout') === 'cancelled')
        <div class="card">Checkout was cancelled. No entitlement was created by the redirect.</div>
    @endif

    <section class="card" aria-labelledby="test-flow-heading">
        <h2 id="test-flow-heading">How to test this demo</h2>
        <ol class="steps">
            <li><strong>Configure the test environment.</strong> Follow the README's Local setup steps with Stripe <strong>test-mode</strong> API values and one recurring test price in the ignored <code>.env</code>. Never use live keys or real card details.</li>
            <li><strong>Forward signed webhooks.</strong> In a separate terminal, run <code>stripe listen --forward-to {{ url('/stripe/webhook') }}</code>. Put the printed <code>whsec_…</code> value in <code>STRIPE_WEBHOOK_SECRET</code> in <code>.env</code>, then from the repository folder run <code>docker compose exec laravel.test php artisan config:clear</code> so Laravel loads the new values. Keep the listener running while you check out.</li>
            <li><strong>Complete a test checkout.</strong> Select <em>Start test checkout</em> below, then use a test card from Stripe's testing documentation on the hosted Checkout page.</li>
            <li><strong>Check the result.</strong> Return to this page. The redirect alone does not grant access; wait for the signed invoice webhook, then refresh. A successful paid invoice should appear in the state below and the protected feature should allow access.</li>
        </ol>
        <p class="notice"><strong>If access is still pending:</strong> check that the Stripe CLI listener is connected, its signing secret is in <code>.env</code>, and the app container was recreated after the change. See <code>evidence/SCENARIOS.md</code> for the executed S01 result and the remaining scenario statuses.</p>
        <p class="muted">This demo has one synthetic user and permits one subscription. To start over after a completed checkout, use the README's reset instructions; removing the Docker volume deletes the local demo records.</p>
    </section>

    <section class="card">
        <h2>Current state</h2>
        <dl>
            <dt>Application user</dt><dd>{{ $user->name }} ({{ $user->email }})</dd>
            <dt>Subscription</dt><dd>{{ $subscription?->stripe_id ?? 'None observed' }}</dd>
            <dt>Cashier state</dt><dd>{{ $subscription?->stripe_status ?? 'None observed' }}</dd>
            <dt>Latest paid invoice</dt><dd>{{ $latestPeriod?->stripe_invoice_id ?? 'None observed' }}</dd>
            <dt>Paid entitlement ends</dt><dd>{{ $paidUntil ?? 'No paid period recorded' }} UTC</dd>
            <dt>Protected access</dt><dd class="{{ $hasAccess ? 'yes' : 'no' }}">{{ $hasAccess ? 'Allowed: paid period remains current' : 'Denied: no current paid period' }}</dd>
            <dt>Latest webhook receipt</dt><dd>{{ $latestReceipt ? $latestReceipt->event_type.' / '.$latestReceipt->status : 'None observed' }}</dd>
        </dl>
    </section>

    <section class="card">
        <h2>Actions</h2>
        @if ($planConfigured && ! $subscription)
            <form method="post" action="{{ route('demo.checkout') }}">@csrf<button type="submit">Start test checkout</button></form>
        @else
            <p class="muted">{{ $subscription ? 'This synthetic user already has a subscription.' : 'Set a Stripe test price ID to enable checkout.' }}</p>
        @endif
        <p><a href="{{ route('demo.protected') }}">Check protected feature</a></p>
    </section>
</main></body></html>



