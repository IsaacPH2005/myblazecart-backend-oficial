<?php

namespace App\Http\Controllers\api;

use App\Http\Controllers\Controller;
use App\Models\TransactionStates;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class TransactionStateController extends Controller
{
    public function transactionStateActives()
    {
        $paymentMethods = TransactionStates::where('estado', true)
            ->select('id', 'nombre', 'descripcion', 'estado')
            ->get();

        return response()->json([
            "mensaje" => "Estados de transaccion Cargados",
            "datos" => $paymentMethods
        ]);
    }
    /**
     * Listar todos los estados de transacción
     */
    public function index()
    {
        try {
            $states = TransactionStates::all();
            return response()->json([
                'message' => 'Estados de transacción obtenidos exitosamente',
                'datos' => $states
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Error al obtener los estados de transacción',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Listar estados de transacción activos
     */
    public function actives()
    {
        try {
            $states = TransactionStates::where('estado', true)
                ->select('id', 'nombre', 'descripcion', 'estado')
                ->get();
            return response()->json([
                'message' => 'Estados de transacción activos obtenidos exitosamente',
                'datos' => $states
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Error al obtener los estados de transacción activos',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Mostrar un estado de transacción específico
     */
    public function show($id)
    {
        try {
            $state = TransactionStates::findOrFail($id);
            return response()->json([
                'message' => 'Estado de transacción obtenido exitosamente',
                'datos' => $state
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Estado de transacción no encontrado',
                'error' => $e->getMessage()
            ], 404);
        }
    }

    /**
     * Crear un nuevo estado de transacción
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'nombre' => 'required|string|max:255',
            'descripcion' => 'nullable|string',
            'estado' => 'boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Error de validación',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $state = TransactionStates::create($request->all());
            return response()->json([
                'message' => 'Estado de transacción creado exitosamente',
                'datos' => $state
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Error al crear el estado de transacción',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Actualizar un estado de transacción existente
     */
    public function update(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'nombre' => 'sometimes|required|string|max:255',
            'descripcion' => 'nullable|string',
            'estado' => 'boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Error de validación',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $state = TransactionStates::findOrFail($id);
            $state->update($request->all());
            return response()->json([
                'message' => 'Estado de transacción actualizado exitosamente',
                'datos' => $state
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Error al actualizar el estado de transacción',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Eliminar un estado de transacción
     */
    public function destroy($id)
    {
        try {
            $state = TransactionStates::findOrFail($id);
            $state->delete();
            return response()->json([
                'message' => 'Estado de transacción eliminado exitosamente'
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Error al eliminar el estado de transacción',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Activar un estado de transacción
     */
    public function activate($id)
    {
        try {
            $state = TransactionStates::findOrFail($id);
            $state->estado = true;
            $state->save();
            return response()->json([
                'message' => 'Estado de transacción activado exitosamente',
                'datos' => $state
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Error al activar el estado de transacción',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Desactivar un estado de transacción
     */
    public function deactivate($id)
    {
        try {
            $state = TransactionStates::findOrFail($id);
            $state->estado = false;
            $state->save();
            return response()->json([
                'message' => 'Estado de transacción desactivado exitosamente',
                'datos' => $state
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Error al desactivar el estado de transacción',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
