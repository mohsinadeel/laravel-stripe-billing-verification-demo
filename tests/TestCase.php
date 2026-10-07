<?php

namespace Tests;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

abstract class TestCase extends BaseTestCase
{
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
