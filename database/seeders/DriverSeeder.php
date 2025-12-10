<?php

namespace Database\Seeders;

use App\Models\Driver;
use App\Models\GeneralData;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;

class DriverSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Datos completos de usuarios con su información general
        $usuariosData = [
            [
                'user' => [
                    'email' => 'fernando.ramirez@empresa.com',
                    'password' => Hash::make('password123'),
                    'estado' => true,
                ],
                'general_data' => [
                    'nombre' => 'Fernando',
                    'apellido' => 'Ramírez',
                    'documento_identidad' => '67890123',
                    'celular' => '+591 70678901',
                    'nacimiento' => '1988-02-14',
                    'genero' => 'masculino',
                    'direccion' => 'Av. Ejército Nacional #987',
                    'ciudad' => 'Santa Cruz',
                    'departamento' => 'Santa Cruz',
                    'codigo_postal' => '0006',
                    'contacto_emergencia_nombre' => 'Patricia Ramírez',
                    'contacto_emergencia_telefono' => '+591 70109876',
                    'notas' => 'Conductor con experiencia en transporte de alimentos',
                ]
            ],
            [
                'user' => [
                    'email' => 'david.torres@empresa.com',
                    'password' => Hash::make('password123'),
                    'estado' => true,
                ],
                'general_data' => [
                    'nombre' => 'David',
                    'apellido' => 'Torres',
                    'documento_identidad' => '78901234',
                    'celular' => '+591 70789012',
                    'nacimiento' => '1983-12-03',
                    'genero' => 'masculino',
                    'direccion' => 'Calle Junín #246',
                    'ciudad' => 'Potosí',
                    'departamento' => 'Potosí',
                    'codigo_postal' => '0007',
                    'contacto_emergencia_nombre' => 'Sofía Torres',
                    'contacto_emergencia_telefono' => '+591 70210987',
                    'notas' => 'Experto en transporte de minerales',
                ]
            ],
            [
                'user' => [
                    'email' => 'javier.diaz@empresa.com',
                    'password' => Hash::make('password123'),
                    'estado' => true,
                ],
                'general_data' => [
                    'nombre' => 'Javier',
                    'apellido' => 'Díaz',
                    'documento_identidad' => '89012345',
                    'celular' => '+591 70890123',
                    'nacimiento' => '1987-08-27',
                    'genero' => 'masculino',
                    'direccion' => 'Av. Monseñor Rivero #135',
                    'ciudad' => 'Santa Cruz',
                    'departamento' => 'Santa Cruz',
                    'codigo_postal' => '0008',
                    'contacto_emergencia_nombre' => 'Lucía Díaz',
                    'contacto_emergencia_telefono' => '+591 70321098',
                    'notas' => 'Conductor con experiencia en transporte internacional',
                ]
            ],
            [
                'user' => [
                    'email' => 'ricardo.herrera@empresa.com',
                    'password' => Hash::make('password123'),
                    'estado' => true,
                ],
                'general_data' => [
                    'nombre' => 'Ricardo',
                    'apellido' => 'Herrera',
                    'documento_identidad' => '90123456',
                    'celular' => '+591 70901234',
                    'nacimiento' => '1984-06-10',
                    'genero' => 'masculino',
                    'direccion' => 'Calle Ballivián #579',
                    'ciudad' => 'Oruro',
                    'departamento' => 'Oruro',
                    'codigo_postal' => '0009',
                    'contacto_emergencia_nombre' => 'Valeria Herrera',
                    'contacto_emergencia_telefono' => '+591 70432109',
                    'notas' => 'Especialista en transporte de líquidos',
                ]
            ],
            [
                'user' => [
                    'email' => 'sergio.morales@empresa.com',
                    'password' => Hash::make('password123'),
                    'estado' => true,
                ],
                'general_data' => [
                    'nombre' => 'Sergio',
                    'apellido' => 'Morales',
                    'documento_identidad' => '12345678',
                    'celular' => '+591 70012345',
                    'nacimiento' => '1986-04-25',
                    'genero' => 'masculino',
                    'direccion' => 'Av. Saavedra #864',
                    'ciudad' => 'Beni',
                    'departamento' => 'Beni',
                    'codigo_postal' => '0010',
                    'contacto_emergencia_nombre' => 'Gabriela Morales',
                    'contacto_emergencia_telefono' => '+591 70543210',
                    'notas' => 'Conductor con experiencia en zonas tropicales',
                ]
            ],
            [
                'user' => [
                    'email' => 'martin.rojas@empresa.com',
                    'password' => Hash::make('password123'),
                    'estado' => true,
                ],
                'general_data' => [
                    'nombre' => 'Martín',
                    'apellido' => 'Rojas',
                    'documento_identidad' => '23456789',
                    'celular' => '+591 71123456',
                    'nacimiento' => '1982-07-18',
                    'genero' => 'masculino',
                    'direccion' => 'Av. Heroinas #321',
                    'ciudad' => 'Cochabamba',
                    'departamento' => 'Cochabamba',
                    'codigo_postal' => '0011',
                    'contacto_emergencia_nombre' => 'Laura Rojas',
                    'contacto_emergencia_telefono' => '+591 71234567',
                    'notas' => 'Especialista en transporte de mercancías frágiles',
                ]
            ],
            [
                'user' => [
                    'email' => 'ana.rodriguez@empresa.com',
                    'password' => Hash::make('password123'),
                    'estado' => true,
                ],
                'general_data' => [
                    'nombre' => 'Ana',
                    'apellido' => 'Rodríguez',
                    'documento_identidad' => '34567890',
                    'celular' => '+591 70234567',
                    'nacimiento' => '1990-07-22',
                    'genero' => 'femenino',
                    'direccion' => 'Calle Jordán #456',
                    'ciudad' => 'Cochabamba',
                    'departamento' => 'Cochabamba',
                    'codigo_postal' => '0002',
                    'contacto_emergencia_nombre' => 'Pedro Rodríguez',
                    'contacto_emergencia_telefono' => '+591 70765432',
                    'notas' => 'Especialista en rutas urbanas',
                ]
            ],
            [
                'user' => [
                    'email' => 'roberto.silva@empresa.com',
                    'password' => Hash::make('password123'),
                    'estado' => true,
                ],
                'general_data' => [
                    'nombre' => 'Roberto',
                    'apellido' => 'Silva',
                    'documento_identidad' => '45678901',
                    'celular' => '+591 70345678',
                    'nacimiento' => '1982-11-08',
                    'genero' => 'masculino',
                    'direccion' => 'Av. Blanco Galindo Km 4',
                    'ciudad' => 'Quillacollo',
                    'departamento' => 'Cochabamba',
                    'codigo_postal' => '0003',
                    'contacto_emergencia_nombre' => 'Carmen Silva',
                    'contacto_emergencia_telefono' => '+591 70876543',
                    'notas' => 'Conductor de rutas largas',
                ]
            ],
            [
                'user' => [
                    'email' => 'maria.gonzalez@empresa.com',
                    'password' => Hash::make('password123'),
                    'estado' => true,
                ],
                'general_data' => [
                    'nombre' => 'María',
                    'apellido' => 'González',
                    'documento_identidad' => '56789012',
                    'celular' => '+591 70456789',
                    'nacimiento' => '1988-05-12',
                    'genero' => 'femenino',
                    'direccion' => 'Calle Sucre #789',
                    'ciudad' => 'Sacaba',
                    'departamento' => 'Cochabamba',
                    'codigo_postal' => '0004',
                    'contacto_emergencia_nombre' => 'Luis González',
                    'contacto_emergencia_telefono' => '+591 70987654',
                    'notas' => 'Conductor de vehículos livianos',
                ]
            ],
            [
                'user' => [
                    'email' => 'luis.vargas@empresa.com',
                    'password' => Hash::make('password123'),
                    'estado' => true,
                ],
                'general_data' => [
                    'nombre' => 'Luis',
                    'apellido' => 'Vargas',
                    'documento_identidad' => '11223344',
                    'celular' => '+591 70567890',
                    'nacimiento' => '1979-09-25',
                    'genero' => 'masculino',
                    'direccion' => 'Av. Oquendo #321',
                    'ciudad' => 'Cochabamba',
                    'departamento' => 'Cochabamba',
                    'codigo_postal' => '0005',
                    'contacto_emergencia_nombre' => 'Rosa Vargas',
                    'contacto_emergencia_telefono' => '+591 70098765',
                    'notas' => 'Certificado HAZMAT',
                ]
            ],
            [
                'user' => [
                    'email' => 'carmen.flores@empresa.com',
                    'password' => Hash::make('password123'),
                    'estado' => true,
                ],
                'general_data' => [
                    'nombre' => 'Carmen',
                    'apellido' => 'Flores',
                    'documento_identidad' => '22334455',
                    'celular' => '+591 70678901',
                    'nacimiento' => '1992-01-30',
                    'genero' => 'femenino',
                    'direccion' => 'Calle Antezana #654',
                    'ciudad' => 'Cochabamba',
                    'departamento' => 'Cochabamba',
                    'codigo_postal' => '0006',
                    'contacto_emergencia_nombre' => 'José Flores',
                    'contacto_emergencia_telefono' => '+591 70109876',
                    'notas' => 'Transporte ejecutivo',
                ]
            ],
            [
                'user' => [
                    'email' => 'diego.morales@empresa.com',
                    'password' => Hash::make('password123'),
                    'estado' => false, // Usuario inactivo por licencia próxima a vencer
                ],
                'general_data' => [
                    'nombre' => 'Diego',
                    'apellido' => 'Morales',
                    'documento_identidad' => '33445566',
                    'celular' => '+591 70789012',
                    'nacimiento' => '1987-12-03',
                    'genero' => 'masculino',
                    'direccion' => 'Av. Heroínas #987',
                    'ciudad' => 'Cochabamba',
                    'departamento' => 'Cochabamba',
                    'codigo_postal' => '0007',
                    'contacto_emergencia_nombre' => 'Ana Morales',
                    'contacto_emergencia_telefono' => '+591 70210987',
                    'notas' => 'Requiere renovación urgente',
                ]
            ],
            [
                'user' => [
                    'email' => 'patricia.lopez@empresa.com',
                    'password' => Hash::make('password123'),
                    'estado' => true,
                ],
                'general_data' => [
                    'nombre' => 'Patricia',
                    'apellido' => 'López',
                    'documento_identidad' => '44556677',
                    'celular' => '+591 70890123',
                    'nacimiento' => '1983-04-18',
                    'genero' => 'femenino',
                    'direccion' => 'Calle 25 de Mayo #147',
                    'ciudad' => 'Tiquipaya',
                    'departamento' => 'Cochabamba',
                    'codigo_postal' => '0008',
                    'contacto_emergencia_nombre' => 'Miguel López',
                    'contacto_emergencia_telefono' => '+591 70321098',
                    'notas' => 'Conductor senior internacional',
                ]
            ],
        ];

        $usuariosCreados = [];
        // Crear usuarios con sus datos generales
        foreach ($usuariosData as $index => $userData) {
            // Crear usuario
            $user = User::firstOrCreate(
                ['email' => $userData['user']['email']],
                $userData['user']
            );
            // Crear datos generales del usuario
            GeneralData::firstOrCreate(
                ['user_id' => $user->id],
                array_merge($userData['general_data'], ['user_id' => $user->id])
            );
            $usuariosCreados[] = $user;
            // Asignar rol de carrier si usas Spatie Permission
            if (method_exists($user, 'assignRole')) {
                $user->assignRole('carrier');
            }
        }

        // Datos de conductores - Ajustados para coincidir con los selects del formulario
        $conductores = [
            [
                'user_id' => $usuariosCreados[0]->id,
                'numero_licencia' => 'CDL006789012',
                'vencimiento_licencia' => Carbon::now()->addYears(2),
                'estado_licencia' => 'vigente',
                'clase_cdl' => 'B',
                'tipo_licencia' => 'profesional',
                'restricciones' => 'Transporte de alimentos perecederos',
                'categoria' => 'segunda',
                'estado' => true,
                'observaciones' => 'Especialista en cadena de frío',
                'foto' => 'drivers/fernando_ramirez.jpg',
            ],
            [
                'user_id' => $usuariosCreados[1]->id,
                'numero_licencia' => 'CDL007890123',
                'vencimiento_licencia' => Carbon::now()->addYears(3),
                'estado_licencia' => 'vigente',
                'clase_cdl' => 'A',
                'tipo_licencia' => 'profesional',
                'restricciones' => 'Transporte de minerales',
                'categoria' => 'primera',
                'estado' => true,
                'observaciones' => 'Certificado para transporte de materiales pesados',
                'foto' => 'drivers/david_torres.jpg',
            ],
            [
                'user_id' => $usuariosCreados[2]->id,
                'numero_licencia' => 'CDL008901234',
                'vencimiento_licencia' => Carbon::now()->addYears(2),
                'estado_licencia' => 'vigente',
                'clase_cdl' => 'A',
                'tipo_licencia' => 'internacional',
                'restricciones' => 'Ninguna',
                'categoria' => 'primera',
                'estado' => true,
                'observaciones' => 'Experiencia en rutas internacionales',
                'foto' => 'drivers/javier_diaz.jpg',
            ],
            [
                'user_id' => $usuariosCreados[3]->id,
                'numero_licencia' => 'CDL009012345',
                'vencimiento_licencia' => Carbon::now()->addYears(1),
                'estado_licencia' => 'vigente',
                'clase_cdl' => 'B',
                'tipo_licencia' => 'profesional',
                'restricciones' => 'Transporte de líquidos',
                'categoria' => 'segunda',
                'estado' => true,
                'observaciones' => 'Especialista en tanques y cisternas',
                'foto' => 'drivers/ricardo_herrera.jpg',
            ],
            [
                'user_id' => $usuariosCreados[4]->id,
                'numero_licencia' => 'CDL010123456',
                'vencimiento_licencia' => Carbon::now()->addYears(3),
                'estado_licencia' => 'vigente',
                'clase_cdl' => 'C',
                'tipo_licencia' => 'profesional',
                'restricciones' => 'Conducción en zonas tropicales',
                'categoria' => 'tercera',
                'estado' => true,
                'observaciones' => 'Experiencia en rutas de selva y trópico',
                'foto' => 'drivers/sergio_morales.jpg',
            ],
            [
                'user_id' => $usuariosCreados[5]->id,
                'numero_licencia' => 'CDL011234567',
                'vencimiento_licencia' => Carbon::now()->addYears(2),
                'estado_licencia' => 'vigente',
                'clase_cdl' => 'B',
                'tipo_licencia' => 'profesional',
                'restricciones' => 'Transporte de mercancías frágiles',
                'categoria' => 'segunda',
                'estado' => true,
                'observaciones' => 'Certificado en manejo de carga delicada',
                'foto' => 'drivers/martin_rojas.jpg',
            ],
        ];

        // Crear los registros de conductores
        foreach ($conductores as $conductorData) {
            Driver::create($conductorData);
        }

        $this->command->info('✅ Se han creado ' . count($conductores) . ' conductores exitosamente');
    }
}
