<?php

namespace Database\Seeders;

use App\Models\Room;
use App\Models\RoomType;
use Illuminate\Database\Seeder;

class RoomSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Create 5 specific room types
        $roomTypeNames = [
            'Single Room',
            'Double Room',
            'Suite',
            'Family Room',
            'Deluxe Room'
        ];

        $roomTypes = [];

        foreach ($roomTypeNames as $name) {
            $roomTypes[] = RoomType::factory()->create([
                'name' => $name,
                'description' => "This is a $name with standard amenities"
            ]);
        }

        // For each room type, create 100 rooms
        foreach ($roomTypes as $roomType) {
            Room::factory()
                ->count(100)
                ->forRoomType($roomType)
                ->create();
        }
    }
}
