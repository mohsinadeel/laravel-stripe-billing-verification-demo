<?php

namespace Tests;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Stripe\ApiRequestor;
use Stripe\HttpClient\ClientInterface;
use Stripe\HttpClient\CurlClient;

abstract class TestCase extends BaseTestCase
{
    protected bool $useTransactions = true;

    protected function fakeStripe(array $responses): void
    {
        $client = \Mockery::mock(ClientInterface::class);
        $client->shouldReceive('request')->andReturnUsing(function ($method, $url, $headers, $params) use ($responses): array {
            $key = strtoupper($method).' '.parse_url($url, PHP_URL_PATH);
            if (! array_key_exists($key, $responses)) {
                throw new \RuntimeException('Unexpected Stripe fixture request: '.$key);
            }
            $response = $responses[$key];
            if (is_callable($response)) {
                $response = $response($params);
            }

            return [json_encode($response, JSON_THROW_ON_ERROR), isset($response['error']) ? 400 : 200, []];
        });
        ApiRequestor::setHttpClient($client);
        $this->beforeApplicationDestroyed(fn () => ApiRequestor::setHttpClient(new CurlClient));
        config()->set('cashier.secret', 'sk_test_fixture');
        config()->set('cashier.key', 'pk_test_fixture');
        config()->set('cashier.webhook.secret', 'whsec_fixture_secret');
    }

    private static bool $schemaPrepared = false;

    protected function setUp(): void
    {
        parent::setUp();

        if (config('database.connections.mysql.database') !== 'testing' || config('database.connections.shared_users.database') !== 'testing') {
            throw new \RuntimeException('Demo tests must use the dedicated testing database.');
        }

        if (! self::$schemaPrepared) {
            $schema = Schema::connection('shared_users');
            if (! $schema->hasTable('users')) {
                $schema->create('users', function (Blueprint $table): void {
                    $table->id();
                    $table->string('name');
                    $table->string('email')->unique();
                    $table->timestamp('email_verified_at')->nullable();
                    $table->string('password');
                    $table->rememberToken();
                    $table->string('stripe_id')->nullable()->index();
                    $table->string('pm_type')->nullable();
                    $table->string('pm_last_four', 4)->nullable();
                    $table->timestamp('trial_ends_at')->nullable();
                    $table->text('two_factor_secret')->nullable();
                    $table->timestamps();
                });
            }
            $this->artisan('migrate', ['--force' => true])->assertSuccessful();
            self::$schemaPrepared = true;
        }

        if (! $this->useTransactions) {
            return;
        }

        foreach (['mysql', 'shared_users'] as $connection) {
            DB::connection($connection)->beginTransaction();
        }

        $this->beforeApplicationDestroyed(function (): void {
            foreach (['mysql', 'shared_users'] as $connection) {
                DB::connection($connection)->rollBack();
            }
        });
    }
}
