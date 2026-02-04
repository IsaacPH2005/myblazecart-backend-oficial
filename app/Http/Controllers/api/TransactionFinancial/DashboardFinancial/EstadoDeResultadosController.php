<?php

namespace App\Http\Controllers\api\TransactionFinancial\DashboardFinancial;

use App\Exports\FinancialStatementExport;
use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Models\FinancialTransactions;
use App\Models\OperatingBox;
use App\Models\Vehicle;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Facades\Excel;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;

class EstadoDeResultadosController extends Controller
{
    private function getUrlCompleta($rutaArchivo)
    {
        if (!$rutaArchivo) {
            return null;
        }

        if (filter_var($rutaArchivo, FILTER_VALIDATE_URL)) {
            return $rutaArchivo;
        }

        if (Storage::disk('public')->exists($rutaArchivo)) {
            return asset('storage/' . $rutaArchivo);
        }

        if (file_exists(public_path($rutaArchivo))) {
            return asset($rutaArchivo);
        }

        return url($rutaArchivo);
    }

    private function esImagen($tipoArchivo)
    {
        $tiposImagen = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif', 'image/webp', 'image/svg+xml'];
        return in_array(strtolower($tipoArchivo), $tiposImagen);
    }

    private function formatearTamanoArchivo($bytes)
    {
        if (!$bytes || $bytes == 0) {
            return '0 B';
        }

        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $factor = floor((strlen($bytes) - 1) / 3);

        return sprintf("%.2f %s", $bytes / pow(1024, $factor), $units[$factor]);
    }

    public function getFinancialStatementByDateRange(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'negocio_id' => ['required', 'exists:businesses,id'],
            'vehicle_id' => ['nullable', 'exists:vehicles,id'],
            'fecha_inicial' => 'required|date',
            'fecha_final' => 'required|date|after_or_equal:fecha_inicial',
        ], [
            'negocio_id.required' => 'El ID del negocio es obligatorio',
            'negocio_id.exists' => 'El negocio seleccionado no existe',
            'vehicle_id.exists' => 'El vehículo seleccionado no existe',
            'fecha_inicial.required' => 'La fecha inicial es obligatoria',
            'fecha_inicial.date' => 'La fecha inicial debe ser una fecha válida',
            'fecha_final.required' => 'La fecha final es obligatoria',
            'fecha_final.date' => 'La fecha final debe ser una fecha válida',
            'fecha_final.after_or_equal' => 'La fecha final debe ser posterior o igual a la fecha inicial',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Error de validación',
                'errors' => $validator->errors()
            ], 422);
        }

        $negocioId = $request->negocio_id;
        $vehicleId = $request->vehicle_id;
        $fechaInicial = $request->fecha_inicial;
        $fechaFinal = $request->fecha_final;

        try {
            $negocio = Business::findOrFail($negocioId);
            $vehicle = null;
            $esFiltradoPorVehiculo = !is_null($vehicleId);

            if ($esFiltradoPorVehiculo) {
                $vehicle = Vehicle::findOrFail($vehicleId);

                if ($vehicle->negocio_id != $negocioId) {
                    return response()->json([
                        'status' => 'error',
                        'message' => 'El vehículo no pertenece al negocio seleccionado'
                    ], 400);
                }
            }

            $queryIngresos = FinancialTransactions::where('negocio_id', $negocioId)
                ->where('tipo_de_transaccion', 'Ingreso')
                ->whereNull('caja_operativa_id')
                ->whereBetween('fecha', [$fechaInicial, $fechaFinal]);

            $queryEgresos = FinancialTransactions::where('negocio_id', $negocioId)
                ->where('tipo_de_transaccion', 'Egreso')
                ->whereBetween('fecha', [$fechaInicial, $fechaFinal]);

            if ($esFiltradoPorVehiculo) {
                $queryIngresos->where('vehicle_id', $vehicleId);
                $queryEgresos->where('vehicle_id', $vehicleId);
            }

            $totalIngresosBrutos = $queryIngresos->sum('importe_total');
            $totalEgresosBrutos = $queryEgresos->sum('importe_total');

            $margenBruto = $totalIngresosBrutos - $totalEgresosBrutos;
            $margenUtilAntesImpuestos = 0;
            $impuestosEstimados = 0;
            $costosFijosAdicionales = 0;

            $rentabilidadPorcentaje = $totalIngresosBrutos > 0
                ? ($margenBruto / $totalIngresosBrutos) * 100
                : 0;

            $estadoPorCaja = [];
            $totalesGlobalesCajas = [
                'total_ingresos_cajas' => 0,
                'total_egresos_cajas' => 0,
                'balance_global_cajas' => 0
            ];
            $distribucionCajasPorBalance = collect([]);

            if (!$esFiltradoPorVehiculo) {
                $cajasConTransacciones = FinancialTransactions::where('negocio_id', $negocioId)
                    ->whereNotNull('caja_operativa_id')
                    ->whereBetween('fecha', [$fechaInicial, $fechaFinal])
                    ->select('caja_operativa_id')
                    ->distinct()
                    ->pluck('caja_operativa_id');

                $cajasOperativas = OperatingBox::whereIn('id', $cajasConTransacciones)
                    ->where('estado', true)
                    ->get();

                foreach ($cajasOperativas as $caja) {
                    $ingresosCaja = FinancialTransactions::where('negocio_id', $negocioId)
                        ->where('caja_operativa_id', $caja->id)
                        ->where('tipo_de_transaccion', 'Ingreso')
                        ->whereBetween('fecha', [$fechaInicial, $fechaFinal])
                        ->sum('importe_total');

                    $egresosCaja = FinancialTransactions::where('negocio_id', $negocioId)
                        ->where('caja_operativa_id', $caja->id)
                        ->where('tipo_de_transaccion', 'Egreso')
                        ->whereBetween('fecha', [$fechaInicial, $fechaFinal])
                        ->sum('importe_total');

                    $balanceCaja = $ingresosCaja - $egresosCaja;

                    $totalTransaccionesIngresos = FinancialTransactions::where('negocio_id', $negocioId)
                        ->where('caja_operativa_id', $caja->id)
                        ->where('tipo_de_transaccion', 'Ingreso')
                        ->whereBetween('fecha', [$fechaInicial, $fechaFinal])
                        ->count();

                    $totalTransaccionesEgresos = FinancialTransactions::where('negocio_id', $negocioId)
                        ->where('caja_operativa_id', $caja->id)
                        ->where('tipo_de_transaccion', 'Egreso')
                        ->whereBetween('fecha', [$fechaInicial, $fechaFinal])
                        ->count();

                    $transaccionesPorEstadoCaja = FinancialTransactions::where('negocio_id', $negocioId)
                        ->where('caja_operativa_id', $caja->id)
                        ->whereBetween('fecha', [$fechaInicial, $fechaFinal])
                        ->join('transaction_states', 'financial_transactions.estado_de_transaccion_id', '=', 'transaction_states.id')
                        ->select(
                            'transaction_states.id as estado_id',
                            'transaction_states.nombre as estado_nombre',
                            DB::raw('COALESCE(transaction_states.descripcion, "") as estado_descripcion'),
                            'financial_transactions.tipo_de_transaccion',
                            DB::raw('COUNT(*) as total_transacciones'),
                            DB::raw('SUM(importe_total) as total_importe')
                        )
                        ->groupBy(
                            'transaction_states.id',
                            'transaction_states.nombre',
                            'transaction_states.descripcion',
                            'financial_transactions.tipo_de_transaccion'
                        )
                        ->get();

                    $estadosPorCaja = [];
                    foreach ($transaccionesPorEstadoCaja as $transaccion) {
                        $estadoId = $transaccion->estado_id;
                        $estadoNombre = strtoupper($transaccion->estado_nombre);
                        $tipo = $transaccion->tipo_de_transaccion;

                        if (!isset($estadosPorCaja[$estadoId])) {
                            $estadosPorCaja[$estadoId] = [
                                'estado_id' => $estadoId,
                                'estado_nombre' => $estadoNombre,
                                'estado_descripcion' => $transaccion->estado_descripcion ?? '',
                                'ingresos_recargas' => 0,
                                'egresos_subtracciones' => 0,
                                'total_transacciones_recargas' => 0,
                                'total_transacciones_subtracciones' => 0,
                                'balance_estado_caja' => 0
                            ];
                        }

                        if ($tipo === 'Ingreso') {
                            $estadosPorCaja[$estadoId]['ingresos_recargas'] = floatval($transaccion->total_importe);
                            $estadosPorCaja[$estadoId]['total_transacciones_recargas'] = intval($transaccion->total_transacciones);
                        } else {
                            $estadosPorCaja[$estadoId]['egresos_subtracciones'] = floatval($transaccion->total_importe);
                            $estadosPorCaja[$estadoId]['total_transacciones_subtracciones'] = intval($transaccion->total_transacciones);
                        }

                        $estadosPorCaja[$estadoId]['balance_estado_caja'] =
                            $estadosPorCaja[$estadoId]['ingresos_recargas'] - $estadosPorCaja[$estadoId]['egresos_subtracciones'];
                    }

                    $promedioIngreso = $totalTransaccionesIngresos > 0 ? $ingresosCaja / $totalTransaccionesIngresos : 0;
                    $promedioEgreso = $totalTransaccionesEgresos > 0 ? $egresosCaja / $totalTransaccionesEgresos : 0;

                    $estadoPorCaja[] = [
                        'caja_operativa' => [
                            'id' => $caja->id,
                            'nombre' => strtoupper($caja->nombre),
                            'descripcion' => $caja->descripcion ?? 'Sin descripción',
                            'saldo_actual' => floatval($caja->saldo),
                        ],
                        'periodo' => [
                            'ingresos_recargas' => floatval($ingresosCaja),
                            'egresos_subtracciones' => floatval($egresosCaja),
                            'balance_periodo' => floatval($balanceCaja),
                        ],
                        'transacciones_totales' => [
                            'total_recargas' => intval($totalTransaccionesIngresos),
                            'total_subtracciones' => intval($totalTransaccionesEgresos),
                            'total_transacciones_caja' => intval($totalTransaccionesIngresos + $totalTransaccionesEgresos),
                        ],
                        'promedios' => [
                            'promedio_recarga' => round($promedioIngreso, 2),
                            'promedio_subtraccion' => round($promedioEgreso, 2),
                        ],
                        'rentabilidad_caja' => $ingresosCaja > 0 ? round((($ingresosCaja - $egresosCaja) / $ingresosCaja) * 100, 2) : 0,
                        'diferencia_saldo' => floatval($caja->saldo - $balanceCaja),
                        'detalle_por_estado' => array_values($estadosPorCaja),
                    ];

                    $totalesGlobalesCajas['total_ingresos_cajas'] += $ingresosCaja;
                    $totalesGlobalesCajas['total_egresos_cajas'] += $egresosCaja;
                    $totalesGlobalesCajas['balance_global_cajas'] += $balanceCaja;
                }

                $distribucionCajasPorBalance = collect($estadoPorCaja)->map(function ($item) use ($totalesGlobalesCajas) {
                    $balanceGlobal = $totalesGlobalesCajas['balance_global_cajas'];
                    return [
                        'caja_id' => $item['caja_operativa']['id'],
                        'nombre_caja' => $item['caja_operativa']['nombre'],
                        'balance_periodo' => $item['periodo']['balance_periodo'],
                        'porcentaje_balance' => $balanceGlobal != 0
                            ? round(($item['periodo']['balance_periodo'] / $balanceGlobal) * 100, 2)
                            : 0,
                    ];
                });
            }

            $queryTransaccionesEstado = FinancialTransactions::where('negocio_id', $negocioId)
                ->where(function ($query) {
                    $query->where('tipo_de_transaccion', 'Egreso')
                        ->orWhere(function ($query) {
                            $query->where('tipo_de_transaccion', 'Ingreso')
                                ->whereNull('caja_operativa_id');
                        });
                })
                ->whereBetween('fecha', [$fechaInicial, $fechaFinal]);

            if ($esFiltradoPorVehiculo) {
                $queryTransaccionesEstado->where('vehicle_id', $vehicleId);
            }

            $transaccionesPorEstado = $queryTransaccionesEstado
                ->join('transaction_states', 'financial_transactions.estado_de_transaccion_id', '=', 'transaction_states.id')
                ->select(
                    'transaction_states.id as estado_id',
                    'transaction_states.nombre as estado_nombre',
                    DB::raw('COALESCE(transaction_states.descripcion, "") as estado_descripcion'),
                    'financial_transactions.tipo_de_transaccion',
                    DB::raw('COUNT(*) as total_transacciones'),
                    DB::raw('SUM(importe_total) as total_importe')
                )
                ->groupBy(
                    'transaction_states.id',
                    'transaction_states.nombre',
                    'transaction_states.descripcion',
                    'financial_transactions.tipo_de_transaccion'
                )
                ->get();

            $estadosFinancieros = [];
            foreach ($transaccionesPorEstado as $transaccion) {
                $estadoId = $transaccion->estado_id;
                $estadoNombre = strtoupper($transaccion->estado_nombre);
                $tipo = $transaccion->tipo_de_transaccion;

                if (!isset($estadosFinancieros[$estadoId])) {
                    $estadosFinancieros[$estadoId] = [
                        'estado_id' => $estadoId,
                        'estado_nombre' => $estadoNombre,
                        'estado_descripcion' => $transaccion->estado_descripcion ?? '',
                        'ingresos' => 0,
                        'egresos' => 0,
                        'total_transacciones_ingresos' => 0,
                        'total_transacciones_egresos' => 0,
                        'balance_estado' => 0,
                        'transacciones_detalladas' => []
                    ];
                }

                if ($tipo === 'Ingreso') {
                    $estadosFinancieros[$estadoId]['ingresos'] = floatval($transaccion->total_importe);
                    $estadosFinancieros[$estadoId]['total_transacciones_ingresos'] = intval($transaccion->total_transacciones);
                } else {
                    $estadosFinancieros[$estadoId]['egresos'] = floatval($transaccion->total_importe);
                    $estadosFinancieros[$estadoId]['total_transacciones_egresos'] = intval($transaccion->total_transacciones);
                }

                $estadosFinancieros[$estadoId]['balance_estado'] =
                    $estadosFinancieros[$estadoId]['ingresos'] - $estadosFinancieros[$estadoId]['egresos'];
            }

            foreach ($estadosFinancieros as $estadoId => &$estadoData) {
                $queryDetalles = FinancialTransactions::where('negocio_id', $negocioId)
                    ->where('estado_de_transaccion_id', $estadoId)
                    ->where(function ($query) {
                        $query->where('tipo_de_transaccion', 'Egreso')
                            ->orWhere(function ($query) {
                                $query->where('tipo_de_transaccion', 'Ingreso')
                                    ->whereNull('caja_operativa_id');
                            });
                    })
                    ->whereBetween('fecha', [$fechaInicial, $fechaFinal]);

                if ($esFiltradoPorVehiculo) {
                    $queryDetalles->where('vehicle_id', $vehicleId);
                }

                $transaccionesDetalle = $queryDetalles
                    ->with([
                        'negocio:id,nombre,descripcion',
                        'metodo' => function ($query) {
                            $query->select('id', 'nombre');
                        },
                        'categoria:id,nombre,descripcion,tipo',
                        'user.generalData:user_id,nombre,apellido,documento_identidad,celular',
                        'vehicle:id,codigo_unico,numero_placa,marca,modelo,año,tipo_vehiculo,tipo_propiedad',
                        'estadoDeTransaccion:id,nombre,descripcion,color',
                        'cajaOperativa:id,nombre,descripcion,saldo',
                        'archivos:id,financial_transaction_id,nombre_archivo,ruta_archivo,tipo_archivo,tamano_archivo,created_at',
                        'pendingPayment:id,financial_transaction_id,monto_pendiente,fecha_vencimiento,estado'
                    ])
                    ->orderBy('fecha', 'desc')
                    ->orderBy('created_at', 'desc')
                    ->get();

                $estadoData['transacciones_detalladas'] = $transaccionesDetalle->map(function ($trans) {
                    return [
                        'id' => $trans->id,
                        'numero_transaccion' => $trans->numero_transaccion,
                        'tipo_de_transaccion' => $trans->tipo_de_transaccion,
                        'fecha' => $trans->fecha ? $trans->fecha->format('Y-m-d') : null,
                        'fecha_formateada' => $trans->fecha ? $trans->fecha->format('d/m/Y') : null,
                        'item' => $trans->item,
                        'cantidad' => floatval($trans->cantidad ?? 0),
                        'importe_total' => floatval($trans->importe_total ?? 0),
                        'importe_total_formateado' => number_format($trans->importe_total ?? 0, 2, '.', ','),
                        'cliente_proveedor' => $trans->cliente_proveedor,
                        'subcategoria' => $trans->subcategoria,
                        'observaciones' => $trans->observaciones,
                        'estado' => $trans->estado,
                        'monto_excedido' => floatval($trans->monto_excedido ?? 0),
                        'monto_excedido_formateado' => number_format($trans->monto_excedido ?? 0, 2, '.', ','),
                        'punto_de_partida' => $trans->punto_de_partida,
                        'destino' => $trans->destino,
                        'millas' => floatval($trans->millas ?? 0),
                        'millas_formateadas' => number_format($trans->millas ?? 0, 2),

                        'negocio' => $trans->negocio ? [
                            'id' => $trans->negocio->id,
                            'nombre' => $trans->negocio->nombre,
                            'descripcion' => $trans->negocio->descripcion ?? '',
                        ] : null,

                        'metodo_pago' => $trans->metodo ? [
                            'id' => $trans->metodo->id,
                            'nombre' => $trans->metodo->nombre,
                            'descripcion' => null,
                        ] : null,

                        'categoria' => $trans->categoria ? [
                            'id' => $trans->categoria->id,
                            'nombre' => $trans->categoria->nombre,
                            'descripcion' => $trans->categoria->descripcion ?? '',
                            'tipo' => $trans->categoria->tipo ?? null,
                        ] : null,

                        'usuario' => $trans->user && $trans->user->generalData ? [
                            'id' => $trans->user->id,
                            'nombre_completo' => trim($trans->user->generalData->nombre . ' ' . $trans->user->generalData->apellido),
                            'nombre' => $trans->user->generalData->nombre,
                            'apellido' => $trans->user->generalData->apellido,
                            'documento_identidad' => $trans->user->generalData->documento_identidad,
                            'celular' => $trans->user->generalData->celular,
                        ] : null,

                        'vehiculo' => $trans->vehicle ? [
                            'id' => $trans->vehicle->id,
                            'codigo_unico' => $trans->vehicle->codigo_unico,
                            'numero_placa' => $trans->vehicle->numero_placa,
                            'marca' => $trans->vehicle->marca,
                            'modelo' => $trans->vehicle->modelo,
                            'año' => $trans->vehicle->año,
                            'tipo_vehiculo' => $trans->vehicle->tipo_vehiculo,
                            'tipo_propiedad' => $trans->vehicle->tipo_propiedad,
                            'nombre_completo' => trim("{$trans->vehicle->codigo_unico} - {$trans->vehicle->numero_placa} ({$trans->vehicle->marca} {$trans->vehicle->modelo})"),
                        ] : null,

                        'estado_transaccion' => $trans->estadoDeTransaccion ? [
                            'id' => $trans->estadoDeTransaccion->id,
                            'nombre' => $trans->estadoDeTransaccion->nombre,
                            'descripcion' => $trans->estadoDeTransaccion->descripcion ?? '',
                            'color' => $trans->estadoDeTransaccion->color ?? '#6B7280',
                        ] : null,

                        'caja_operativa' => $trans->cajaOperativa ? [
                            'id' => $trans->cajaOperativa->id,
                            'nombre' => $trans->cajaOperativa->nombre,
                            'descripcion' => $trans->cajaOperativa->descripcion ?? '',
                            'saldo' => floatval($trans->cajaOperativa->saldo),
                            'saldo_formateado' => number_format($trans->cajaOperativa->saldo, 2, '.', ','),
                        ] : null,

                        'archivos' => $trans->archivos->map(function ($archivo) {
                            return [
                                'id' => $archivo->id,
                                'nombre_archivo' => $archivo->nombre_archivo,
                                'ruta_archivo' => $archivo->ruta_archivo,
                                'tipo_archivo' => $archivo->tipo_archivo,
                                'tamano_archivo' => $archivo->tamano_archivo,
                                'tamano_formateado' => $this->formatearTamanoArchivo($archivo->tamano_archivo),
                                'url_completa' => $this->getUrlCompleta($archivo->ruta_archivo),
                                'es_imagen' => $this->esImagen($archivo->tipo_archivo),
                                'fecha_subida' => $archivo->created_at ? $archivo->created_at->format('Y-m-d H:i:s') : null,
                                'fecha_subida_formateada' => $archivo->created_at ? $archivo->created_at->format('d/m/Y H:i') : null,
                            ];
                        })->toArray(),

                        'total_archivos' => $trans->archivos->count(),
                        'total_imagenes' => $trans->archivos->filter(function ($a) {
                            return $this->esImagen($a->tipo_archivo);
                        })->count(),
                        'tiene_imagenes' => $trans->archivos->filter(function ($a) {
                            return $this->esImagen($a->tipo_archivo);
                        })->count() > 0,

                        'pago_pendiente' => $trans->pendingPayment ? [
                            'id' => $trans->pendingPayment->id,
                            'monto_pendiente' => floatval($trans->pendingPayment->monto_pendiente),
                            'monto_pendiente_formateado' => number_format($trans->pendingPayment->monto_pendiente, 2, '.', ','),
                            'fecha_vencimiento' => $trans->pendingPayment->fecha_vencimiento,
                            'estado' => $trans->pendingPayment->estado,
                        ] : null,

                        'created_at' => $trans->created_at ? $trans->created_at->format('Y-m-d H:i:s') : null,
                        'updated_at' => $trans->updated_at ? $trans->updated_at->format('Y-m-d H:i:s') : null,
                        'created_at_formateado' => $trans->created_at ? $trans->created_at->format('d/m/Y H:i') : null,
                        'updated_at_formateado' => $trans->updated_at ? $trans->updated_at->format('d/m/Y H:i') : null,
                    ];
                })->toArray();
            }

            $queryDistribucion = FinancialTransactions::where('negocio_id', $negocioId)
                ->where(function ($query) {
                    $query->where('tipo_de_transaccion', 'Egreso')
                        ->orWhere(function ($query) {
                            $query->where('tipo_de_transaccion', 'Ingreso')
                                ->whereNull('caja_operativa_id');
                        });
                })
                ->whereBetween('fecha', [$fechaInicial, $fechaFinal]);

            if ($esFiltradoPorVehiculo) {
                $queryDistribucion->where('vehicle_id', $vehicleId);
            }

            $distribucionEstadosPorCantidad = $queryDistribucion
                ->join('transaction_states', 'financial_transactions.estado_de_transaccion_id', '=', 'transaction_states.id')
                ->select(
                    'transaction_states.id as estado_id',
                    'transaction_states.nombre as estado_nombre',
                    DB::raw('COUNT(*) as cantidad'),
                    DB::raw('SUM(importe_total) as total_importe')
                )
                ->groupBy('transaction_states.id', 'transaction_states.nombre')
                ->get();

            $totalTransacciones = $distribucionEstadosPorCantidad->sum('cantidad');
            $totalImporte = $distribucionEstadosPorCantidad->sum('total_importe');

            $distribucionEstadosPorCantidad = $distribucionEstadosPorCantidad->map(function ($item) use ($totalTransacciones, $totalImporte) {
                return [
                    'estado_id' => $item->estado_id,
                    'estado_nombre' => strtoupper($item->estado_nombre),
                    'cantidad' => $item->cantidad,
                    'total_importe' => floatval($item->total_importe),
                    'porcentaje_cantidad' => $totalTransacciones > 0
                        ? round(($item->cantidad / $totalTransacciones) * 100, 2)
                        : 0,
                    'porcentaje_importe' => $totalImporte > 0
                        ? round(($item->total_importe / $totalImporte) * 100, 2)
                        : 0
                ];
            });

            $queryCategoria = FinancialTransactions::where('negocio_id', $negocioId)
                ->where(function ($query) {
                    $query->where('tipo_de_transaccion', 'Egreso')
                        ->orWhere(function ($query) {
                            $query->where('tipo_de_transaccion', 'Ingreso')
                                ->whereNull('caja_operativa_id');
                        });
                })
                ->whereBetween('fecha', [$fechaInicial, $fechaFinal]);

            if ($esFiltradoPorVehiculo) {
                $queryCategoria->where('vehicle_id', $vehicleId);
            }

            $resumenPorCategoria = $queryCategoria
                ->leftJoin('categories', 'financial_transactions.categoria_id', '=', 'categories.id')
                ->select(
                    DB::raw('COALESCE(categories.nombre, "Sin Categoría") as categoria'),
                    'financial_transactions.tipo_de_transaccion',
                    DB::raw('COUNT(*) as cantidad'),
                    DB::raw('SUM(importe_total) as total')
                )
                ->groupBy('categories.nombre', 'financial_transactions.tipo_de_transaccion')
                ->get()
                ->groupBy('categoria')
                ->map(function ($items, $categoria) {
                    $categoriaData = [
                        'categoria' => $categoria,
                        'total_ingresos' => 0,
                        'total_egresos' => 0,
                        'cantidad_ingresos' => 0,
                        'cantidad_egresos' => 0
                    ];

                    foreach ($items as $item) {
                        if ($item->tipo_de_transaccion === 'Ingreso') {
                            $categoriaData['total_ingresos'] += $item->total;
                            $categoriaData['cantidad_ingresos'] += $item->cantidad;
                        } else {
                            $categoriaData['total_egresos'] += $item->total;
                            $categoriaData['cantidad_egresos'] += $item->cantidad;
                        }
                    }

                    $categoriaData['balance_categoria'] = $categoriaData['total_ingresos'] - $categoriaData['total_egresos'];
                    return $categoriaData;
                })
                ->filter(function ($categoria) {
                    $totalIngresos = floatval($categoria['total_ingresos']);
                    $totalEgresos = floatval($categoria['total_egresos']);
                    $cantidadTotal = intval($categoria['cantidad_ingresos']) + intval($categoria['cantidad_egresos']);
                    return $cantidadTotal > 0 && ($totalIngresos > 0 || $totalEgresos > 0);
                });

            $categoriaMayorEgreso = null;
            $categoriaMenorEgreso = null;

            if ($resumenPorCategoria->isNotEmpty()) {
                $categoriasConEgresos = $resumenPorCategoria->filter(function ($cat) {
                    return floatval($cat['total_egresos']) > 0;
                });

                if ($categoriasConEgresos->isNotEmpty()) {
                    $categoriaMayorEgreso = $categoriasConEgresos->sortByDesc('total_egresos')->first();
                    $categoriaMenorEgreso = $categoriasConEgresos->sortBy('total_egresos')->first();
                }
            }

            $formatoMoneda = function ($valor) {
                return number_format($valor, 2, '.', ',');
            };

            $responseData = [
                'negocio' => [
                    'id' => $negocioId,
                    'nombre' => strtoupper($negocio->nombre)
                ],
                'periodo' => [
                    'fecha_inicial' => $fechaInicial,
                    'fecha_final' => $fechaFinal,
                    'dias_periodo' => Carbon::parse($fechaInicial)->diffInDays(Carbon::parse($fechaFinal)) + 1
                ],
                'filtro' => [
                    'por_vehiculo' => $esFiltradoPorVehiculo,
                    'vehicle_id' => $vehicleId,
                ],
                'resumen_financiero' => [
                    'total_ingresos_brutos' => $formatoMoneda($totalIngresosBrutos),
                    'total_ingresos_brutos_raw' => floatval($totalIngresosBrutos),
                    'total_egresos_brutos' => $formatoMoneda($totalEgresosBrutos),
                    'total_egresos_brutos_raw' => floatval($totalEgresosBrutos),
                    'margen_bruto' => $formatoMoneda($margenBruto),
                    'margen_bruto_raw' => floatval($margenBruto),
                    'margen_util_antes_impuestos' => $formatoMoneda($margenUtilAntesImpuestos),
                    'margen_util_antes_impuestos_raw' => floatval($margenUtilAntesImpuestos),
                    'impuestos_estimados' => $formatoMoneda($impuestosEstimados),
                    'costos_fijos_adicionales' => $formatoMoneda($costosFijosAdicionales),
                    'rentabilidad_porcentaje' => number_format($rentabilidadPorcentaje, 2, '.', ''),
                    'rentabilidad_porcentaje_raw' => floatval($rentabilidadPorcentaje),
                ],
                'detalle_por_estado' => array_values($estadosFinancieros),
                'distribucion_estados' => [
                    'por_cantidad' => $distribucionEstadosPorCantidad->toArray(),
                    'por_importe' => $distribucionEstadosPorCantidad->sortByDesc('total_importe')->values()->toArray()
                ],
                'resumen_por_categoria' => $resumenPorCategoria->values()->all(),
                'estadisticas_adicionales' => [
                    'total_transacciones' => $transaccionesPorEstado->sum('total_transacciones'),
                    'total_transacciones_ingresos' => $transaccionesPorEstado->filter(fn($t) => $t->tipo_de_transaccion === 'Ingreso')->sum('total_transacciones'),
                    'total_transacciones_egresos' => $transaccionesPorEstado->filter(fn($t) => $t->tipo_de_transaccion === 'Egreso')->sum('total_transacciones'),
                    'promedio_ingreso_transaccion' => $totalIngresosBrutos > 0 &&
                        $transaccionesPorEstado->filter(fn($t) => $t->tipo_de_transaccion === 'Ingreso')->sum('total_transacciones') > 0
                        ? $formatoMoneda($totalIngresosBrutos / $transaccionesPorEstado->filter(fn($t) => $t->tipo_de_transaccion === 'Ingreso')->sum('total_transacciones'))
                        : '0.00',
                    'promedio_egreso_transaccion' => $totalEgresosBrutos > 0 &&
                        $transaccionesPorEstado->filter(fn($t) => $t->tipo_de_transaccion === 'Egreso')->sum('total_transacciones') > 0
                        ? $formatoMoneda($totalEgresosBrutos / $transaccionesPorEstado->filter(fn($t) => $t->tipo_de_transaccion === 'Egreso')->sum('total_transacciones'))
                        : '0.00',
                    'categoria_mayor_egreso' => $categoriaMayorEgreso ? [
                        'categoria' => $categoriaMayorEgreso['categoria'],
                        'total_egresos' => floatval($categoriaMayorEgreso['total_egresos']),
                        'cantidad_transacciones' => intval($categoriaMayorEgreso['cantidad_egresos'])
                    ] : null,
                    'categoria_menor_egreso' => $categoriaMenorEgreso ? [
                        'categoria' => $categoriaMenorEgreso['categoria'],
                        'total_egresos' => floatval($categoriaMenorEgreso['total_egresos']),
                        'cantidad_transacciones' => intval($categoriaMenorEgreso['cantidad_egresos'])
                    ] : null,
                ]
            ];

            if ($esFiltradoPorVehiculo && $vehicle) {
                $responseData['vehiculo'] = [
                    'id' => $vehicle->id,
                    'codigo_unico' => $vehicle->codigo_unico,
                    'numero_placa' => $vehicle->numero_placa,
                    'marca' => $vehicle->marca,
                    'modelo' => $vehicle->modelo,
                    'año' => $vehicle->año,
                    'tipo_vehiculo' => $vehicle->tipo_vehiculo,
                    'tipo_propiedad' => strtoupper($vehicle->tipo_propiedad),
                    'valor_actual' => floatval($vehicle->valor_actual ?? 0),
                    'precio_compra' => floatval($vehicle->precio_compra ?? 0),
                ];
            }

            if (!$esFiltradoPorVehiculo) {
                $responseData['resumen_global_cajas'] = [
                    'total_ingresos_cajas' => $formatoMoneda($totalesGlobalesCajas['total_ingresos_cajas']),
                    'total_ingresos_cajas_raw' => $totalesGlobalesCajas['total_ingresos_cajas'],
                    'total_egresos_cajas' => $formatoMoneda($totalesGlobalesCajas['total_egresos_cajas']),
                    'total_egresos_cajas_raw' => $totalesGlobalesCajas['total_egresos_cajas'],
                    'balance_global_cajas' => $formatoMoneda($totalesGlobalesCajas['balance_global_cajas']),
                    'balance_global_cajas_raw' => $totalesGlobalesCajas['balance_global_cajas'],
                    'total_cajas_activas' => count($estadoPorCaja),
                ];
                $responseData['detalle_por_caja'] = array_values($estadoPorCaja);
                $responseData['distribucion_cajas'] = [
                    'por_balance' => $distribucionCajasPorBalance->values()->toArray(),
                    'por_ingresos' => $distribucionCajasPorBalance->sortByDesc('balance_periodo')->values()->toArray(),
                ];
                $responseData['estadisticas_adicionales']['total_transacciones_cajas'] = collect($estadoPorCaja)->sum('transacciones_totales.total_transacciones_caja');
                $responseData['estadisticas_adicionales']['promedio_balance_por_caja'] = count($estadoPorCaja) > 0
                    ? round($totalesGlobalesCajas['balance_global_cajas'] / count($estadoPorCaja), 2)
                    : 0;
            }

            return response()->json([
                'status' => 'success',
                'message' => $esFiltradoPorVehiculo
                    ? 'Estado financiero del vehículo con detalles completos generado exitosamente'
                    : 'Estado financiero global con detalles completos generado exitosamente',
                'datos' => $responseData,
                'timestamp' => now()->toDateTimeString()
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Error al generar el estado financiero',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function getVehiclesByBusiness(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'negocio_id' => 'required|exists:businesses,id',
            ], [
                'negocio_id.required' => 'El ID del negocio es obligatorio',
                'negocio_id.exists' => 'El negocio seleccionado no existe',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Parámetros inválidos',
                    'errors' => $validator->errors()
                ], 422);
            }

            $negocioId = $request->input('negocio_id');
            $totalVehicles = Vehicle::where('negocio_id', $negocioId)->count();
            $activeVehicles = Vehicle::where('negocio_id', $negocioId)
                ->where('is_active', true)
                ->count();

            $vehicles = Vehicle::where('negocio_id', $negocioId)
                ->where('is_active', true)
                ->with(['user.generalData'])
                ->orderBy('tipo_propiedad')
                ->orderBy('codigo_unico')
                ->get();

            if ($vehicles->isEmpty()) {
                $allVehicles = Vehicle::where('negocio_id', $negocioId)->get();

                return response()->json([
                    'status' => 'success',
                    'message' => 'No hay vehículos activos para este negocio',
                    'datos' => [],
                    'total' => 0,
                    'debug' => [
                        'total_vehiculos_db' => $totalVehicles,
                        'vehiculos_activos' => $activeVehicles,
                        'vehiculos_inactivos' => $allVehicles->pluck('codigo_unico')
                    ]
                ], 200);
            }

            $vehiculosData = $vehicles->map(function ($vehicle) {
                $assignedUserName = 'Sin asignar';
                if ($vehicle->user && $vehicle->user->generalData) {
                    $assignedUserName = $vehicle->user->generalData->nombre . ' ' .
                        $vehicle->user->generalData->apellido;
                }

                return [
                    'id' => $vehicle->id,
                    'codigo_unico' => $vehicle->codigo_unico,
                    'numero_placa' => $vehicle->numero_placa,
                    'numero_vin' => $vehicle->numero_vin,
                    'marca' => $vehicle->marca,
                    'modelo' => $vehicle->modelo,
                    'año' => $vehicle->año,
                    'color' => $vehicle->color,
                    'tipo_vehiculo' => $vehicle->tipo_vehiculo,
                    'tipo_propiedad' => strtoupper($vehicle->tipo_propiedad),
                    'usuario_asignado' => $assignedUserName,
                    'usuario_asignado_id' => $vehicle->user_id,
                    'valor_actual' => floatval($vehicle->valor_actual ?? 0),
                    'precio_compra' => floatval($vehicle->precio_compra ?? 0),
                    'millaje' => intval($vehicle->millaje ?? 0),
                    'is_active' => $vehicle->estado,
                    'nombre_display' => trim("{$vehicle->codigo_unico} - {$vehicle->numero_placa} ({$vehicle->marca} {$vehicle->modelo})")
                ];
            });

            return response()->json([
                'status' => 'success',
                'message' => 'Vehículos obtenidos correctamente',
                'datos' => $vehiculosData->toArray(),
                'total' => $vehiculosData->count(),
                'timestamp' => now()->toDateTimeString()
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Error al obtener los vehículos',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function exportToExcel(Request $request)
    {
        if (!Auth::check()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Usuario no autenticado',
            ], 401);
        }

        $validator = Validator::make($request->all(), [
            'negocio_id' => 'required|exists:businesses,id',
            'fecha_inicial' => 'required|date',
            'fecha_final' => 'required|date|after_or_equal:fecha_inicial',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Error de validación',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $response = $this->getFinancialStatementByDateRange($request);
            $responseData = $response->getData(true);

            if ($responseData['status'] !== 'success') {
                throw new \Exception($responseData['message'] ?? 'Error al obtener datos');
            }

            $export = new FinancialStatementExport($responseData['datos'], $request->all());

            $negocio = Business::findOrFail($request->negocio_id);
            $nombreArchivo = 'Estado_Financiero_' .
                str_replace(' ', '_', strtoupper($negocio->nombre)) . '_' .
                $request->fecha_inicial . '_a_' .
                $request->fecha_final . '_' .
                date('Y-m-d_H-i-s') . '.xlsx';

            return Excel::download($export, $nombreArchivo);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Error al exportar a Excel',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function exportToPDF(Request $request)
    {
        if (!Auth::check()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Usuario no autenticado'
            ], 401);
        }

        $validator = Validator::make($request->all(), [
            'negocio_id' => 'required|exists:businesses,id',
            'fecha_inicial' => 'required|date',
            'fecha_final' => 'required|date|after_or_equal:fecha_inicial',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Error de validación',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $response = $this->getFinancialStatementByDateRange($request);
            $responseData = $response->getData(true);

            if ($responseData['status'] !== 'success') {
                throw new \Exception('Error al obtener datos financieros');
            }

            $data = $responseData['datos'];

            $pdf = Pdf::loadView('exports.financial_statement_pdf', ['data' => $data]);
            $pdf->setPaper('A4', 'portrait');

            $negocio = Business::findOrFail($request->negocio_id);
            $nombreArchivo = 'Estado_Financiero_' .
                str_replace(' ', '_', strtoupper($negocio->nombre)) . '_' .
                $request->fecha_inicial . '_a_' .
                $request->fecha_final . '_' .
                date('Y-m-d_H-i-s') . '.pdf';

            return $pdf->download($nombreArchivo);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Error al exportar a PDF',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
