<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class CarrierMiddleware
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
        if (!$request->user() || !$request->user()->hasRole('CARRIER')) {
            return new JsonResponse([
                'success' => false,
                'message' => 'No tienes permisos de transportista para acceder a este recurso.'
            ], 403);
        }

        return $next($request);
    }
}
