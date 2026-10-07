<?php

namespace App\Listeners;

use App\Models\PaidPeriod;
use App\Models\StripeEventReceipt;
use Illuminate\Support\Carbon;
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

        if (($payload['livemode'] ?? null) !== false || ($invoice['livemode'] ?? null) !== false) {
            throw new UnexpectedValueException('This demonstration accepts test-mode invoices only.');
        }

        if (($invoice['status'] ?? null) !== 'paid' || ($invoice['amount_paid'] ?? 0) <= 0) {
            throw new UnexpectedValueException('Invoice does not confirm a positive payment.');
        }

        $invoiceId = $invoice['id'] ?? null;
        $eventId = $payload['id'] ?? null;
        $priceId = $user->stripeDemoPriceId();

        if (! is_string($invoiceId) || ! is_string($eventId) || ! is_string($priceId) || $priceId === '') {
            throw new UnexpectedValueException('Invoice or configured plan is incomplete.');
        }

        $subscriptionId = $invoice['parent']['subscription_details']['subscription'] ?? null;
        $matchingLine = null;

        foreach ($invoice['lines']['data'] ?? [] as $line) {
            $linePrice = $line['pricing']['price_details']['price'] ?? $line['price']['id'] ?? null;
            $lineSubscription = $line['parent']['subscription_item_details']['subscription'] ?? null;

            if ($linePrice === $priceId && ($subscriptionId === null || $lineSubscription === null || $lineSubscription === $subscriptionId)) {
                $matchingLine = $line;
                $subscriptionId ??= $lineSubscription;
                break;
            }
        }

        if (! $matchingLine || ! is_string($subscriptionId)) {
            throw new UnexpectedValueException('No matching subscription line was found on the paid invoice.');
        }

        $start = $matchingLine['period']['start'] ?? null;
        $end = $matchingLine['period']['end'] ?? null;

        if (! is_int($start) || ! is_int($end) || $end <= $start) {
            throw new UnexpectedValueException('Paid subscription period is invalid.');
        }

        DB::transaction(function () use ($user, $eventId, $invoiceId, $subscriptionId, $start, $end): void {
            PaidPeriod::firstOrCreate(['stripe_invoice_id' => $invoiceId], [
                'user_id' => $user->getKey(),
                'stripe_subscription_id' => $subscriptionId,
                'period_start' => Carbon::createFromTimestampUTC($start),
                'period_end' => Carbon::createFromTimestampUTC($end),
            ]);

            StripeEventReceipt::firstOrCreate(['stripe_event_id' => $eventId], [
                'user_id' => $user->getKey(),
                'event_type' => 'invoice.payment_succeeded',
                'status' => 'completed',
                'processed_at' => now(),
            ]);
        });
    }
}
