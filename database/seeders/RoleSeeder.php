<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;

class RoleSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Crear los roles
        $roles = [
            'admin',
            'usuario',
            'shipper',
            'dispatch',
            'inversionista',
            'carrier'
        ];

        foreach ($roles as $roleName) {
            Role::firstOrCreate([
                'name' => $roleName,
                'guard_name' => 'sanctum'
            ]);

            $this->command->info("Rol '{$roleName}' creado correctamente.");
        }
    }
}
