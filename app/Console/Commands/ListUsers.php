<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

class ListUsers extends Command
{
    protected $signature = 'sbn:list-users';
    protected $description = 'List all users with their instructor flag.';

    public function handle(): int
    {
        $rows = User::orderBy('id')->get(['id', 'name', 'email', 'is_instructor'])
            ->map(fn ($u) => [
                $u->id,
                $u->name,
                $u->email,
                $u->is_instructor ? 'yes' : 'no',
            ])->all();

        $this->table(['ID', 'Name', 'Email', 'Instructor'], $rows);
        return self::SUCCESS;
    }
}
