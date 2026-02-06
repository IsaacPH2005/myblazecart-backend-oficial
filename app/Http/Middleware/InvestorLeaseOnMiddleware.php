<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class InvestorLeaseOnMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @return mixed
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Verificar que el usuario esté autenticado
        if (!$request->user()) {
            return new JsonResponse([
                'success' => false,
                'message' => 'No estás autenticado. Por favor inicia sesión.'
            ], 401);
        }

        // Verificar que el usuario tenga el rol de INVERSIONISTA LEASE ON
        if (!$request->user()->hasRole('INVERSIONISTA LEASE ON')) {
            return new JsonResponse([
                'success' => false,
                'message' => 'No tienes permisos de inversionista para acceder a este recurso.'
            ], 403);
        }

        return $next($request);
    }
}
