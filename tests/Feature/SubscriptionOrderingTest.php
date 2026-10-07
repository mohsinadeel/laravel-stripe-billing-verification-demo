<?php

namespace Tests\Feature;

use App\Models\PaidPeriod;
use App\Models\User;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class SubscriptionOrderingTest extends TestCase
{
    public function test_old_updated_and_deleted_events_cannot_regress_current_stripe_state(): void
    {
        $user = User::factory()->create(['stripe_id' => 'cus_ordering']);
        $current = $this->subscription('active');
        $this->fakeStripe(['GET /v1/subscriptions/sub_ordering' => $current]);
        $this->postEvent('evt_newer', 'customer.subscription.updated', $current)->assertOk();
        $this->postEvent('evt_older', 'customer.subscription.updated', $this->subscription('past_due'))->assertOk();
        $this->postEvent('evt_stale_deleted', 'customer.subscription.deleted', $this->subscription('canceled'))->assertOk();
        $this->assertSame('active', $user->subscription('default')->stripe_status);
        $this->assertDatabaseCount('subscriptions', 1);
        $this->assertDatabaseCount('subscription_items', 1);
        $this->assertDatabaseCount('stripe_event_receipts', 3);
        $this->assertDatabaseCount('paid_periods', 0);
    }

    public function test_period_end_cancellation_keeps_paid_access_and_does_not_reactivate_from_old_event(): void
    {
        $this->freezeTime();
        $end = now()->addHour();
        $user = User::factory()->create(['stripe_id' => 'cus_ordering']);
        PaidPeriod::create(['user_id' => $user->id, 'stripe_subscription_id' => 'sub_ordering', 'stripe_invoice_id' => 'in_ordering', 'period_start' => now(), 'period_end' => $end]);
        $current = $this->subscription('active');
        $current['cancel_at_period_end'] = true;
        $this->fakeStripe([
            'GET /v1/subscriptions/sub_ordering' => $current,
            'GET /v1/subscription_items/si_ordering' => ['id' => 'si_ordering', 'object' => 'subscription_item', 'current_period_end' => $end->timestamp],
        ]);
        $this->postEvent('evt_cancel_scheduled', 'customer.subscription.updated', $current)->assertOk();
        $this->assertSame($end->toDateTimeString(), $user->subscription('default')->ends_at->toDateTimeString());
        $this->actingAs($user)->get('/protected')->assertOk();
        $this->travelTo($end);
        $this->get('/protected')->assertForbidden();
        $cancelled = $this->subscription('canceled');
        $cancelled['canceled_at'] = $end->timestamp;
        $this->fakeStripe(['GET /v1/subscriptions/sub_ordering' => $cancelled]);
        $this->postEvent('evt_old_active', 'customer.subscription.updated', $this->subscription('active'))->assertOk();
        $this->assertSame('canceled', $user->fresh()->subscription('default')->stripe_status);
        $this->get('/protected')->assertForbidden();
        $this->assertDatabaseCount('paid_periods', 1);
    }

    public function test_authoritative_lookup_failure_returns_retryable_failure_without_overwriting_state(): void
    {
        $user = User::factory()->create(['stripe_id' => 'cus_ordering']);
        $user->subscriptions()->create(['type' => 'default', 'stripe_id' => 'sub_ordering', 'stripe_status' => 'active']);
        $this->fakeStripe(['GET /v1/subscriptions/sub_ordering' => ['error' => ['type' => 'invalid_request_error', 'message' => 'private error fixture']]]);
        $this->postEvent('evt_lookup_failure', 'customer.subscription.updated', $this->subscription('past_due'))->assertStatus(500);
        $this->assertSame('active', $user->fresh()->subscription('default')->stripe_status);
        $this->assertDatabaseHas('stripe_event_receipts', ['stripe_event_id' => 'evt_lookup_failure', 'status' => 'failed']);
    }

    private function subscription(string $status): array
    {
        return ['id' => 'sub_ordering', 'object' => 'subscription', 'customer' => 'cus_ordering', 'livemode' => false, 'status' => $status,
            'cancel_at_period_end' => false, 'trial_end' => null, 'metadata' => ['type' => 'default'],
            'items' => ['data' => [['id' => 'si_ordering', 'quantity' => 1, 'price' => ['id' => 'price_ordering', 'product' => 'prod_ordering']]]]];
    }

    private function postEvent(string $id, string $type, array $object): TestResponse
    {
        $body = json_encode(['id' => $id, 'type' => $type, 'livemode' => false, 'data' => ['object' => $object]], JSON_THROW_ON_ERROR);
        $timestamp = time();
        $signature = hash_hmac('sha256', $timestamp.'.'.$body, 'whsec_fixture_secret');

        return $this->call('POST', '/stripe/webhook', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_STRIPE_SIGNATURE' => "t={$timestamp},v1={$signature}"], $body);
    }
}
