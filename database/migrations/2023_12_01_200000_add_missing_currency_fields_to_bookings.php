<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // First check if each column exists before trying to add it
        Schema::table('bookings', function (Blueprint $table) {
            $hasExchangeRate = DB::getSchemaBuilder()->hasColumn('bookings', 'exchange_rate');
            $hasTotalPriceLocal = DB::getSchemaBuilder()->hasColumn('bookings', 'total_price_local');

            if (!$hasExchangeRate) {
                $table->decimal('exchange_rate', 10, 2)->nullable();
            }

            if (!$hasTotalPriceLocal) {
                $table->decimal('total_price_local', 12, 2)->nullable();
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Only drop columns if they exist
        Schema::table('bookings', function (Blueprint $table) {
            $hasExchangeRate = DB::getSchemaBuilder()->hasColumn('bookings', 'exchange_rate');
            $hasTotalPriceLocal = DB::getSchemaBuilder()->hasColumn('bookings', 'total_price_local');

            if ($hasExchangeRate) {
                $table->dropColumn('exchange_rate');
            }

            if ($hasTotalPriceLocal) {
                $table->dropColumn('total_price_local');
            }
        });
    }
};
