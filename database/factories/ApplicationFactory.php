<?php

namespace Database\Factories;

use App\Models\Application;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Application>
 */
class ApplicationFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->company(),
            'slug' => fake()->unique()->slug(2),
            'api_key_hash' => hash('sha256', 'app_'.bin2hex(random_bytes(32))),
            'is_active' => true,
        ];
    }

    /**
     * Create an application with a known plain-text API key.
     *
     * @return array{factory: static, plain_key: string}
     */
    public static function withPlainKey(): array
    {
        $plainKey = 'app_'.bin2hex(random_bytes(32));

        return [
            'factory' => (new static)->state(['api_key_hash' => hash('sha256', $plainKey)]),
            'plain_key' => $plainKey,
        ];
    }

    public function inactive(): static
    {
        return $this->state(['is_active' => false]);
    }
}
