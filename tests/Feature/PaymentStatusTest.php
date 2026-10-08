<?php

namespace Tests\Feature;

use App\Models\PaidPeriod;
use App\Models\User;
use Tests\TestCase;

class PaymentStatusTest extends TestCase
{
    public function test_guest_cannot_poll_payment_status(): void
    {
        $this->getJson('/checkout/status')->assertUnauthorized();
    }

    public function test_only_the_signed_in_users_current_paid_period_confirms_payment(): void
    {
        $this->freezeTime();
        $owner = User::factory()->create();
        $visitor = User::factory()->create();
        $end = now()->addHour();
        $this->actingAs($owner)->getJson('/checkout/status')->assertExactJson(['confirmed' => false]);
        $this->get('/?checkout=returned')->assertSee('checkout-status.js')->assertSee('This page will update automatically');
        $this->get('/')->assertDontSee('checkout-status.js');
        PaidPeriod::create(['user_id' => $owner->id, 'stripe_invoice_id' => 'in_status', 'stripe_subscription_id' => 'sub_status', 'period_start' => now(), 'period_end' => $end]);
        $this->getJson('/checkout/status')->assertExactJson(['confirmed' => true])->assertHeader('Cache-Control', 'no-store, private');
        $this->get('/?checkout=returned')->assertSee('Payment confirmed')->assertDontSee('checkout-status.js');
        $this->actingAs($visitor)->getJson('/checkout/status')->assertExactJson(['confirmed' => false]);
        $this->travelTo($end);
        $this->actingAs($owner)->getJson('/checkout/status')->assertExactJson(['confirmed' => false]);
    }
}
