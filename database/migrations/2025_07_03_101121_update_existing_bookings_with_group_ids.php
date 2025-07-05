<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use App\Models\Booking;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Get all bookings with null booking_group_id
        $bookings = Booking::whereNull('booking_group_id')->get();

        // Group by user_id, check_in_date and check_out_date
        $groups = $bookings->groupBy(function ($booking) {
            return $booking->user_id . '_' . $booking->check_in_date . '_' . $booking->check_out_date;
        });

        foreach ($groups as $bookingGroup) {
            // Generate a unique booking group ID
            $bookingGroupId = 'BKG-' . uniqid();

            // Update all bookings in this group
            foreach ($bookingGroup as $booking) {
                $booking->booking_group_id = $bookingGroupId;
                $booking->save();
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // No down method needed as we don't want to revert to NULL values
    }
};
