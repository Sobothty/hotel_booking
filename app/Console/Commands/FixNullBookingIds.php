<?php

namespace App\Console\Commands;

use App\Models\Booking;
use Illuminate\Console\Command;

class FixNullBookingIds extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'bookings:fix-null-ids';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Fix bookings with null booking_group_id by assigning new unique IDs';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $nullIdBookings = Booking::whereNull('booking_group_id')->get();
        $count = $nullIdBookings->count();

        if ($count === 0) {
            $this->info('No bookings with null IDs found.');
            return 0;
        }

        $this->info("Found {$count} bookings with null booking_group_id.");

        // Group by user_id, check_in_date, and check_out_date to assign the same booking_group_id
        // to bookings that were likely made together
        $groupedBookings = $nullIdBookings->groupBy(function ($booking) {
            return $booking->user_id . '_' . $booking->check_in_date . '_' . $booking->check_out_date;
        });

        $groupsFixed = 0;
        $bookingsFixed = 0;

        foreach ($groupedBookings as $group) {
            $bookingGroupId = 'BKG-' . uniqid();
            $groupsFixed++;

            foreach ($group as $booking) {
                $booking->booking_group_id = $bookingGroupId;
                $booking->save();
                $bookingsFixed++;
            }

            $this->info("Fixed group {$groupsFixed}: assigned ID {$bookingGroupId} to {$group->count()} bookings.");
        }

        $this->info("Fixed {$bookingsFixed} bookings in {$groupsFixed} groups.");

        return 0;
    }
}
