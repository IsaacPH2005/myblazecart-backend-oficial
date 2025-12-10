<?php

namespace App\Http\Controllers\api\TransactionFinancial;

use App\Exports\FinancialTransactionsDriverExport;
use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Models\FinancialTransactions;
use App\Models\MovementsBox;
use App\Models\PendingPayment;
use App\Models\TransactionFile;
use App\Models\Vehicle;
use App\Models\TransactionStates;
use App\Services\OperatingBoxHistoryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Maatwebsite\Excel\Facades\Excel;

class DriverFinancialTransactionController extends Controller
{
    protected $operatingBoxHistoryService;

    public function __construct(OperatingBoxHistoryService $operatingBoxHistoryService)
    {
        $this->operatingBoxHistoryService = $operatingBoxHistoryService;
    }

    public function storeAuthDriver(Request $request)
    {
        try {
            // Verificar autenticación y rol
            if (!Auth::check()) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'No tienes permiso',
                    'details' => 'Debes iniciar sesión para registrar gastos. Si tienes problemas, contacta a soporte técnico.'
                ], 401);
            }

            $user = Auth::user();
            if (!$user->hasRole('carrier')) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Acceso restringido',
                    'details' => 'Esta función es solo para conductores. Si crees que esto es un error, contacta a soporte técnico.'
                ], 403);
            }

            // Validación básica - Solo permitir estado "Pagado" (ID 2)
            $validator = Validator::make($request->all(), [
                'vehicle_id' => 'required|exists:vehicles,id',
                'negocio_id' => 'nullable|exists:businesses,id,estado,1',
                'metodo_id' => 'required|exists:payment_methods,id',
                'categoria_id' => 'required|exists:categories,id',
                'caja_operativa_id' => 'nullable|exists:operating_boxes,id',
                'estado_de_transaccion_id' => 'required|exists:transaction_states,id|in:2',
                'fecha' => 'required|date|before_or_equal:today',
                'punto_de_partida' => 'nullable|string|max:255',
                'destino' => 'nullable|string|max:255',
                'millas' => 'nullable|integer|min:0',
                'item' => 'required|string|max:255',
                'cantidad' => 'required|numeric|min:0.01',
                'importe_total' => 'required|numeric|min:0.01',
                'cliente_proveedor' => 'nullable|string|max:255',
                'egreso_directo' => 'required|boolean',
                'observaciones' => 'nullable|string|max:1000',
                'archivo' => 'nullable|array|max:5',
                'archivo.*' => 'file|mimes:pdf,jpg,jpeg,png|max:10240',
                'numero_transaccion' => 'required|string|max:255',
            ], [
                // Mensajes personalizados amigables
                'vehicle_id.required' => 'Debes seleccionar tu vehículo',
                'vehicle_id.exists' => 'El vehículo seleccionado no es válido',
                'metodo_id.required' => 'Debes seleccionar cómo pagaste',
                'metodo_id.exists' => 'El método de pago no es válido',
                'categoria_id.required' => 'Debes seleccionar la categoría del gasto',
                'categoria_id.exists' => 'La categoría seleccionada no es válida',
                'caja_operativa_id.exists' => 'La caja operativa seleccionada no es válida',
                'estado_de_transaccion_id.required' => 'Falta el estado de la transacción',
                'estado_de_transaccion_id.in' => 'El estado debe ser "Pagado"',
                'fecha.required' => 'Debes ingresar la fecha del gasto',
                'fecha.date' => 'La fecha no es válida',
                'fecha.before_or_equal' => 'La fecha no puede ser futura',
                'punto_de_partida.max' => 'El punto de partida es muy largo (máximo 255 caracteres)',
                'destino.max' => 'El destino es muy largo (máximo 255 caracteres)',
                'millas.integer' => 'Las millas deben ser un número entero',
                'millas.min' => 'Las millas no pueden ser negativas',
                'item.required' => 'Debes escribir qué compraste',
                'item.max' => 'La descripción es muy larga (máximo 255 caracteres)',
                'cantidad.required' => 'Debes ingresar la cantidad',
                'cantidad.numeric' => 'La cantidad debe ser un número',
                'cantidad.min' => 'La cantidad debe ser mayor a 0',
                'importe_total.required' => 'Debes ingresar cuánto pagaste',
                'importe_total.numeric' => 'El importe debe ser un número',
                'importe_total.min' => 'El importe debe ser mayor a $0.01',
                'cliente_proveedor.max' => 'El nombre del proveedor es muy largo (máximo 255 caracteres)',
                'egreso_directo.required' => 'Debes seleccionar el tipo de egreso (Directo o Indirecto)',
                'egreso_directo.boolean' => 'El tipo de egreso no es válido',
                'observaciones.max' => 'Las observaciones son muy largas (máximo 1000 caracteres)',
                'archivo.array' => 'Los archivos no son válidos',
                'archivo.max' => 'Solo puedes subir hasta 5 archivos',
                'archivo.*.file' => 'Uno de los archivos no es válido',
                'archivo.*.mimes' => 'Solo puedes subir archivos PDF, JPG o PNG',
                'archivo.*.max' => 'Los archivos no deben pesar más de 10 MB cada uno',
                'numero_transaccion.required' => 'Debes ingresar el número del ticket',
                'numero_transaccion.max' => 'El número de ticket es muy largo (máximo 255 caracteres)',
            ]);

            if ($validator->fails()) {
                $errors = $validator->errors()->all();
                $errorMessage = implode(' ', $errors);

                return response()->json([
                    'status' => 'error',
                    'message' => 'Falta información o hay errores',
                    'details' => $errorMessage . ' Si tienes dudas, contacta a soporte técnico.',
                    'errors' => $validator->errors()
                ], 422);
            }

            DB::beginTransaction();

            $negocio_id = null;

            // Validar que el vehículo pertenezca al usuario
            $vehicle = Vehicle::where('id', $request->vehicle_id)
                ->where('user_id', $user->id)
                ->first();

            if (!$vehicle) {
                DB::rollBack();
                return response()->json([
                    'status' => 'error',
                    'message' => 'Vehículo no encontrado',
                    'details' => 'El vehículo seleccionado no existe o no está en tu cuenta. Contacta a soporte técnico si crees que esto es un error.'
                ], 404);
            }

            if (!$vehicle->negocio_id) {
                DB::rollBack();
                return response()->json([
                    'status' => 'error',
                    'message' => 'Vehículo sin negocio',
                    'details' => 'Tu vehículo no tiene un negocio asignado. Por favor, contacta a soporte técnico para solucionar esto.'
                ], 422);
            }

            $negocio_id = $vehicle->negocio_id;

            // Validar que el negocio sea "Lease on" (id = 1)
            if ($negocio_id != 1) {
                DB::rollBack();
                return response()->json([
                    'status' => 'error',
                    'message' => 'Negocio no permitido',
                    'details' => 'Este registro solo está permitido para el negocio "Lease on". Contacta a soporte técnico si necesitas ayuda.',
                    'errors' => [
                        'negocio' => ['Solo se permiten gastos del negocio "Lease on"']
                    ]
                ], 422);
            }

            // Validar que la caja operativa exista y esté activa (si se proporciona)
            if ($request->filled('caja_operativa_id')) {
                $cajaVerificacion = DB::table('operating_boxes')
                    ->where('id', $request->caja_operativa_id)
                    ->where('estado', true)
                    ->first();

                if (!$cajaVerificacion) {
                    DB::rollBack();
                    return response()->json([
                        'status' => 'error',
                        'message' => 'Caja operativa no válida',
                        'details' => 'La caja operativa seleccionada no existe o no está activa. Contacta a soporte técnico.'
                    ], 422);
                }
            }

            // Convertir campos de texto a mayúsculas
            $puntoPartida = $request->filled('punto_de_partida') ? strtoupper($request->punto_de_partida) : null;
            $destino = $request->filled('destino') ? strtoupper($request->destino) : null;
            $item = strtoupper($request->item);
            $clienteProveedor = $request->filled('cliente_proveedor') ? strtoupper($request->cliente_proveedor) : null;
            $observaciones = $request->filled('observaciones') ? strtoupper($request->observaciones) : null;
            $numeroTransaccion = strtoupper($request->numero_transaccion);

            // Crear transacción financiera
            $transaction = FinancialTransactions::create([
                'negocio_id' => $negocio_id,
                'metodo_id' => $request->metodo_id,
                'categoria_id' => $request->categoria_id,
                'user_id' => $user->id,
                'vehicle_id' => $request->vehicle_id,
                'caja_operativa_id' => $request->caja_operativa_id,
                'estado_de_transaccion_id' => $request->estado_de_transaccion_id,
                'fecha' => $request->fecha,
                'punto_de_partida' => $puntoPartida,
                'destino' => $destino,
                'millas' => $request->millas,
                'tipo_de_transaccion' => 'EGRESO',
                'item' => $item,
                'cantidad' => $request->cantidad,
                'importe_total' => $request->importe_total,
                'monto_excedido' => 0,
                'cliente_proveedor' => $clienteProveedor,
                'egreso_directo' => $request->egreso_directo ?? false,
                'observaciones' => $observaciones,
                'numero_transaccion' => $numeroTransaccion,
            ]);

            // Cargar relaciones necesarias
            $transaction->load([
                'categoria',
                'cajaOperativa',
                'estadoDeTransaccion',
                'vehicle'
            ]);

            // Procesamiento de archivos múltiples
            $archivosGuardados = [];
            if ($request->hasFile('archivo')) {
                // Validar cantidad de archivos
                $archivos = $request->file('archivo');
                if (count($archivos) > 5) {
                    DB::rollBack();
                    return response()->json([
                        'status' => 'error',
                        'message' => 'Demasiados archivos',
                        'details' => 'Solo puedes subir hasta 5 archivos a la vez.'
                    ], 422);
                }

                // Obtener información del usuario autenticado
                $generalData = $user->generalData;
                $nombre = $generalData->nombre ?? 'SIN_NOMBRE';
                $apellido = $generalData->apellido ?? 'SIN_APELLIDO';
                $nombreLimpio = $this->limpiarNombre($nombre . '_' . $apellido);
                $fechaTransaccion = \Carbon\Carbon::parse($request->fecha);
                $año = $fechaTransaccion->year;
                $mes = $fechaTransaccion->format('m_F');
                $rutaRelativa = "TRANSACCIONES_FINANCIERAS/{$año}/{$mes}/{$nombreLimpio}";
                $rutaCompleta = public_path($rutaRelativa);

                if (!file_exists($rutaCompleta)) {
                    mkdir($rutaCompleta, 0755, true);
                }

                foreach ($archivos as $archivo) {
                    // Validar MIME type
                    $mimeType = $archivo->getMimeType();
                    $allowedMimeTypes = [
                        'application/pdf',
                        'image/jpeg',
                        'image/png'
                    ];

                    if (!in_array($mimeType, $allowedMimeTypes)) {
                        DB::rollBack();
                        throw new \Exception('Solo puedes subir archivos PDF, JPG o PNG. Si necesitas subir otro tipo de archivo, contacta a soporte técnico.');
                    }

                    // Validar tamaño del archivo (10 MB)
                    if ($archivo->getSize() > 10485760) {
                        DB::rollBack();
                        throw new \Exception('El archivo "' . $archivo->getClientOriginalName() . '" es muy grande. El tamaño máximo es 10 MB.');
                    }

                    $extension = $archivo->getClientOriginalExtension();
                    $timestamp = time() . '_' . uniqid();
                    $nombreArchivo = "EGRESO_{$timestamp}.{$extension}";
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
                    'monto_excedido' => 0,
                    'numero_transaccion' => $numeroTransaccion,
                    'tipo' => 'EGRESO',
                    'descripcion' => $item,
                    'fecha_movimiento' => $request->fecha,
                    'transaccion_financiera_id' => $transaction->id,
                    'user_id' => $user->id,
                ]);

                $cajaOperativa = $transaction->cajaOperativa;
                $importeTotal = (float) $request->importe_total;

                // Guardar el saldo anterior antes de modificarlo
                $saldoAnterior = $cajaOperativa->saldo;

                // Verificar si la caja operativa tiene saldo suficiente
                if ($cajaOperativa->saldo < $importeTotal) {
                    // Saldo insuficiente: calcular excedente
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
                        "MONTO TOTAL: $" . number_format($importeTotal, 2) . ". " .
                        "MONTO DESCONTADO: $" . number_format($saldoDisponible, 2) . ". " .
                        "MONTO EXCEDIDO: $" . number_format($excedentePorPagar, 2);

                    // Registrar movimiento en el historial de caja operativa por el monto descontado
                    $this->operatingBoxHistoryService->registrarMovimiento(
                        $cajaOperativa,
                        $saldoDisponible,
                        'EGRESO_PARCIAL',
                        $descripcionHistorial,
                        $transaction,
                        $saldoAnterior,
                        0
                    );

                    // Crear registro en pending_payments si hay monto excedido
                    if ($excedentePorPagar > 0) {
                        // Obtener el conductor asociado al usuario actual
                        $driver = $user->driver;

                        // Si no existe un conductor asociado, mostrar un error
                        if (!$driver) {
                            DB::rollBack();
                            return response()->json([
                                'status' => 'error',
                                'message' => 'Conductor no encontrado',
                                'details' => 'No se encontró tu información de conductor. Por favor, contacta a soporte técnico.'
                            ], 422);
                        }

                        $pendingPayment = \App\Models\PendingPayment::create([
                            'negocio_id' => $negocio_id,
                            'driver_id' => $driver->id,
                            'financial_transaction_id' => $transaction->id,
                            'monto' => $excedentePorPagar,
                            'descripcion' => "Excedente por pagar: {$item}",
                            'estado' => 'pendiente',
                            'user_id' => $user->id,
                        ]);
                    }

                    // Establecer una advertencia amigable
                    $advertencia = "La caja operativa no tenía suficiente dinero. Se descontó $" . number_format($saldoDisponible, 2) .
                        " y el resto ($" . number_format($excedentePorPagar, 2) . ") quedó registrado como pago pendiente.";

                    // Actualizar la variable $operatingBoxActualizada
                    $operatingBoxActualizada = [
                        'id' => $cajaOperativa->id,
                        'nombre' => strtoupper($cajaOperativa->nombre),
                        'saldo_anterior' => number_format($saldoAnterior, 2),
                        'saldo_actual' => '0.00',
                        'monto_descontado' => number_format($saldoDisponible, 2),
                        'excedente_por_pagar' => number_format($excedentePorPagar, 2),
                        'descripcion_historial' => $descripcionHistorial
                    ];
                } else {
                    // Si hay saldo suficiente, proceder normalmente
                    $cajaOperativa->saldo -= $importeTotal;
                    $cajaOperativa->save();

                    // Descripción detallada para el historial
                    $descripcionHistorial = "EGRESO COMPLETO: {$item}. MONTO: $" . number_format($importeTotal, 2);

                    // Registrar movimiento en el historial de caja operativa
                    $this->operatingBoxHistoryService->registrarMovimiento(
                        $cajaOperativa,
                        $importeTotal,
                        'EGRESO',
                        $descripcionHistorial,
                        $transaction,
                        $saldoAnterior,
                        $cajaOperativa->saldo
                    );

                    // Recargar la caja operativa para obtener el saldo actualizado
                    $cajaOperativa->refresh();

                    $operatingBoxActualizada = [
                        'id' => $cajaOperativa->id,
                        'nombre' => strtoupper($cajaOperativa->nombre),
                        'saldo_anterior' => number_format($saldoAnterior, 2),
                        'saldo_actual' => number_format($cajaOperativa->saldo, 2),
                        'monto_descontado' => number_format($importeTotal, 2),
                        'descripcion_historial' => $descripcionHistorial
                    ];

                    // Verificar si después del descuento la caja quedó con poco saldo
                    if ($cajaOperativa->saldo <= 100) {
                        $advertencia = "La caja operativa '{$cajaOperativa->nombre}' quedó con saldo bajo ($" .
                            number_format($cajaOperativa->saldo, 2) . "). Considera reponer fondos pronto.";
                    }
                }
            }

            DB::commit();

            // Preparar respuesta amigable
            $response = [
                'status' => 'success',
                'message' => '¡Gasto registrado correctamente!',
                'details' => $request->caja_operativa_id
                    ? 'Tu gasto y el movimiento de caja han sido registrados.'
                    : 'Tu gasto ha sido registrado correctamente.',
                'data' => [
                    'transaction' => $transaction->load([
                        'user:id,email',
                        'negocio:id,nombre',
                        'categoria:id,nombre',
                        'estadoDeTransaccion:id,nombre',
                        'cajaOperativa:id,nombre,saldo',
                        'vehicle:id,numero_placa,marca,modelo'
                    ]),
                    'resumen' => [
                        'fecha' => \Carbon\Carbon::parse($transaction->fecha)->format('d/m/Y'),
                        'categoria' => $transaction->categoria->nombre,
                        'descripcion' => $transaction->item,
                        'cantidad' => $transaction->cantidad,
                        'importe_total' => '$' . number_format($transaction->importe_total, 2),
                        'vehiculo' => $transaction->vehicle ? $transaction->vehicle->numero_placa : 'N/A',
                        'tipo_egreso' => $transaction->egreso_directo ? 'Directo' : 'Indirecto',
                        'archivos_subidos' => count($archivosGuardados)
                    ]
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
                $response['excedente_por_pagar'] = '$' . number_format($excedentePorPagar, 2);
            }

            // Agregar información del pago pendiente si existe
            if ($pendingPayment) {
                $response['pending_payment'] = [
                    'id' => $pendingPayment->id,
                    'monto' => '$' . number_format($pendingPayment->monto, 2),
                    'descripcion' => $pendingPayment->descripcion,
                    'estado' => 'Pendiente',
                    'created_at' => \Carbon\Carbon::parse($pendingPayment->created_at)->format('d/m/Y H:i')
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
                'message' => 'Error al guardar el gasto',
                'details' => 'Hubo un problema al procesar tu solicitud. Por favor, intenta de nuevo. Si el problema persiste, contacta a soporte técnico.',
                'technical_error' => config('app.debug') ? $e->getMessage() : 'Error interno del servidor'
            ], 500);
        }
    }


    /**
     * Obtener transacciones del usuario autenticado con filtros y paginación
     * SOLO del negocio Lease on
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function getUserTransactions(Request $request)
    {
        // ========== VERIFICAR AUTENTICACIÓN ==========
        if (!Auth::check()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Usuario no autenticado'
            ], 401);
        }

        try {
            // ========== VALIDAR PARÁMETROS ==========
            $validator = Validator::make($request->all(), [
                'per_page' => 'nullable|integer|min:1|max:100',
                'page' => 'nullable|integer|min:1',
                'fecha_desde' => 'nullable|date',
                'fecha_hasta' => 'nullable|date|after_or_equal:fecha_desde',
                'estado_id' => 'nullable|exists:transaction_states,id',
                'tipo_transaccion' => 'nullable|in:Ingreso,Egreso',
                'categoria_id' => 'nullable|integer',
                'search' => 'nullable|string|max:255'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Validación fallida',
                    'errors' => $validator->errors()
                ], 422);
            }

            // ========== CONFIGURACIÓN INICIAL ==========
            $perPage = $request->get('per_page', 15);
            $userId = Auth::id();

            // Obtener ID de Lease on dinámicamente
            $leaseOnBusiness = Business::where('nombre', 'Lease on')->first();
            if (!$leaseOnBusiness) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Negocio Lease on no configurado en la base de datos'
                ], 404);
            }
            $leaseOnId = $leaseOnBusiness->id;

            // ========== CONSTRUIR QUERY BASE ==========
            $query = FinancialTransactions::with([
                'user.generalData',
                'user.driver',
                'negocio',
                'metodo',
                'categoria',
                'vehicle',
                'estadoDeTransaccion',
                'cajaOperativa',
                'archivos'
            ])
                ->where('user_id', $userId)
                ->where('negocio_id', $leaseOnId);

            // Filtrar por tipo de transacción (por defecto: Egreso)
            $tipoTransaccion = $request->get('tipo_transaccion', 'Egreso');
            if ($tipoTransaccion) {
                $query->where('tipo_de_transaccion', $tipoTransaccion);
            }

            // ========== APLICAR FILTROS OPCIONALES ==========

            // Filtro de fecha desde
            if ($request->filled('fecha_desde')) {
                $query->where('fecha', '>=', $request->fecha_desde);
            }

            // Filtro de fecha hasta
            if ($request->filled('fecha_hasta')) {
                $query->where('fecha', '<=', $request->fecha_hasta);
            }

            // Filtro de estado
            if ($request->filled('estado_id')) {
                $query->where('estado_de_transaccion_id', $request->estado_id);
            }

            // Filtro de categoría
            if ($request->filled('categoria_id')) {
                $query->where('categoria_id', $request->categoria_id);
            }

            // Filtro de búsqueda
            if ($request->filled('search')) {
                $search = $request->search;
                $query->where(function ($q) use ($search) {
                    $q->where('numero_transaccion', 'like', "%{$search}%")
                        ->orWhere('item', 'like', "%{$search}%")
                        ->orWhere('cliente_proveedor', 'like', "%{$search}%")
                        ->orWhere('observaciones', 'like', "%{$search}%");
                });
            }

            // ========== ORDENAR Y PAGINAR ==========
            $query->orderBy('fecha', 'desc')->orderBy('created_at', 'desc');

            $items = $query->paginate($perPage);

            // ========== PROCESAR ITEMS ==========
            $processedItems = $items->getCollection()->map(function ($transaction) use ($leaseOnId) {
                // Carga lazy de pendingPayment
                $pendingPayment = null;
                try {
                    $pendingPayment = PendingPayment::where('financial_transactions_id', $transaction->id)
                        ->where('negocio_id', $leaseOnId)
                        ->first();
                } catch (\Exception $e) {
                    // Log removido
                }

                return [
                    'id' => $transaction->id,
                    'numero_transaccion' => $transaction->numero_transaccion,
                    'fecha' => $transaction->fecha ? $transaction->fecha->format('Y-m-d') : null,
                    'item' => $transaction->item,
                    'cantidad' => $transaction->cantidad,
                    'importe_total' => $transaction->importe_total,
                    'importe_formateado' => number_format($transaction->importe_total, 2),
                    'tipo_de_transaccion' => $transaction->tipo_de_transaccion,
                    'cliente_proveedor' => $transaction->cliente_proveedor,
                    'egreso_directo' => $transaction->egreso_directo,
                    'observaciones' => $transaction->observaciones,
                    'caja_operativa_id' => $transaction->caja_operativa_id,
                    'vehicle_id' => $transaction->vehicle_id,
                    'estado_de_transaccion_id' => $transaction->estado_de_transaccion_id,
                    'created_at' => $transaction->created_at,
                    'updated_at' => $transaction->updated_at,

                    // ========== RELACIONES ==========
                    'user' => $transaction->user ? [
                        'id' => $transaction->user->id,
                        'email' => $transaction->user->email,
                        'nombre' => optional($transaction->user->generalData)->nombre ?? 'N/A',
                        'apellido' => optional($transaction->user->generalData)->apellido ?? 'N/A'
                    ] : null,

                    'negocio' => optional($transaction->negocio)->only(['id', 'nombre']),

                    'metodo' => optional($transaction->metodo)->only(['id', 'nombre']),

                    'categoria' => optional($transaction->categoria)->only(['id', 'nombre']),

                    'vehicle' => optional($transaction->vehicle)->only([
                        'id',
                        'codigo_unico',
                        'marca',
                        'modelo',
                        'numero_placa'
                    ]),

                    'estadoDeTransaccion' => optional($transaction->estadoDeTransaccion)->only([
                        'id',
                        'nombre'
                    ]),

                    'cajaOperativa' => optional($transaction->cajaOperativa)->only([
                        'id',
                        'nombre',
                        'saldo'
                    ]),

                    // ========== PAGO PENDIENTE ==========
                    'pendingPayment' => $pendingPayment ? [
                        'id' => $pendingPayment->id,
                        'monto' => $pendingPayment->monto,
                        'monto_formateado' => number_format($pendingPayment->monto, 2),
                        'estado' => $pendingPayment->estado
                    ] : null,

                    // ========== ARCHIVOS ==========
                    'archivos_count' => $transaction->archivos ? $transaction->archivos->count() : 0,

                    'archivos' => $transaction->archivos
                        ? $transaction->archivos->take(5)->map(function ($archivo) {
                            return [
                                'id' => $archivo->id,
                                'nombre' => $archivo->nombre_original ?? $archivo->nombre,
                                'tipo' => $archivo->tipo,
                                'tamano' => $archivo->tamano,
                                'tamano_formateado' => $this->formatFileSize($archivo->tamano),
                                'ruta' => $archivo->ruta ?? $archivo->path
                            ];
                        })->values()
                        : collect()
                ];
            });

            $items->setCollection($processedItems);

            // ========== RESPUESTA VACÍA ==========
            if ($items->total() === 0) {
                return response()->json([
                    'status' => 'success',
                    'message' => "No se encontraron {$tipoTransaccion} para el negocio Lease on",
                    'data' => [],
                    'pagination' => [
                        'current_page' => 1,
                        'last_page' => 1,
                        'per_page' => $perPage,
                        'total' => 0,
                        'from' => null,
                        'to' => null
                    ],
                    'negocio' => [
                        'id' => $leaseOnId,
                        'nombre' => $leaseOnBusiness->nombre
                    ],
                    'usuario' => [
                        'id' => $userId,
                        'nombre' => optional(Auth::user()->generalData)->nombre ?? 'N/A',
                        'apellido' => optional(Auth::user()->generalData)->apellido ?? 'N/A',
                        'email' => Auth::user()->email
                    ],
                    'timestamp' => now()->toDateTimeString()
                ], 200);
            }

            // ========== RESPUESTA CON DATOS ==========
            return response()->json([
                'status' => 'success',
                'message' => "{$tipoTransaccion} del negocio Lease on obtenidas exitosamente",
                'data' => $items->items(),
                'pagination' => [
                    'current_page' => $items->currentPage(),
                    'last_page' => $items->lastPage(),
                    'per_page' => $items->perPage(),
                    'total' => $items->total(),
                    'from' => $items->firstItem(),
                    'to' => $items->lastItem()
                ],
                'negocio' => [
                    'id' => $leaseOnId,
                    'nombre' => $leaseOnBusiness->nombre
                ],
                'usuario' => [
                    'id' => $userId,
                    'nombre' => optional(Auth::user()->generalData)->nombre ?? 'N/A',
                    'apellido' => optional(Auth::user()->generalData)->apellido ?? 'N/A',
                    'email' => Auth::user()->email
                ],
                'filtros_aplicados' => [
                    'fecha_desde' => $request->fecha_desde ?? null,
                    'fecha_hasta' => $request->fecha_hasta ?? null,
                    'estado_id' => $request->estado_id ?? null,
                    'categoria_id' => $request->categoria_id ?? null,
                    'search' => $request->search ?? null
                ],
                'timestamp' => now()->toDateTimeString()
            ], 200);
        } catch (\Exception $e) {
            $errorMessage = app()->environment('production')
                ? 'Error interno del servidor'
                : $e->getMessage();

            return response()->json([
                'status' => 'error',
                'message' => 'Error al obtener las transacciones',
                'error' => $errorMessage
            ], 500);
        }
    }


    /**
     * Formatea tamaño de archivo
     */
    private function formatFileSize($bytes)
    {
        if (!$bytes) return '0 Bytes';
        $k = 1024;
        $sizes = ['Bytes', 'KB', 'MB', 'GB'];
        $i = floor(log($bytes, $k));
        return round($bytes / pow($k, $i), 2) . ' ' . $sizes[$i];
    }


    /**
     * Export financial transactions to Excel
     *
     */
    public function exportToExcelDriverTransaccion(Request $request)
    {
        try {
            // Logging para debug: Inicia traza
            Log::info('Iniciando exportación de egresos', ['user_id' => Auth::id(), 'request' => $request->all()]);

            // Validar parámetros
            $validator = Validator::make($request->all(), [
                'fecha_desde' => 'nullable|date',
                'fecha_hasta' => 'nullable|date|after_or_equal:fecha_desde',
                'estado_id' => 'nullable|exists:transaction_states,id'
            ]);

            if ($validator->fails()) {
                Log::warning('Validación fallida en exportación', ['errors' => $validator->errors()]);
                return response()->json([
                    'status' => 'error',
                    'message' => 'VALIDACIÓN FALLIDA',
                    'errors' => $validator->errors()
                ], 422);
            }

            // ID del negocio Lease on
            $leaseOnId = 1;

            // Verificar si user está autenticado
            if (!Auth::check()) {
                Log::error('Usuario no autenticado en exportación');
                return response()->json([
                    'status' => 'error',
                    'message' => 'USUARIO NO AUTENTICADO'
                ], 401);
            }

            // Construir query base para el usuario autenticado - CARGAR TODAS LAS RELACIONES NECESARIAS
            $query = FinancialTransactions::with([
                'user.generalData',
                'user.driver',
                'negocio',
                'metodo',
                'categoria',
                'vehicle', // Para codigo_unico
                'estadoDeTransaccion',
                'cajaOperativa', // Para nombre de caja
                'archivos' // CRÍTICO: Para count() en Export, evita N+1 y nulls
            ])
                ->where('user_id', Auth::id())
                ->where('negocio_id', $leaseOnId)
                ->where('tipo_de_transaccion', 'Egreso');

            // Aplicar filtros si existen (mejora: si no hay fecha_hasta, usa hoy para pruebas)
            if ($request->filled('fecha_desde')) {
                $query->where('fecha', '>=', $request->fecha_desde);
            }
            if ($request->filled('fecha_hasta')) {
                $query->where('fecha', '<=', $request->fecha_hasta);
            } else {
                // Opcional: Default a hoy si no se pasa
                $query->where('fecha', '<=', now()->format('Y-m-d'));
            }
            if ($request->filled('estado_id')) {
                $query->where('estado_de_transaccion_id', $request->estado_id);
            }

            // Ordenar por fecha más reciente
            $query->orderBy('fecha', 'desc')->orderBy('created_at', 'desc');

            $transactions = $query->get();

            // Logging: Verificar si hay datos
            Log::info('Transacciones encontradas para exportar', ['count' => $transactions->count()]);

            // Si no hay transacciones, devolver mensaje en lugar de Excel vacío
            if ($transactions->isEmpty()) {
                return response()->json([
                    'status' => 'warning',
                    'message' => 'NO SE ENCONTRARON EGRESOS PARA EXPORTAR',
                    'sugerencia' => 'Intenta ajustar las fechas o verifica que existan egresos en Lease On.'
                ], 200);
            }

            // Generar nombre de archivo con fecha
            $filename = 'EGRESOS_LEASE_ON_' . now()->format('Y_m_d_H_i') . '.xlsx'; // Agregada hora para unicidad

            // Logging: Exportación exitosa
            Log::info('Generando Excel', ['filename' => $filename]);

            return Excel::download(new FinancialTransactionsDriverExport($transactions), $filename);
        } catch (\Exception $e) {
            // Logging detallado del error
            Log::error('Error en exportación de egresos', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'user_id' => Auth::id(),
                'request' => $request->all()
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'ERROR AL EXPORTAR LOS DATOS',
                'error' => $e->getMessage(),
                'sugerencia' => 'Revisa los logs del servidor para más detalles.'
            ], 500);
        }
    }
    /**
     * Obtener datos para el dashboard del conductor usando solo transacciones
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function getDriverDashboardData(Request $request)
    {
        try {
            // Verificar autenticación y rol
            if (!Auth::check()) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Acceso no autorizado'
                ], 401);
            }

            $user = Auth::user();
            if (!$user->hasRole('carrier')) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Permisos insuficientes'
                ], 403);
            }

            // ID del negocio Lease on - CORREGIDO: usar 1 en lugar de 2
            $leaseOnId = 1;

            // Obtener vehículo del conductor
            $vehicle = Vehicle::where('user_id', $user->id)->first();

            // Estadísticas básicas
            $stats = [
                'activeRoutes' => $this->getActiveRoutesCount($user->id),
                'pendingDocs' => 0, // No podemos calcular sin tabla de documentos
                'milesDriven' => $this->getTotalMilesDriven($user->id, $leaseOnId),
                'nextMaintenance' => $vehicle ? $vehicle->millaje + 10000 : 0, // Estimado basado en millaje actual
            ];

            // Datos financieros
            $financialData = $this->getFinancialData($user->id, $leaseOnId);

            // Rutas recientes
            $recentRoutes = $this->getRecentRoutes($user->id, $leaseOnId);

            return response()->json([
                'status' => 'success',
                'message' => 'Datos del dashboard obtenidos correctamente',
                'data' => [
                    'stats' => $stats,
                    'financialData' => $financialData,
                    'recentRoutes' => $recentRoutes,
                    'user' => [
                        'id' => $user->id,
                        'name' => $user->generalData->nombre ?? 'N/A',
                        'lastName' => $user->generalData->apellido ?? 'N/A',
                    ],
                    'vehicle' => $vehicle ? [
                        'id' => $vehicle->id,
                        'make' => $vehicle->marca,
                        'model' => $vehicle->modelo,
                        'year' => $vehicle->año,
                        'plate' => $vehicle->numero_placa,
                        'vin' => $vehicle->numero_vin,
                        'currentMileage' => $vehicle->millaje,
                    ] : null,
                ]
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Error al obtener datos del dashboard',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Obtener conteo de rutas activas
     */
    private function getActiveRoutesCount($userId)
    {
        // CORREGIDO: Usar el estado correcto para rutas activas
        // Asumimos que los estados activos son 1 (Pendiente), 2 (Pagado) y 3 (Procesando)
        // El estado 4 es "Por Pagar" que también podría considerarse activo
        return FinancialTransactions::where('user_id', $userId)
            ->where('tipo_de_transaccion', 'Egreso')
            ->whereIn('estado_de_transaccion_id', [1, 2, 3, 4]) // Estados activos
            ->whereNotNull('punto_de_partida')
            ->whereNotNull('destino')
            ->count();
    }

    /**
     * Obtener total de millas recorridas
     */
    private function getTotalMilesDriven($userId, $negocioId)
    {
        return FinancialTransactions::where('user_id', $userId)
            ->where('negocio_id', $negocioId)
            ->where('tipo_de_transaccion', 'Egreso')
            ->sum('millas');
    }

    /**
     * Obtener datos financieros para el dashboard
     */
    private function getFinancialData($userId, $negocioId)
    {
        // Obtener mes y año actual
        $currentMonth = now()->month;
        $currentYear = now()->year;

        // Calcular totales
        $totalExpenses = FinancialTransactions::where('user_id', $userId)
            ->where('negocio_id', $negocioId)
            ->where('tipo_de_transaccion', 'Egreso')
            ->whereYear('fecha', $currentYear)
            ->whereMonth('fecha', $currentMonth)
            ->sum('importe_total');

        // Distribución de estados - MEJORADO: Usar relaciones en lugar de joins
        $statusDistribution = FinancialTransactions::where('user_id', $userId)
            ->where('negocio_id', $negocioId)
            ->where('tipo_de_transaccion', 'Egreso')
            ->whereYear('fecha', $currentYear)
            ->whereMonth('fecha', $currentMonth)
            ->with('estadoDeTransaccion')
            ->get()
            ->groupBy(function ($transaction) {
                return $transaction->estadoDeTransaccion->nombre ?? 'Sin estado';
            })
            ->map->count()
            ->toArray();

        // Egresos por categoría - MEJORADO: Usar relaciones en lugar de joins
        $expensesByCategory = FinancialTransactions::where('user_id', $userId)
            ->where('negocio_id', $negocioId)
            ->where('tipo_de_transaccion', 'Egreso')
            ->whereYear('fecha', $currentYear)
            ->whereMonth('fecha', $currentMonth)
            ->with('categoria')
            ->get()
            ->groupBy(function ($transaction) {
                return $transaction->categoria->nombre ?? 'Sin categoría';
            })
            ->map(function ($group) {
                return [
                    'total' => $group->sum('importe_total'),
                    'transacciones' => $group->count(),
                    'promedio' => $group->count() > 0 ? $group->sum('importe_total') / $group->count() : 0
                ];
            })
            ->toArray();

        return [
            'period' => now()->format('F Y'),
            'total_egresos_brutos' => $totalExpenses,
            'status_distribution' => $statusDistribution,
            'egresos_por_categoria' => $expensesByCategory,
        ];
    }

    /**
     * Obtener rutas recientes
     */
    private function getRecentRoutes($userId, $negocioId)
    {
        return FinancialTransactions::where('user_id', $userId)
            ->where('negocio_id', $negocioId)
            ->where('tipo_de_transaccion', 'Egreso')
            ->whereNotNull('punto_de_partida')
            ->whereNotNull('destino')
            ->orderBy('fecha', 'desc')
            ->take(5)
            ->get()
            ->map(function ($transaction) {
                // Asegurarse de que la fecha sea un objeto Carbon antes de formatear
                $fecha = is_string($transaction->fecha)
                    ? Carbon::parse($transaction->fecha)
                    : $transaction->fecha;

                return [
                    'id' => $transaction->id,
                    'origin' => $transaction->punto_de_partida,
                    'destination' => $transaction->destino,
                    'miles' => $transaction->millas,
                    'date' => $fecha->format('Y-m-d'),
                ];
            })
            ->toArray();
    }

    /**
     * Retrieve pending payments for the authenticated carrier user with optional filters and pagination.
     * SOLO para el negocio 'Lease on' (crea si no existe). Filtra por driver_id/user_id flexible.
     * Only accessible to users with role 'carrier' (using Spatie Laravel Permission).
     * Incluye logging detallado para debug de "no encuentra pagos".
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function indexPaymentPendingDriver(Request $request)
    {
        // Verificar autenticación
        if (!Auth::check()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Usuario no autenticado'
            ], 401);
        }

        $user = Auth::user();

        // Verificar rol 'carrier'
        if (!$user->hasRole('carrier')) {
            return response()->json([
                'status' => 'error',
                'message' => 'Acceso denegado. Solo carriers pueden ver pagos pendientes.'
            ], 403);
        }

        try {
            // Logging para debug
            Log::info('Solicitando pagos pendientes de carrier (solo Lease on)', [
                'user_id' => $user->id,
                'carrier_role' => $user->getRoleNames()->first(),
            ]);

            // Validar parámetros opcionales
            $validator = Validator::make($request->all(), [
                'per_page' => 'nullable|integer|min:1|max:50',
                'page' => 'nullable|integer|min:1',
                'fecha_desde' => 'nullable|date',
                'fecha_hasta' => 'nullable|date|after_or_equal:fecha_desde',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Validación fallida',
                    'errors' => $validator->errors()
                ], 422);
            }

            // Configurar paginación
            $perPage = $request->get('per_page', 10);

            // BUSCAR NEGOCIO LEASE ON
            $leaseOnBusiness = Business::where('nombre', 'Lease on')->first();
            if (!$leaseOnBusiness) {
                Log::error('Negocio "Lease on" no encontrado en BD');
                return response()->json([
                    'status' => 'error',
                    'message' => 'Negocio "Lease on" no configurado en la base de datos. Contacta al administrador.'
                ], 404);
            }
            $negocioId = $leaseOnBusiness->id;

            Log::info('Negocio Lease on encontrado', ['negocio_id' => $negocioId]);

            // DEBUGGING: Verificar datos existentes
            $totalPendingForUser = PendingPayment::where('user_id', $user->id)->count();
            $totalPendingForNegocio = PendingPayment::where('negocio_id', $negocioId)->count();

            Log::info('Debug PendingPayments', [
                'total_for_user' => $totalPendingForUser,
                'total_for_negocio' => $totalPendingForNegocio,
                'user_id' => $user->id,
                'negocio_id' => $negocioId
            ]);

            // CONSTRUIR QUERY PRINCIPAL
            $pendingQuery = PendingPayment::where('user_id', $user->id)
                ->where('negocio_id', $negocioId)
                ->where('monto', '>', 0)
                ->with([
                    'financialTransaction' => function ($q) {
                        $q->with(['negocio', 'estadoDeTransaccion', 'user.generalData']);
                    }
                ]);

            // Filtros de fecha sobre la transacción financiera
            if ($request->filled('fecha_desde')) {
                $pendingQuery->whereHas('financialTransaction', function ($q) use ($request) {
                    $q->where('fecha', '>=', $request->fecha_desde);
                });
            }
            if ($request->filled('fecha_hasta')) {
                $pendingQuery->whereHas('financialTransaction', function ($q) use ($request) {
                    $q->where('fecha', '<=', $request->fecha_hasta);
                });
            }

            // DEBUGGING: Ver SQL generado
            $sql = $pendingQuery->toSql();
            $bindings = $pendingQuery->getBindings();
            Log::info('Query SQL generado', [
                'sql' => $sql,
                'bindings' => $bindings
            ]);

            // Ordenar
            $pendingQuery->orderBy('created_at', 'desc');

            // Paginado
            $items = $pendingQuery->paginate($perPage);

            Log::info('Resultados obtenidos', [
                'total' => $items->total(),
                'current_page' => $items->currentPage()
            ]);

            // Procesar items SIN días_pendientes
            $processedItems = $items->getCollection()->map(function ($pending) {
                $transaction = $pending->financialTransaction;

                // Si no hay transacción asociada, loguear
                if (!$transaction) {
                    Log::warning('PendingPayment sin FinancialTransaction', [
                        'pending_payment_id' => $pending->id,
                        'financial_transaction_id' => $pending->financial_transaction_id
                    ]);
                }

                return [
                    'id' => $pending->id,
                    'monto' => $pending->monto,
                    'descripcion' => $pending->descripcion,
                    'estado' => $pending->estado,
                    'fecha_pago' => $pending->fecha_pago,
                    'created_at' => $pending->created_at,
                    'updated_at' => $pending->updated_at,

                    // Datos de la transacción financiera
                    'transaction' => $transaction ? [
                        'id' => $transaction->id,
                        'numero_transaccion' => $transaction->numero_transaccion,
                        'fecha' => $transaction->fecha ? $transaction->fecha->format('Y-m-d') : null,
                        'item' => $transaction->item,
                        'importe_total' => $transaction->importe_total,
                        'tipo_de_transaccion' => $transaction->tipo_de_transaccion,
                        'estado' => optional($transaction->estadoDeTransaccion)->nombre ?? 'N/A',
                        'negocio' => optional($transaction->negocio)->nombre ?? 'N/A',
                        'cliente_proveedor' => $transaction->cliente_proveedor,
                        'observaciones' => $transaction->observaciones,
                    ] : null,

                    // Campos calculados (SIN dias_pendientes)
                    'monto_formateado' => number_format($pending->monto, 2),
                ];
            });

            $items->setCollection($processedItems);

            // Estadísticas
            $statsQuery = PendingPayment::where('user_id', $user->id)
                ->where('negocio_id', $negocioId)
                ->where('monto', '>', 0);

            // Aplicar mismos filtros de fecha
            if ($request->filled('fecha_desde')) {
                $statsQuery->whereHas('financialTransaction', function ($q) use ($request) {
                    $q->where('fecha', '>=', $request->fecha_desde);
                });
            }
            if ($request->filled('fecha_hasta')) {
                $statsQuery->whereHas('financialTransaction', function ($q) use ($request) {
                    $q->where('fecha', '<=', $request->fecha_hasta);
                });
            }

            $totalPendiente = $statsQuery->sum('monto');
            $totalPendientesCount = $statsQuery->count();

            $negocioNombre = $leaseOnBusiness->nombre;

            // Respuesta vacía con debug
            if ($items->total() === 0) {
                return response()->json([
                    'status' => 'success',
                    'message' => 'No se encontraron pagos pendientes en el negocio Lease on para este carrier',
                    'data' => [],
                    'pagination' => [
                        'current_page' => 1,
                        'last_page' => 1,
                        'per_page' => $perPage,
                        'total' => 0,
                        'from' => null,
                        'to' => null
                    ],
                    'estadisticas' => [
                        'total_pendiente' => '0.00',
                        'total_pendientes_count' => 0,
                        'negocio_id' => $negocioId,
                        'negocio_nombre' => $negocioNombre
                    ],
                    'debug' => [
                        'total_pending_payments_user' => $totalPendingForUser,
                        'total_pending_payments_negocio' => $totalPendingForNegocio,
                        'filters_applied' => [
                            'user_id' => $user->id,
                            'negocio_id' => $negocioId,
                            'fecha_desde' => $request->fecha_desde,
                            'fecha_hasta' => $request->fecha_hasta,
                        ]
                    ],
                    'user' => [
                        'id' => $user->id,
                        'nombre' => optional($user->generalData)->nombre ?? 'N/A',
                        'apellido' => optional($user->generalData)->apellido ?? 'N/A',
                        'roles' => $user->getRoleNames()
                    ],
                    'timestamp' => now()->toDateTimeString()
                ], 200);
            }

            // Respuesta con datos
            return response()->json([
                'status' => 'success',
                'message' => 'Pagos pendientes en Lease on del carrier obtenidos exitosamente',
                'data' => $items->items(),
                'pagination' => [
                    'current_page' => $items->currentPage(),
                    'last_page' => $items->lastPage(),
                    'per_page' => $items->perPage(),
                    'total' => $items->total(),
                    'from' => $items->firstItem(),
                    'to' => $items->lastItem()
                ],
                'estadisticas' => [
                    'total_pendiente' => number_format($totalPendiente, 2),
                    'total_pendientes_count' => $totalPendientesCount,
                    'negocio_id' => $negocioId,
                    'negocio_nombre' => $negocioNombre
                ],
                'user' => [
                    'id' => $user->id,
                    'nombre' => optional($user->generalData)->nombre ?? 'N/A',
                    'apellido' => optional($user->generalData)->apellido ?? 'N/A',
                    'roles' => $user->getRoleNames()
                ],
                'timestamp' => now()->toDateTimeString()
            ], 200);
        } catch (\Exception $e) {
            $errorMessage = app()->environment('production') ? 'Error interno del servidor' : $e->getMessage();
            Log::error('Error al obtener pagos pendientes', [
                'user_id' => $user->id ?? 'unknown',
                'error' => $e->getMessage(),
                'line' => $e->getLine(),
                'file' => $e->getFile(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Error al obtener pagos pendientes en Lease on',
                'error' => $errorMessage
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
