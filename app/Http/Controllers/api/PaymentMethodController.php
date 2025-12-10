<?php

namespace App\Http\Controllers\api;

use App\Http\Controllers\Controller;
use App\Models\PaymentMethod;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class PaymentMethodController extends Controller
{
    /**
     * Mostrar métodos de pago activos (función existente)
     */
    public function paymentMethodsActives()
    {
        $paymentMethods = PaymentMethod::where('estado', true)
            ->select('id', 'nombre', 'estado')
            ->get();
        return response()->json([
            "mensaje" => "Metodos de Pagos Cargados",
            "datos" => $paymentMethods
        ]);
    }
    /**
     * Mostrar todos los métodos de pago
     */
    public function index()
    {
        $paymentMethods = PaymentMethod::all();
        return response()->json([
            "mensaje" => "Lista de métodos de pago",
            "datos" => $paymentMethods
        ]);
    }
    /**
     * Almacenar un nuevo método de pago
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
        $paymentMethod = PaymentMethod::create($request->all());
        return response()->json([
            "mensaje" => "Método de pago creado exitosamente",
            "datos" => $paymentMethod
        ], 201);
    }
    /**
     * Mostrar un método de pago específico
     */
    public function show($id)
    {
        $paymentMethod = PaymentMethod::findOrFail($id);
        /*        if (!$paymentMethod) {
            return response()->json([
                "mensaje" => "Método de pago no encontrado"
            ], 404);
        } */
        return response()->json([
            "mensaje" => "Método de pago encontrado",
            "datos" => $paymentMethod
        ]);
    }
    /**
     * Actualizar un método de pago existente
     */
    public function update(Request $request, $id)
    {
        $paymentMethod = PaymentMethod::find($id);
        if (!$paymentMethod) {
            return response()->json([
                "mensaje" => "Método de pago no encontrado"
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
        $paymentMethod->update($request->all());
        return response()->json([
            "mensaje" => "Método de pago actualizado exitosamente",
            "datos" => $paymentMethod
        ]);
    }
    /**
     * Eliminar un método de pago
     */
    public function destroy($id)
    {
        $paymentMethod = PaymentMethod::find($id);
        if (!$paymentMethod) {
            return response()->json([
                "mensaje" => "Método de pago no encontrado"
            ], 404);
        }
        $paymentMethod->delete();
        return response()->json([
            "mensaje" => "Método de pago eliminado exitosamente"
        ]);
    }
    /**
     * Activar un método de pago
     */
    public function activate($id)
    {
        $paymentMethod = PaymentMethod::find($id);
        if (!$paymentMethod) {
            return response()->json([
                "mensaje" => "Método de pago no encontrado"
            ], 404);
        }
        $paymentMethod->estado = true;
        $paymentMethod->save();
        return response()->json([
            "mensaje" => "Método de pago activado exitosamente",
            "datos" => $paymentMethod
        ]);
    }
    /**
     * Desactivar un método de pago
     */
    public function desactivate($id)
    {
        $paymentMethod = PaymentMethod::find($id);
        if (!$paymentMethod) {
            return response()->json([
                "mensaje" => "Método de pago no encontrado"
            ], 404);
        }
        $paymentMethod->estado = false;
        $paymentMethod->save();
        return response()->json([
            "mensaje" => "Método de pago desactivado exitosamente",
            "datos" => $paymentMethod
        ]);
    }
}
