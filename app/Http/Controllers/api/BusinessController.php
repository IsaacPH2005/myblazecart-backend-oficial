<?php

namespace App\Http\Controllers\api;

use App\Http\Controllers\Controller;
use App\Models\Business;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class BusinessController extends Controller
{
    /**
     * Mostrar todos los negocios
     */
    public function index()
    {
        $businesses = Business::all();
        return response()->json([
            "mensaje" => "Lista de negocios",
            "datos" => $businesses
        ]);
    }

    /**
     * Mostrar negocios activos (función existente)
     */
    public function businessActives()
    {
        $business = Business::with('vehicles')->where('estado', true)
            ->select('id', 'nombre', 'descripcion', 'estado')
            ->get();
        return response()->json([
            "mensaje" => "Negocios Cargados",
            "datos" => $business
        ]);
    }

    /**
     * Almacenar un nuevo negocio
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'nombre' => 'required|string|max:255',
            'estado' => 'boolean'
        ]);

        if ($validator->fails()) {
            return response()->json([
                "mensaje" => "Error de validación",
                "errores" => $validator->errors()
            ], 422);
        }

        $business = Business::create($request->all());

        return response()->json([
            "mensaje" => "Negocio creado exitosamente",
            "datos" => $business
        ], 201);
    }

    /**
     * Mostrar un negocio específico
     */
    public function show($id)
    {
        $business = Business::with('vehicles')->find($id);

        if (!$business) {
            return response()->json([
                "mensaje" => "Negocio no encontrado"
            ], 404);
        }

        return response()->json([
            "mensaje" => "Negocio encontrado",
            "datos" => $business
        ]);
    }

    /**
     * Actualizar un negocio existente
     */
    public function update(Request $request, $id)
    {
        $business = Business::find($id);

        if (!$business) {
            return response()->json([
                "mensaje" => "Negocio no encontrado"
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'nombre' => 'string|max:255',
            'estado' => 'boolean'
        ]);

        if ($validator->fails()) {
            return response()->json([
                "mensaje" => "Error de validación",
                "errores" => $validator->errors()
            ], 422);
        }

        $business->update($request->all());

        return response()->json([
            "mensaje" => "Negocio actualizado exitosamente",
            "datos" => $business
        ]);
    }

    /**
     * Eliminar un negocio
     */
    public function destroy($id)
    {
        $business = Business::find($id);

        if (!$business) {
            return response()->json([
                "mensaje" => "Negocio no encontrado"
            ], 404);
        }

        $business->delete();

        return response()->json([
            "mensaje" => "Negocio eliminado exitosamente"
        ]);
    }

    /**
     * Activar un negocio
     */
    public function activate($id)
    {
        $business = Business::find($id);
        if (!$business) {
            return response()->json([
                "mensaje" => "Negocio no encontrado"
            ], 404);
        }

        $business->estado = true;
        $business->save();

        return response()->json([
            "mensaje" => "Negocio activado exitosamente",
            "datos" => $business
        ]);
    }

    /**
     * Desactivar un negocio
     */
    public function deactivate($id)
    {
        $business = Business::find($id);
        if (!$business) {
            return response()->json([
                "mensaje" => "Negocio no encontrado"
            ], 404);
        }

        $business->estado = false;
        $business->save();

        return response()->json([
            "mensaje" => "Negocio desactivado exitosamente",
            "datos" => $business
        ]);
    }
}
