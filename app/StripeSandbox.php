<?php

namespace App;

use Illuminate\Validation\ValidationException;
use Laravel\Cashier\Cashier;
use Stripe\Exception\ApiErrorException;

class StripeSandbox
{
    public function configured(): bool
    {
        return str_starts_with((string) config('cashier.secret'), 'sk_test_')
            && config('cashier.secret') !== 'sk_test_replace_me'
            && str_starts_with((string) config('cashier.key'), 'pk_test_')
            && config('cashier.key') !== 'pk_test_replace_me'
            && str_starts_with((string) config('cashier.webhook.secret'), 'whsec_');
    }

    public function validatePrice(string $priceId, string $errorBag = 'default'): void
    {
        $message = null;
        if (! $this->configured()) {
            $message = 'The administrator must configure Stripe test keys and the endpoint signing secret before Checkout.';
        } else {
            try {
                $price = Cashier::stripe()->prices->retrieve($priceId);
                if ($price->livemode !== false || ! $price->active || $price->type !== 'recurring' || ! $price->recurring) {
                    $message = 'Choose an active recurring price in the same Stripe sandbox as the server test keys.';
                }
            } catch (ApiErrorException) {
                $message = 'Stripe could not verify this price. Check its sandbox and ID, or retry when Stripe is available.';
            }
        }

        if ($message !== null) {
            throw ValidationException::withMessages(['stripe_price_id' => $message])->errorBag($errorBag);
        }
    }
}
