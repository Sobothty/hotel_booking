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
        Schema::create('bookings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained();
            $table->foreignId('room_type_id')->constrained();
            $table->foreignId('room_id')->nullable()->constrained();
            $table->date('check_in_date');
            $table->date('check_out_date');
            $table->integer('guests');
            $table->enum('status', ['confirmed', 'checked_in', 'completed', 'cancelled'])->default('confirmed');
            $table->decimal('total_price', 10, 2)->nullable();
            $table->enum('payment_status', ['unpaid', 'paid'])->default('unpaid');
            $table->string('payment_method')->default('cash');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('bookings');
    }
};
