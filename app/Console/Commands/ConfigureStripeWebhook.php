<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Laravel\Cashier\Cashier;
use Laravel\Cashier\Console\WebhookCommand;
use Throwable;

#[Signature('stripe:configure-webhook')]
#[Description('Configure the hosted sandbox endpoint and save its signing secret privately')]
class ConfigureStripeWebhook extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $url = 'https://demo.mohsinadeel.dev/laravel-stripe-billing-verification-demo/stripe/webhook';
        if (! str_starts_with((string) config('cashier.secret'), 'sk_test_') || config('cashier.secret') === 'sk_test_replace_me') {
            $this->error('A valid server Stripe test key is required.');

            return self::FAILURE;
        }
        $path = app()->environmentFilePath();
        if (! is_file($path) || ! is_writable($path)) {
            $this->error('The server environment file must exist and be writable.');

            return self::FAILURE;
        }
        $events = array_merge(WebhookCommand::DEFAULT_EVENTS, ['invoice.payment_failed', 'checkout.session.completed', 'checkout.session.async_payment_succeeded']);
        try {
            $api = Cashier::stripe()->webhookEndpoints;
            foreach ($api->all(['limit' => 100])->autoPagingIterator() as $endpoint) {
                if ($endpoint->url === $url) {
                    $contents = file_get_contents($path);
                    if (! preg_match('/^STRIPE_WEBHOOK_ENDPOINT_ID='.preg_quote($endpoint->id, '/').'\s*$/m', $contents)
                        || ! preg_match('/^STRIPE_WEBHOOK_SECRET=whsec_[A-Za-z0-9_]+\s*$/m', $contents)) {
                        $this->error('A matching endpoint already exists but its saved secret cannot be confirmed. Configure its secret privately; no endpoint was replaced.');

                        return self::FAILURE;
                    }
                    $api->update($endpoint->id, ['enabled_events' => $events, 'disabled' => false]);
                    $this->info('Existing hosted sandbox destination retained. Signing secret was not displayed or changed.');

                    return self::SUCCESS;
                }
            }
            $endpoint = $api->create(['url' => $url, 'api_version' => Cashier::STRIPE_VERSION, 'enabled_events' => $events,
                'description' => 'Laravel Stripe billing verification demo sandbox']);
            if (! preg_match('/^whsec_[A-Za-z0-9_]+$/', (string) $endpoint->secret)) {
                throw new \RuntimeException('Endpoint secret unavailable');
            }
            $contents = file_get_contents($path);
            foreach (['STRIPE_WEBHOOK_SECRET' => $endpoint->secret, 'STRIPE_WEBHOOK_ENDPOINT_ID' => $endpoint->id] as $key => $value) {
                $line = $key.'='.$value;
                $contents = preg_match('/^'.preg_quote($key, '/').'=/m', $contents)
                    ? preg_replace('/^'.preg_quote($key, '/').'=.*$/m', $line, $contents)
                    : rtrim($contents)."\n".$line."\n";
            }
            if (file_put_contents($path, $contents, LOCK_EX) === false) {
                throw new \RuntimeException('Environment write failed');
            }
            $this->info('Hosted sandbox destination configured. Signing secret saved privately; refresh configuration.');

            return self::SUCCESS;
        } catch (Throwable) {
            $this->error('Sandbox webhook configuration failed. Check API access and environment-file permissions privately. No secret was displayed.');

            return self::FAILURE;
        }
    }
}
