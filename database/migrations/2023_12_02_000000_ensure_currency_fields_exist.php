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
        if (!Schema::hasColumn('bookings', 'currency')) {
            Schema::table('bookings', function (Blueprint $table) {
                $table->string('currency')->default('USD');
            });
        }

        if (!Schema::hasColumn('bookings', 'exchange_rate')) {
            Schema::table('bookings', function (Blueprint $table) {
                $table->decimal('exchange_rate', 10, 2)->nullable();
            });
        }

        if (!Schema::hasColumn('bookings', 'total_price_local')) {
            Schema::table('bookings', function (Blueprint $table) {
                $table->decimal('total_price_local', 12, 2)->nullable();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // We won't drop columns in down() to prevent data loss
    }
};
