<?php

namespace App\Http\Controllers\api\OperatingBox;

use App\Http\Controllers\Controller;
use App\Models\OperatingBox;
use App\Models\OperatingBoxHistorie;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class OperatingBoxController extends Controller
{
    /**
     * Listar todas las cajas operativas
     */
    public function index()
    {
        $cajas = OperatingBox::orderBy('created_at', "desc")->get();
        return response()->json([
            'success' => true,
            'data' => $cajas
        ], 200);
    }
    /**
     * Crear una nueva caja operativa
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
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

        $caja = OperatingBox::create([
            'nombre' => $request->nombre,
            'saldo' => $request->saldo,
            'descripcion' => $request->descripcion ?? null,
            'estado' => $request->boolean('estado', true),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Caja operativa creada exitosamente.',
            'data' => $caja
        ], 201);
    }
    /**
     * Mostrar una caja específica
     */
    public function show($id)
    {
        $caja = OperatingBox::find($id);

        if (!$caja) {
            return response()->json([
                'success' => false,
                'message' => 'Caja operativa no encontrada.'
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $caja
        ], 200);
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
            'nombre' => 'required',
            'saldo' => 'required|numeric|min:0',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        $caja->update([
            'nombre' => $request->nombre,
            'saldo' => $request->saldo,
            'descripcion' => $request->descripcion ?? null,
            'estado' => $request->boolean('estado', $caja->estado),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Caja operativa actualizada exitosamente.',
            'data' => $caja
        ], 200);
    }
    /**
     * Eliminar (o desactivar) una caja operativa
     * Nota: No eliminamos físicamente, solo desactivamos
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

        // Cambiamos estado a false (soft delete lógico)
        $caja->update(['estado' => false]);

        return response()->json([
            'success' => true,
            'message' => 'Caja operativa desactivada exitosamente.'
        ], 200);
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

        $caja->update(['estado' => true]);

        return response()->json([
            'success' => true,
            'message' => 'Caja operativa reactivada exitosamente.'
        ], 200);
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
            DB::transaction(function () use ($caja) {
                // Eliminamos todos los registros de historial asociados a esta caja
                OperatingBoxHistorie::where('operating_box_id', $caja->id)->delete();

                // Eliminamos permanentemente la caja
                $caja->delete();
            });

            return response()->json([
                'success' => true,
                'message' => 'Caja operativa y sus historiales eliminados permanentemente.'
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al eliminar permanentemente la caja operativa: ' . $e->getMessage()
            ], 500);
        }
    }
    /**
     * Listar solo las cajas operativas activas (estado = true)
     */
    public function boxActives()
    {
        $cajas = OperatingBox::where('estado', true)
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $cajas
        ], 200);
    }
}
