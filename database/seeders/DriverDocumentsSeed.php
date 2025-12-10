<?php

namespace Database\Seeders;

use App\Models\Driver;
use App\Models\DriverDocument;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

class DriverDocumentsSeed extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Obtener todos los conductores existentes
        $drivers = Driver::all();

        // Tipos de documentos disponibles
        $tiposDocumentos = [
            'licencia',
            'seguro',
            'identificacion',
            'certificado_medico',
            'registro_vehicular',
            'otros'
        ];

        // Nombres de documentos según el tipo
        $nombresDocumentos = [
            'licencia' => 'Licencia de Conducir',
            'seguro' => 'Póliza de Seguro',
            'identificacion' => 'Documento de Identidad',
            'certificado_medico' => 'Certificado Médico',
            'registro_vehicular' => 'Registro Vehicular',
            'otros' => 'Documento Adicional'
        ];

        // Crear documentos para cada conductor
        foreach ($drivers as $driver) {
            // Crear entre 2 y 4 documentos por conductor
            $numDocumentos = rand(2, 4);

            // Seleccionar tipos de documentos aleatorios sin repetir
            $tiposSeleccionados = (array)array_rand($tiposDocumentos, $numDocumentos);

            foreach ($tiposSeleccionados as $tipoIndex) {
                $tipo = is_int($tipoIndex) ? $tiposDocumentos[$tipoIndex] : $tipoIndex;

                // Determinar si el documento está aprobado (80% de probabilidad)
                $aprobado = rand(1, 100) <= 80;

                // Generar fecha de vencimiento (entre 1 mes y 3 años a partir de hoy)
                $diasVencimiento = rand(30, 1095); // 30 días a 3 años
                $fechaVencimiento = Carbon::now()->addDays($diasVencimiento);

                // Para algunos documentos, simular que están próximos a vencer o vencidos
                if (rand(1, 100) <= 20) { // 20% de probabilidad
                    // Documentos próximos a vencer (entre 1 y 30 días)
                    $fechaVencimiento = Carbon::now()->addDays(rand(1, 30));
                } elseif (rand(1, 100) <= 10) { // 10% de probabilidad
                    // Documentos vencidos (entre 1 y 90 días en el pasado)
                    $fechaVencimiento = Carbon::now()->subDays(rand(1, 90));
                }

                // Crear el documento
                DriverDocument::create([
                    'driver_id' => $driver->id,
                    'tipo' => $tipo,
                    'nombre' => $nombresDocumentos[$tipo] . ' - ' . $driver->user->generalData->nombre . ' ' . $driver->user->generalData->apellido,
                    'archivo' => 'drivers/documents/' . $tipo . '_' . $driver->id . '_' . uniqid() . '.pdf',
                    'fecha_vencimiento' => $fechaVencimiento,
                    'aprobado' => $aprobado,
                    'observaciones' => $this->generarObservaciones($tipo, $aprobado, $fechaVencimiento),
                    'created_at' => now(),
                    'updated_at' => now()
                ]);
            }
        }

        $this->command->info('✅ Se han creado documentos para ' . $drivers->count() . ' conductores exitosamente');
    }

    /**
     * Genera observaciones para el documento según su tipo y estado
     */
    private function generarObservaciones($tipo, $aprobado, $fechaVencimiento)
    {
        $observaciones = [];

        // Observaciones según tipo de documento
        switch ($tipo) {
            case 'licencia':
                $observaciones[] = 'Clase ' . ['A', 'B', 'C'][rand(0, 2)];
                break;
            case 'seguro':
                $observaciones[] = 'Cobertura completa';
                break;
            case 'certificado_medico':
                $observaciones[] = 'Apto para conducción profesional';
                break;
        }

        // Observaciones según estado de aprobación
        if (!$aprobado) {
            $observaciones[] = 'Pendiente de aprobación';
        } else {
            $observaciones[] = 'Aprobado';
        }

        // Observaciones según fecha de vencimiento
        $hoy = Carbon::now();
        if ($fechaVencimiento->lt($hoy)) {
            $observaciones[] = 'DOCUMENTO VENCIDO';
        } elseif ($fechaVencimiento->diffInDays($hoy) <= 30) {
            $observaciones[] = 'Próximo a vencer';
        }

        return implode(', ', $observaciones);
    }
}
