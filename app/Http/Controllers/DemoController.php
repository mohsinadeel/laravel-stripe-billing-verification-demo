<?php

namespace App\Http\Controllers;

use App\Models\PaidPeriod;
use App\Models\StripeEventReceipt;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;

class DemoController extends Controller
{
    public function index(Request $request): View
    {
        $user = $request->user();
        $paidUntil = PaidPeriod::query()->where('user_id', $user->id)->max('period_end');
        $hasAccess = $paidUntil !== null && now()->lessThan($paidUntil);

        return view('demo', [
            'user' => $user,
            'subscription' => $user->subscription('default'),
            'paidUntil' => $paidUntil,
            'hasAccess' => $hasAccess,
            'latestPeriod' => PaidPeriod::query()->where('user_id', $user->id)->latest('id')->first(),
            'latestReceipt' => StripeEventReceipt::query()->where('user_id', $user->id)->latest('id')->first(),
            'planConfigured' => str_starts_with((string) config('services.stripe.demo_price_id'), 'price_')
                && config('services.stripe.demo_price_id') !== 'price_replace_me',
        ]);
    }

    public function checkout(Request $request)
    {
        abort_unless(app()->environment('local'), 403);
        abort_unless(str_starts_with((string) config('cashier.secret'), 'sk_test_') && config('cashier.secret') !== 'sk_test_replace_me', 503);
        abort_unless(str_starts_with((string) config('services.stripe.demo_price_id'), 'price_') && config('services.stripe.demo_price_id') !== 'price_replace_me', 503);

        $user = $request->user();
        abort_if($user->subscription('default') !== null, 409, 'This synthetic user already has a subscription.');

        return $user->newSubscription('default', config('services.stripe.demo_price_id'))
            ->checkout([
                'success_url' => route('demo.index').'?checkout=returned',
                'cancel_url' => route('demo.index').'?checkout=cancelled',
            ]);
    }

    public function protected(Request $request): Response
    {
        $user = $request->user();
        $paidUntil = PaidPeriod::query()->where('user_id', $user->id)->max('period_end');

        abort_unless($paidUntil !== null && now()->lessThan($paidUntil), 403, 'No current paid entitlement.');

        return response('Synthetic protected feature is available.');
    }
}
