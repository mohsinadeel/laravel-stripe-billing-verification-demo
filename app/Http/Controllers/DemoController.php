<?php

namespace App\Http\Controllers;

use App\Models\DemoSetting;
use App\Models\OneTimePayment;
use App\Models\PaidPeriod;
use App\Models\StripeEventReceipt;
use App\StripeSandbox;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Laravel\Cashier\Checkout;
use Stripe\Exception\ApiErrorException;

class DemoController extends Controller
{
    public function oneTimeCheckout(Request $request, StripeSandbox $sandbox): Checkout
    {
        if (! $sandbox->configured()) {
            throw ValidationException::withMessages(['one_time_payment' => 'The administrator must configure test keys and the webhook secret.']);
        }
        $user = $request->user();
        $payment = OneTimePayment::create(['user_id' => $user->id]);
        try {
            $checkout = $user->checkout([['price_data' => [
                'currency' => 'usd', 'unit_amount' => 1000, 'product_data' => ['name' => 'One-time demo payment'],
            ], 'quantity' => 1]], [
                'mode' => 'payment', 'payment_method_types' => ['card'], 'adaptive_pricing' => ['enabled' => false],
                'metadata' => ['demo_type' => 'one_time', 'payment_id' => (string) $payment->id],
                'success_url' => route('demo.index').'?one_time=returned&payment='.$payment->id,
                'cancel_url' => route('demo.index').'?one_time=cancelled&payment='.$payment->id,
            ]);
            $payment->update(['stripe_checkout_session_id' => $checkout->id]);

            return $checkout;
        } catch (ApiErrorException) {
            $payment->update(['status' => 'checkout_failed']);
            throw ValidationException::withMessages(['one_time_payment' => 'Stripe could not start the one-time test Checkout. Please retry.']);
        }
    }

    public function oneTimeStatus(Request $request, int $payment): JsonResponse
    {
        $record = OneTimePayment::where('user_id', $request->user()->id)->findOrFail($payment);

        return response()->json(['confirmed' => $record->status === 'paid'])->header('Cache-Control', 'private, no-store');
    }

    public function paymentStatus(Request $request): JsonResponse
    {
        $paidUntil = PaidPeriod::query()->where('user_id', $request->user()->id)->max('period_end');

        return response()->json(['confirmed' => $paidUntil !== null && now()->lessThan($paidUntil)])
            ->header('Cache-Control', 'private, no-store');
    }

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
            'returnedOneTimePayment' => OneTimePayment::where('user_id', $user->id)->find($request->integer('payment')),
            'oneTimePayments' => OneTimePayment::where('user_id', $user->id)->latest('id')->limit(5)->get(),
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
