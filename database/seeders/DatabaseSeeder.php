<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // Your existing seeders

        // Add the rooms seeder
        $this->call([
            RoomsTableSeeder::class,
        ]);
    }
}
