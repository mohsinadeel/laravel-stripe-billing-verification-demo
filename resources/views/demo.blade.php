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
        @media (max-width: 550px) { dl { grid-template-columns: 1fr; gap: .1rem; } dd { margin-bottom: .8rem; } }
    </style>
</head>
<body><main>
    <h1>Stripe billing verification</h1>
    <p class="muted">Self-directed working demonstration. Synthetic user and test-mode payments only.</p>

    @if (request('checkout') === 'returned')
        <div class="card">Checkout returned. Access remains pending until a signed paid-invoice webhook is processed.</div>
    @elseif (request('checkout') === 'cancelled')
        <div class="card">Checkout was cancelled. No entitlement was created by the redirect.</div>
    @endif

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
