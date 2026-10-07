<?php

namespace Tests\Feature;

use App\Models\PaidPeriod;
use App\Models\Subscription;
use App\Models\SubscriptionItem;
use App\Models\User;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SharedUsersTest extends TestCase
{
    public function test_guests_must_sign_in_to_the_demo(): void
    {
        $this->get('/')->assertRedirect(route('login'));
        $this->get('/protected')->assertRedirect(route('login'));
    }

    public function test_shared_credentials_sign_in_and_log_out_of_the_demo(): void
    {
        $user = User::factory()->create(['password' => 'shared-test-password']);
        $this->post('/login', ['email' => $user->email, 'password' => 'shared-test-password'])
            ->assertRedirect(route('demo.index'));
        $this->assertAuthenticatedAs($user);
        $this->get('/')->assertOk()->assertSee($user->email);
        $this->post('/logout')->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_two_factor_accounts_cannot_bypass_their_challenge(): void
    {
        $user = User::factory()->create();
        $user->forceFill(['two_factor_secret' => 'enabled-fixture'])->save();
        $this->post('/login', ['email' => $user->email, 'password' => 'password'])
            ->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_hosted_checkout_rejects_live_keys(): void
    {
        $user = User::factory()->create();
        $this->app->detectEnvironment(fn (): string => 'production');
        config()->set('cashier.secret', 'sk_live_fixture');
        $this->actingAs($user)
            ->withSession(['_token' => 'checkout-fixture-token'])
            ->post('/checkout', ['_token' => 'checkout-fixture-token'])
            ->assertSessionHasErrors('stripe_price_id');
    }

    public function test_invalid_credentials_cannot_sign_in(): void
    {
        $user = User::factory()->create();
        $this->post('/login', ['email' => $user->email, 'password' => 'wrong-password'])
            ->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_users_are_shared_and_billing_relations_use_the_prefixed_connection(): void
    {
        $user = User::factory()->create();
        $subscription = $user->subscriptions()->create([
            'type' => 'default', 'stripe_id' => 'sub_shared_fixture', 'stripe_status' => 'active',
        ]);
        $this->assertSame('shared_users', $user->getConnectionName());
        $this->assertInstanceOf(Subscription::class, $subscription);
        $this->assertSame('mysql', $subscription->getConnectionName());
        $item = $subscription->items()->create([
            'stripe_id' => 'si_shared_fixture',
            'stripe_product' => 'prod_fixture',
            'stripe_price' => 'price_fixture',
        ]);
        $this->assertInstanceOf(SubscriptionItem::class, $item);
        $this->assertSame('mysql', $item->getConnectionName());
        $this->assertSame($user->id, $subscription->owner->id);
        $this->assertTrue(Schema::connection('shared_users')->hasTable('users'));
        $this->assertFalse(Schema::hasTable('users'));
    }

    public function test_another_users_paid_period_does_not_grant_access(): void
    {
        $owner = User::factory()->create();
        $visitor = User::factory()->create();
        PaidPeriod::create([
            'user_id' => $owner->id, 'stripe_subscription_id' => 'sub_other',
            'stripe_invoice_id' => 'in_other', 'period_start' => now(), 'period_end' => now()->addHour(),
        ]);
        $this->actingAs($visitor)->get('/protected')->assertForbidden();
        $this->get('/')->assertDontSee('in_other');
    }
}
