<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;
use Illuminate\Support\Facades\Hash;
use App\Models\User;

class AdminSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        //
        $adminRole = Role::firstOrCreate(['name' => 'admin']);
        Role::firstOrCreate(['name' => 'user']);

        // Create the initial administrator
        $admin = User::firstOrCreate(
            ['email' => 'admin@gmail.com'],
            [
                'name' => 'Administrator',
                'password' => Hash::make('admin'),
                'email_verified_at' => now(),
            ]
        );

        $admin->assignRole($adminRole);
    }
}
