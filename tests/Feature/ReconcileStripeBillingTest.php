<?php

namespace Tests\Feature;

use App\Models\PaidPeriod;
use App\Models\User;
use Tests\TestCase;

class ReconcileStripeBillingTest extends TestCase
{
    public function test_read_only_diagnosis_and_explicit_repeatable_repair(): void
    {
        $user = User::factory()->create(['stripe_id' => 'cus_reconcile']);
        config()->set('services.stripe.demo_price_id', 'price_reconcile');
        $this->fakeStripe([
            'GET /v1/invoices' => ['object' => 'list', 'has_more' => false, 'data' => [[
                'id' => 'in_reconcile', 'object' => 'invoice', 'customer' => 'cus_reconcile', 'status' => 'paid', 'livemode' => false, 'amount_paid' => 1000,
                'parent' => ['subscription_details' => ['subscription' => 'sub_reconcile']],
                'lines' => ['has_more' => false, 'data' => [['pricing' => ['price_details' => ['price' => 'price_reconcile']], 'period' => ['start' => 1791450000, 'end' => 1791453600]]]],
            ]]],
            'GET /v1/subscriptions/sub_reconcile' => ['id' => 'sub_reconcile', 'object' => 'subscription', 'customer' => 'cus_reconcile', 'livemode' => false, 'status' => 'active'],
        ]);
        $local = PaidPeriod::create(['user_id' => $user->id, 'stripe_invoice_id' => 'in_reconcile', 'stripe_subscription_id' => 'sub_reconcile', 'period_start' => '2026-10-08 09:00:00', 'period_end' => '2026-10-08 11:00:00']);
        $before = $local->fresh()->getRawOriginal();
        $this->artisan('stripe:reconcile', ['user' => $user->id])->expectsOutput('Read-only mismatches: 1')->assertFailed();
        $this->assertSame($before, $local->fresh()->getRawOriginal());
        $this->artisan('stripe:reconcile', ['user' => $user->id, '--repair' => true])->expectsOutput('Repaired periods: 1')->assertSuccessful();
        $this->assertSame('2026-10-08 10:00:00', $local->fresh()->period_end->toDateTimeString());
        $this->artisan('stripe:reconcile', ['user' => $user->id, '--repair' => true])->expectsOutput('Repaired periods: 0')->assertSuccessful();
        $this->assertDatabaseCount('paid_periods', 1);
        $this->assertDatabaseCount('stripe_event_receipts', 0);
        $this->app->detectEnvironment(fn (): string => 'production');
        $this->artisan('stripe:reconcile', ['user' => $user->id, '--repair' => true])->assertFailed();
    }
}
