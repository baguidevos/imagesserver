<?php

namespace App\Console\Commands;

use App\Models\Admin;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;

class CreateAdminCommand extends Command
{
    protected $signature = 'app:create-admin
        {--name= : The admin name}
        {--email= : The admin email}
        {--password= : The admin password}';

    protected $description = 'Create a new admin user for the Filament panel';

    public function handle(): int
    {
        $name = $this->option('name') ?: $this->ask('Name', 'Admin');
        $email = $this->option('email') ?: $this->ask('Email address');

        if (Admin::where('email', $email)->exists()) {
            $this->error("An admin with email \"{$email}\" already exists.");

            return self::FAILURE;
        }

        $password = $this->option('password') ?: $this->secret('Password');

        if (! $password) {
            $this->error('Password cannot be empty.');

            return self::FAILURE;
        }

        $admin = Admin::create([
            'name' => $name,
            'email' => $email,
            'password' => Hash::make($password),
        ]);

        $this->info("Admin [{$admin->email}] created successfully!");

        return self::SUCCESS;
    }
}
