<?php

namespace Tests\Feature;

use App\Models\DemoSetting;
use App\Models\User;
use Tests\TestCase;

class DemoSettingsTest extends TestCase
{
    public function test_guest_cannot_save_stripe_settings(): void
    {
        $this->post('/settings/stripe', ['stripe_price_id' => 'price_TestA'])
            ->assertRedirect(route('login'));
        $this->assertDatabaseCount('demo_settings', 0);
    }

    public function test_price_is_saved_updated_and_scoped_to_the_signed_in_user(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $this->actingAs($owner)->post('/settings/stripe', ['stripe_price_id' => 'price_TestA', 'user_id' => $other->id])
            ->assertRedirect(route('demo.index'));
        $this->assertSame('price_TestA', $owner->stripeDemoPriceId());
        $this->assertDatabaseHas('demo_settings', ['user_id' => $owner->id, 'stripe_price_id' => 'price_TestA']);
        $this->assertDatabaseMissing('demo_settings', ['user_id' => $other->id]);
        $this->post('/settings/stripe', ['stripe_price_id' => 'price_TestB'])->assertRedirect(route('demo.index'));
        $this->assertDatabaseCount('demo_settings', 1);
        $this->get('/')->assertSee('price_TestB')->assertSee('Stripe test setup');
        $this->actingAs($other)->get('/')->assertDontSee('price_TestB');
    }

    public function test_invalid_price_is_rejected_and_shown_in_the_modal_error_bag(): void
    {
        $this->actingAs(User::factory()->create())
            ->post('/settings/stripe', ['stripe_price_id' => 'sk_live_not_a_price'])
            ->assertSessionHasErrorsIn('stripeSetup', 'stripe_price_id');
        $this->assertDatabaseCount('demo_settings', 0);
    }

    public function test_existing_subscription_prevents_a_price_change(): void
    {
        $user = User::factory()->create();
        DemoSetting::create(['user_id' => $user->id, 'stripe_price_id' => 'price_Original']);
        $user->subscriptions()->create(['type' => 'default', 'stripe_id' => 'sub_locked_fixture', 'stripe_status' => 'active']);
        $this->actingAs($user)->post('/settings/stripe', ['stripe_price_id' => 'price_Replacement'])
            ->assertSessionHasErrorsIn('stripeSetup', 'stripe_price_id');
        $this->assertSame('price_Original', $user->stripeDemoPriceId());
    }
}
