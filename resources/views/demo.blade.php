<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Billing verification demo</title>
    <style>
        body { font: 16px/1.5 system-ui, sans-serif; margin: 0; background: #f5f5f1; color: #1d2927; }
        main { max-width: 1440px; margin: 4rem auto; padding: 0 1.5rem; }
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
        * { box-sizing: border-box; }
        .page-header { display: flex; gap: 24px; align-items: center; justify-content: space-between; }
        .layout { display: grid; grid-template-columns: minmax(0, 1fr) 340px; gap: 24px; align-items: start; }
        .sidebar { position: sticky; top: 24px; font-size: 14px; }
        .sidebar h2 { font-size: 18px; }
        .sidebar .steps { margin-bottom: 0; }
        .toolbar { display: flex; flex-wrap: wrap; gap: 12px; align-items: center; }
        button.secondary { background: white; color: #173f39; border: 1px solid #cbd3ce; }
        button:disabled { opacity: .5; cursor: not-allowed; }
        dialog { width: min(480px, calc(100% - 32px)); padding: 28px; border: 1px solid #cbd3ce; border-radius: 12px; color: #1d2927; }
        dialog::backdrop { background: rgba(0,0,0,.45); }
        dialog h2 { margin-top: 0; }
        label { display: block; font-weight: 600; margin: 20px 0 8px; }
        input { width: 100%; font: inherit; padding: 12px; border: 1px solid #a8b5af; border-radius: 6px; }
        input:focus-visible, button:focus-visible, a:focus-visible { outline: 2px solid #086647; outline-offset: 3px; }
        .modal-actions { display: flex; justify-content: flex-end; gap: 12px; margin-top: 24px; }
        .error { color: #a1432b; }
        @media (max-width: 950px) { .layout { grid-template-columns: 1fr; } .sidebar { position: static; } }
        @media (max-width: 550px) { .page-header { align-items: start; flex-direction: column; } main { padding: 0 16px; margin: 24px auto; } .card { padding: 20px; } }
    </style>
</head>
<body><main>
    <div class="page-header"><h1>Stripe billing verification</h1>
    <form method="POST" action="{{ route('logout') }}">@csrf <button type="submit">Sign out of this demo</button></form></div>
    <p class="muted">Self-directed working demonstration. Test-mode payments only. Signed in as {{ $user->email }}.</p>

    <div class="layout"><div class="content">
    @if (session('status'))<div class="card success" role="status">{{ session('status') }}</div>@endif
    @if ($errors->getBag('default')->has('stripe_price_id'))<div class="card notice" role="alert">{{ $errors->getBag('default')->first('stripe_price_id') }}</div>@endif
    @if (request('checkout') === 'returned')
        @if ($hasAccess && $latestPeriod?->stripe_invoice_id)
            <div class="card success" role="status">Payment confirmed. A paid invoice has been processed, and protected access is active until {{ $paidUntil }} UTC.</div>
        @else
            <div class="card notice" role="status" id="checkout-pending" data-status-url="{{ route('demo.payment-status') }}">Checkout returned. Access remains pending until a signed paid-invoice webhook is processed. This page will update automatically when payment is confirmed.</div>
            <script src="{{ asset('js/checkout-status.js') }}" defer></script>
        @endif
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
            @if ($latestReceipt)
                <dt>Processing attempts</dt><dd>{{ $latestReceipt->attempts }}</dd>
                @if ($latestReceipt->last_error)<dt>Processing error</dt><dd>{{ $latestReceipt->last_error }}</dd>@endif
            @endif
        </dl>
    </section>

    <section class="card">
        <h2>Actions</h2>
        <p class="muted">Stripe test price: <code>{{ $planConfigured ? $priceId : 'Not configured' }}</code></p>
        <div class="toolbar">
            <button type="button" class="secondary" id="open-stripe-setup" @disabled($subscription)>{{ $planConfigured ? 'Edit Stripe setup' : 'Add Stripe setup' }}</button>
            @if ($planConfigured && ! $subscription && $checkoutConfigured)
                <form method="post" action="{{ route('demo.checkout') }}">@csrf<button type="submit">Start test checkout</button></form>
            @endif
        </div>
        @if ($subscription)
            <p class="muted">This account already has a subscription. Its price is locked for consistent webhook verification.</p>
        @elseif (! $planConfigured)
            <p class="muted">Add your Stripe test price ID to configure checkout.</p>
        @endif
        @if (! $checkoutConfigured)
            <p class="notice">The administrator must configure test keys and the endpoint signing secret before Checkout is available.</p>
        @endif
        <p><a href="{{ route('demo.protected') }}">Check protected feature</a></p>
    </section>
    </div>
    <aside class="sidebar card" aria-labelledby="test-flow-heading">
        <h2 id="test-flow-heading">How to test this demo</h2>
        <ol class="steps">
            <li><strong>Set up your test price.</strong> Use Add Stripe setup to save a recurring price ID from the Stripe test account configured for this demo.</li>
            <li><strong>Configure Stripe delivery.</strong> The administrator must configure test API keys and the webhook signing secret. For local testing, forward events to <code>{{ url('/stripe/webhook') }}</code> using Stripe CLI. Hosted testing uses a Stripe webhook endpoint.</li>
            <li><strong>Complete test Checkout.</strong> When enabled, use an official Stripe test card. Never use live keys or real payment details.</li>
            <li><strong>Check the result.</strong> The return URL does not grant access. The pending return page checks automatically for up to 90 seconds and updates after the signed paid invoice is processed. Then check the paid period and protected feature.</li>
        </ol>
        <p class="notice">If access is pending, check webhook delivery and the configured signing secret. Only the scenarios recorded in the source repository have been verified.</p>
    </aside>
    </div>
    <dialog id="stripe-setup" aria-labelledby="stripe-setup-title">
        <h2 id="stripe-setup-title">Stripe test setup</h2>
        <p class="muted">Save the recurring test price for your account. API keys remain on the server.</p>
        <form method="POST" action="{{ route('demo.settings.stripe') }}">
            @csrf
            <label for="stripe-price-id">Stripe test price ID</label>
            <input id="stripe-price-id" name="stripe_price_id" value="{{ old('stripe_price_id', $planConfigured ? $priceId : '') }}" placeholder="price_…" required maxlength="255" aria-describedby="stripe-price-help">
            <p id="stripe-price-help" class="muted">Copy the price ID from a recurring price in Stripe test mode.</p>
            @error('stripe_price_id', 'stripeSetup')<p class="error" role="alert">{{ $message }}</p>@enderror
            <div class="modal-actions">
                <button type="button" class="secondary" id="close-stripe-setup">Cancel</button>
                <button type="submit">Save setup</button>
            </div>
        </form>
    </dialog>
    <script>
        const setupDialog = document.getElementById('stripe-setup');
        document.getElementById('open-stripe-setup').addEventListener('click', () => setupDialog.showModal());
        document.getElementById('close-stripe-setup').addEventListener('click', () => setupDialog.close());
        if (@json($errors->getBag('stripeSetup')->any())) setupDialog.showModal();
    </script>
</main></body></html>



