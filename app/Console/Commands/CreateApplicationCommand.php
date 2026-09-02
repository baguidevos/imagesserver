<?php

namespace App\Console\Commands;

use App\Models\Application;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class CreateApplicationCommand extends Command
{
    protected $signature = 'app:create-application
        {name : The application name}
        {--slug= : URL-friendly slug (auto-generated from name if omitted)}';

    protected $description = 'Create a new application and display its API key';

    public function handle(): int
    {
        $name = $this->argument('name');
        $slug = $this->option('slug') ?? Str::slug($name);

        if (Application::where('slug', $slug)->exists()) {
            $this->error("An application with slug \"{$slug}\" already exists.");

            return self::FAILURE;
        }

        $plainKey = 'app_'.bin2hex(random_bytes(32));

        $application = Application::create([
            'name' => $name,
            'slug' => $slug,
            'api_key_hash' => hash('sha256', $plainKey),
        ]);

        $this->info('Application created successfully!');
        $this->newLine();
        $this->table(
            ['Field', 'Value'],
            [
                ['ID', $application->id],
                ['Name', $application->name],
                ['Slug', $application->slug],
                ['API Key', $plainKey],
            ],
        );
        $this->newLine();
        $this->warn('⚠  Save this API key now. It cannot be retrieved later.');

        return self::SUCCESS;
    }
}
