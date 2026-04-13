<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        User::updateOrCreate(
            ['email' => 'lucas@soulbossanova.com'],
            [
                'name'     => 'Lucas',
                'password' => Hash::make('changeme123'),
            ]
        );
    }
}
