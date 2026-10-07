<?php

namespace App\Http\Controllers;

use App\Models\DemoSetting;
use App\Models\PaidPeriod;
use App\Models\StripeEventReceipt;
use App\StripeSandbox;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Laravel\Cashier\Checkout;
use Stripe\Exception\ApiErrorException;

class DemoController extends Controller
{
    public function index(Request $request): View
    {
        $user = $request->user();
        $priceId = $user->stripeDemoPriceId();
        $paidUntil = PaidPeriod::query()->where('user_id', $user->id)->max('period_end');
        $hasAccess = $paidUntil !== null && now()->lessThan($paidUntil);

        return view('demo', [
            'user' => $user,
            'subscription' => $user->subscription('default'),
            'paidUntil' => $paidUntil,
            'hasAccess' => $hasAccess,
            'latestPeriod' => PaidPeriod::query()->where('user_id', $user->id)->latest('id')->first(),
            'latestReceipt' => StripeEventReceipt::query()->where('user_id', $user->id)->latest('id')->first(),
            'priceId' => $priceId,
            'checkoutConfigured' => app(StripeSandbox::class)->configured(),
            'planConfigured' => str_starts_with((string) $priceId, 'price_')
                && $priceId !== 'price_replace_me',
        ]);
    }

    public function checkout(Request $request, StripeSandbox $sandbox): Checkout
    {
        $user = $request->user();
        abort_if($user->subscription('default') !== null, 409, 'This synthetic user already has a subscription.');
        $priceId = (string) $user->stripeDemoPriceId();
        $sandbox->validatePrice($priceId);
        try {
            return $user->newSubscription('default', $priceId)
                ->checkout([
                    'success_url' => route('demo.index').'?checkout=returned',
                    'cancel_url' => route('demo.index').'?checkout=cancelled',
                ]);
        } catch (ApiErrorException) {
            throw ValidationException::withMessages(['stripe_price_id' => 'Stripe could not start Checkout. Retry or ask the administrator to check the sandbox configuration.']);
        }
    }

    public function saveSettings(Request $request): RedirectResponse
    {
        $values = $request->validateWithBag('stripeSetup', [
            'stripe_price_id' => ['required', 'string', 'max:255', 'regex:/^price_[A-Za-z0-9]+$/'],
        ]);
        $user = $request->user();
        if ($user->subscription('default') !== null) {
            throw ValidationException::withMessages([
                'stripe_price_id' => 'The price cannot be changed after a subscription has been created.',
            ])->errorBag('stripeSetup');
        }

        app(StripeSandbox::class)->validatePrice($values['stripe_price_id'], 'stripeSetup');

        DemoSetting::query()->updateOrCreate(['user_id' => $user->id], $values);

        return redirect()->route('demo.index')->with('status', 'Stripe test price saved.');
    }

    public function protected(Request $request): Response
    {
        $user = $request->user();
        $paidUntil = PaidPeriod::query()->where('user_id', $user->id)->max('period_end');

        abort_unless($paidUntil !== null && now()->lessThan($paidUntil), 403, 'No current paid entitlement.');

        return response('Synthetic protected feature is available.');
    }
}
