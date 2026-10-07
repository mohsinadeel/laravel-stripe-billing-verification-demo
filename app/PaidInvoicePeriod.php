<?php

namespace App;

use App\Models\User;
use Illuminate\Support\Carbon;
use UnexpectedValueException;

class PaidInvoicePeriod
{
    public function fromInvoice(array $invoice, User $user): array
    {
        if (($invoice['livemode'] ?? null) !== false || ($invoice['customer'] ?? null) !== $user->stripe_id
            || ($invoice['status'] ?? null) !== 'paid' || ($invoice['amount_paid'] ?? 0) <= 0 || ! is_string($invoice['id'] ?? null)) {
            throw new UnexpectedValueException('A positive paid test invoice for this customer is required.');
        }
        if ($invoice['lines']['has_more'] ?? false) {
            throw new UnexpectedValueException('Complete invoice lines are required.');
        }
        $subscription = $invoice['parent']['subscription_details']['subscription'] ?? null;
        $matches = [];
        foreach ($invoice['lines']['data'] ?? [] as $line) {
            $price = $line['pricing']['price_details']['price'] ?? $line['price']['id'] ?? null;
            $lineSubscription = $line['parent']['subscription_item_details']['subscription'] ?? null;
            if ($price === $user->stripeDemoPriceId() && ($subscription === null || $lineSubscription === null || $lineSubscription === $subscription)) {
                $matches[] = $line;
                $subscription ??= $lineSubscription;
            }
        }
        if (count($matches) !== 1 || ! is_string($subscription)) {
            throw new UnexpectedValueException('One unambiguous matching subscription line is required.');
        }
        $start = $matches[0]['period']['start'] ?? null;
        $end = $matches[0]['period']['end'] ?? null;
        if (! is_int($start) || ! is_int($end) || $end <= $start) {
            throw new UnexpectedValueException('Paid subscription period is invalid.');
        }

        return ['user_id' => $user->id, 'stripe_invoice_id' => $invoice['id'], 'stripe_subscription_id' => $subscription,
            'period_start' => Carbon::createFromTimestampUTC($start)->toDateTimeString(), 'period_end' => Carbon::createFromTimestampUTC($end)->toDateTimeString()];
    }
}
