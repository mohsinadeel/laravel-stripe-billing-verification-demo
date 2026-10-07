<?php

namespace App\Listeners;

use App\Models\PaidPeriod;
use App\Models\StripeEventReceipt;
use App\PaidInvoicePeriod;
use Illuminate\Support\Facades\DB;
use Laravel\Cashier\Cashier;
use Laravel\Cashier\Events\WebhookHandled;
use UnexpectedValueException;

class RecordPaidInvoice
{
    public function handle(WebhookHandled $event): void
    {
        $payload = $event->payload;
        if (($payload['type'] ?? null) !== 'invoice.payment_succeeded') {
            return;
        }
        $invoice = $payload['data']['object'] ?? [];
        $customer = $invoice['customer'] ?? null;
        $user = is_string($customer) ? Cashier::findBillable($customer) : null;
        if (! $user) {
            return;
        }
        $eventId = $payload['id'] ?? null;
        if (($payload['livemode'] ?? null) !== false || ! is_string($eventId)) {
            throw new UnexpectedValueException('A complete test-mode event is required.');
        }
        $period = app(PaidInvoicePeriod::class)->fromInvoice($invoice, $user);
        DB::transaction(function () use ($user, $eventId, $period): void {
            $existing = PaidPeriod::firstOrCreate(['stripe_invoice_id' => $period['stripe_invoice_id']], $period);
            if ($existing->user_id !== $user->id || $existing->stripe_subscription_id !== $period['stripe_subscription_id']) {
                throw new UnexpectedValueException('Invoice ownership mismatch.');
            }
            StripeEventReceipt::firstOrCreate(['stripe_event_id' => $eventId], [
                'user_id' => $user->id, 'event_type' => 'invoice.payment_succeeded',
                'status' => 'completed', 'processed_at' => now(),
            ]);
        });
    }
}
