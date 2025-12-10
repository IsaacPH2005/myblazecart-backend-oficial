<?php

namespace App\Http\Controllers\api\Vehicles;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Models\VehicleMaintenance;
use App\Models\Vehicle;
use Illuminate\Support\Facades\File;

class VehicleMaintenanceController extends Controller
{
    /**
     * Listar todos los mantenimientos de vehículos
     */
    public function index()
    {
        try {
            $maintenances = VehicleMaintenance::with('vehicle')->get();

            // Agregar URL del archivo
            foreach ($maintenances as $maintenance) {
                if ($maintenance->archivo) {
                    $maintenance->archivo = asset($maintenance->archivo);
                }
                $maintenance->rutaArchivoCompleta = $maintenance->archivo && file_exists(public_path($maintenance->archivo)) ? asset($maintenance->archivo) : null;
            }

            return response()->json([
                'message' => 'Mantenimientos de vehículos obtenidos exitosamente',
                'datos' => $maintenances
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Error al obtener los mantenimientos',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Mostrar mantenimientos de un vehículo específico
     */
    public function show($vehicleId)
    {
        try {
            $vehicle = Vehicle::findOrFail($vehicleId);
            $maintenances = $vehicle->maintenances()->with('vehicle')->get();

            // Agregar URL del archivo
            $maintenances->transform(function ($maintenance) {
                $maintenance->rutaArchivoCompleta = $maintenance->archivo && file_exists(public_path($maintenance->archivo)) ? asset($maintenance->archivo) : null;
                return $maintenance;
            });

            return response()->json([
                'message' => 'Mantenimientos del vehículo obtenidos exitosamente',
                'vehiculo' => $vehicle,
                'mantenimientos' => $maintenances
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Vehículo no encontrado',
                'error' => $e->getMessage()
            ], 404);
        }
    }

    /**
     * Subir un nuevo mantenimiento para un vehículo
     */
    public function store(Request $request)
    {
        $request->validate([
            'vehicle_id' => 'required|exists:vehicles,id',
            'tipo' => 'required|in:cambio_aceite,rotacion_neumaticos,inspeccion_frenos,alineacion,cambio_filtros,inspeccion_general,cambio_bateria,otros',
            'descripcion' => 'nullable|string|max:255',
            'fecha_programada' => 'required|date',
            'kilometraje_programado' => 'required|integer|min:0',
            'kilometraje_real' => 'nullable|integer|min:0',
            'costo' => 'nullable|numeric|min:0',
            'estado' => 'required|in:pendiente,completado,atrasado',
            'archivo' => 'nullable|file|mimes:pdf,jpg,jpeg,png,doc,docx|max:10240', // 10MB max
            'observaciones' => 'nullable|string'
        ]);

        try {
            DB::beginTransaction();

            // Obtener información del vehículo
            $vehicle = Vehicle::findOrFail($request->vehicle_id);

            // Manejo del archivo
            $rutaArchivo = null;
            if ($request->hasFile('archivo')) {
                $archivo = $request->file('archivo');
                $mimeType = $archivo->getMimeType();
                $allowedMimeTypes = [
                    'application/pdf',
                    'image/jpeg',
                    'image/png',
                    'application/msword',
                    'application/vnd.openxmlformats-officedocument.wordprocessingml.document'
                ];
                if (!in_array($mimeType, $allowedMimeTypes)) {
                    throw new \Exception('El archivo no tiene un formato válido');
                }

                $marca = $vehicle->marca ?? 'sin_marca';
                $modelo = $vehicle->modelo ?? 'sin_modelo';
                $nombreLimpio = $this->limpiarNombre($marca . '_' . $modelo);
                $extension = $archivo->getClientOriginalExtension();
                $nombreArchivo = "{$request->tipo}_" . time() . ".{$extension}";

                // Crear la carpeta específica del vehículo
                $rutaVehiculo = "vehicle_maintenances/{$nombreLimpio}";
                $rutaCarpetaCompleta = public_path($rutaVehiculo);
                if (!file_exists($rutaCarpetaCompleta)) {
                    mkdir($rutaCarpetaCompleta, 0755, true);
                }

                // Mover el archivo
                $archivo->move($rutaCarpetaCompleta, $nombreArchivo);
                $rutaArchivo = "{$rutaVehiculo}/{$nombreArchivo}";
            }

            // Crear el mantenimiento
            $maintenance = VehicleMaintenance::create([
                'vehicle_id' => $request->vehicle_id,
                'tipo' => $request->tipo,
                'descripcion' => $request->descripcion,
                'fecha_programada' => $request->fecha_programada,
                'kilometraje_programado' => $request->kilometraje_programado,
                'kilometraje_real' => $request->kilometraje_real,
                'costo' => $request->costo,
                'estado' => $request->estado,
                'archivo' => $rutaArchivo,
                'observaciones' => $request->observaciones,
            ]);

            // Si es un cambio de aceite completado, programar el próximo
            if ($request->tipo === 'cambio_aceite' && $request->estado === 'completado' && $request->kilometraje_real) {
                $nextKilometraje = $request->kilometraje_real + 10000;
                $nextFechaProgramada = now()->addMonths(6); // Estimación de 6 meses
                VehicleMaintenance::create([
                    'vehicle_id' => $request->vehicle_id,
                    'tipo' => 'cambio_aceite',
                    'descripcion' => 'Próximo cambio de aceite',
                    'fecha_programada' => $nextFechaProgramada,
                    'kilometraje_programado' => $nextKilometraje,
                    'estado' => 'pendiente',
                    'observaciones' => 'Programado automáticamente tras cambio de aceite completado',
                ]);
            }

            DB::commit();

            return response()->json([
                'message' => 'Mantenimiento registrado exitosamente',
                'datos' => $maintenance->load('vehicle'),
            ], 201);
        } catch (\Exception $e) {
            DB::rollback();

            // Eliminar archivo si se subió pero falló
            if ($rutaArchivo && file_exists(public_path($rutaArchivo))) {
                unlink(public_path($rutaArchivo));
            }

            return response()->json([
                'message' => 'Error al registrar el mantenimiento',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Actualizar un mantenimiento
     */
    public function update(Request $request, $id)
    {
        $request->validate([
            'tipo' => 'sometimes|in:cambio_aceite,rotacion_neumaticos,inspeccion_frenos,alineacion,cambio_filtros,inspeccion_general,cambio_bateria,otros',
            'descripcion' => 'sometimes|nullable|string|max:255',
            'fecha_programada' => 'sometimes|required|date',
            'kilometraje_programado' => 'sometimes|required|integer|min:0',
            'kilometraje_real' => 'sometimes|nullable|integer|min:0',
            'costo' => 'sometimes|nullable|numeric|min:0',
            'estado' => 'sometimes|required|in:pendiente,completado,atrasado',
            'archivo' => 'nullable|file|mimes:pdf,jpg,jpeg,png,doc,docx|max:10240',
            'observaciones' => 'sometimes|nullable|string'
        ]);

        try {
            DB::beginTransaction();

            $maintenance = VehicleMaintenance::with('vehicle')->findOrFail($id);
            $archivoAnterior = $maintenance->archivo;
            $nombreArchivo = $archivoAnterior;

            // Manejo de nuevo archivo
            if ($request->hasFile('archivo')) {
                $archivo = $request->file('archivo');
                $mimeType = $archivo->getMimeType();
                $allowedMimeTypes = [
                    'application/pdf',
                    'image/jpeg',
                    'image/png',
                    'application/msword',
                    'application/vnd.openxmlformats-officedocument.wordprocessingml.document'
                ];
                if (!in_array($mimeType, $allowedMimeTypes)) {
                    throw new \Exception('El archivo no tiene un formato válido');
                }

                $vehicle = $maintenance->vehicle;
                $marca = $vehicle->marca ?? 'sin_marca';
                $modelo = $vehicle->modelo ?? 'sin_modelo';
                $nombreLimpio = $this->limpiarNombre($marca . '_' . $modelo);
                $tipoDoc = $request->get('tipo', $maintenance->tipo);
                $extension = $archivo->getClientOriginalExtension();
                $nombreArchivo = "{$tipoDoc}_" . time() . ".{$extension}";

                // Crear carpeta
                $rutaVehiculo = "vehicle_maintenances/{$nombreLimpio}";
                $rutaCarpetaCompleta = public_path($rutaVehiculo);
                if (!file_exists($rutaCarpetaCompleta)) {
                    mkdir($rutaCarpetaCompleta, 0755, true);
                }

                // Mover archivo
                $archivo->move($rutaCarpetaCompleta, $nombreArchivo);
                $nombreArchivo = "{$rutaVehiculo}/{$nombreArchivo}";

                // Eliminar archivo anterior
                if ($archivoAnterior && file_exists(public_path($archivoAnterior))) {
                    unlink(public_path($archivoAnterior));
                }
            }

            // Actualizar datos
            $datosActualizar = $request->only([
                'tipo',
                'descripcion',
                'fecha_programada',
                'kilometraje_programado',
                'kilometraje_real',
                'costo',
                'estado',
                'observaciones'
            ]);

            if ($request->hasFile('archivo')) {
                $datosActualizar['archivo'] = $nombreArchivo;
            }

            // Si el mantenimiento se completa, programar el próximo cambio de aceite
            if ($request->has('estado') && $request->estado === 'completado' && $request->tipo === 'cambio_aceite' && $request->kilometraje_real) {
                $nextKilometraje = $request->kilometraje_real + 10000;
                $nextFechaProgramada = now()->addMonths(6);
                VehicleMaintenance::create([
                    'vehicle_id' => $maintenance->vehicle_id,
                    'tipo' => 'cambio_aceite',
                    'descripcion' => 'Próximo cambio de aceite',
                    'fecha_programada' => $nextFechaProgramada,
                    'kilometraje_programado' => $nextKilometraje,
                    'estado' => 'pendiente',
                    'observaciones' => 'Programado automáticamente tras cambio de aceite completado',
                ]);
            }

            $maintenance->update($datosActualizar);

            DB::commit();

            return response()->json([
                'message' => 'Mantenimiento actualizado exitosamente',
                'datos' => $maintenance->load('vehicle'),
            ], 200);
        } catch (\Exception $e) {
            DB::rollback();

            // Eliminar nuevo archivo si falló
            if (isset($nombreArchivo) && $nombreArchivo !== $archivoAnterior && file_exists(public_path($nombreArchivo))) {
                unlink(public_path($nombreArchivo));
            }

            return response()->json([
                'message' => 'Error al actualizar el mantenimiento',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Eliminar un mantenimiento
     */
    public function destroy($id)
    {
        try {
            $maintenance = VehicleMaintenance::findOrFail($id);

            // Eliminar archivo físico
            if ($maintenance->archivo && file_exists(public_path($maintenance->archivo))) {
                unlink(public_path($maintenance->archivo));

                // Intentar eliminar la carpeta si está vacía
                $rutaVehiculo = dirname(public_path($maintenance->archivo));
                if (is_dir($rutaVehiculo) && count(scandir($rutaVehiculo)) == 2) {
                    rmdir($rutaVehiculo);
                }
            }

            // Eliminar registro
            $maintenance->delete();

            return response()->json([
                'message' => 'Mantenimiento eliminado exitosamente'
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Error al eliminar el mantenimiento',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Descargar un documento de mantenimiento
     */
    public function download($id)
    {
        try {
            $maintenance = VehicleMaintenance::findOrFail($id);
            $filePath = public_path($maintenance->archivo);

            if (!File::exists($filePath)) {
                return response()->json([
                    'message' => 'Archivo no encontrado',
                    'error' => 'El archivo no existe en el servidor'
                ], 404);
            }

            $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
            $mimeTypes = [
                'pdf' => 'application/pdf',
                'jpg' => 'image/jpeg',
                'jpeg' => 'image/jpeg',
                'png' => 'image/png',
                'doc' => 'application/msword',
                'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'
            ];
            $mimeType = isset($mimeTypes[$extension]) ? $mimeTypes[$extension] : File::mimeType($filePath);
            $fileName = ($maintenance->descripcion ?? $maintenance->tipo) . '.' . $extension;

            $headers = [
                'Content-Type' => $mimeType,
                'Content-Disposition' => 'attachment; filename="' . $fileName . '"',
                'Cache-Control' => 'no-cache, no-store, must-revalidate',
                'Pragma' => 'no-cache',
                'Expires' => '0',
            ];

            return response()->file($filePath, $headers);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Error al descargar el documento',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Obtener mantenimientos activos (pendientes o atrasados)
     */
    public function getActiveMaintenances(Request $request)
    {
        try {
            $query = VehicleMaintenance::with(['vehicle'])
                ->whereIn('estado', ['pendiente', 'atrasado'])
                ->whereHas('vehicle', function ($q) {
                    $q->where('is_active', true);
                });

            // Filtros opcionales
            if ($request->has('tipo') && $request->tipo !== '') {
                $query->where('tipo', $request->tipo);
            }

            if ($request->has('estado') && $request->estado !== '') {
                $query->where('estado', $request->estado);
            }

            // Búsqueda por descripción o vehículo
            if ($request->has('search') && $request->search !== '') {
                $search = $request->search;
                $query->where(function ($q) use ($search) {
                    $q->where('descripcion', 'like', "%{$search}%")
                        ->orWhereHas('vehicle', function ($subQ) use ($search) {
                            $subQ->where('numero_vin', 'like', "%{$search}%")
                                ->orWhere('numero_placa', 'like', "%{$search}%")
                                ->orWhere('marca', 'like', "%{$search}%")
                                ->orWhere('modelo', 'like', "%{$search}%");
                        });
                });
            }

            // Ordenar por fecha programada
            $query->orderBy('fecha_programada', 'asc');

            $maintenances = $query->get();

            // Agregar URL de archivo
            $maintenances->transform(function ($maintenance) {
                $maintenance->rutaArchivoCompleta = $maintenance->archivo && file_exists(public_path($maintenance->archivo)) ? asset($maintenance->archivo) : null;
                return $maintenance;
            });

            return response()->json([
                'message' => 'Mantenimientos activos obtenidos exitosamente',
                'datos' => $maintenances,
                'total' => $maintenances->count()
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Error al obtener los mantenimientos activos',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Método auxiliar para limpiar nombres
     */
    private function limpiarNombre($nombre)
    {
        $nombre = iconv('UTF-8', 'ASCII//TRANSLIT', $nombre);
        $nombre = preg_replace('/[^a-zA-Z0-9]/', '_', $nombre);
        $nombre = preg_replace('/_+/', '_', $nombre);
        $nombre = trim($nombre, '_');
        return strtolower($nombre);
    }
}
