<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PaidInvoiceWebhookTest extends TestCase
{
    use RefreshDatabase;

    private function invoicePayload(int $end): array
    {
        return [
            'id' => 'evt_fixture_1',
            'type' => 'invoice.payment_succeeded',
            'livemode' => false,
            'data' => ['object' => [
                'id' => 'in_fixture_1',
                'customer' => 'cus_fixture_1',
                'livemode' => false,
                'status' => 'paid',
                'amount_paid' => 1200,
                'parent' => ['subscription_details' => ['subscription' => 'sub_fixture_1']],
                'lines' => ['data' => [[
                    'pricing' => ['price_details' => ['price' => 'price_fixture_1']],
                    'parent' => ['subscription_item_details' => ['subscription' => 'sub_fixture_1']],
                    'period' => ['start' => $end - 3600, 'end' => $end],
                ]]],
            ]],
        ];
    }

    private function signedPost(array $payload): \Illuminate\Testing\TestResponse
    {
        $body = json_encode($payload, JSON_THROW_ON_ERROR);
        $timestamp = time();
        $signature = hash_hmac('sha256', $timestamp.'.'.$body, 'whsec_fixture_secret');

        return $this->call('POST', '/stripe/webhook', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_STRIPE_SIGNATURE' => "t={$timestamp},v1={$signature}",
        ], $body);
    }

    public function test_signed_paid_invoice_grants_access_only_before_its_period_end(): void
    {
        config()->set('cashier.webhook.secret', 'whsec_fixture_secret');
        config()->set('services.stripe.demo_price_id', 'price_fixture_1');
        User::factory()->create(['email' => 'demo@example.test', 'stripe_id' => 'cus_fixture_1']);
        $end = now()->addHour()->timestamp;

        $this->get('/protected')->assertForbidden();
        $this->signedPost($this->invoicePayload($end))->assertOk();
        $this->signedPost($this->invoicePayload($end))->assertOk();
        $this->assertDatabaseCount('paid_periods', 1);
        $this->get('/protected')->assertOk();

        $this->travelTo(now()->addHours(2));
        $this->get('/protected')->assertForbidden();
    }

    public function test_invalid_signature_cannot_create_entitlement(): void
    {
        config()->set('cashier.webhook.secret', 'whsec_fixture_secret');
        config()->set('services.stripe.demo_price_id', 'price_fixture_1');
        User::factory()->create(['email' => 'demo@example.test', 'stripe_id' => 'cus_fixture_1']);

        $this->withHeader('Stripe-Signature', 't=1,v1=invalid')
            ->postJson('/stripe/webhook', $this->invoicePayload(now()->addHour()->timestamp))
            ->assertForbidden();

        $this->assertDatabaseCount('paid_periods', 0);
    }
}
