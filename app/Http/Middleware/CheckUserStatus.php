<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckUserStatus
{
    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @return mixed
     */
    public function handle(Request $request, Closure $next)
    {
        // Obtener el usuario autenticado
        $user = $request->user();

        // Si el usuario existe y está desactivado (estado = false)
        if ($user && !$user->estado) {
            // Revocar todos los tokens del usuario
            $user->tokens()->delete();

            // Responder con un error 401 (Unauthorized)
            return new JsonResponse([
                'message' => 'Tu cuenta ha sido desactivada. Por favor, contacta al administrador.'
            ], 401);
        }

        return $next($request);
    }
}
