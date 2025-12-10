<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Carbon;
use Spatie\Permission\Models\Role;

class AdminSeeder extends Seeder
{
    /* Super admin con todos los roles */
    public function run(): void
    {
        // Array con los datos de los tres administradores
        $admins = [
            [
                'email' => 'admin@blazecart.com',
                'password' => 'AdminPassword123!',
                'nombre' => 'Admin',
                'apellido' => 'Sistema',
                'documento_identidad' => '00000000',
                'celular' => '+51 999 888 777',
                'nacimiento' => '1985-01-15',
                'genero' => 'masculino',
                'direccion' => 'Av. Logística 123',
                'ciudad' => 'Lima',
                'departamento' => 'Lima Metropolitana',
                'codigo_postal' => '15001',
                'contacto_emergencia_nombre' => 'Soporte Técnico',
                'contacto_emergencia_telefono' => '+51 980 123 456',
                'notas' => 'Super administrador con acceso a todos los roles y permisos del sistema'
            ],
            [
                'email' => 'david@myblazecart.com',
                'password' => 'david1234',
                'nombre' => 'David',
                'apellido' => 'Villarroel',
                'documento_identidad' => '11111111',
                'celular' => '+1 3852963231',
                'nacimiento' => '1980-05-20',
                'genero' => 'masculino',
                'direccion' => 'Av. Administración 456',
                'ciudad' => 'Lima',
                'departamento' => 'Lima Metropolitana',
                'codigo_postal' => '15002',
                'contacto_emergencia_nombre' => 'Soporte Técnico 2',
                'contacto_emergencia_telefono' => '+51 980 654 321',
                'notas' => 'Segundo administrador con acceso a todos los roles y permisos del sistema'
            ],
            [
                'email' => 'alvaro@myblazecart.com',
                'password' => 'admin123',
                'nombre' => 'Alvaro',
                'apellido' => 'Sistema 3',
                'documento_identidad' => '22222222',
                'celular' => '+51 999 666 555',
                'nacimiento' => '1990-10-30',
                'genero' => 'femenino',
                'direccion' => 'Av. Seguridad 789',
                'ciudad' => 'Lima',
                'departamento' => 'Lima Metropolitana',
                'codigo_postal' => '15003',
                'contacto_emergencia_nombre' => 'Soporte Técnico 3',
                'contacto_emergencia_telefono' => '+51 980 987 654',
                'notas' => 'Tercer administrador con acceso a todos los roles y permisos del sistema'
            ]
        ];

        // Obtener TODOS los roles existentes en el sistema
        $allRoles = Role::all();

        foreach ($admins as $adminData) {
            // Verificar si el usuario ya existe
            $existingUser = User::where('email', $adminData['email'])->first();

            if (!$existingUser) {
                // Crear usuario administrador
                $userId = DB::table('users')->insertGetId([
                    'email' => $adminData['email'],
                    'password' => Hash::make($adminData['password']),
                    'email_verified_at' => now(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                // Crear datos generales asociados al administrador
                DB::table('general_data')->insert([
                    'user_id' => $userId,
                    'nombre' => $adminData['nombre'],
                    'apellido' => $adminData['apellido'],
                    'documento_identidad' => $adminData['documento_identidad'],
                    'celular' => $adminData['celular'],
                    'nacimiento' => $adminData['nacimiento'],
                    'genero' => $adminData['genero'],
                    'direccion' => $adminData['direccion'],
                    'ciudad' => $adminData['ciudad'],
                    'departamento' => $adminData['departamento'],
                    'codigo_postal' => $adminData['codigo_postal'],
                    'contacto_emergencia_nombre' => $adminData['contacto_emergencia_nombre'],
                    'contacto_emergencia_telefono' => $adminData['contacto_emergencia_telefono'],
                    'notas' => $adminData['notas'],
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                // Obtener el usuario
                $admin = User::find($userId);

                // Asignar todos los roles al usuario administrador
                $admin->assignRole($allRoles);

                // Mostrar mensaje informativo
                $this->command->info("Administrador creado con email '{$adminData['email']}'");
                $this->command->info("Se han asignado {$allRoles->count()} roles al administrador:");
            } else {
                // Si el usuario ya existe, asignamos todos los roles por si faltan alguno
                $existingUser->assignRole($allRoles);
                $this->command->info("El usuario con email '{$adminData['email']}' ya existe. Se han verificado/actualizado sus roles.");
            }
        }

        // Listar los roles asignados
        $this->command->info("Roles disponibles en el sistema:");
        foreach ($allRoles as $role) {
            $this->command->info("- {$role->name}");
        }
    }
}
