<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use App\Models\OperatingBox; // Asegúrate de importar el modelo

class OperatingBoxSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Datos de ejemplo para las cajas operativas
        $operatingBoxes = [
            [
                'nombre' => 'Caja Principal',
                'saldo' => 10000.00,
                'descripcion' => 'Caja operativa principal para ingresos y egresos generales',
                'estado' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'nombre' => 'Caja de Combustible',
                'saldo' => 5000.00,
                'descripcion' => 'Caja operativa para gastos de combustible',
                'estado' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'nombre' => 'Caja de Mantenimiento',
                'saldo' => 3000.00,
                'descripcion' => 'Caja operativa para gastos de mantenimiento de vehículos',
                'estado' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'nombre' => 'Caja de Viáticos',
                'saldo' => 2000.00,
                'descripcion' => 'Caja operativa para viáticos y gastos de viaje',
                'estado' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'nombre' => 'Caja de Emergencia',
                'saldo' => 1500.00,
                'descripcion' => 'Caja operativa para gastos de emergencia',
                'estado' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ];

        // Insertar los datos en la base de datos
        foreach ($operatingBoxes as $box) {
            OperatingBox::create($box);
        }

        $this->command->info('Se han creado ' . count($operatingBoxes) . ' cajas operativas exitosamente.');
    }
}
