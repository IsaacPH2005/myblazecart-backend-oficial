<?php

namespace App\Http\Controllers\api\MovementBox;

use App\Http\Controllers\Controller;
use App\Models\MovementsBox;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class MovementBoxController extends Controller
{
    /**
     * SOLUCIÓN DEFINITIVA - Sin columnas que no existen
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function index(Request $request): JsonResponse
    {
        try {
            Log::info('========== INICIO index() ==========');

            // ========== PARÁMETROS ==========
            $tipo = $request->query('tipo');
            $categoriaId = $request->query('categoria_id');
            $operatingBoxId = $request->query('operating_box_id');
            $fechaInicio = $request->query('fecha_inicio');
            $fechaFin = $request->query('fecha_fin');
            $userId = $request->query('user_id');
            $search = $request->query('search');
            $perPage = $request->query('per_page', 15);

            // ========== CONTEOS ==========
            $totalRegistros = MovementsBox::count();

            // ========== FECHAS EN BD - CONVERSIÓN SEGURA ==========
            $primeraFechaRaw = MovementsBox::min('fecha_movimiento');
            $ultimaFechaRaw = MovementsBox::max('fecha_movimiento');

            $primeraFecha = null;
            $ultimaFecha = null;

            if ($primeraFechaRaw) {
                try {
                    $primeraFecha = is_string($primeraFechaRaw)
                        ? Carbon::createFromFormat('Y-m-d H:i:s', $primeraFechaRaw)
                        : $primeraFechaRaw;
                } catch (\Exception $e) {
                    Log::warning('Error al convertir primera fecha:', ['error' => $e->getMessage()]);
                }
            }

            if ($ultimaFechaRaw) {
                try {
                    $ultimaFecha = is_string($ultimaFechaRaw)
                        ? Carbon::createFromFormat('Y-m-d H:i:s', $ultimaFechaRaw)
                        : $ultimaFechaRaw;
                } catch (\Exception $e) {
                    Log::warning('Error al convertir última fecha:', ['error' => $e->getMessage()]);
                }
            }

            Log::info('Rango BD:', [
                'primera' => $primeraFecha?->format('Y-m-d') ?? 'N/A',
                'última' => $ultimaFecha?->format('Y-m-d') ?? 'N/A'
            ]);

            // ========== CONSTRUIR CONSULTA - SIN 'name' EN USERS ==========
            $query = MovementsBox::query()
                ->with([
                    // Transacción Financiera con todos sus campos
                    'transaccionFinanciera' => function ($q) {
                        $q->select(
                            'id',
                            'categoria_id',
                            'negocio_id',
                            'metodo_id',
                            'estado_de_transaccion_id',
                            'vehicle_id',
                            'caja_operativa_id',
                            'fecha',
                            'punto_de_partida',
                            'destino',
                            'millas',
                            'item',
                            'cantidad',
                            'importe_total',
                            'numero_transaccion',
                            'cliente_proveedor',
                            'observaciones',
                            'created_at',
                            'updated_at'
                        );
                    },
                    'transaccionFinanciera.categoria:id,nombre',
                    'transaccionFinanciera.negocio:id,nombre',
                    'transaccionFinanciera.metodo:id,nombre',
                    'transaccionFinanciera.estadoDeTransaccion:id,nombre',
                    'transaccionFinanciera.vehicle' => function ($q) {
                        $q->select(
                            'id',
                            'numero_placa',
                            'modelo',
                            'marca',
                            'año',
                            'color',
                            'numero_vin',
                            'codigo_unico',
                            'tipo_vehiculo',
                            'combustible',
                            'transmision',
                            'capacidad_carga',
                            'estado'
                        );
                    },
                    'transaccionFinanciera.cajaOperativa:id,nombre,saldo',
                    // SOLUCIÓN: Solo seleccionar 'id' y 'email' - SIN 'name'
                    'user:id,email'
                ])
                ->orderBy('fecha_movimiento', 'desc');

            Log::info('✅ Consulta base creada');

            // ========== APLICAR FILTROS ==========

            if ($tipo && trim($tipo) !== '') {
                $query->where('tipo', strtolower($tipo));
            }

            if ($userId && $userId !== '') {
                $query->where('user_id', $userId);
            }

            if ($categoriaId && $categoriaId !== '') {
                $query->whereHas('transaccionFinanciera', function ($q) use ($categoriaId) {
                    $q->where('categoria_id', $categoriaId);
                });
            }

            if ($operatingBoxId && $operatingBoxId !== '') {
                $query->whereHas('transaccionFinanciera', function ($q) use ($operatingBoxId) {
                    $q->where('caja_operativa_id', $operatingBoxId);
                });
            }

            // ========== FILTRO FECHAS ==========
            if (($fechaInicio && trim($fechaInicio) !== '') || ($fechaFin && trim($fechaFin) !== '')) {

                if ($fechaInicio && trim($fechaInicio) !== '') {
                    try {
                        $inicio = Carbon::createFromFormat('Y-m-d', $fechaInicio, 'UTC')->startOfDay();
                        $query->whereDate('fecha_movimiento', '>=', $inicio->toDateString());
                    } catch (\Exception $e) {
                        Log::warning('Error fecha inicio:', ['error' => $e->getMessage()]);
                    }
                }

                if ($fechaFin && trim($fechaFin) !== '') {
                    try {
                        $fin = Carbon::createFromFormat('Y-m-d', $fechaFin, 'UTC')->endOfDay();
                        $query->whereDate('fecha_movimiento', '<=', $fin->toDateString());
                    } catch (\Exception $e) {
                        Log::warning('Error fecha fin:', ['error' => $e->getMessage()]);
                    }
                }
            } else {
                // Por defecto: mes actual
                $hoy = Carbon::now('UTC');
                $primerDiaMes = $hoy->copy()->startOfMonth();
                $query->whereDate('fecha_movimiento', '>=', $primerDiaMes->toDateString())
                    ->whereDate('fecha_movimiento', '<=', $hoy->toDateString());
            }

            // Búsqueda
            if ($search && trim($search) !== '') {
                $query->where(function ($q) use ($search) {
                    $q->where('descripcion', 'like', "%{$search}%")
                        ->orWhere('numero_transaccion', 'like', "%{$search}%")
                        ->orWhereHas('transaccionFinanciera', function ($q2) use ($search) {
                            $q2->where('item', 'like', "%{$search}%")
                                ->orWhere('numero_transaccion', 'like', "%{$search}%");
                        });
                });
            }

            // ========== TOTALES ==========
            $totalFiltrado = $query->count();

            $queryParaTotales = clone $query;
            $totalIngresos = (clone $queryParaTotales)->where('tipo', 'ingreso')->sum('monto');
            $totalEgresos = (clone $queryParaTotales)->where('tipo', 'egreso')->sum('monto');
            $saldo = $totalIngresos - $totalEgresos;

            // ========== PAGINAR ==========
            $movimientos = $query->paginate($perPage);

            // ========== RESPUESTA ==========
            $respuesta = [
                'status' => 'success',
                'message' => $totalFiltrado === 0
                    ? 'No se encontraron movimientos'
                    : "✅ {$totalFiltrado} movimientos encontrados",
                'data' => $movimientos->items(),
                'totales' => [
                    'ingresos' => round($totalIngresos, 2),
                    'egresos' => round($totalEgresos, 2),
                    'saldo' => round($saldo, 2),
                    'total_filtrados' => $totalFiltrado,
                    'total_sistema' => $totalRegistros
                ],
                'paginacion' => [
                    'página' => $movimientos->currentPage(),
                    'por_página' => (int)$perPage,
                    'total_páginas' => $movimientos->lastPage(),
                    'total' => $movimientos->total()
                ],
                'filtros' => [
                    'tipo' => $tipo ?? null,
                    'categoria_id' => $categoriaId ?? null,
                    'operating_box_id' => $operatingBoxId ?? null,
                    'fecha_inicio' => $fechaInicio ?? null,
                    'fecha_fin' => $fechaFin ?? null,
                    'user_id' => $userId ?? null,
                    'search' => $search ?? null
                ],
                'debug' => [
                    'primera_fecha_bd' => $primeraFecha?->format('Y-m-d') ?? null,
                    'ultima_fecha_bd' => $ultimaFecha?->format('Y-m-d') ?? null
                ]
            ];

            Log::info('✅ ========== FIN - ÉXITO ==========');

            return response()->json($respuesta, 200);
        } catch (\Exception $e) {
            Log::error('❌ ERROR:', [
                'mensaje' => $e->getMessage(),
                'archivo' => $e->getFile(),
                'línea' => $e->getLine()
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Error al obtener movimientos',
                'error' => $e->getMessage(),
                'debug' => config('app.debug') ? $e->getTraceAsString() : null
            ], 500);
        }
    }

    /**
     * Mostrar un movimiento de caja específico
     *
     * @param string $id
     * @return JsonResponse
     */
    public function show(string $id): JsonResponse
    {
        try {
            // Validar que el ID sea un número
            if (!is_numeric($id)) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'ID inválido. Debe ser un número.'
                ], 422);
            }

            $movimiento = MovementsBox::with([
                'transaccionFinanciera.categoria.cajaOperativa',
                'transaccionFinanciera.negocio',
                'transaccionFinanciera.metodo',
                'transaccionFinanciera.estadoDeTransaccion',
                'transaccionFinanciera.vehicle',
                'user:id,email'
            ])->findOrFail((int)$id);

            return response()->json([
                'status' => 'success',
                'message' => 'Movimiento de caja obtenido exitosamente',
                'data' => $movimiento
            ], 200);
        } catch (\Exception $e) {
            Log::error('Error en MovementBoxController@show: ' . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Error al obtener el movimiento de caja',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Obtener resumen de movimientos por categoría
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function resumenPorCategoria(Request $request): JsonResponse
    {
        try {
            $fechaInicio = $request->query('fecha_inicio');
            $fechaFin = $request->query('fecha_fin');
            $tipo = $request->query('tipo'); // 'ingreso' o 'egreso'

            $query = MovementsBox::with('transaccionFinanciera.categoria')
                ->selectRaw('transaccion_financiera_id, SUM(monto) as total')
                ->groupBy('transaccion_financiera_id');

            if ($fechaInicio) {
                // Usar whereDate para comparar solo la parte de la fecha
                $query->whereDate('fecha_movimiento', '>=', $fechaInicio);
            }

            if ($fechaFin) {
                // Usar whereDate para comparar solo la parte de la fecha
                $query->whereDate('fecha_movimiento', '<=', $fechaFin);
            }

            if ($tipo) {
                $query->where('tipo', $tipo);
            }

            $resumen = $query->get()->map(function ($movimiento) {
                return [
                    'categoria' => $movimiento->transaccionFinanciera->categoria->nombre ?? 'Sin categoría',
                    'categoria_id' => $movimiento->transaccionFinanciera->categoria->id ?? null,
                    'total' => $movimiento->total
                ];
            });

            return response()->json([
                'status' => 'success',
                'message' => 'Resumen por categoría obtenido exitosamente',
                'data' => $resumen,
                'filtros_aplicados' => [
                    'fecha_inicio' => $fechaInicio,
                    'fecha_fin' => $fechaFin,
                    'tipo' => $tipo
                ]
            ], 200);
        } catch (\Exception $e) {
            Log::error('Error en MovementBoxController@resumenPorCategoria: ' . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Error al obtener el resumen por categoría',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Obtener movimientos recientes (últimos 7 días)
     *
     * @return JsonResponse
     */
    public function recientes(): JsonResponse
    {
        try {
            $fechaLimite = now()->subDays(7)->endOfDay();

            $movimientos = MovementsBox::with([
                'transaccionFinanciera.categoria',
                'user:id,email'
            ])
                ->where('fecha_movimiento', '>=', $fechaLimite)
                ->orderBy('fecha_movimiento', 'desc')
                ->take(10)
                ->get();

            return response()->json([
                'status' => 'success',
                'message' => 'Movimientos recientes obtenidos exitosamente',
                'data' => $movimientos
            ], 200);
        } catch (\Exception $e) {
            Log::error('Error en MovementBoxController@recientes: ' . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Error al obtener los movimientos recientes',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Obtener movimientos por caja operativa
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function porCajaOperativa(Request $request): JsonResponse
    {
        try {
            $operatingBoxId = $request->query('operating_box_id');
            $fechaInicio = $request->query('fecha_inicio');
            $fechaFin = $request->query('fecha_fin');

            if (!$operatingBoxId) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'El ID de la caja operativa es requerido'
                ], 422);
            }

            $query = MovementsBox::with([
                'transaccionFinanciera.categoria',
                'transaccionFinanciera.negocio',
                'user:id,email,name'
            ])->whereHas('transaccionFinanciera', function ($q) use ($operatingBoxId) {
                // Filtrar directamente por caja operativa en la transacción financiera
                $q->where('caja_operativa_id', $operatingBoxId);
            });

            if ($fechaInicio) {
                // Usar whereDate para comparar solo la parte de la fecha
                $query->whereDate('fecha_movimiento', '>=', $fechaInicio);
            }

            if ($fechaFin) {
                // Usar whereDate para comparar solo la parte de la fecha
                $query->whereDate('fecha_movimiento', '<=', $fechaFin);
            }

            $movimientos = $query->orderBy('fecha_movimiento', 'desc')->get();

            // Calcular totales
            $totales = [
                'ingresos' => $movimientos->where('tipo', 'ingreso')->sum('monto'),
                'egresos' => $movimientos->where('tipo', 'egreso')->sum('monto'),
                'saldo' => $movimientos->where('tipo', 'ingreso')->sum('monto') -
                    $movimientos->where('tipo', 'egreso')->sum('monto')
            ];

            return response()->json([
                'status' => 'success',
                'message' => 'Movimientos por caja operativa obtenidos exitosamente',
                'data' => $movimientos,
                'totales' => $totales,
                'filtros_aplicados' => [
                    'operating_box_id' => $operatingBoxId,
                    'fecha_inicio' => $fechaInicio,
                    'fecha_fin' => $fechaFin
                ]
            ], 200);
        } catch (\Exception $e) {
            Log::error('Error en MovementBoxController@porCajaOperativa: ' . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Error al obtener los movimientos por caja operativa',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
