<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('stripe_event_receipts', function (Blueprint $table) {
            $table->unsignedInteger('attempts')->default(0);
            $table->string('last_error')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('stripe_event_receipts', function (Blueprint $table) {
            $table->dropColumn(['attempts', 'last_error']);
        });
    }
};
