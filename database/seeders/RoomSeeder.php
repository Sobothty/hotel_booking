<?php

namespace Database\Seeders;

use App\Models\Room;
use App\Models\RoomType;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class RoomSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Clear existing data first
        DB::statement('SET FOREIGN_KEY_CHECKS=0;');
        Room::truncate();
        RoomType::truncate();
        DB::statement('SET FOREIGN_KEY_CHECKS=1;');

        // Create 5 specific room types with prices
        $roomTypeData = [
            'Single Room' => 50.99,
            'Double Room' => 89.99,
            'Suite' => 159.99,
            'Family Room' => 129.99,
            'Deluxe Room' => 199.99
        ];

        $roomTypes = [];

        foreach ($roomTypeData as $name => $price) {
            $roomTypes[] = RoomType::factory()->create([
                'name' => $name,
                'description' => "This is a $name with standard amenities",
                'price' => $price
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
