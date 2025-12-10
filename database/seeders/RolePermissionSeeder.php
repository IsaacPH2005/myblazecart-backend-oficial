<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;

class RolePermissionSeeder extends Seeder
{
    public function run(): void
    {
        // Asignar permisos al rol Admin
        $adminRole = Role::where('name', 'admin')->where('guard_name', 'sanctum')->first();
        if ($adminRole) {
            $adminRole->givePermissionTo(Permission::all());
            $this->command->info("Permisos asignados al rol 'admin'.");
        }

        // Asignar permisos al rol Carrier
        $carrierRole = Role::where('name', 'carrier')->where('guard_name', 'sanctum')->first();
        if ($carrierRole) {
            $carrierRole->givePermissionTo([
                'view_dashboard',
                'edit_profile',
                'view_assigned_shipments',
                'update_delivery_status',
                'upload_delivery_proof',
                'view_route_details',
                'update_location'
            ]);
            $this->command->info("Permisos asignados al rol 'carrier'.");
        }
    }
}
