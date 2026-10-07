<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $schema = Schema::connection('shared_users');
        if (! $schema->hasTable('users') || ! $schema->hasColumns('users', ['stripe_id', 'pm_type', 'pm_last_four', 'trial_ends_at'])) {
            throw new RuntimeException('Deploy and migrate the main application before the Stripe demo: shared users and Cashier fields are required.');
        }
    }

    public function down(): void
    {
        // The main application owns the shared users schema.
    }
};
