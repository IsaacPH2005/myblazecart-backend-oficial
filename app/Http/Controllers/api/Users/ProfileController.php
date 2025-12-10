<?php

namespace App\Http\Controllers\api\Users;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\GeneralData;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class ProfileController extends Controller
{
    /**
     * Obtener el perfil del usuario autenticado
     * 
     * @return JsonResponse
     */
    public function show(): JsonResponse
    {
        try {
            $user = auth()->user()->load(['generalData', 'roles:id,name', 'permissions:id,name']);

            return response()->json([
                'success' => true,
                'message' => 'Perfil obtenido exitosamente',
                'data' => $user
            ], 200);
        } catch (\Throwable $th) {
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener el perfil',
                'error' => config('app.debug') ? $th->getMessage() : 'Error interno'
            ], 500);
        }
    }

    /**
     * Actualizar el perfil del usuario autenticado
     * 
     * @param Request $request
     * @return JsonResponse
     */
    public function update(Request $request): JsonResponse
    {
        $user = auth()->user();

        $validator = Validator::make($request->all(), [
            // Datos del usuario
            'email' => 'required|email|unique:users,email,' . $user->id . '|max:255',

            // Datos generales (requeridos)
            'general_data.nombre' => 'required|string|max:255',
            'general_data.apellido' => 'required|string|max:255',
            'general_data.documento_identidad' => 'required|string|max:50|unique:general_data,documento_identidad,' . $user->id . ',user_id',
            'general_data.celular' => 'required|string|max:20',
            'general_data.direccion' => 'required|string|max:255',
            'general_data.ciudad' => 'required|string|max:100',
            'general_data.departamento' => 'required|string|max:100',

            // Datos generales (opcionales)
            'general_data.nacimiento' => 'nullable|date|before:today',
            'general_data.genero' => 'nullable|in:masculino,femenino,otro',
            'general_data.codigo_postal' => 'nullable|string|max:20',
            'general_data.contacto_emergencia_nombre' => 'nullable|string|max:255',
            'general_data.contacto_emergencia_telefono' => 'nullable|string|max:20',
            'general_data.notas' => 'nullable|string|max:1000',
        ], [
            // Mensajes de error personalizados
            'email.required' => 'El correo electrónico es obligatorio',
            'email.email' => 'Debe ingresar un correo electrónico válido',
            'email.unique' => 'Este correo electrónico ya está registrado',
            'email.max' => 'El correo electrónico no puede superar los 255 caracteres',
            'general_data.nombre.required' => 'El nombre es obligatorio',
            'general_data.nombre.max' => 'El nombre no puede superar los 255 caracteres',
            'general_data.apellido.required' => 'El apellido es obligatorio',
            'general_data.apellido.max' => 'El apellido no puede superar los 255 caracteres',
            'general_data.documento_identidad.required' => 'El documento de identidad es obligatorio',
            'general_data.documento_identidad.unique' => 'Este documento de identidad ya está registrado',
            'general_data.documento_identidad.max' => 'El documento de identidad no puede superar los 50 caracteres',
            'general_data.celular.required' => 'El número de celular es obligatorio',
            'general_data.celular.max' => 'El número de celular no puede superar los 20 caracteres',
            'general_data.direccion.required' => 'La dirección es obligatoria',
            'general_data.direccion.max' => 'La dirección no puede superar los 255 caracteres',
            'general_data.ciudad.required' => 'La ciudad es obligatoria',
            'general_data.ciudad.max' => 'La ciudad no puede superar los 100 caracteres',
            'general_data.departamento.required' => 'El departamento es obligatorio',
            'general_data.departamento.max' => 'El departamento no puede superar los 100 caracteres',
            'general_data.nacimiento.date' => 'La fecha de nacimiento debe ser una fecha válida',
            'general_data.nacimiento.before' => 'La fecha de nacimiento debe ser anterior a hoy',
            'general_data.genero.in' => 'El género debe ser masculino, femenino u otro',
            'general_data.codigo_postal.max' => 'El código postal no puede superar los 20 caracteres',
            'general_data.contacto_emergencia_nombre.max' => 'El nombre de contacto de emergencia no puede superar los 255 caracteres',
            'general_data.contacto_emergencia_telefono.max' => 'El teléfono de contacto de emergencia no puede superar los 20 caracteres',
            'general_data.notas.max' => 'Las notas no pueden superar los 1000 caracteres',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Errores de validación',
                'errors' => $validator->errors()
            ], 422);
        }

        DB::beginTransaction();
        try {
            // 1. Actualizar datos del usuario principal
            $user->update([
                'email' => $request->input('email'),
            ]);

            // 2. Actualizar o crear datos generales
            $generalData = [
                'nombre' => $request->input('general_data.nombre'),
                'apellido' => $request->input('general_data.apellido'),
                'documento_identidad' => $request->input('general_data.documento_identidad'),
                'celular' => $request->input('general_data.celular'),
                'direccion' => $request->input('general_data.direccion'),
                'ciudad' => $request->input('general_data.ciudad'),
                'departamento' => $request->input('general_data.departamento'),
                'nacimiento' => $request->input('general_data.nacimiento'),
                'genero' => $request->input('general_data.genero'),
                'codigo_postal' => $request->input('general_data.codigo_postal'),
                'contacto_emergencia_nombre' => $request->input('general_data.contacto_emergencia_nombre'),
                'contacto_emergencia_telefono' => $request->input('general_data.contacto_emergencia_telefono'),
                'notas' => $request->input('general_data.notas'),
            ];

            if ($user->generalData) {
                // Actualizar datos existentes
                $user->generalData->update($generalData);
            } else {
                // Crear nuevos datos
                $generalData['user_id'] = $user->id;
                GeneralData::create($generalData);
            }

            DB::commit();

            // Cargar relaciones actualizadas
            $user->load(['generalData', 'roles:id,name', 'permissions:id,name']);

            return response()->json([
                'success' => true,
                'message' => 'Perfil actualizado exitosamente',
                'data' => $user
            ], 200);
        } catch (\Throwable $th) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Error al actualizar el perfil',
                'error' => config('app.debug') ? $th->getMessage() : 'Error interno'
            ], 500);
        }
    }

    /**
     * Cambiar la contraseña del usuario autenticado
     * 
     * @param Request $request
     * @return JsonResponse
     */
    public function changePassword(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'current_password' => 'required|string',
            'password' => 'required|string|min:8|confirmed',
        ], [
            'current_password.required' => 'La contraseña actual es obligatoria',
            'password.required' => 'La nueva contraseña es obligatoria',
            'password.min' => 'La nueva contraseña debe tener al menos 8 caracteres',
            'password.confirmed' => 'La confirmación de la nueva contraseña no coincide',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Errores de validación',
                'errors' => $validator->errors()
            ], 422);
        }

        $user = auth()->user();

        // Verificar que la contraseña actual sea correcta
        if (!Hash::check($request->input('current_password'), $user->password)) {
            return response()->json([
                'success' => false,
                'message' => 'La contraseña actual es incorrecta'
            ], 422);
        }

        try {
            // Actualizar la contraseña
            $user->update([
                'password' => Hash::make($request->input('password'))
            ]);

            // Revocar todos los tokens existentes (forzar cierre de sesión en otros dispositivos)
            $user->tokens()->delete();

            return response()->json([
                'success' => true,
                'message' => 'Contraseña actualizada exitosamente'
            ], 200);
        } catch (\Throwable $th) {
            return response()->json([
                'success' => false,
                'message' => 'Error al actualizar la contraseña',
                'error' => config('app.debug') ? $th->getMessage() : 'Error interno'
            ], 500);
        }
    }

    /**
     * Obtener información del usuario autenticado para el formulario de perfil
     * 
     * @return JsonResponse
     */
    public function getProfileFormData(): JsonResponse
    {
        try {
            $user = auth()->user()->load(['generalData']);

            return response()->json([
                'success' => true,
                'message' => 'Datos del formulario obtenidos exitosamente',
                'data' => [
                    'email' => $user->email,
                    'general_data' => $user->generalData ? [
                        'nombre' => $user->generalData->nombre,
                        'apellido' => $user->generalData->apellido,
                        'documento_identidad' => $user->generalData->documento_identidad,
                        'celular' => $user->generalData->celular,
                        'nacimiento' => $user->generalData->nacimiento,
                        'genero' => $user->generalData->genero,
                        'direccion' => $user->generalData->direccion,
                        'ciudad' => $user->generalData->ciudad,
                        'departamento' => $user->generalData->departamento,
                        'codigo_postal' => $user->generalData->codigo_postal,
                        'contacto_emergencia_nombre' => $user->generalData->contacto_emergencia_nombre,
                        'contacto_emergencia_telefono' => $user->generalData->contacto_emergencia_telefono,
                        'notas' => $user->generalData->notas,
                    ] : null
                ]
            ], 200);
        } catch (\Throwable $th) {
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener los datos del formulario',
                'error' => config('app.debug') ? $th->getMessage() : 'Error interno'
            ], 500);
        }
    }
}
