<?php

namespace Tests\Feature;

use App\Models\DemoSetting;
use App\Models\User;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class CheckoutTest extends TestCase
{
    public function test_hosted_test_checkout_redirects_without_granting_access(): void
    {
        $this->app->detectEnvironment(fn (): string => 'production');
        $user = User::factory()->create(['stripe_id' => 'cus_checkout']);
        DemoSetting::create(['user_id' => $user->id, 'stripe_price_id' => 'price_Checkout']);
        $this->fakeStripe([
            'GET /v1/prices/price_Checkout' => $this->price(),
            'GET /v1/customers/cus_checkout' => ['id' => 'cus_checkout', 'object' => 'customer', 'livemode' => false],
            'POST /v1/checkout/sessions' => function (array $params): array {
                $this->assertSame('cus_checkout', $params['customer']);
                $this->assertSame('price_Checkout', $params['line_items'][0]['price']);
                $this->assertSame('subscription', $params['mode']);
                $this->assertSame(route('demo.index').'?checkout=returned', $params['success_url']);

                return ['id' => 'cs_test_fixture', 'object' => 'checkout.session', 'url' => 'https://checkout.stripe.com/fixture'];
            },
        ]);
        $this->actingAs($user)->get('/')->assertSee('Start test checkout');
        $this->withSession(['_token' => 'checkout-token'])->post('/checkout', ['_token' => 'checkout-token'])->assertRedirect('https://checkout.stripe.com/fixture');
        $this->get('/?checkout=returned')->assertSee('Access remains pending');
        $this->get('/protected')->assertForbidden();
        $this->assertDatabaseCount('paid_periods', 0);
    }

    #[TestWith(['livemode', true])]
    #[TestWith(['active', false])]
    #[TestWith(['type', 'one_time'])]
    #[TestWith(['recurring', null])]
    public function test_unsuitable_price_cannot_be_saved_or_used_for_checkout(string $field, mixed $value): void
    {
        $user = User::factory()->create();
        config()->set('services.stripe.demo_price_id', 'price_Checkout');
        $this->fakeStripe(['GET /v1/prices/price_Checkout' => array_replace($this->price(), [$field => $value])]);
        $this->actingAs($user)->post('/settings/stripe', ['stripe_price_id' => 'price_Checkout'])
            ->assertSessionHasErrorsIn('stripeSetup', 'stripe_price_id');
        $this->assertDatabaseCount('demo_settings', 0);
        $this->post('/checkout')->assertSessionHasErrors('stripe_price_id');
        $this->assertDatabaseCount('paid_periods', 0);
    }

    public function test_price_from_another_sandbox_returns_a_safe_error(): void
    {
        $this->fakeStripe(['GET /v1/prices/price_Checkout' => ['error' => ['type' => 'invalid_request_error', 'message' => 'SECRET_ERROR_FIXTURE']]]);
        $this->actingAs(User::factory()->create())->post('/settings/stripe', ['stripe_price_id' => 'price_Checkout'])
            ->assertSessionHasErrorsIn('stripeSetup', ['stripe_price_id' => 'Stripe could not verify this price. Check its sandbox and ID, or retry when Stripe is available.']);
        $this->assertDatabaseCount('demo_settings', 0);
    }

    public function test_existing_subscription_blocks_another_checkout(): void
    {
        $user = User::factory()->create();
        $user->subscriptions()->create(['type' => 'default', 'stripe_id' => 'sub_incomplete', 'stripe_status' => 'incomplete']);
        $this->actingAs($user)->post('/checkout')->assertConflict();
        $this->get('/protected')->assertForbidden();
    }

    private function price(): array
    {
        return ['id' => 'price_Checkout', 'object' => 'price', 'livemode' => false, 'active' => true, 'type' => 'recurring', 'recurring' => ['interval' => 'month']];
    }
}
