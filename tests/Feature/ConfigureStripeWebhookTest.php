<?php

namespace Tests\Feature;

use Tests\TestCase;

class ConfigureStripeWebhookTest extends TestCase
{
    public function test_setup_saves_secret_privately_and_repeat_reuses_destination(): void
    {
        $directory = sys_get_temp_dir().'/stripe-config-'.bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        $path = $directory.'/.env';
        file_put_contents($path, "APP_ENV=production\nSTRIPE_WEBHOOK_SECRET=whsec_old_fixture\n");
        $original = app()->environmentPath();
        app()->useEnvironmentPath($directory);
        try {
            $this->fakeStripe([
                'GET /v1/webhook_endpoints' => ['object' => 'list', 'has_more' => false, 'data' => []],
                'POST /v1/webhook_endpoints' => function (array $params): array {
                    $this->assertSame('https://demo.mohsinadeel.dev/laravel-stripe-billing-verification-demo/stripe/webhook', $params['url']);
                    $this->assertContains('invoice.payment_failed', $params['enabled_events']);
                    $this->assertSame('2026-08-26.dahlia', $params['api_version']);

                    return ['id' => 'we_fixture', 'object' => 'webhook_endpoint', 'secret' => 'whsec_endpoint_fixture'];
                },
            ]);
            $this->artisan('stripe:configure-webhook')
                ->expectsOutput('Hosted sandbox destination configured. Signing secret saved privately; refresh configuration.')
                ->assertSuccessful();
            $this->assertStringContainsString('STRIPE_WEBHOOK_SECRET=whsec_endpoint_fixture', file_get_contents($path));
            $this->assertStringContainsString('APP_ENV=production', file_get_contents($path));
            $saved = file_get_contents($path);
            $this->fakeStripe([
                'GET /v1/webhook_endpoints' => ['object' => 'list', 'has_more' => false, 'data' => [['id' => 'we_fixture', 'url' => 'https://demo.mohsinadeel.dev/laravel-stripe-billing-verification-demo/stripe/webhook']]],
                'POST /v1/webhook_endpoints/we_fixture' => ['id' => 'we_fixture', 'object' => 'webhook_endpoint'],
            ]);
            $this->artisan('stripe:configure-webhook')->assertSuccessful();
            $this->assertSame($saved, file_get_contents($path));
        } finally {
            app()->useEnvironmentPath($original);
            unlink($path);
            rmdir($directory);
        }
    }
}
