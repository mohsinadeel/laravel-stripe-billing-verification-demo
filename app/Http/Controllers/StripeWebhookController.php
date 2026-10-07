<?php

namespace App\Http\Controllers;

use App\Models\StripeEventReceipt;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Laravel\Cashier\Cashier;
use Laravel\Cashier\Http\Controllers\WebhookController;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class StripeWebhookController extends WebhookController
{
    public function handleWebhook(Request $request): Response
    {
        $payload = json_decode($request->getContent(), true);
        if (! is_array($payload) || ! is_string($payload['id'] ?? null) || ! is_string($payload['type'] ?? null)) {
            return new Response('Invalid event', 400);
        }
        if (($payload['livemode'] ?? null) !== false) {
            return new Response('Test-mode events only', 400);
        }
        $object = $payload['data']['object'] ?? [];
        $customer = str_starts_with($payload['type'], 'customer.') && ! str_starts_with($payload['type'], 'customer.subscription.')
            ? ($object['id'] ?? null) : ($object['customer'] ?? null);
        $user = is_string($customer) ? Cashier::findBillable($customer) : null;
        if (! $user) {
            return new Response('No matching demo user', 200);
        }

        $connection = DB::connection('mysql');
        $lock = 'stripe-demo:'.hash('sha256', (string) $user->id);
        $lock = substr($lock, 0, 64);
        if ((int) $connection->selectOne('SELECT GET_LOCK(?, 5) AS acquired', [$lock])->acquired !== 1) {
            return new Response('Processing busy; retry delivery', 503);
        }

        try {
            $receipt = StripeEventReceipt::firstOrCreate(['stripe_event_id' => $payload['id']], [
                'user_id' => $user->id, 'event_type' => $payload['type'], 'status' => 'accepted',
            ]);
            if ($receipt->user_id !== $user->id || $receipt->event_type !== $payload['type']) {
                return new Response('Event identity mismatch', 400);
            }
            if ($receipt->status === 'completed') {
                return new Response('Already processed', 200);
            }
            $receipt->update(['status' => 'processing', 'attempts' => $receipt->attempts + 1, 'last_error' => null]);
            try {
                return $connection->transaction(function () use ($request, $receipt): Response {
                    $response = parent::handleWebhook($request) ?? new Response('Webhook handled', 200);
                    if ($response->getStatusCode() >= 400) {
                        throw new \RuntimeException('Webhook processing returned a failure');
                    }
                    if (app()->environment(['local', 'testing']) && config('services.stripe.fail_after_processing', false)) {
                        throw new \RuntimeException('Local processing failure fixture');
                    }
                    $receipt->update(['status' => 'completed', 'processed_at' => now(), 'last_error' => null]);

                    return $response;
                });
            } catch (Throwable) {
                $receipt->update(['status' => 'failed', 'processed_at' => null, 'last_error' => 'Processing failed; retry the signed delivery.']);

                return new Response('Processing failed; retry delivery', 500);
            }
        } finally {
            $connection->select('SELECT RELEASE_LOCK(?)', [$lock]);
        }
    }

    protected function handleInvoicePaymentFailed(array $payload): Response
    {
        return new Response('Failed payment observed; paid entitlement unchanged', 200);
    }
}
