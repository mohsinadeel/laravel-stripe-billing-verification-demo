<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireStripeWebhookSecret
{
    public function handle(Request $request, Closure $next): Response
    {
        $secret = config('cashier.webhook.secret');

        if (! is_string($secret) || ! str_starts_with($secret, 'whsec_') || $secret === 'whsec_replace_me') {
            abort(503, 'Stripe webhook signing is not configured.');
        }

        return $next($request);
    }
}
