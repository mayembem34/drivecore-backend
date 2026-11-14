<?php

namespace App\Console\Commands;

use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;

class BootstrapAdministrator extends Command
{
    protected $signature = 'drivecore:bootstrap-admin {email} {--name=Administrator}';

    protected $description = 'Create the first platform administrator using an interactive password prompt';

    public function handle(): int
    {
        if (! $this->input->isInteractive()) {
            $this->error('Run interactively; passwords must not be command-line arguments.');

            return self::FAILURE;
        }
        if (User::where('is_platform_admin', true)->exists()) {
            $this->error('A platform administrator already exists.');

            return self::FAILURE;
        }
        $email = mb_strtolower($this->argument('email'));
        if (! filter_var($email, FILTER_VALIDATE_EMAIL) || User::where('email', $email)->exists()) {
            $this->error('A new valid email address is required.');

            return self::FAILURE;
        }
        $password = $this->secret('Password (at least 12 characters, letters and numbers)');
        if (! is_string($password) || mb_strlen($password) < 12 || ! preg_match('/[a-zA-Z]/', $password) || ! preg_match('/[0-9]/', $password) || $password !== $this->secret('Confirm password')) {
            $this->error('Password requirements or confirmation failed.');

            return self::FAILURE;
        }
        $this->call('db:seed', ['--class' => RolePermissionSeeder::class, '--force' => true]);
        $user = new User;
        $user->name = $this->option('name');
        $user->email = $email;
        $user->password = Hash::make($password);
        $user->is_platform_admin = true;
        $user->save();
        $this->info('Administrator created. No credentials were printed.');

        return self::SUCCESS;
    }
}
