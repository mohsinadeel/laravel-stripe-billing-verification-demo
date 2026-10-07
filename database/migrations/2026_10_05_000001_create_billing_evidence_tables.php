<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('paid_periods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->index();
            $table->string('stripe_subscription_id');
            $table->string('stripe_invoice_id')->unique();
            $table->timestamp('period_start');
            $table->timestamp('period_end');
            $table->timestamps();
        });

        Schema::create('stripe_event_receipts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->index();
            $table->string('stripe_event_id')->unique();
            $table->string('event_type');
            $table->string('status');
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stripe_event_receipts');
        Schema::dropIfExists('paid_periods');
    }
};
