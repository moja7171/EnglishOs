<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // Dev/testing convenience only — User::factory() needs Faker,
        // which is a require-dev package and correctly absent from a
        // `composer install --no-dev` production build. Gating this also
        // means a real production deploy's db:seed never creates a bogus
        // test@example.com account on the live site.
        if (app()->environment('local', 'testing')) {
            User::firstOrCreate(
                ['email' => 'test@example.com'],
                User::factory()->raw(['name' => 'Test User', 'email' => 'test@example.com'])
            );
        }

        $this->call(MissionSeeder::class);
    }
}
