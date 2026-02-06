<?php

namespace App\Http\Controllers\api\OperatingBox;

use App\Http\Controllers\Controller;
use App\Models\OperatingBox;
use App\Models\OperatingBoxHistorie;
use App\Models\Vehicle;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class OperatingBoxController extends Controller
{
    /**
     * Listar todas las cajas operativas con sus relaciones
     */
    public function index(Request $request)
    {
        try {
            $query = OperatingBox::with(['negocio', 'vehicle']);

            // Filtro por negocio
            if ($request->has('negocio_id') && !empty($request->negocio_id)) {
                $query->where('negocio_id', $request->negocio_id);
            }

            // Filtro por vehículo
            if ($request->has('vehicle_id') && !empty($request->vehicle_id)) {
                $query->where('vehicle_id', $request->vehicle_id);
            }

            // Filtro por estado
            if ($request->has('estado')) {
                $query->where('estado', $request->boolean('estado'));
            }

            $cajas = $query->orderBy('created_at', 'desc')->get();

            return response()->json([
                'success' => true,
                'data' => $cajas,
                'count' => $cajas->count()
            ], 200);
        } catch (\Exception $e) {
            Log::error('Error en index de OperatingBox: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener las cajas operativas: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Obtener vehículos filtrados por negocio
     */
    public function getVehiclesByBusiness(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'negocio_id' => 'required|integer|exists:businesses,id'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'errors' => $validator->errors()
                ], 422);
            }

            // Obtener vehículos del negocio especificado
            $vehicles = Vehicle::where('negocio_id', $request->negocio_id)
                ->where('estado', 1)
                ->select('id', 'numero_placa', 'modelo', 'marca', 'negocio_id', 'codigo_unico')
                ->orderBy('numero_placa', 'asc')
                ->get();
            // Formatear los vehículos para el frontend
            $vehiclesFormatted = $vehicles->map(function ($vehicle) {
                return [
                    'id' => $vehicle->id,
                    'numero_placa' => $vehicle->numero_placa,
                    'modelo' => $vehicle->modelo,
                    'marca' => $vehicle->marca,
                    'negocio_id' => $vehicle->negocio_id,
                    'display_name' => $vehicle->numero_placa . ' - ' . $vehicle->marca . ' ' . $vehicle->modelo,
                    'codigo_unico' => $vehicle->codigo_unico
                ];
            });

            return response()->json([
                'success' => true,
                'data' => $vehiclesFormatted,
                'count' => $vehiclesFormatted->count()
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener los vehículos: ' . $e->getMessage(),
                'data' => [],
                'count' => 0
            ], 500);
        }
    }

    /**
     * Crear una nueva caja operativa
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'negocio_id' => 'nullable|exists:businesses,id',
            'vehicle_id' => 'nullable|exists:vehicles,id',
            'nombre' => 'required|string|max:255|unique:operating_boxes,nombre',
            'saldo' => 'required|numeric|min:0',
            'descripcion' => 'nullable|string',
            'estado' => 'boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        // Validar que el vehículo pertenezca al negocio seleccionado
        if ($request->negocio_id && $request->vehicle_id) {
            $vehicle = Vehicle::where('id', $request->vehicle_id)
                ->where('negocio_id', $request->negocio_id)
                ->first();

            if (!$vehicle) {
                return response()->json([
                    'success' => false,
                    'message' => 'El vehículo seleccionado no pertenece al negocio especificado.'
                ], 422);
            }
        }

        try {
            DB::beginTransaction();

            $caja = OperatingBox::create([
                'negocio_id' => $request->negocio_id,
                'vehicle_id' => $request->vehicle_id,
                'nombre' => strtoupper($request->nombre),
                'saldo' => $request->saldo,
                'descripcion' => $request->descripcion ? strtoupper($request->descripcion) : null,
                'estado' => $request->boolean('estado', true),
            ]);

            // Registrar en el historial
            OperatingBoxHistorie::create([
                'operating_box_id' => $caja->id,
                'tipo_movimiento' => 'apertura',
                'monto' => $request->saldo,
                'saldo_anterior' => 0,
                'saldo_nuevo' => $request->saldo,
                'descripcion' => 'APERTURA DE CAJA OPERATIVA',
                'fecha_movimiento' => now(),
            ]);

            DB::commit();

            // Cargar relaciones
            $caja->load(['negocio', 'vehicle']);

            return response()->json([
                'success' => true,
                'message' => 'Caja operativa creada exitosamente.',
                'data' => $caja
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error al crear caja operativa: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error al crear la caja operativa: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Mostrar una caja específica
     */
    public function show($id)
    {
        try {
            $caja = OperatingBox::with(['negocio', 'vehicle'])->find($id);

            if (!$caja) {
                return response()->json([
                    'success' => false,
                    'message' => 'Caja operativa no encontrada.'
                ], 404);
            }

            // Obtener estadísticas de la caja
            $estadisticas = [
                'total_ingresos' => OperatingBoxHistorie::where('operating_box_id', $id)
                    ->whereIn('tipo_movimiento', ['ingreso', 'ajuste_ingreso', 'apertura'])
                    ->sum('monto'),
                'total_egresos' => OperatingBoxHistorie::where('operating_box_id', $id)
                    ->whereIn('tipo_movimiento', ['egreso', 'ajuste_egreso'])
                    ->sum('monto'),
                'cantidad_movimientos' => OperatingBoxHistorie::where('operating_box_id', $id)->count(),
            ];

            return response()->json([
                'success' => true,
                'data' => $caja,
                'estadisticas' => $estadisticas
            ], 200);
        } catch (\Exception $e) {
            Log::error('Error en show de OperatingBox: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener la caja operativa: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Actualizar una caja operativa
     */
    public function update(Request $request, $id)
    {
        $caja = OperatingBox::find($id);

        if (!$caja) {
            return response()->json([
                'success' => false,
                'message' => 'Caja operativa no encontrada.'
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'negocio_id' => 'nullable|exists:businesses,id',
            'vehicle_id' => 'nullable|exists:vehicles,id',
            'nombre' => 'required|string|max:255|unique:operating_boxes,nombre,' . $id,
            'saldo' => 'required|numeric|min:0',
            'descripcion' => 'nullable|string',
            'estado' => 'boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        // Validar que el vehículo pertenezca al negocio seleccionado
        if ($request->negocio_id && $request->vehicle_id) {
            $vehicle = Vehicle::where('id', $request->vehicle_id)
                ->where('negocio_id', $request->negocio_id)
                ->first();

            if (!$vehicle) {
                return response()->json([
                    'success' => false,
                    'message' => 'El vehículo seleccionado no pertenece al negocio especificado.'
                ], 422);
            }
        }

        try {
            DB::beginTransaction();

            $saldoAnterior = $caja->saldo;
            $nuevoSaldo = $request->saldo;

            // Actualizar la caja
            $caja->update([
                'negocio_id' => $request->negocio_id,
                'vehicle_id' => $request->vehicle_id,
                'nombre' => strtoupper($request->nombre),
                'saldo' => $nuevoSaldo,
                'descripcion' => $request->descripcion ? strtoupper($request->descripcion) : null,
                'estado' => $request->boolean('estado', $caja->estado),
            ]);

            // Si el saldo cambió, registrar en el historial
            if ($saldoAnterior != $nuevoSaldo) {
                $diferencia = $nuevoSaldo - $saldoAnterior;
                $tipoMovimiento = $diferencia > 0 ? 'ajuste_ingreso' : 'ajuste_egreso';

                OperatingBoxHistorie::create([
                    'operating_box_id' => $caja->id,
                    'tipo_movimiento' => $tipoMovimiento,
                    'monto' => abs($diferencia),
                    'saldo_anterior' => $saldoAnterior,
                    'saldo_nuevo' => $nuevoSaldo,
                    'descripcion' => 'AJUSTE MANUAL DE SALDO',
                    'fecha_movimiento' => now(),
                ]);
            }

            DB::commit();

            // Cargar relaciones
            $caja->load(['negocio', 'vehicle']);

            return response()->json([
                'success' => true,
                'message' => 'Caja operativa actualizada exitosamente.',
                'data' => $caja
            ], 200);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error al actualizar caja operativa: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error al actualizar la caja operativa: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Eliminar (o desactivar) una caja operativa
     */
    public function destroy($id)
    {
        $caja = OperatingBox::find($id);

        if (!$caja) {
            return response()->json([
                'success' => false,
                'message' => 'Caja operativa no encontrada.'
            ], 404);
        }

        try {
            $caja->update(['estado' => false]);

            return response()->json([
                'success' => true,
                'message' => 'Caja operativa desactivada exitosamente.'
            ], 200);
        } catch (\Exception $e) {
            Log::error('Error al desactivar caja operativa: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error al desactivar la caja operativa: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Reactivar una caja operativa
     */
    public function activate($id)
    {
        $caja = OperatingBox::find($id);

        if (!$caja) {
            return response()->json([
                'success' => false,
                'message' => 'Caja operativa no encontrada.'
            ], 404);
        }

        if ($caja->estado) {
            return response()->json([
                'success' => false,
                'message' => 'La caja ya está activa.'
            ], 400);
        }

        try {
            $caja->update(['estado' => true]);

            return response()->json([
                'success' => true,
                'message' => 'Caja operativa reactivada exitosamente.'
            ], 200);
        } catch (\Exception $e) {
            Log::error('Error al reactivar caja operativa: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error al reactivar la caja operativa: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Eliminar permanentemente una caja operativa y sus historiales
     */
    public function deletePermanent($id)
    {
        $caja = OperatingBox::find($id);

        if (!$caja) {
            return response()->json([
                'success' => false,
                'message' => 'Caja operativa no encontrada.'
            ], 404);
        }

        try {
            DB::beginTransaction();

            // Eliminamos todos los registros de historial asociados a esta caja
            OperatingBoxHistorie::where('operating_box_id', $caja->id)->delete();

            // Eliminamos permanentemente la caja
            $caja->delete();

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Caja operativa y sus historiales eliminados permanentemente.'
            ], 200);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error al eliminar permanentemente caja operativa: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error al eliminar permanentemente la caja operativa: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Listar solo las cajas operativas activas
     */
    public function boxActives(Request $request)
    {
        try {
            $query = OperatingBox::with(['negocio', 'vehicle'])
                ->where('estado', true);

            // Filtro por negocio
            if ($request->has('negocio_id') && !empty($request->negocio_id)) {
                $query->where('negocio_id', $request->negocio_id);
            }

            // Filtro por vehículo
            if ($request->has('vehicle_id') && !empty($request->vehicle_id)) {
                $query->where('vehicle_id', $request->vehicle_id);
            }

            $cajas = $query->orderBy('created_at', 'desc')->get();

            return response()->json([
                'success' => true,
                'data' => $cajas,
                'count' => $cajas->count()
            ], 200);
        } catch (\Exception $e) {
            Log::error('Error en boxActives: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener las cajas activas: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Obtener resumen de cajas por negocio
     */
    public function summaryByBusiness(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'negocio_id' => 'required|exists:businesses,id'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'errors' => $validator->errors()
                ], 422);
            }

            $cajas = OperatingBox::with(['vehicle'])
                ->where('negocio_id', $request->negocio_id)
                ->where('estado', true)
                ->get();

            $resumen = [
                'total_cajas' => $cajas->count(),
                'saldo_total' => $cajas->sum('saldo'),
                'cajas_con_vehiculo' => $cajas->whereNotNull('vehicle_id')->count(),
                'cajas_sin_vehiculo' => $cajas->whereNull('vehicle_id')->count(),
                'detalle' => $cajas->map(function ($caja) {
                    return [
                        'id' => $caja->id,
                        'nombre' => $caja->nombre,
                        'saldo' => $caja->saldo,
                        'vehiculo' => $caja->vehicle ? [
                            'id' => $caja->vehicle->id,
                            'placa' => $caja->vehicle->numero_placa,
                            'modelo' => $caja->vehicle->modelo,
                        ] : null
                    ];
                })
            ];

            return response()->json([
                'success' => true,
                'data' => $resumen
            ], 200);
        } catch (\Exception $e) {
            Log::error('Error en summaryByBusiness: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener el resumen: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Validar si un vehículo ya tiene una caja asignada
     */
    public function checkVehicleBox(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'vehicle_id' => 'required|exists:vehicles,id',
                'exclude_box_id' => 'nullable|exists:operating_boxes,id'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'errors' => $validator->errors()
                ], 422);
            }

            $query = OperatingBox::where('vehicle_id', $request->vehicle_id)
                ->where('estado', true);

            // Excluir una caja específica (útil para edición)
            if ($request->has('exclude_box_id')) {
                $query->where('id', '!=', $request->exclude_box_id);
            }

            $cajaExistente = $query->first();

            return response()->json([
                'success' => true,
                'tiene_caja' => $cajaExistente ? true : false,
                'caja' => $cajaExistente ? [
                    'id' => $cajaExistente->id,
                    'nombre' => $cajaExistente->nombre,
                    'saldo' => $cajaExistente->saldo,
                ] : null
            ], 200);
        } catch (\Exception $e) {
            Log::error('Error en checkVehicleBox: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error al validar el vehículo: ' . $e->getMessage()
            ], 500);
        }
    }
}
