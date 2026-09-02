<?php

namespace Database\Seeders;

use App\Models\Application;
use Illuminate\Database\Seeder;

class CreateDefaultApplicationSeeder extends Seeder
{
    public function run(): void
    {
        $plainKey = 'app_dev_'.str_repeat('0', 60);

        Application::updateOrCreate(
            ['slug' => 'default'],
            [
                'name' => 'Default Application',
                'api_key_hash' => hash('sha256', $plainKey),
                'is_active' => true,
            ],
        );

        $this->command->info('Default application seeded.');
        $this->command->info("API Key: {$plainKey}");
        $this->command->warn('⚠  This is a development key. Do not use in production.');
    }
}
