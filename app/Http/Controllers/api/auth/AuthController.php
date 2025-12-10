<?php

namespace App\Http\Controllers\api\auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function login(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
            'password' => 'required',
        ]);

        // Buscar usuario con sus relaciones
        $user = User::with(['generalData', 'roles.permissions'])
            ->where('email', $request->email)
            ->first();

        // Verificar si el usuario existe y la contraseña es correcta
        if (! $user || ! Hash::check($request->password, $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['Las credenciales proporcionadas son incorrectas.'],
            ]);
        }

        // Verificar si el usuario está baneado (estado = false)
        if (!$user->estado) {
            throw ValidationException::withMessages([
                'email' => ['Esta cuenta ha sido desactivada. Contacta al administrador.'],
            ]);
        }

        // Elimina tokens antiguos (si lo deseas)
        /* $user->tokens()->delete(); */

        // Crea nuevo token
        $token = $user->createToken($request->email)->plainTextToken;

        // Carga explícitamente la relación si no se cargó con with()
        if (!$user->relationLoaded('generalData')) {
            $user->load('generalData');
        }

        return response()->json([
            'user' => $user,
            'token' => $token,
        ]);
    }

    public function logout(Request $request)
    {
        // Eliminar todos los tokens del usuario (cerrar sesión en todos los dispositivos)
        $request->user()->tokens()->delete();

        return response()->json([
            'message' => 'Sesión cerrada exitosamente.'
        ]);
    }
}
