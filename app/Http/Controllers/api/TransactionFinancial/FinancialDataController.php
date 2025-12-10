<?php

namespace App\Http\Controllers\api\TransactionFinancial;

use App\Http\Controllers\Controller;
use App\Models\FinancialTransactions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Validator;


class FinancialDataController extends Controller
{
    // Función 1: Refrescar datos generales con paginación
    public function refreshData(Request $request)
    {
        try {
            // Validar parámetros opcionales
            $validator = Validator::make($request->all(), [
                'per_page' => 'nullable|integer|min:1|max:100',
                'page' => 'nullable|integer|min:1',
                'negocio_id' => 'nullable|exists:businesses,id',
                'fecha_desde' => 'nullable|date',
                'fecha_hasta' => 'nullable|date|after_or_equal:fecha_desde',
                'tipo_transaccion' => 'nullable|in:Ingreso,Egreso',
                'estado_id' => 'nullable|exists:transaction_states,id'
            ]);
            if ($validator->fails()) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }
            // Configurar paginación
            $perPage = $request->get('per_page', 15);
            // Construir query base
            $query = FinancialTransactions::with([
                'user.generalData',
                'user.driver',
                'negocio',
                'metodo',
                'categoria',
                'vehicle',
                'estadoDeTransaccion'
            ]);
            // Aplicar filtros si existen
            if ($request->filled('negocio_id')) {
                $query->where('negocio_id', $request->negocio_id);
            }
            if ($request->filled('fecha_desde')) {
                $query->where('fecha', '>=', $request->fecha_desde);
            }
            if ($request->filled('fecha_hasta')) {
                $query->where('fecha', '<=', $request->fecha_hasta);
            }
            if ($request->filled('tipo_transaccion')) {
                $query->where('tipo_de_transaccion', $request->tipo_transaccion);
            }
            if ($request->filled('estado_id')) {
                $query->where('estado_de_transaccion_id', $request->estado_id);
            }
            // Ordenar por fecha más reciente
            $query->orderBy('fecha', 'desc')->orderBy('created_at', 'desc');
            // Obtener datos paginados
            $items = $query->paginate($perPage);
            // Calcular estadísticas rápidas
            $totalIngresos = FinancialTransactions::where('tipo_de_transaccion', 'Ingreso')
                ->when($request->filled('negocio_id'), function ($q) use ($request) {
                    return $q->where('negocio_id', $request->negocio_id);
                })
                ->sum('importe_total');
            $totalEgresos = FinancialTransactions::where('tipo_de_transaccion', 'Egreso')
                ->when($request->filled('negocio_id'), function ($q) use ($request) {
                    return $q->where('negocio_id', $request->negocio_id);
                })
                ->sum('importe_total');
            return response()->json([
                'status' => 'success',
                'message' => 'Datos refrescados exitosamente',
                'data' => $items->items(),
                'pagination' => [
                    'current_page' => $items->currentPage(),
                    'last_page' => $items->lastPage(),
                    'per_page' => $items->perPage(),
                    'total' => $items->total(),
                    'from' => $items->firstItem(),
                    'to' => $items->lastItem()
                ],
                'estadisticas_rapidas' => [
                    'total_ingresos' => number_format($totalIngresos, 2),
                    'total_egresos' => number_format($totalEgresos, 2),
                    'balance' => number_format($totalIngresos - $totalEgresos, 2),
                    'total_transacciones' => $items->total()
                ],
                'timestamp' => now()->toDateTimeString()
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Error al refrescar los datos',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    // Función 2: Refrescar solo transacciones recientes (últimas 24 horas)
    public function refreshRecentData(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'negocio_id' => 'nullable|exists:businesses,id',
                'horas' => 'nullable|integer|min:1|max:168' // máximo 7 días
            ]);
            if ($validator->fails()) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }
            $horas = $request->get('horas', 24);
            $fechaLimite = now()->subHours($horas);
            $query = FinancialTransactions::with([
                'user.generalData',
                'negocio',
                'metodo',
                'categoria',
                'estadoDeTransaccion'
            ])
                ->where('created_at', '>=', $fechaLimite);
            if ($request->filled('negocio_id')) {
                $query->where('negocio_id', $request->negocio_id);
            }
            $transaccionesRecientes = $query->orderBy('created_at', 'desc')->get();
            // Estadísticas de transacciones recientes
            $ingresosRecientes = $transaccionesRecientes
                ->where('tipo_de_transaccion', 'Ingreso')
                ->sum('importe_total');
            $egresosRecientes = $transaccionesRecientes
                ->where('tipo_de_transaccion', 'Egreso')
                ->sum('importe_total');
            return response()->json([
                'status' => 'success',
                'message' => "Datos de las últimas {$horas} horas refrescados",
                'data' => $transaccionesRecientes,
                'estadisticas_periodo' => [
                    'periodo_horas' => $horas,
                    'desde' => $fechaLimite->toDateTimeString(),
                    'hasta' => now()->toDateTimeString(),
                    'total_transacciones' => $transaccionesRecientes->count(),
                    'ingresos_periodo' => number_format($ingresosRecientes, 2),
                    'egresos_periodo' => number_format($egresosRecientes, 2),
                    'balance_periodo' => number_format($ingresosRecientes - $egresosRecientes, 2)
                ],
                'timestamp' => now()->toDateTimeString()
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Error al obtener datos recientes',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    // Función 3: Refrescar estado financiero actualizado
    public function refreshFinancialStatus(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'negocio_id' => 'nullable|exists:businesses,id'
            ]);
            if ($validator->fails()) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }
            $negocioId = $request->negocio_id;
            $hoy = now()->toDateString();
            $inicioMes = now()->startOfMonth()->toDateString();
            $inicioAno = now()->startOfYear()->toDateString();
            // Query base
            $queryBase = FinancialTransactions::query();
            if ($negocioId) {
                $queryBase->where('negocio_id', $negocioId);
            }
            // Estadísticas del día
            $estadisticasHoy = [
                'ingresos' => (clone $queryBase)
                    ->where('tipo_de_transaccion', 'Ingreso')
                    ->whereDate('fecha', $hoy)
                    ->sum('importe_total'),
                'egresos' => (clone $queryBase)
                    ->where('tipo_de_transaccion', 'Egreso')
                    ->whereDate('fecha', $hoy)
                    ->sum('importe_total'),
                'transacciones' => (clone $queryBase)
                    ->whereDate('fecha', $hoy)
                    ->count()
            ];
            // Estadísticas del mes
            $estadisticasMes = [
                'ingresos' => (clone $queryBase)
                    ->where('tipo_de_transaccion', 'Ingreso')
                    ->whereBetween('fecha', [$inicioMes, $hoy])
                    ->sum('importe_total'),
                'egresos' => (clone $queryBase)
                    ->where('tipo_de_transaccion', 'Egreso')
                    ->whereBetween('fecha', [$inicioMes, $hoy])
                    ->sum('importe_total'),
                'transacciones' => (clone $queryBase)
                    ->whereBetween('fecha', [$inicioMes, $hoy])
                    ->count()
            ];
            // Estadísticas del año
            $estadisticasAno = [
                'ingresos' => (clone $queryBase)
                    ->where('tipo_de_transaccion', 'Ingreso')
                    ->whereBetween('fecha', [$inicioAno, $hoy])
                    ->sum('importe_total'),
                'egresos' => (clone $queryBase)
                    ->where('tipo_de_transaccion', 'Egreso')
                    ->whereBetween('fecha', [$inicioAno, $hoy])
                    ->sum('importe_total'),
                'transacciones' => (clone $queryBase)
                    ->whereBetween('fecha', [$inicioAno, $hoy])
                    ->count()
            ];
            // Top 5 transacciones más recientes
            $transaccionesRecientes = (clone $queryBase)
                ->with(['negocio:id,nombre', 'categoria:id,descripcion', 'estadoDeTransaccion:id,descripcion'])
                ->orderBy('created_at', 'desc')
                ->limit(5)
                ->get(['id', 'negocio_id', 'categoria_id', 'estado_de_transaccion_id', 'fecha', 'tipo_de_transaccion', 'item', 'importe_total', 'created_at']);
            return response()->json([
                'status' => 'success',
                'message' => 'Estado financiero refrescado',
                'data' => [
                    'resumen_hoy' => [
                        'fecha' => $hoy,
                        'ingresos' => number_format($estadisticasHoy['ingresos'], 2),
                        'egresos' => number_format($estadisticasHoy['egresos'], 2),
                        'balance' => number_format($estadisticasHoy['ingresos'] - $estadisticasHoy['egresos'], 2),
                        'transacciones' => $estadisticasHoy['transacciones']
                    ],
                    'resumen_mes' => [
                        'periodo' => now()->format('F Y'),
                        'ingresos' => number_format($estadisticasMes['ingresos'], 2),
                        'egresos' => number_format($estadisticasMes['egresos'], 2),
                        'balance' => number_format($estadisticasMes['ingresos'] - $estadisticasMes['egresos'], 2),
                        'transacciones' => $estadisticasMes['transacciones']
                    ],
                    'resumen_ano' => [
                        'ano' => now()->year,
                        'ingresos' => number_format($estadisticasAno['ingresos'], 2),
                        'egresos' => number_format($estadisticasAno['egresos'], 2),
                        'balance' => number_format($estadisticasAno['ingresos'] - $estadisticasAno['egresos'], 2),
                        'transacciones' => $estadisticasAno['transacciones']
                    ],
                    'transacciones_recientes' => $transaccionesRecientes
                ],
                'negocio_filtro' => $negocioId,
                'timestamp' => now()->toDateTimeString()
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Error al refrescar estado financiero',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    // Función 4: Limpiar caché y refrescar (si usas caché)
    public function clearCacheAndRefresh(Request $request)
    {
        try {
            // Limpiar caché relacionado con transacciones financieras
            $cacheKeys = [
                'financial_transactions_summary',
                'financial_stats_today',
                'financial_stats_month',
                'recent_transactions'
            ];
            foreach ($cacheKeys as $key) {
                Cache::forget($key);
            }
            // Si usas cache con tags
            // \Cache::tags(['financial_transactions'])->flush();
            // Recargar datos frescos
            $freshData = $this->refreshData($request);
            return response()->json([
                'status' => 'success',
                'message' => 'Caché limpiado y datos refrescados exitosamente',
                'cache_cleared' => $cacheKeys,
                'fresh_data' => $freshData->getData()
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Error al limpiar caché y refrescar',
                'error' => $e->getMessage()
            ], 500);
        }
    }


    // Función para obtener archivos de transacciones
    public function getTransactionFiles(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'user_id' => 'nullable|exists:users,id',
            'year' => 'nullable|integer|min:2020|max:2030',
            'month' => 'nullable|integer|min:1|max:12',
            'transaction_id' => 'nullable|exists:financial_transactions,id'
        ]);
        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }
        try {
            // Si se especifica una transacción específica
            if ($request->filled('transaction_id')) {
                $transaction = FinancialTransactions::with('user.generalData')
                    ->find($request->transaction_id);
                if (!$transaction || !$transaction->archivo) {
                    return response()->json([
                        'status' => 'error',
                        'message' => 'Transacción no encontrada o sin archivo'
                    ], 404);
                }
                $rutaCompleta = public_path($transaction->archivo);
                if (!file_exists($rutaCompleta)) {
                    return response()->json([
                        'status' => 'error',
                        'message' => 'Archivo no encontrado en el servidor'
                    ], 404);
                }
                return response()->json([
                    'status' => 'success',
                    'data' => [
                        'transaction_id' => $transaction->id,
                        'archivo_url' => asset($transaction->archivo),
                        'archivo_nombre' => basename($transaction->archivo),
                        'fecha_transaccion' => $transaction->fecha,
                        'tipo_transaccion' => $transaction->tipo_de_transaccion,
                        'usuario' => $transaction->user->generalData->nombre ?? 'N/A'
                    ]
                ]);
            }
            // Listar archivos por filtros
            $query = FinancialTransactions::with('user.generalData')
                ->whereNotNull('archivo');
            if ($request->filled('user_id')) {
                $query->where('user_id', $request->user_id);
            }
            if ($request->filled('year')) {
                $query->whereYear('fecha', $request->year);
            }
            if ($request->filled('month')) {
                $query->whereMonth('fecha', $request->month);
            }
            $transacciones = $query->orderBy('fecha', 'desc')->get();
            $archivos = $transacciones->map(function ($transaction) {
                $rutaCompleta = public_path($transaction->archivo);
                $existe = file_exists($rutaCompleta);
                return [
                    'transaction_id' => $transaction->id,
                    'archivo_url' => $existe ? asset($transaction->archivo) : null,
                    'archivo_nombre' => basename($transaction->archivo),
                    'archivo_existe' => $existe,
                    'fecha_transaccion' => $transaction->fecha,
                    'tipo_transaccion' => $transaction->tipo_de_transaccion,
                    'importe_total' => $transaction->importe_total,
                    'item' => $transaction->item,
                    'usuario' => $transaction->user->generalData->nombre ?? 'N/A'
                ];
            });
            return response()->json([
                'status' => 'success',
                'data' => $archivos,
                'total_archivos' => $archivos->count(),
                'filtros_aplicados' => $request->only(['user_id', 'year', 'month'])
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Error al obtener archivos',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
