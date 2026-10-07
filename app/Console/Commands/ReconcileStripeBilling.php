<?php

namespace App\Console\Commands;

use App\Models\PaidPeriod;
use App\Models\User;
use App\PaidInvoicePeriod;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Laravel\Cashier\Cashier;
use Throwable;

#[Signature('stripe:reconcile {user : Shared user ID} {--repair : Repair paid periods in disposable local testing MySQL only}')]
#[Description('Compare Stripe paid invoice periods with local entitlements; read-only by default')]
class ReconcileStripeBilling extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(PaidInvoicePeriod $period): int
    {
        if ($this->option('repair') && (! app()->environment(['local', 'testing']) || config('database.connections.mysql.database') !== 'testing')) {
            $this->error('Repair is restricted to the disposable local MySQL testing database.');

            return self::FAILURE;
        }
        if (! str_starts_with((string) config('cashier.secret'), 'sk_test_') || config('cashier.secret') === 'sk_test_replace_me') {
            $this->error('Stripe test keys are required.');

            return self::FAILURE;
        }
        $user = User::find($this->argument('user'));
        if (! $user || ! $user->stripe_id) {
            $this->error('This user has no Stripe sandbox customer.');

            return self::FAILURE;
        }
        try {
            $stripe = Cashier::stripe();
            $expected = [];
            $observedSubscriptions = [];
            foreach ($stripe->invoices->all(['customer' => $user->stripe_id, 'status' => 'paid', 'limit' => 100])->autoPagingIterator() as $invoice) {
                $data = $invoice->toArray();
                if (($data['amount_paid'] ?? 0) <= 0 || ! ($data['parent']['subscription_details']['subscription'] ?? null)) {
                    continue;
                }
                if ($data['lines']['has_more'] ?? false) {
                    $data['lines'] = ['has_more' => false, 'data' => array_map(fn ($line): array => $line->toArray(), iterator_to_array($stripe->invoices->allLines($invoice->id, ['limit' => 100])->autoPagingIterator()))];
                }
                $attributes = $period->fromInvoice($data, $user);
                $subscriptionId = $attributes['stripe_subscription_id'];
                if (! isset($observedSubscriptions[$subscriptionId])) {
                    $subscription = $stripe->subscriptions->retrieve($subscriptionId);
                    if ($subscription->livemode !== false || $subscription->customer !== $user->stripe_id) {
                        throw new \UnexpectedValueException('Subscription ownership mismatch');
                    }
                    $observedSubscriptions[$subscriptionId] = true;
                    $this->line('Stripe subscription '.$subscriptionId.': '.$subscription->status);
                }
                $existing = PaidPeriod::where('stripe_invoice_id', $invoice->id)->first();
                if ($existing && $existing->user_id !== $user->id) {
                    throw new \UnexpectedValueException('Invoice ownership conflict');
                }
                $expected[$invoice->id] = $attributes;
            }
            foreach (PaidPeriod::where('user_id', $user->id)->get() as $existing) {
                if (! isset($expected[$existing->stripe_invoice_id])) {
                    $this->error('An unexpected local invoice period exists; automatic deletion is unsupported.');

                    return self::FAILURE;
                }
            }
            $mismatches = [];
            foreach ($expected as $invoiceId => $attributes) {
                $existing = PaidPeriod::where('stripe_invoice_id', $invoiceId)->first();
                $matches = $existing && $existing->stripe_subscription_id === $attributes['stripe_subscription_id']
                    && $existing->period_start->toDateTimeString() === $attributes['period_start'] && $existing->period_end->toDateTimeString() === $attributes['period_end'];
                $this->line($invoiceId.': '.($matches ? 'matches' : 'mismatch').' | expected expiry '.$attributes['period_end'].' UTC | local '.($existing?->period_end?->toDateTimeString() ?? 'missing'));
                if (! $matches) {
                    $mismatches[$invoiceId] = $attributes;
                }
            }
            if ($this->option('repair')) {
                DB::transaction(function () use ($mismatches): void {
                    foreach ($mismatches as $invoiceId => $attributes) {
                        PaidPeriod::updateOrCreate(['stripe_invoice_id' => $invoiceId], $attributes);
                    }
                });
                $this->info('Repaired periods: '.count($mismatches));

                return self::SUCCESS;
            }
            $this->info('Read-only mismatches: '.count($mismatches));

            return $mismatches === [] ? self::SUCCESS : self::FAILURE;
        } catch (Throwable) {
            $this->error('Reconciliation could not establish authoritative sandbox state. No repair was performed.');

            return self::FAILURE;
        }
    }
}
