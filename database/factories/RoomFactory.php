<?php

namespace Database\Factories;

use App\Models\Room;
use App\Models\RoomType;
use Illuminate\Database\Eloquent\Factories\Factory;
use phpDocumentor\Reflection\PseudoTypes\True_;

class RoomFactory extends Factory
{
    protected $model = Room::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => 'Room ' . $this->faker->numberBetween(1000, 9999), // Larger range
            'room_type_id' => RoomType::factory(),
            'description' => $this->faker->paragraph(),
            'is_available' => true,
        ];
    }

    /**
     * Configure the room to belong to a specific room type.
     *
     * @return \Illuminate\Database\Eloquent\Factories\Factory
     */
    public function forRoomType(RoomType $roomType)
    {
        return $this->state(function (array $attributes) use ($roomType) {
            return [
                'room_type_id' => $roomType->id,
                'name' => $roomType->name . ' #' . $this->faker->numberBetween(1, 200), // Scoped range per room type
            ];
        });
    }
}
