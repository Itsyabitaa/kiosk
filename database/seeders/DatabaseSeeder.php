<?php

namespace Database\Seeders;

use App\Models\Admin;
use App\Models\Organization;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $org = Organization::create([
            'name' => 'Acme Corp',
            'plan_tier' => 'pro',
        ]);

        Admin::create([
            'org_id' => $org->id,
            'email' => 'admin@acmecorp.com',
            'password_hash' => Hash::make('password'),
            'role' => 'super_admin',
        ]);
    }
}
