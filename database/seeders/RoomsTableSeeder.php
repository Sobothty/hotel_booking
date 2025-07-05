<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use App\Models\Room;
use App\Models\RoomType;
use Illuminate\Support\Facades\DB;

class RoomsTableSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Get all room types
        $roomTypes = RoomType::all();

        foreach ($roomTypes as $roomType) {
            // Create 20 rooms for each room type
            for ($i = 1; $i <= 20; $i++) {
                // Generate room number based on room type name
                $prefix = strtoupper(substr(str_replace(' ', '', $roomType->name), 0, 2));
                $roomNumber = $prefix . str_pad($i, 3, '0', STR_PAD_LEFT); // e.g., PR001, PR002, etc.

                Room::create([
                    'name' => $roomNumber,
                    'room_type_id' => $roomType->id,
                    'description' => "{$roomType->name} - Room {$roomNumber}",
                    'is_available' => true,
                ]);
            }

            // Output info (this will only show when using command line)
            if (isset($this->command)) {
                $this->command->info("Created 20 rooms for {$roomType->name}");
            }
        }
    }
}
