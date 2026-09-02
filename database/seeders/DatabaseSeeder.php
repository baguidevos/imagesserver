<?php

namespace Database\Seeders;

use App\Models\Application;
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
        $this->call([
            CreateDefaultApplicationSeeder::class,
            CreateDefaultAdminSeeder::class,
        ]);

        $defaultApp = Application::where('slug', 'default')->first();

        User::factory()->create([
            'application_id' => $defaultApp->id,
            'name' => 'Test User',
            'email' => 'test@example.com',
        ]);
    }
}
