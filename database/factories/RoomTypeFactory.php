<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\RoomType>
 */
class RoomTypeFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => $this->faker->unique()->randomElement([
                'Single Room',
                'Double Room',
                'Suite',
                'Deluxe Room',
                'Family Room',
                'Executive Room',
                'Presidential Suite',
            ]),
            'description' => $this->faker->paragraph(),
            'price' => $this->faker->numberBetween(50, 300) + 0.99,
        ];
    }
}
