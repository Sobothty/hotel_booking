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
        // Check if columns exist before adding
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
        Schema::table('bookings', function (Blueprint $table) {
            $columns = [];

            if (Schema::hasColumn('bookings', 'exchange_rate')) {
                $columns[] = 'exchange_rate';
            }

            if (Schema::hasColumn('bookings', 'total_price_local')) {
                $columns[] = 'total_price_local';
            }

            if (!empty($columns)) {
                $table->dropColumn($columns);
            }
        });
    }
};
