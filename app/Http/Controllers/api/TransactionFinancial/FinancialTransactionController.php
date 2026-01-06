<?php

namespace App\Http\Controllers\api\TransactionFinancial;

use App\Exports\FinancialTransactionsExport;
use App\Http\Controllers\Controller;
use App\Models\FinancialTransactions;
use App\Models\MovementsBox;
use App\Models\OperatingBox;
use App\Models\TransactionStates;
use App\Services\OperatingBoxHistoryService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Maatwebsite\Excel\Facades\Excel;

class FinancialTransactionController extends Controller
{
    protected $operatingBoxHistoryService;

    public function __construct(OperatingBoxHistoryService $operatingBoxHistoryService)
    {
        $this->operatingBoxHistoryService = $operatingBoxHistoryService;
    }

    /**
     * Obtener lista de transacciones financieras
     */
    public function index(Request $request)
    {
        $query = FinancialTransactions::with('user.generalData', 'user.driver', 'negocio', 'metodo', 'categoria', 'vehicle', 'estadoDeTransaccion', 'cajaOperativa', 'archivos');

        // Validar fechas antes de aplicar filtros
        if ($request->has('fecha_desde') && $request->fecha_desde) {
            try {
                Carbon::parse($request->fecha_desde);
            } catch (\Exception $e) {
                return response()->json(['status' => 'error', 'message' => 'Fecha desde inválida'], 422);
            }
            $query->whereDate('fecha', '>=', $request->fecha_desde);
        }

        if ($request->has('fecha_hasta') && $request->fecha_hasta) {
            try {
                Carbon::parse($request->fecha_hasta);
            } catch (\Exception $e) {
                return response()->json(['status' => 'error', 'message' => 'Fecha hasta inválida'], 422);
            }
            $query->whereDate('fecha', '<=', $request->fecha_hasta);
        }

        // Filtro por búsqueda
        if ($request->has('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('item', 'like', '%' . $search . '%')
                    ->orWhere('cliente_proveedor', 'like', '%' . $search . '%')
                    ->orWhere('observaciones', 'like', '%' . $search . '%')
                    ->orWhere('numero_transaccion', 'like', '%' . $search . '%');
            });
        }

        // Filtro por tipo de transacción
        if ($request->has('tipo')) {
            $query->where('tipo_de_transaccion', $request->tipo);
        }

        // Filtro por estado
        if ($request->has('estado')) {
            $query->where('estado_de_transaccion_id', $request->estado);
        }

        // Filtro por categoría (por ID)
        if ($request->has('categoria')) {
            $query->where('categoria_id', $request->categoria);
        }

        // Filtro por subcategoría
        if ($request->has('subcategoria')) {
            $query->where('subcategoria', $request->subcategoria);
        }

        // Filtros por caja operativa
        if ($request->has('caja_operativa_id')) {
            $cajaOperativaId = $request->caja_operativa_id;
            $query->where('caja_operativa_id', $cajaOperativaId);

            // Si se especifica un tipo de movimiento en caja (ingreso, egreso o ambos)
            if ($request->has('tipo_movimiento_caja')) {
                $tipoMovimientoCaja = $request->tipo_movimiento_caja;
                if ($tipoMovimientoCaja === 'ingreso') {
                    $query->where('tipo_de_transaccion', 'Ingreso');
                } elseif ($tipoMovimientoCaja === 'egreso') {
                    $query->where('tipo_de_transaccion', 'Egreso');
                }
            }
        }

        // Clonar la consulta para cálculos globales
        $globalQuery = clone $query;

        // Calcular ingresos brutos (excluyendo transferencias)
        $ingresosGlobales = (clone $globalQuery)
            ->where('tipo_de_transaccion', 'Ingreso')
            ->where(function ($q) {
                $q->where('subcategoria', '!=', 'Transferencia')
                    ->orWhereNull('subcategoria'); // Incluir NULL como ingresos reales
            })
            ->sum('importe_total');

        $egresosGlobales = (clone $globalQuery)
            ->where('tipo_de_transaccion', 'Egreso')
            ->sum('importe_total');

        $balanceGlobal = $ingresosGlobales - $egresosGlobales;

        // Calcular totales por caja operativa si se solicita
        $totalesPorCaja = null;
        if ($request->has('calcular_totales_caja') && $request->calcular_totales_caja) {
            $totalesPorCaja = [];

            $cajasConTransacciones = (clone $globalQuery)
                ->select('caja_operativa_id')
                ->distinct()
                ->whereNotNull('caja_operativa_id')
                ->pluck('caja_operativa_id');

            foreach ($cajasConTransacciones as $cajaId) {
                $cajaQuery = (clone $globalQuery)->where('caja_operativa_id', $cajaId);

                // Excluir transferencias en el cálculo por caja
                $ingresosCaja = (clone $cajaQuery)
                    ->where('tipo_de_transaccion', 'Ingreso')
                    ->where(function ($q) {
                        $q->where('subcategoria', '!=', 'Transferencia')
                            ->orWhereNull('subcategoria');
                    })
                    ->sum('importe_total');

                $egresosCaja = (clone $cajaQuery)
                    ->where('tipo_de_transaccion', 'Egreso')
                    ->sum('importe_total');

                $balanceCaja = $ingresosCaja - $egresosCaja;

                $caja = OperatingBox::find($cajaId);

                if ($caja) {
                    $totalesPorCaja[] = [
                        'caja_operativa_id' => $cajaId,
                        'nombre_caja' => $caja->nombre,
                        'saldo_actual' => $caja->saldo,
                        'ingresos' => $ingresosCaja,
                        'egresos' => $egresosCaja,
                        'balance' => $balanceCaja
                    ];
                }
            }
        }

        // Ordenamiento y paginación
        $query->orderBy('created_at', 'desc');
        $perPage = $request->get('per_page', 15);
        $items = $query->paginate($perPage);

        // Formatear fechas para mostrar
        $transactions = $items->items();
        foreach ($transactions as $transaction) {
            if ($transaction->fecha) {
                $transaction->fecha_formateada = Carbon::parse($transaction->fecha)->format('d/m/Y');
            } else {
                $transaction->fecha_formateada = null;
            }
        }

        // Preparar respuesta
        $response = [
            "mensaje" => "Datos cargados exitosamente",
            "datos" => $transactions,
            "meta" => [
                "total" => $items->total(),
                "current_page" => $items->currentPage(),
                "per_page" => $items->perPage(),
                "last_page" => $items->lastPage(),
            ],
            "totales" => [
                "ingresos" => $ingresosGlobales,
                "egresos" => $egresosGlobales,
                "balance" => $balanceGlobal
            ]
        ];

        if ($totalesPorCaja !== null) {
            $response["totales_por_caja"] = $totalesPorCaja;
        }

        return response()->json($response);
    }


    public function store(Request $request)
    {
        try {
            // Verificar autenticación y rol de administrador
            if (!Auth::check()) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Acceso no autorizado',
                    'details' => 'Debe iniciar sesión para realizar esta operación'
                ], 401);
            }
            $user = Auth::user();
            if (!$user->hasRole('admin')) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Permisos insuficientes',
                    'details' => 'Esta operación solo puede ser realizada por administradores'
                ], 403);
            }

            // Validación de datos
            $validator = Validator::make($request->all(), [
                'negocio_id' => 'required|exists:businesses,id',
                'metodo_id' => 'required|exists:payment_methods,id',
                'categoria_id' => 'required|exists:categories,id',
                'vehicle_id' => 'nullable|exists:vehicles,id',
                'estado_de_transaccion_id' => 'required|exists:transaction_states,id',
                'caja_operativa_id' => 'nullable|exists:operating_boxes,id',
                'fecha' => 'required|date',
                'punto_de_partida' => 'nullable|string',
                'destino' => 'nullable|string',
                'millas' => 'nullable|integer',
                'tipo_de_transaccion' => 'required|in:Ingreso,Egreso',
                'item' => 'required|string',
                'cantidad' => 'required|numeric',
                'importe_total' => 'required|numeric|min:0.01',
                'cliente_proveedor' => 'nullable|string',
                'observaciones' => 'nullable|string',
                'archivo' => 'nullable|array',
                'archivo.*' => 'file|mimes:pdf,jpg,jpeg,png,doc,docx|max:10240',
                'numero_transaccion' => 'required|string|max:255',
            ], [
                'required' => 'El campo :attribute es obligatorio',
                'exists' => 'El valor seleccionado para :attribute no es válido',
                'date' => 'El campo :attribute debe ser una fecha válida',
                'numeric' => 'El campo :attribute debe ser un número',
                'min' => 'El campo :attribute debe ser mayor que cero',
                'mimes' => 'Los archivos deben ser de tipo: pdf, jpg, png, doc, docx',
                'max' => 'Los archivos no deben pesar más de 10MB',
                'unique' => 'El número de transacción ya está en uso',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Datos de entrada inválidos',
                    'details' => 'Por favor, verifique los campos del formulario',
                    'errors' => $validator->errors()
                ], 422);
            }

            DB::beginTransaction();

            // Convertir campos de texto a mayúsculas
            $puntoPartida = $request->filled('punto_de_partida') ? strtoupper($request->punto_de_partida) : null;
            $destino = $request->filled('destino') ? strtoupper($request->destino) : null;
            $item = strtoupper($request->item);
            $clienteProveedor = $request->filled('cliente_proveedor') ? strtoupper($request->cliente_proveedor) : null;
            $observaciones = $request->filled('observaciones') ? strtoupper($request->observaciones) : null;
            $numeroTransaccion = strtoupper($request->numero_transaccion);

            // Obtener la subcategoría de la categoría seleccionada
            $categoria = \App\Models\Category::find($request->categoria_id);
            $subcategoria = $categoria ? $categoria->subcategoria : null;

            // Crear transacción financiera con el usuario autenticado
            $transaction = FinancialTransactions::create([
                'negocio_id' => $request->negocio_id,
                'metodo_id' => $request->metodo_id,
                'categoria_id' => $request->categoria_id,
                'user_id' => $user->id, // Usar el ID del usuario autenticado
                'vehicle_id' => $request->vehicle_id,
                'estado_de_transaccion_id' => $request->estado_de_transaccion_id,
                'caja_operativa_id' => $request->caja_operativa_id, // Agregar caja operativa
                'fecha' => $request->fecha,
                'punto_de_partida' => $puntoPartida,
                'destino' => $destino,
                'millas' => $request->millas,
                'tipo_de_transaccion' => $request->tipo_de_transaccion,
                'item' => $item,
                'cantidad' => $request->cantidad,
                'importe_total' => $request->importe_total,
                'cliente_proveedor' => $clienteProveedor,
                'subcategoria' => $subcategoria, // Asignar la subcategoría obtenida
                'observaciones' => $observaciones,
                'numero_transaccion' => $numeroTransaccion,
                'monto_excedido' => 0, // Inicializar monto excedido en 0
            ]);

            // Cargar relaciones necesarias
            $transaction->load([
                'categoria',
                'estadoDeTransaccion',
                'cajaOperativa' // Cargar relación con caja operativa
            ]);

            // Procesamiento de archivos múltiples
            $archivosGuardados = [];
            if ($request->hasFile('archivo')) {
                // Obtener información del usuario autenticado
                $generalData = $user->generalData;
                $nombre = $generalData->nombre ?? 'sin_nombre';
                $apellido = $generalData->apellido ?? 'sin_apellido';
                $nombreLimpio = $this->limpiarNombre($nombre . '_' . $apellido);
                $fechaTransaccion = Carbon::parse($request->fecha);
                $año = $fechaTransaccion->year;
                $mes = $fechaTransaccion->format('m_F');
                $rutaRelativa = "transacciones_financieras/{$año}/{$mes}/{$nombreLimpio}";
                $rutaCompleta = public_path($rutaRelativa);

                if (!file_exists($rutaCompleta)) {
                    mkdir($rutaCompleta, 0755, true);
                }

                foreach ($request->file('archivo') as $archivo) {
                    $mimeType = $archivo->getMimeType();
                    $allowedMimeTypes = [
                        'application/pdf',
                        'image/jpeg',
                        'image/png',
                        'application/msword',
                        'application/vnd.openxmlformats-officedocument.wordprocessingml.document'
                    ];
                    if (!in_array($mimeType, $allowedMimeTypes)) {
                        throw new \Exception('El formato de archivo no es válido. Solo se permiten PDF, JPG, PNG, DOC y DOCX');
                    }

                    $extension = $archivo->getClientOriginalExtension();
                    $timestamp = time() . '_' . uniqid();
                    $nombreArchivo = "transaccion_{$timestamp}.{$extension}";
                    $nombreOriginal = $archivo->getClientOriginalName();

                    $archivo->move($rutaCompleta, $nombreArchivo);
                    $rutaArchivoCompleta = "{$rutaRelativa}/{$nombreArchivo}";

                    // Guardar en la tabla relacionada
                    $transactionFile = \App\Models\TransactionFile::create([
                        'financial_transaction_id' => $transaction->id,
                        'ruta' => $rutaArchivoCompleta,
                        'nombre_original' => $nombreOriginal,
                        'mime_type' => $mimeType,
                        'estado' => true,
                    ]);

                    $archivosGuardados[] = [
                        'id' => $transactionFile->id,
                        'ruta' => asset($rutaArchivoCompleta),
                        'nombre_original' => $nombreOriginal,
                        'mime_type' => $mimeType,
                    ];
                }
            }

            // Inicializar variables
            $movementBox = null;
            $operatingBoxActualizada = null;
            $advertencia = null;
            $excedentePorPagar = 0;
            $pendingPayment = null;

            // Verificar si se proporcionó una caja operativa
            if ($request->caja_operativa_id) {
                // Crear registro en movements_boxes SOLO si hay caja operativa
                $movementBox = MovementsBox::create([
                    'monto' => $request->importe_total,
                    'tipo' => strtolower($request->tipo_de_transaccion), // 'ingreso' o 'egreso'
                    'descripcion' => $item,
                    'fecha_movimiento' => $request->fecha,
                    'transaccion_financiera_id' => $transaction->id,
                    'user_id' => $user->id, // Usar el ID del usuario autenticado
                    'numero_transaccion' => $numeroTransaccion, // Agregar número de transacción
                    'monto_excedido' => 0, // Inicializar monto excedido en 0
                ]);

                $cajaOperativa = $transaction->cajaOperativa; // Usar la relación cargada
                $importeTotal = (float) $request->importe_total;

                // Si es un egreso, descontar de la caja operativa
                if ($request->tipo_de_transaccion === 'Egreso') {
                    // Guardar el saldo anterior antes de modificarlo
                    $saldoAnterior = $cajaOperativa->saldo;

                    // Verificar si hay saldo suficiente
                    if ($cajaOperativa->saldo >= $importeTotal) {
                        // Hay saldo suficiente, proceder normalmente
                        $cajaOperativa->saldo -= $importeTotal;
                        $cajaOperativa->save();

                        // Descripción detallada para el historial
                        $descripcionHistorial = "EGRESO COMPLETO: {$item}. MONTO: {$importeTotal}";

                        // Registrar movimiento en el historial de caja operativa
                        $this->operatingBoxHistoryService->registrarMovimiento(
                            $cajaOperativa,
                            $importeTotal,
                            'egreso',
                            $descripcionHistorial,
                            $transaction,
                            $saldoAnterior, // Saldo antes del movimiento
                            $cajaOperativa->saldo // Saldo después del movimiento
                        );

                        $operatingBoxActualizada = [
                            'id' => $cajaOperativa->id,
                            'nombre' => strtoupper($cajaOperativa->nombre),
                            'saldo_anterior' => $saldoAnterior,
                            'saldo_actual' => $cajaOperativa->saldo,
                            'monto_descontado' => $importeTotal,
                            'descripcion_historial' => $descripcionHistorial
                        ];

                        // Verificar si después del descuento la caja quedó sin saldo
                        if ($cajaOperativa->saldo <= 0) {
                            $advertencia = "ADVERTENCIA: LA CAJA OPERATIVA '{$cajaOperativa->nombre}' SE QUEDÓ SIN SALDO. DEBE REPONERSE FONDOS.";
                        }
                    } else {
                        // No hay saldo suficiente, calcular monto excedido
                        $saldoDisponible = $cajaOperativa->saldo;
                        $excedentePorPagar = $importeTotal - $saldoDisponible;

                        // Actualizar el saldo de la caja operativa (a cero)
                        $cajaOperativa->saldo = 0;
                        $cajaOperativa->save();

                        // Actualizar el monto excedido en la transacción
                        $transaction->monto_excedido = $excedentePorPagar;
                        $transaction->save();

                        // Actualizar el registro en movements_boxes con el monto excedido
                        $movementBox->monto_excedido = $excedentePorPagar;
                        $movementBox->save();

                        // Descripción detallada para el historial
                        $descripcionHistorial = "EGRESO PARCIAL POR SALDO INSUFICIENTE: {$item}. " .
                            "MONTO TOTAL: {$importeTotal}. " .
                            "MONTO DESCONTADO: {$saldoDisponible}. " .
                            "MONTO EXCEDIDO: {$excedentePorPagar}";

                        // Registrar movimiento en el historial de caja operativa por el monto descontado
                        $this->operatingBoxHistoryService->registrarMovimiento(
                            $cajaOperativa,
                            $saldoDisponible, // Monto descontado real
                            'EGRESO_PARCIAL',
                            $descripcionHistorial,
                            $transaction,
                            $saldoAnterior,
                            0 // Saldo después del movimiento
                        );

                        // Crear registro en pending_payments si hay monto excedido
                        if ($excedentePorPagar > 0) {
                            try {
                                // Para administradores, usar solo user_id sin requerir driver_id
                                $pendingPayment = \App\Models\PendingPayment::create([
                                    'negocio_id' => $request->negocio_id,
                                    'driver_id' => null, // No se requiere driver_id para administradores
                                    'financial_transaction_id' => $transaction->id,
                                    'monto' => $excedentePorPagar,
                                    'descripcion' => "Excedente por pagar de la transacción: {$item}",
                                    'estado' => 'pendiente',
                                    'user_id' => $user->id, // Usar el ID del administrador
                                ]);
                            } catch (\Exception $e) {
                                // Si falla la creación del pending payment, registramos el error y continuamos
                                $pendingPayment = null;
                            }
                        }

                        // Establecer una advertencia
                        $advertencia = "ADVERTENCIA: SALDO INSUFICIENTE. SE DESCANTÓ {$saldoDisponible} DE LA CAJA OPERATIVA Y EL EXCEDENTE ({$excedentePorPagar}) QUEDA COMO POR PAGAR.";

                        // Actualizar la variable $operatingBoxActualizada
                        $operatingBoxActualizada = [
                            'id' => $cajaOperativa->id,
                            'nombre' => strtoupper($cajaOperativa->nombre),
                            'saldo_anterior' => $saldoAnterior,
                            'saldo_actual' => 0,
                            'monto_descontado' => $saldoDisponible,
                            'excedente_por_pagar' => $excedentePorPagar,
                            'descripcion_historial' => $descripcionHistorial
                        ];
                    }
                }
                // Si es un ingreso, agregar a la caja operativa
                elseif ($request->tipo_de_transaccion === 'Ingreso') {
                    // Guardar el saldo anterior antes de modificarlo
                    $saldoAnterior = $cajaOperativa->saldo;

                    // Actualizar saldo de la caja operativa
                    $cajaOperativa->saldo += $importeTotal;
                    $cajaOperativa->save();

                    // Descripción detallada para el historial
                    $descripcionHistorial = "INGRESO COMPLETO: {$item}. MONTO: {$importeTotal}";

                    // Registrar movimiento en el historial de caja operativa
                    $this->operatingBoxHistoryService->registrarMovimiento(
                        $cajaOperativa,
                        $importeTotal,
                        'ingreso',
                        $descripcionHistorial,
                        $transaction,
                        $saldoAnterior, // Saldo antes del movimiento
                        $cajaOperativa->saldo // Saldo después del movimiento
                    );

                    $operatingBoxActualizada = [
                        'id' => $cajaOperativa->id,
                        'nombre' => strtoupper($cajaOperativa->nombre),
                        'saldo_anterior' => $saldoAnterior,
                        'saldo_actual' => $cajaOperativa->saldo,
                        'monto_agregado' => $importeTotal,
                        'descripcion_historial' => $descripcionHistorial
                    ];
                }
            }

            DB::commit();

            // Preparar respuesta
            $response = [
                'status' => 'success',
                'message' => 'Operación completada exitosamente',
                'details' => 'La transacción ha sido registrada correctamente' .
                    ($request->caja_operativa_id ? ' y el movimiento de caja asociado' : ''),
                'data' => [
                    'transaction' => $transaction->load([
                        'user:id,email',
                        'negocio:id,nombre',
                        'categoria:id,nombre,subcategoria', // Incluir subcategoria en la respuesta
                        'estadoDeTransaccion:id,nombre',
                        'cajaOperativa:id,nombre,saldo'
                    ])
                ],
                'archivos_guardados' => !empty($archivosGuardados),
                'archivos' => $archivosGuardados
            ];

            // Agregar movement_box solo si existe
            if ($movementBox) {
                $response['data']['movement_box'] = $movementBox;
            }

            // Agregar operating_box solo si existe
            if ($operatingBoxActualizada) {
                $response['data']['operating_box'] = $operatingBoxActualizada;
            }

            // Agregar advertencia si existe
            if ($advertencia) {
                $response['advertencia'] = $advertencia;
            }

            // Agregar información de excedente por pagar si existe
            if ($excedentePorPagar > 0) {
                $response['excedente_por_pagar'] = $excedentePorPagar;
            }

            // Agregar información del pago pendiente si existe
            if ($pendingPayment) {
                $response['pending_payment'] = [
                    'id' => $pendingPayment->id,
                    'driver_id' => $pendingPayment->driver_id, // Incluido para mostrar que es nulo
                    'monto' => $pendingPayment->monto,
                    'descripcion' => $pendingPayment->descripcion,
                    'estado' => $pendingPayment->estado,
                    'created_at' => $pendingPayment->created_at
                ];
            }

            return response()->json($response, 201);
        } catch (\Exception $e) {
            DB::rollBack();

            // Eliminar archivos si se subieron y falló
            if (!empty($archivosGuardados)) {
                foreach ($archivosGuardados as $archivo) {
                    $rutaCompleta = public_path(str_replace(asset(''), '', $archivo['ruta']));
                    if (file_exists($rutaCompleta)) {
                        unlink($rutaCompleta);
                    }
                }
            }

            return response()->json([
                'status' => 'error',
                'message' => 'Error en el servidor',
                'details' => 'Ha ocurrido un error al procesar la solicitud. Por favor, inténtelo de nuevo más tarde.',
                'technical_error' => $e->getMessage()
            ], 500);
        }
    }

    public function show($id)
    {
        $transaction = FinancialTransactions::with([
            'user.generalData',
            'user.driver',
            'negocio',
            'metodo',
            'categoria',
            'vehicle',
            'estadoDeTransaccion',
            'cajaOperativa',
            'archivos' // Incluir la relación con los archivos
        ])->find($id);

        if (!$transaction) {
            return response()->json([
                'status' => 'error',
                'message' => 'Transaction not found'
            ], 404);
        }

        // Preparar la información de archivos
        $archivos = [];

        // Si hay archivos en la relación, procesarlos
        if ($transaction->archivos && $transaction->archivos->count() > 0) {
            foreach ($transaction->archivos as $archivo) {
                $archivos[] = [
                    'id' => $archivo->id,
                    'ruta' => asset($archivo->ruta),
                    'nombre_original' => $archivo->nombre_original,
                    'mime_type' => $archivo->mime_type,
                    'estado' => $archivo->estado,
                    'created_at' => $archivo->created_at,
                    'updated_at' => $archivo->updated_at,
                ];
            }
        }

        // Si hay un archivo en el campo archivo (para compatibilidad con versiones anteriores)
        if ($transaction->archivo) {
            $archivos[] = [
                'id' => null,
                'ruta' => asset($transaction->archivo),
                'nombre_original' => basename($transaction->archivo),
                'mime_type' => null,
                'estado' => true,
                'created_at' => $transaction->created_at,
                'updated_at' => $transaction->updated_at,
            ];
        }

        // Agregar los archivos procesados a la respuesta
        $transaction->archivos_procesados = $archivos;

        return response()->json([
            'status' => 'success',
            'message' => 'Transaction retrieved successfully',
            'data' => $transaction
        ], 200);
    }

    public function update(Request $request, $id)
    {
        try {
            // Verificar autenticación y rol de administrador
            if (!Auth::check()) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Acceso no autorizado',
                    'details' => 'Debe iniciar sesión para realizar esta operación'
                ], 401);
            }
            $user = Auth::user();
            if (!$user->hasRole('admin')) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Permisos insuficientes',
                    'details' => 'Esta operación solo puede ser realizada por administradores'
                ], 403);
            }

            // Obtener la transacción existente
            $transaction = FinancialTransactions::with('cajaOperativa', 'estadoDeTransaccion', 'archivos')->find($id);
            if (!$transaction) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Transacción no encontrada'
                ], 404);
            }

            // Validación de datos
            $validator = Validator::make($request->all(), [
                'negocio_id' => 'sometimes|required|exists:businesses,id',
                'metodo_id' => 'sometimes|required|exists:payment_methods,id',
                'categoria_id' => 'sometimes|required|exists:categories,id',
                'vehicle_id' => 'nullable|exists:vehicles,id',
                'estado_de_transaccion_id' => 'sometimes|required|exists:transaction_states,id',
                'caja_operativa_id' => 'nullable|exists:operating_boxes,id',
                'fecha' => 'sometimes|required|date',
                'punto_de_partida' => 'nullable|string',
                'destino' => 'nullable|string',
                'millas' => 'nullable|integer',
                'tipo_de_transaccion' => 'sometimes|required|in:Ingreso,Egreso',
                'item' => 'sometimes|required|string',
                'cantidad' => 'sometimes|required|numeric',
                'importe_total' => 'sometimes|required|numeric|min:0.01',
                'cliente_proveedor' => 'nullable|string',
                'observaciones' => 'nullable|string',
                'archivo' => 'nullable|array',
                'archivo.*' => 'file|mimes:pdf,jpg,jpeg,png,doc,docx|max:10240',
                'numero_transaccion' => 'nullable|string|max:255',
            ], [
                'required' => 'El campo :attribute es obligatorio',
                'exists' => 'El valor seleccionado para :attribute no es válido',
                'date' => 'El campo :attribute debe ser una fecha válida',
                'numeric' => 'El campo :attribute debe ser un número',
                'min' => 'El campo :attribute debe ser mayor que cero',
                'mimes' => 'Los archivos deben ser de tipo: pdf, jpg, png, doc, docx',
                'max' => 'Los archivos no deben pesar más de 10MB',
                'unique' => 'El número de transacción ya está en uso',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Datos de entrada inválidos',
                    'details' => 'Por favor, verifique los campos del formulario',
                    'errors' => $validator->errors()
                ], 422);
            }

            DB::beginTransaction();

            // Guardar valores originales para comparación
            $originalValues = [
                'caja_operativa_id' => $transaction->caja_operativa_id,
                'estado_de_transaccion_id' => $transaction->estado_de_transaccion_id,
                'tipo_de_transaccion' => $transaction->tipo_de_transaccion,
                'importe_total' => $transaction->importe_total,
                'categoria_id' => $transaction->categoria_id,
                'subcategoria' => $transaction->subcategoria,
            ];

            // Obtener la subcategoría si se cambia la categoría
            $subcategoria = $transaction->subcategoria; // Mantener el valor actual por defecto
            if ($request->has('categoria_id') && $request->categoria_id != $transaction->categoria_id) {
                $categoria = \App\Models\Category::find($request->categoria_id);
                $subcategoria = $categoria ? $categoria->subcategoria : null;
            }

            // Convertir campos de texto a mayúsculas si se proporcionan
            $puntoPartida = $request->filled('punto_de_partida') ? strtoupper($request->punto_de_partida) : $transaction->punto_de_partida;
            $destino = $request->filled('destino') ? strtoupper($request->destino) : $transaction->destino;
            $item = $request->filled('item') ? strtoupper($request->item) : $transaction->item;
            $clienteProveedor = $request->filled('cliente_proveedor') ? strtoupper($request->cliente_proveedor) : $transaction->cliente_proveedor;
            $observaciones = $request->filled('observaciones') ? strtoupper($request->observaciones) : $transaction->observaciones;
            $numeroTransaccion = $request->filled('numero_transaccion') ? strtoupper($request->numero_transaccion) : $transaction->numero_transaccion;

            // Procesamiento de archivos múltiples
            $archivosGuardados = [];
            $archivosEliminados = [];

            // Si se proporcionan nuevos archivos
            if ($request->hasFile('archivo')) {
                // Eliminar archivos anteriores si existen
                foreach ($transaction->archivos as $archivoExistente) {
                    $rutaCompleta = public_path($archivoExistente->ruta);
                    if (file_exists($rutaCompleta)) {
                        unlink($rutaCompleta);
                    }
                    $archivoExistente->delete();
                    $archivosEliminados[] = [
                        'id' => $archivoExistente->id,
                        'ruta' => $archivoExistente->ruta,
                        'nombre_original' => $archivoExistente->nombre_original
                    ];
                }

                // Obtener información del usuario autenticado
                $generalData = $user->generalData;
                $nombre = $generalData->nombre ?? 'sin_nombre';
                $apellido = $generalData->apellido ?? 'sin_apellido';
                $nombreLimpio = $this->limpiarNombre($nombre . '_' . $apellido);
                $fechaTransaccion = \Carbon\Carbon::parse($request->fecha ?? $transaction->fecha);
                $año = $fechaTransaccion->year;
                $mes = $fechaTransaccion->format('m_F');
                $rutaRelativa = "transacciones_financieras/{$año}/{$mes}/{$nombreLimpio}";
                $rutaCompleta = public_path($rutaRelativa);

                if (!file_exists($rutaCompleta)) {
                    mkdir($rutaCompleta, 0755, true);
                }

                foreach ($request->file('archivo') as $archivo) {
                    $mimeType = $archivo->getMimeType();
                    $allowedMimeTypes = [
                        'application/pdf',
                        'image/jpeg',
                        'image/png',
                        'application/msword',
                        'application/vnd.openxmlformats-officedocument.wordprocessingml.document'
                    ];
                    if (!in_array($mimeType, $allowedMimeTypes)) {
                        throw new \Exception('El formato de archivo no es válido. Solo se permiten PDF, JPG, PNG, DOC y DOCX');
                    }

                    $extension = $archivo->getClientOriginalExtension();
                    $timestamp = time() . '_' . uniqid();
                    $nombreArchivo = "transaccion_{$timestamp}.{$extension}";
                    $nombreOriginal = $archivo->getClientOriginalName();

                    $archivo->move($rutaCompleta, $nombreArchivo);
                    $rutaArchivoCompleta = "{$rutaRelativa}/{$nombreArchivo}";

                    // Guardar en la tabla relacionada
                    $transactionFile = \App\Models\TransactionFile::create([
                        'financial_transaction_id' => $transaction->id,
                        'ruta' => $rutaArchivoCompleta,
                        'nombre_original' => $nombreOriginal,
                        'mime_type' => $mimeType,
                        'estado' => true,
                    ]);

                    $archivosGuardados[] = [
                        'id' => $transactionFile->id,
                        'ruta' => asset($rutaArchivoCompleta),
                        'nombre_original' => $nombreOriginal,
                        'mime_type' => $mimeType,
                    ];
                }
            }

            // Actualizar la transacción (sin cambiar el user_id)
            $transaction->update([
                'negocio_id' => $request->negocio_id ?? $transaction->negocio_id,
                'metodo_id' => $request->metodo_id ?? $transaction->metodo_id,
                'categoria_id' => $request->categoria_id ?? $transaction->categoria_id,
                'vehicle_id' => $request->vehicle_id ?? $transaction->vehicle_id,
                'estado_de_transaccion_id' => $request->estado_de_transaccion_id ?? $transaction->estado_de_transaccion_id,
                'caja_operativa_id' => $request->caja_operativa_id ?? $transaction->caja_operativa_id,
                'fecha' => $request->fecha ?? $transaction->fecha,
                'punto_de_partida' => $puntoPartida,
                'destino' => $destino,
                'millas' => $request->millas ?? $transaction->millas,
                'tipo_de_transaccion' => $request->tipo_de_transaccion ?? $transaction->tipo_de_transaccion,
                'item' => $item,
                'cantidad' => $request->cantidad ?? $transaction->cantidad,
                'importe_total' => $request->importe_total ?? $transaction->importe_total,
                'cliente_proveedor' => $clienteProveedor,
                'subcategoria' => $subcategoria, // Actualizar la subcategoría si cambió la categoría
                'observaciones' => $observaciones,
                'numero_transaccion' => $numeroTransaccion,
            ]);

            // Recargar relaciones actualizadas
            $transaction->load([
                'cajaOperativa',
                'estadoDeTransaccion',
                'archivos'
            ]);

            // Actualizar registro en movements_boxes
            $movementBox = MovementsBox::where('transaccion_financiera_id', $transaction->id)->first();
            if ($movementBox) {
                $movementBox->update([
                    'monto' => $transaction->importe_total,
                    'tipo' => strtolower($transaction->tipo_de_transaccion),
                    'descripcion' => $transaction->item,
                    'fecha_movimiento' => $transaction->fecha,
                    'user_id' => $user->id, // Usar el ID del usuario autenticado
                    'numero_transaccion' => $transaction->numero_transaccion,
                ]);
            } else {
                $movementBox = MovementsBox::create([
                    'monto' => $transaction->importe_total,
                    'tipo' => strtolower($transaction->tipo_de_transaccion),
                    'descripcion' => $transaction->item,
                    'fecha_movimiento' => $transaction->fecha,
                    'transaccion_financiera_id' => $transaction->id,
                    'user_id' => $user->id, // Usar el ID del usuario autenticado
                    'numero_transaccion' => $transaction->numero_transaccion,
                ]);
            }

            // Lógica para manejar la caja operativa según el estado de la transacción
            $estadoTransaccion = $transaction->estadoDeTransaccion;
            $operatingBoxActualizada = null;
            $advertencia = null;
            $excedentePorPagar = 0;
            $pendingPayment = null;

            // Determinar si hay cambios que afecten la caja operativa
            $cambiosEnCaja =
                $originalValues['caja_operativa_id'] != $transaction->caja_operativa_id ||
                $originalValues['estado_de_transaccion_id'] != $transaction->estado_de_transaccion_id ||
                $originalValues['tipo_de_transaccion'] != $transaction->tipo_de_transaccion ||
                $originalValues['importe_total'] != $transaction->importe_total;

            // Si hay cambios, revertir el movimiento anterior si aplica
            if ($cambiosEnCaja && $originalValues['caja_operativa_id']) {
                $cajaOperativaOriginal = OperatingBox::find($originalValues['caja_operativa_id']);
                $estadoOriginal = TransactionStates::find($originalValues['estado_de_transaccion_id']);

                if ($cajaOperativaOriginal && $estadoOriginal && $estadoOriginal->nombre === 'Pagado') {
                    // Revertir el movimiento anterior
                    $montoRevertir = (float) $originalValues['importe_total'];
                    $tipoReversion = $originalValues['tipo_de_transaccion'] === 'Ingreso' ? 'egreso' : 'ingreso';

                    $saldoAnterior = $cajaOperativaOriginal->saldo;

                    // Actualizar saldo de la caja operativa
                    if ($originalValues['tipo_de_transaccion'] === 'Ingreso') {
                        // Si era un ingreso, revertir significa restar
                        $cajaOperativaOriginal->saldo -= $montoRevertir;
                    } else {
                        // Si era un egreso, revertir significa sumar
                        $cajaOperativaOriginal->saldo += $montoRevertir;
                    }
                    $cajaOperativaOriginal->save();

                    // Registrar movimiento de reversión en el historial de caja operativa
                    $this->operatingBoxHistoryService->registrarMovimiento(
                        $cajaOperativaOriginal,
                        $montoRevertir,
                        $tipoReversion,
                        'Reversión por actualización de transacción: ' . $transaction->item,
                        $transaction,
                        $saldoAnterior,
                        $cajaOperativaOriginal->saldo
                    );
                }
            }

            // Verificar si se proporcionó una caja operativa
            if ($transaction->caja_operativa_id) {
                $cajaOperativa = $transaction->cajaOperativa;
                $importeTotal = (float) $transaction->importe_total;

                // Si es un egreso y estado "Pagado", descontar de la caja operativa
                if ($transaction->tipo_de_transaccion === 'Egreso' && $estadoTransaccion->nombre === 'Pagado') {
                    // Guardar el saldo anterior antes de modificarlo
                    $saldoAnterior = $cajaOperativa->saldo;

                    // Verificar si hay saldo suficiente
                    if ($cajaOperativa->saldo >= $importeTotal) {
                        // Hay saldo suficiente, proceder normalmente
                        $cajaOperativa->saldo -= $importeTotal;
                        $cajaOperativa->save();

                        // Descripción detallada para el historial
                        $descripcionHistorial = "EGRESO COMPLETO: {$item}. MONTO: {$importeTotal}";

                        // Registrar movimiento en el historial de caja operativa
                        $this->operatingBoxHistoryService->registrarMovimiento(
                            $cajaOperativa,
                            $importeTotal,
                            'egreso',
                            $descripcionHistorial,
                            $transaction,
                            $saldoAnterior, // Saldo antes del movimiento
                            $cajaOperativa->saldo // Saldo después del movimiento
                        );

                        $operatingBoxActualizada = [
                            'id' => $cajaOperativa->id,
                            'nombre' => strtoupper($cajaOperativa->nombre),
                            'saldo_anterior' => $saldoAnterior,
                            'saldo_actual' => $cajaOperativa->saldo,
                            'monto_descontado' => $importeTotal,
                            'descripcion_historial' => $descripcionHistorial
                        ];

                        // Verificar si después del descuento la caja quedó sin saldo
                        if ($cajaOperativa->saldo <= 0) {
                            $advertencia = "ADVERTENCIA: LA CAJA OPERATIVA '{$cajaOperativa->nombre}' SE QUEDÓ SIN SALDO. DEBE REPONERSE FONDOS.";
                        }
                    } else {
                        // No hay saldo suficiente, calcular monto excedido
                        $saldoDisponible = $cajaOperativa->saldo;
                        $excedentePorPagar = $importeTotal - $saldoDisponible;

                        // Actualizar el saldo de la caja operativa (a cero)
                        $cajaOperativa->saldo = 0;
                        $cajaOperativa->save();

                        // Actualizar el monto excedido en la transacción
                        $transaction->monto_excedido = $excedentePorPagar;
                        $transaction->save();

                        // Actualizar el registro en movements_boxes con el monto excedido
                        $movementBox->monto_excedido = $excedentePorPagar;
                        $movementBox->save();

                        // Descripción detallada para el historial
                        $descripcionHistorial = "EGRESO PARCIAL POR SALDO INSUFICIENTE: {$item}. " .
                            "MONTO TOTAL: {$importeTotal}. " .
                            "MONTO DESCONTADO: {$saldoDisponible}. " .
                            "MONTO EXCEDIDO: {$excedentePorPagar}";

                        // Registrar movimiento en el historial de caja operativa por el monto descontado
                        $this->operatingBoxHistoryService->registrarMovimiento(
                            $cajaOperativa,
                            $saldoDisponible, // Monto descontado real
                            'EGRESO_PARCIAL',
                            $descripcionHistorial,
                            $transaction,
                            $saldoAnterior,
                            0 // Saldo después del movimiento
                        );

                        // Crear registro en pending_payments si hay monto excedido
                        if ($excedentePorPagar > 0) {
                            try {
                                // Para administradores, usar solo user_id sin requerir driver_id
                                $pendingPayment = \App\Models\PendingPayment::create([
                                    'negocio_id' => $transaction->negocio_id,
                                    'driver_id' => null, // No se requiere driver_id para administradores
                                    'financial_transaction_id' => $transaction->id,
                                    'monto' => $excedentePorPagar,
                                    'descripcion' => "Excedente por pagar de la transacción: {$item}",
                                    'estado' => 'pendiente',
                                    'user_id' => $user->id, // Usar el ID del administrador
                                ]);
                            } catch (\Exception $e) {
                                // Si falla la creación del pending payment, registramos el error y continuamos
                                $pendingPayment = null;
                            }
                        }

                        // Establecer una advertencia
                        $advertencia = "ADVERTENCIA: SALDO INSUFICIENTE. SE DESCANTÓ {$saldoDisponible} DE LA CAJA OPERATIVA Y EL EXCEDENTE ({$excedentePorPagar}) QUEDA COMO POR PAGAR.";

                        // Actualizar la variable $operatingBoxActualizada
                        $operatingBoxActualizada = [
                            'id' => $cajaOperativa->id,
                            'nombre' => strtoupper($cajaOperativa->nombre),
                            'saldo_anterior' => $saldoAnterior,
                            'saldo_actual' => 0,
                            'monto_descontado' => $saldoDisponible,
                            'excedente_por_pagar' => $excedentePorPagar,
                            'descripcion_historial' => $descripcionHistorial
                        ];
                    }
                }
                // Si es un ingreso y estado "Pagado", agregar a la caja operativa
                elseif ($transaction->tipo_de_transaccion === 'Ingreso' && $estadoTransaccion->nombre === 'Pagado') {
                    // Guardar el saldo anterior antes de modificarlo
                    $saldoAnterior = $cajaOperativa->saldo;

                    // Actualizar saldo de la caja operativa
                    $cajaOperativa->saldo += $importeTotal;
                    $cajaOperativa->save();

                    // Descripción detallada para el historial
                    $descripcionHistorial = "INGRESO COMPLETO: {$item}. MONTO: {$importeTotal}";

                    // Registrar movimiento en el historial de caja operativa
                    $this->operatingBoxHistoryService->registrarMovimiento(
                        $cajaOperativa,
                        $importeTotal,
                        'ingreso',
                        $descripcionHistorial,
                        $transaction,
                        $saldoAnterior, // Saldo antes del movimiento
                        $cajaOperativa->saldo // Saldo después del movimiento
                    );

                    $operatingBoxActualizada = [
                        'id' => $cajaOperativa->id,
                        'nombre' => strtoupper($cajaOperativa->nombre),
                        'saldo_anterior' => $saldoAnterior,
                        'saldo_actual' => $cajaOperativa->saldo,
                        'monto_agregado' => $importeTotal,
                        'descripcion_historial' => $descripcionHistorial
                    ];
                }
                // Si es "Reembolso", registrar movimiento sin afectar saldo
                elseif ($estadoTransaccion->nombre === 'Reembolso') {
                    // Registrar movimiento en el historial de caja operativa
                    $this->operatingBoxHistoryService->registrarMovimiento(
                        $cajaOperativa,
                        0,
                        'reembolso',
                        'Reembolso de: ' . $transaction->item,
                        $transaction,
                        $cajaOperativa->saldo, // Saldo antes (igual al después porque no se modifica)
                        $cajaOperativa->saldo  // Saldo después
                    );

                    if ($cajaOperativa->saldo <= 0) {
                        $advertencia = "Advertencia: La caja operativa '{$cajaOperativa->nombre}' se quedó sin saldo. Debe reponerse fondos al usuario.";
                    }

                    $operatingBoxActualizada = [
                        'id' => $cajaOperativa->id,
                        'nombre' => $cajaOperativa->nombre,
                        'saldo_actual' => $cajaOperativa->saldo,
                        'nota' => 'No se afectó el saldo porque es un reembolso'
                    ];
                }
            }

            DB::commit();

            // Preparar respuesta
            $response = [
                'status' => 'success',
                'message' => 'Operación completada exitosamente',
                'details' => 'La transacción y movimiento de caja han sido actualizados correctamente',
                'data' => [
                    'transaction' => $transaction->load([
                        'user:id,email',
                        'negocio:id,nombre',
                        'categoria:id,nombre,subcategoria', // Incluir subcategoria en la respuesta
                        'estadoDeTransaccion:id,nombre',
                        'cajaOperativa:id,nombre,saldo'
                    ]),
                    'movement_box' => $movementBox,
                    'operating_box' => $operatingBoxActualizada
                ],
                'archivos_guardados' => !empty($archivosGuardados),
                'archivos_nuevos' => $archivosGuardados,
                'archivos_eliminados' => $archivosEliminados
            ];

            // Agregar información de excedente por pagar si existe
            if ($excedentePorPagar > 0) {
                $response['excedente_por_pagar'] = $excedentePorPagar;
            }

            // Agregar información del pago pendiente si existe
            if ($pendingPayment) {
                $response['pending_payment'] = [
                    'id' => $pendingPayment->id,
                    'driver_id' => $pendingPayment->driver_id, // Incluido para mostrar que es nulo
                    'monto' => $pendingPayment->monto,
                    'descripcion' => $pendingPayment->descripcion,
                    'estado' => $pendingPayment->estado,
                    'created_at' => $pendingPayment->created_at
                ];
            }

            if ($advertencia) {
                $response['advertencia'] = $advertencia;
            }

            return response()->json($response, 200);
        } catch (\Exception $e) {
            DB::rollBack();

            // Eliminar archivos si se subieron y falló
            if (!empty($archivosGuardados)) {
                foreach ($archivosGuardados as $archivo) {
                    $rutaCompleta = public_path(str_replace(asset(''), '', $archivo['ruta']));
                    if (file_exists($rutaCompleta)) {
                        unlink($rutaCompleta);
                    }
                }
            }

            return response()->json([
                'status' => 'error',
                'message' => 'Error en el servidor',
                'details' => 'Ha ocurrido un error al procesar la solicitud. Por favor, inténtelo de nuevo más tarde.',
                'technical_error' => $e->getMessage()
            ], 500);
        }
    }

    public function destroy($id)
    {
        try {
            // Verificar autenticación y rol de administrador
            if (!Auth::check()) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Acceso no autorizado',
                    'details' => 'Debe iniciar sesión para realizar esta operación'
                ], 401);
            }
            $user = Auth::user();
            if (!$user->hasRole('admin')) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Permisos insuficientes',
                    'details' => 'Esta operación solo puede ser realizada por administradores'
                ], 403);
            }

            DB::beginTransaction();

            // Obtener la transacción con sus relaciones necesarias
            $transaction = FinancialTransactions::with([
                'cajaOperativa',
                'estadoDeTransaccion',
                'movimientosCaja', // Relación con MovementsBox
                'archivos' // Relación con archivos
            ])->find($id);

            if (!$transaction) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Transacción no encontrada'
                ], 404);
            }

            // Eliminar archivos adjuntos si existen
            $archivosEliminados = [];
            foreach ($transaction->archivos as $archivo) {
                $rutaCompleta = public_path($archivo->ruta);
                if (file_exists($rutaCompleta)) {
                    unlink($rutaCompleta);
                }
                $archivo->delete();
                $archivosEliminados[] = [
                    'id' => $archivo->id,
                    'ruta' => $archivo->ruta,
                    'nombre_original' => $archivo->nombre_original
                ];
            }

            // Eliminar el movimiento de caja asociado
            $movementBox = $transaction->movimientosCaja()->first();
            if ($movementBox) {
                $movementBox->delete();
            }

            // Revertir movimiento en caja operativa si aplica
            $estadoTransaccion = $transaction->estadoDeTransaccion;
            $operatingBoxActualizada = null;

            // Verificar si hay una caja operativa asociada y el estado es "Pagado"
            if ($transaction->caja_operativa_id && $estadoTransaccion && $estadoTransaccion->nombre === 'Pagado') {
                $cajaOperativa = $transaction->cajaOperativa;
                $importeTotal = (float) $transaction->importe_total;

                // Determinar tipo de reversión (opuesto al tipo de transacción)
                $tipoReversion = $transaction->tipo_de_transaccion === 'Ingreso' ? 'egreso' : 'ingreso';

                // Guardar saldo anterior antes de modificarlo
                $saldoAnterior = $cajaOperativa->saldo;

                // Actualizar saldo de la caja operativa
                if ($transaction->tipo_de_transaccion === 'Ingreso') {
                    // Si era un ingreso, revertir significa restar
                    $cajaOperativa->saldo -= $importeTotal;
                } else {
                    // Si era un egreso, revertir significa sumar
                    $cajaOperativa->saldo += $importeTotal;
                }
                $cajaOperativa->save();

                // Registrar movimiento de reversión en el historial de caja operativa
                $this->operatingBoxHistoryService->registrarMovimiento(
                    $cajaOperativa,
                    $importeTotal,
                    $tipoReversion,
                    'Reversión por eliminación de transacción: ' . $transaction->item,
                    $transaction,
                    $saldoAnterior,
                    $cajaOperativa->saldo
                );

                $operatingBoxActualizada = [
                    'id' => $cajaOperativa->id,
                    'nombre' => $cajaOperativa->nombre,
                    'saldo_anterior' => $saldoAnterior,
                    'saldo_actual' => $cajaOperativa->saldo,
                    'monto_revertido' => $importeTotal,
                    'nota' => 'Reversión por eliminación de transacción'
                ];
            }

            // Eliminar la transacción financiera
            $transaction->delete();

            DB::commit();

            // Preparar respuesta
            $response = [
                'status' => 'success',
                'message' => 'Transacción eliminada correctamente',
                'details' => 'La transacción y su movimiento de caja han sido eliminados',
                'operating_box' => $operatingBoxActualizada,
                'archivos_eliminados' => $archivosEliminados
            ];

            return response()->json($response, 200);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'status' => 'error',
                'message' => 'Error en el servidor',
                'details' => 'Ha ocurrido un error al procesar la solicitud. Por favor, inténtelo de nuevo más tarde.',
                'technical_error' => $e->getMessage()
            ], 500);
        }
    }


    public function updateTransactionState(Request $request, $id)
    {
        try {
            // Verificar autenticación y rol de administrador
            if (!Auth::check()) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Acceso no autorizado',
                    'details' => 'Debe iniciar sesión para realizar esta operación'
                ], 401);
            }
            $user = Auth::user();
            if (!$user->hasRole('admin')) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Permisos insuficientes',
                    'details' => 'Esta operación solo puede ser realizada por administradores'
                ], 403);
            }

            // Validación de datos
            $validator = Validator::make($request->all(), [
                'estado_de_transaccion_id' => 'required|exists:transaction_states,id',
            ], [
                'required' => 'El campo estado de transacción es obligatorio',
                'exists' => 'El estado de transacción seleccionado no es válido',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Datos de entrada inválidos',
                    'details' => 'Por favor, verifique los campos del formulario',
                    'errors' => $validator->errors()
                ], 422);
            }

            DB::beginTransaction();

            // Obtener la transacción existente
            $transaction = FinancialTransactions::find($id);
            if (!$transaction) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Transacción no encontrada'
                ], 404);
            }

            // Guardar el estado original para la respuesta
            $estadoOriginal = TransactionStates::find($transaction->estado_de_transaccion_id);
            $nuevoEstado = TransactionStates::find($request->estado_de_transaccion_id);

            // Actualizar solo el estado de la transacción
            $transaction->update([
                'estado_de_transaccion_id' => $request->estado_de_transaccion_id
            ]);

            DB::commit();

            // Preparar respuesta
            $response = [
                'status' => 'success',
                'message' => 'Estado de transacción actualizado correctamente',
                'details' => 'El estado de la transacción ha sido cambiado de "' .
                    ($estadoOriginal ? $estadoOriginal->nombre : 'No definido') .
                    '" a "' . $nuevoEstado->nombre . '"',
                'data' => [
                    'transaction' => $transaction->load([
                        'user:id,email',
                        'negocio:id,nombre',
                        'categoria:id,nombre',
                        'estadoDeTransaccion:id,nombre',
                        'cajaOperativa:id,nombre,saldo'
                    ])
                ]
            ];

            return response()->json($response, 200);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'status' => 'error',
                'message' => 'Error en el servidor',
                'details' => 'Ha ocurrido un error al procesar la solicitud. Por favor, inténtelo de nuevo más tarde.',
                'technical_error' => $e->getMessage()
            ], 500);
        }
    }
    public function export(Request $request)
    {
        try {
            $fileName = 'transacciones_financieras_' . date('Y-m-d_H-i-s') . '.xlsx';
            return Excel::download(
                new FinancialTransactionsExport($request),
                $fileName
            );
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Error al exportar los datos',
                'details' => 'Ha ocurrido un error al generar el archivo Excel. Por favor, inténtelo de nuevo más tarde.',
                'technical_error' => $e->getMessage()
            ], 500);
        }
    }

    // Función auxiliar para limpiar nombres
    private function limpiarNombre($nombre)
    {
        // Remover acentos y caracteres especiales
        $nombre = iconv('UTF-8', 'ASCII//TRANSLIT', $nombre);
        // Reemplazar espacios y caracteres no alfanuméricos con guiones bajos
        $nombre = preg_replace('/[^a-zA-Z0-9_]/', '_', $nombre);
        // Remover guiones bajos múltiples
        $nombre = preg_replace('/_+/', '_', $nombre);
        // Remover guiones bajos al inicio y final
        $nombre = trim($nombre, '_');
        // Convertir a minúsculas
        return strtolower($nombre);
    }
}
