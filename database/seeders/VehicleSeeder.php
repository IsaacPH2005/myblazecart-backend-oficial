<?php

namespace Database\Seeders;

use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class VehicleSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Datos de vehículos sin user_id, asociados a Lease On y Flip
        $vehiclesData = [
            // Vehículos para Lease On (negocio_id = 2)
            [
                'negocio_id' => 2, // Lease On
                'user_id' => 2, // Carlos Mendoza
                'numero_vin' => '1HGBH41JXMN109186',
                'marca' => 'Freightliner',
                'modelo' => 'Cascadia',
                'año' => 2020,
                'numero_placa' => 'CBB-001',
                'numero_dot' => 'DOT2023001',
                'tipo_vehiculo' => 'truck',
                'tipo_propiedad' => 'owned',
                'precio_compra' => 85000.00,
                'fecha_compra' => '2020-05-15',
                'valor_actual' => 72000.00,
                'millaje' => 185000,
                'vencimiento_registro' => '2025-12-31',
                'vencimiento_inspeccion' => '2025-10-15',
                'color' => 'Azul',
                'combustible' => 'diesel',
                'transmision' => 'manual',
                'capacidad_carga' => 26000,
                'estado' => 'activo',
                'is_active' => true,
                'observaciones' => 'Vehículo en excelente estado, mantenimiento al día',
            ],
            [
                'negocio_id' => 2, // Lease On
                'user_id' => null, // Se asignará después
                'numero_vin' => '1HGBH41JXMN109186',
                'marca' => 'Freightliner',
                'modelo' => 'Cascadia',
                'año' => 2020,
                'numero_placa' => 'CBB-001',
                'numero_dot' => 'DOT2023001',
                'tipo_vehiculo' => 'truck',
                'tipo_propiedad' => 'owned',
                'precio_compra' => 85000.00,
                'fecha_compra' => '2020-05-15',
                'valor_actual' => 72000.00,
                'millaje' => 185000,
                'vencimiento_registro' => '2025-12-31',
                'vencimiento_inspeccion' => '2025-10-15',
                'color' => 'Azul',
                'combustible' => 'diesel',
                'transmision' => 'manual',
                'capacidad_carga' => 26000,
                'estado' => 'activo',
                'is_active' => true,
                'observaciones' => 'Vehículo en excelente estado, mantenimiento al día',
            ],
            [
                'negocio_id' => 2, // Lease On
                'user_id' => null, // Se asignará después
                'numero_vin' => '1FTFW1ET5DFC10234',
                'marca' => 'Kenworth',
                'modelo' => 'T680',
                'año' => 2019,
                'numero_placa' => 'LPZ-002',
                'numero_dot' => 'DOT2023002',
                'tipo_vehiculo' => 'truck',
                'tipo_propiedad' => 'leased',
                'precio_compra' => 95000.00,
                'fecha_compra' => '2019-08-22',
                'valor_actual' => 65000.00,
                'millaje' => 245000,
                'vencimiento_registro' => '2025-06-30',
                'vencimiento_inspeccion' => '2025-04-20',
                'color' => 'Blanco',
                'combustible' => 'diesel',
                'transmision' => 'automatica',
                'capacidad_carga' => 28000,
                'estado' => 'activo',
                'is_active' => true,
                'observaciones' => 'Equipado con sistema de navegación avanzado',
            ],

            // Vehículos para Flip 4 (negocio_id = 4)
            [
                'negocio_id' => 4, // Flip 4
                'user_id' => null, // Se asignará después
                'numero_vin' => '3D7KU28C57G123456',
                'marca' => 'Peterbilt',
                'modelo' => '389',
                'año' => 2018,
                'numero_placa' => 'SCZ-003',
                'numero_dot' => 'DOT2023003',
                'tipo_vehiculo' => 'truck',
                'tipo_propiedad' => 'owned',
                'precio_compra' => 120000.00,
                'fecha_compra' => '2018-03-10',
                'valor_actual' => 78000.00,
                'millaje' => 320000,
                'vencimiento_registro' => '2025-09-15',
                'vencimiento_inspeccion' => '2025-07-10',
                'color' => 'Rojo',
                'combustible' => 'diesel',
                'transmision' => 'manual',
                'capacidad_carga' => 30000,
                'estado' => 'activo',
                'is_active' => true,
                'observaciones' => 'Certificado para transporte de materiales peligrosos',
            ],

            // Vehículos para Flip 5 (negocio_id = 5)
            [
                'negocio_id' => 5, // Flip 5
                'user_id' => null, // Se asignará después
                'numero_vin' => '1FUJGBDV3BLSP5678',
                'marca' => 'Volvo',
                'modelo' => 'VNL',
                'año' => 2021,
                'numero_placa' => 'PTI-004',
                'numero_dot' => 'DOT2023004',
                'tipo_vehiculo' => 'truck',
                'tipo_propiedad' => 'leased',
                'precio_compra' => 110000.00,
                'fecha_compra' => '2021-01-05',
                'valor_actual' => 95000.00,
                'millaje' => 125000,
                'vencimiento_registro' => '2026-02-28',
                'vencimiento_inspeccion' => '2025-12-15',
                'color' => 'Negro',
                'combustible' => 'diesel',
                'transmision' => 'automatica',
                'capacidad_carga' => 27000,
                'estado' => 'activo',
                'is_active' => true,
                'observaciones' => 'Equipado con tecnología de seguridad avanzada',
            ],

            // Vehículos para Flip 6 (negocio_id = 6)
            [
                'negocio_id' => 6, // Flip 6
                'user_id' => null, // Se asignará después
                'numero_vin' => '1XKWDB0X57J123456',
                'marca' => 'Mack',
                'modelo' => 'Anthem',
                'año' => 2019,
                'numero_placa' => 'TJA-005',
                'numero_dot' => 'DOT2023005',
                'tipo_vehiculo' => 'truck',
                'tipo_propiedad' => 'owned',
                'precio_compra' => 105000.00,
                'fecha_compra' => '2019-11-20',
                'valor_actual' => 72000.00,
                'millaje' => 210000,
                'vencimiento_registro' => '2025-08-15',
                'vencimiento_inspeccion' => '2025-06-10',
                'color' => 'Verde',
                'combustible' => 'diesel',
                'transmision' => 'manual',
                'capacidad_carga' => 29000,
                'estado' => 'activo',
                'is_active' => true,
                'observaciones' => 'Ideal para rutas montañosas',
            ],

            // Segundo vehículo para Lease On (negocio_id = 2)
            [
                'negocio_id' => 2, // Lease On
                'user_id' => null, // Se asignará después
                'numero_vin' => '1FUYFYYBXDHP56789',
                'marca' => 'International',
                'modelo' => 'LoneStar',
                'año' => 2020,
                'numero_placa' => 'STZ-006',
                'numero_dot' => 'DOT2023006',
                'tipo_vehiculo' => 'truck',
                'tipo_propiedad' => 'leased',
                'precio_compra' => 98000.00,
                'fecha_compra' => '2020-07-18',
                'valor_actual' => 76000.00,
                'millaje' => 195000,
                'vencimiento_registro' => '2025-11-30',
                'vencimiento_inspeccion' => '2025-09-20',
                'color' => 'Plateado',
                'combustible' => 'diesel',
                'transmision' => 'automatica',
                'capacidad_carga' => 26500,
                'estado' => 'activo',
                'is_active' => true,
                'observaciones' => 'Equipado con sistema de refrigeración para alimentos',
            ],
        ];

        $vehiculosCreados = [];
        foreach ($vehiclesData as $vehicleData) {
            $vehicle = Vehicle::firstOrCreate(
                ['numero_vin' => $vehicleData['numero_vin']],
                $vehicleData
            );
            $vehiculosCreados[] = $vehicle;
        }

        // Obtener usuarios con IDs del 5 al 10
        $users = User::whereIn('id', [5, 6, 7, 8, 9, 10])->get();

        // Asignar vehículos a usuarios
        foreach ($vehiculosCreados as $index => $vehicle) {
            if ($index < count($users)) {
                $vehicle->user_id = $users[$index]->id;
                $vehicle->save();
            }
        }

        $this->command->info('Seeder de vehículos ejecutado exitosamente.');
        $this->command->info('Total de vehículos creados: ' . count($vehiculosCreados));

        // Mostrar resumen de vehículos por usuario
        foreach ($vehiculosCreados as $vehicle) {
            $userName = $vehicle->user && $vehicle->user->generalData
                ? $vehicle->user->generalData->nombre . ' ' . $vehicle->user->generalData->apellido
                : 'Sin asignar';
            $businessName = $vehicle->business ? $vehicle->business->nombre : 'Sin negocio';
            $this->command->info("Vehículo {$vehicle->marca} {$vehicle->modelo} ({$vehicle->numero_placa}) - Negocio: {$businessName} asignado a: {$userName}");
        }
    }
}
