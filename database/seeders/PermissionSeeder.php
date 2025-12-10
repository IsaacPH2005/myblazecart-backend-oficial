<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;

class PermissionSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Permisos generales
        $generalPermissions = [
            'view_dashboard',
            'edit_profile',
        ];

        // Permisos de administrador
        $adminPermissions = [
            'manage_users',
            'manage_roles',
            'manage_system',
            'view_reports',
            'manage_permissions',
        ];

        // Permisos de carrier
        $carrierPermissions = [
            'view_assigned_shipments',
            'update_delivery_status',
            'upload_delivery_proof',
            'view_route_details',
            'update_location',
        ];

        // Combinar todos los permisos
        $allPermissions = array_merge(
            $generalPermissions,
            $adminPermissions,
            $carrierPermissions
        );

        // Crear los permisos
        foreach ($allPermissions as $permission) {
            Permission::firstOrCreate([
                'name' => $permission,
                'guard_name' => 'sanctum'
            ]);

            $this->command->info("Permiso '{$permission}' creado correctamente.");
        }
    }
}
