<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;

class MakeInstructor extends Command
{
    protected $signature = 'sbn:make-instructor
        {email : Email of the user}
        {--name= : Display name (defaults to email local part)}
        {--password= : Password (prompted if omitted on new user)}
        {--demote : Remove the instructor flag instead of granting it}';

    protected $description = 'Create or promote a user to instructor, or demote with --demote. Idempotent.';

    public function handle(): int
    {
        $email = $this->argument('email');
        $demote = (bool) $this->option('demote');
        $name = $this->option('name') ?: explode('@', $email)[0];

        $user = User::where('email', $email)->first();

        if (!$user) {
            if ($demote) {
                $this->error("No user with email {$email}.");
                return self::FAILURE;
            }
            $password = $this->option('password') ?: $this->secret('Password for new user');
            if (!$password) {
                $this->error('Password is required when creating a new user.');
                return self::FAILURE;
            }
            $user = User::create([
                'name'     => $name,
                'email'    => $email,
                'password' => Hash::make($password),
            ]);
            $this->info("Created user: {$user->email} (id={$user->id})");
        } else {
            $this->line("User exists: {$user->email} (id={$user->id})");
        }

        if ($demote) {
            if ($user->is_instructor) {
                $user->is_instructor = false;
                $user->save();
                $this->info("Removed instructor flag.");
            } else {
                $this->line("Already not an instructor.");
            }
            return self::SUCCESS;
        }

        if (!$user->is_instructor) {
            $user->is_instructor = true;
            $user->save();
            $this->info("Flagged as instructor.");
        } else {
            $this->line("Already an instructor.");
        }

        $this->newLine();
        $this->line("Next: php artisan sbn:backfill-customer-backend --instructor={$email} --commit");

        return self::SUCCESS;
    }
}
