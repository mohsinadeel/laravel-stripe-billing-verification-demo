<?php

namespace Tests\Feature;

use App\Models\OneTimePayment;
use App\Models\PaidPeriod;
use App\Models\User;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class OneTimePaymentTest extends TestCase
{
    public function test_existing_subscriber_can_start_repeated_fixed_price_one_time_checkouts(): void
    {
        $user = User::factory()->create(['stripe_id' => 'cus_one_time']);
        $user->subscriptions()->create(['type' => 'default', 'stripe_id' => 'sub_existing', 'stripe_status' => 'active']);
        $attempt = 0;
        $this->fakeStripe([
            'GET /v1/customers/cus_one_time' => ['id' => 'cus_one_time', 'object' => 'customer'],
            'POST /v1/checkout/sessions' => function (array $params) use (&$attempt): array {
                $attempt++;
                $this->assertSame('payment', $params['mode']);
                $this->assertSame(1000, $params['line_items'][0]['price_data']['unit_amount']);
                $this->assertSame('usd', $params['line_items'][0]['price_data']['currency']);
                $this->assertSame(['card'], $params['payment_method_types']);
                $this->assertSame('false', $params['adaptive_pricing']['enabled']);
                $this->assertSame('one_time', $params['metadata']['demo_type']);
                $this->assertStringContainsString('one_time=returned&payment=', $params['success_url']);

                return ['id' => 'cs_test_attempt_'.$attempt, 'object' => 'checkout.session', 'url' => 'https://checkout.stripe.com/one-time-fixture'];
            },
        ]);
        $this->actingAs($user)->get('/')->assertSee('Start one-time test Checkout');
        $this->post('/checkout/one-time', ['amount' => 1])->assertRedirect('https://checkout.stripe.com/one-time-fixture');
        $this->post('/checkout/one-time')->assertRedirect('https://checkout.stripe.com/one-time-fixture');
        $this->assertDatabaseCount('one_time_payments', 2);
        $this->assertDatabaseHas('one_time_payments', ['user_id' => $user->id, 'stripe_checkout_session_id' => 'cs_test_attempt_2', 'status' => 'pending', 'amount' => 1000, 'currency' => 'usd']);
        $this->assertDatabaseCount('subscriptions', 1);
        $this->assertDatabaseCount('paid_periods', 0);
    }

    public function test_guests_and_live_keys_cannot_start_one_time_payments(): void
    {
        $this->post('/checkout/one-time')->assertRedirect(route('login'));
        $this->getJson('/checkout/one-time/1/status')->assertUnauthorized();
        $this->fakeStripe([]);
        config()->set('cashier.secret', 'sk_live_fixture');
        $this->actingAs(User::factory()->create())->post('/checkout/one-time')->assertSessionHasErrors('one_time_payment');
        $this->assertDatabaseCount('one_time_payments', 0);
    }

    public function test_signed_payment_confirms_only_its_order_and_preserves_subscription_entitlement(): void
    {
        $this->freezeTime();
        $user = User::factory()->create(['stripe_id' => 'cus_one_time']);
        $other = User::factory()->create();
        $period = PaidPeriod::create(['user_id' => $user->id, 'stripe_invoice_id' => 'in_existing', 'stripe_subscription_id' => 'sub_existing', 'period_start' => now(), 'period_end' => now()->addMonth()]);
        $before = $period->fresh()->getRawOriginal();
        $payment = OneTimePayment::create(['user_id' => $user->id, 'stripe_checkout_session_id' => 'cs_test_one_time']);
        $this->fakeStripe(['GET /v1/checkout/sessions/cs_test_one_time' => $this->checkoutSession($payment)]);
        $this->actingAs($user)->get('/?one_time=returned&payment='.$payment->id)->assertSee('One-time payment is pending')->assertSee('checkout-status.js');
        $this->getJson('/checkout/one-time/'.$payment->id.'/status')->assertExactJson(['confirmed' => false]);
        $this->signedEvent($this->checkoutSession($payment))->assertOk();
        $this->signedEvent($this->checkoutSession($payment))->assertOk();
        $this->assertDatabaseHas('one_time_payments', ['id' => $payment->id, 'status' => 'paid', 'stripe_payment_intent_id' => 'pi_one_time']);
        $paidAt = $payment->fresh()->paid_at->toDateTimeString();
        $this->travel(1)->minutes();
        $this->signedEvent($this->checkoutSession($payment), 'evt_related', 'checkout.session.async_payment_succeeded')->assertOk();
        $this->assertSame($paidAt, $payment->fresh()->paid_at->toDateTimeString());
        $this->assertSame($before, $period->fresh()->getRawOriginal());
        $this->assertDatabaseCount('one_time_payments', 1);
        $this->assertDatabaseCount('paid_periods', 1);
        $this->assertDatabaseCount('stripe_event_receipts', 2);
        $this->getJson('/checkout/one-time/'.$payment->id.'/status')->assertExactJson(['confirmed' => true]);
        $this->get('/?one_time=returned&payment='.$payment->id)->assertSee('One-time payment confirmed')->assertDontSee('checkout-status.js');
        $this->actingAs($other)->getJson('/checkout/one-time/'.$payment->id.'/status')->assertNotFound();
        $this->get('/')->assertDontSee('pi_one_time');
    }

    #[TestWith(['payment_status', 'unpaid', 200])]
    #[TestWith(['livemode', true, 500])]
    #[TestWith(['amount_total', 9999, 500])]
    #[TestWith(['customer', 'cus_wrong', 500])]
    #[TestWith(['currency', 'eur', 500])]
    #[TestWith(['status', 'open', 500])]
    #[TestWith(['payment_intent', null, 500])]
    public function test_unpaid_or_mismatched_sessions_cannot_confirm_a_payment(string $field, mixed $value, int $status): void
    {
        $user = User::factory()->create(['stripe_id' => 'cus_one_time']);
        $payment = OneTimePayment::create(['user_id' => $user->id, 'stripe_checkout_session_id' => 'cs_test_one_time']);
        $this->fakeStripe(['GET /v1/checkout/sessions/cs_test_one_time' => array_replace($this->checkoutSession($payment), [$field => $value])]);
        $this->signedEvent($this->checkoutSession($payment))->assertStatus($status);
        $this->assertSame('pending', $payment->fresh()->status);
        $this->assertDatabaseCount('paid_periods', 0);
    }

    public function test_api_failure_is_safe_and_a_failed_webhook_transaction_can_retry(): void
    {
        $user = User::factory()->create(['stripe_id' => 'cus_one_time']);
        $this->fakeStripe([
            'GET /v1/customers/cus_one_time' => ['id' => 'cus_one_time', 'object' => 'customer'],
            'POST /v1/checkout/sessions' => ['error' => ['type' => 'invalid_request_error', 'message' => 'PRIVATE_FIXTURE_MESSAGE']],
        ]);
        $this->actingAs($user)->post('/checkout/one-time')->assertSessionHasErrors(['one_time_payment' => 'Stripe could not start the one-time test Checkout. Please retry.']);
        $this->assertDatabaseHas('one_time_payments', ['user_id' => $user->id, 'status' => 'checkout_failed']);
        $payment = OneTimePayment::create(['user_id' => $user->id, 'stripe_checkout_session_id' => 'cs_test_one_time']);
        $this->fakeStripe(['GET /v1/checkout/sessions/cs_test_one_time' => $this->checkoutSession($payment)]);
        config()->set('services.stripe.fail_after_processing', true);
        $this->signedEvent($this->checkoutSession($payment))->assertStatus(500);
        $this->assertSame('pending', $payment->fresh()->status);
        config()->set('services.stripe.fail_after_processing', false);
        $this->signedEvent($this->checkoutSession($payment))->assertOk();
        $this->assertSame('paid', $payment->fresh()->status);
        $this->assertDatabaseHas('stripe_event_receipts', ['stripe_event_id' => 'evt_one_time', 'status' => 'completed', 'attempts' => 2]);
    }

    private function checkoutSession(OneTimePayment $payment): array
    {
        return ['id' => 'cs_test_one_time', 'object' => 'checkout.session', 'mode' => 'payment', 'status' => 'complete', 'customer' => 'cus_one_time',
            'livemode' => false, 'amount_total' => 1000, 'currency' => 'usd', 'payment_status' => 'paid', 'payment_intent' => 'pi_one_time',
            'metadata' => ['demo_type' => 'one_time', 'payment_id' => (string) $payment->id]];
    }

    private function signedEvent(array $session, string $id = 'evt_one_time', string $type = 'checkout.session.completed'): TestResponse
    {
        $body = json_encode(['id' => $id, 'type' => $type, 'livemode' => false, 'data' => ['object' => $session]], JSON_THROW_ON_ERROR);
        $timestamp = time();
        $signature = hash_hmac('sha256', $timestamp.'.'.$body, 'whsec_fixture_secret');

        return $this->call('POST', '/stripe/webhook', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_STRIPE_SIGNATURE' => "t={$timestamp},v1={$signature}"], $body);
    }
}
