<?php

namespace Database\Seeders;

use App\Models\User;
use App\Models\Monitor;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class UserWithMonitorSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $user = User::create([
            'name' => 'Usuario Test',
            'email' => 'admin@example.com',
            'password' => Hash::make('password'),
        ]);

        Monitor::create([
            'name' => 'Google',
            'url' => 'https://www.google.com',
            'interval' => 60,
            'timeout' => 30,
            'expected_status_code' => 200,
            'user_id' => $user->id,
            'status' => 'up',
        ]);
    }
}
