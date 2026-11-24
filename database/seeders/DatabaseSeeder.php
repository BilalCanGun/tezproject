<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        User::updateOrCreate(['email' => 'admin@lab.test'], [
            'name' => 'Laboratuvar Yöneticisi',
            'password' => 'password',
            'role' => UserRole::Admin,
            'is_active' => true,
        ]);

        User::updateOrCreate(['email' => 'tester@lab.test'], [
            'name' => 'Deney Sorumlusu',
            'password' => 'password',
            'role' => UserRole::Tester,
            'is_active' => true,
        ]);

        $this->call([
            DeviceProfileSeeder::class,
            PilotProtocolSeeder::class,
            PilotProtocolV2Seeder::class,
        ]);
    }
}
