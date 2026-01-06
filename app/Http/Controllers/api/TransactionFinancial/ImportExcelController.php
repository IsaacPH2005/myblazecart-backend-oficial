<?php

namespace App\Http\Controllers\api\TransactionFinancial;

use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Models\Category;
use App\Models\FinancialTransactions;
use App\Models\MovementsBox;
use App\Models\OperatingBox;
use App\Models\PaymentMethod;
use App\Models\PendingPayment;
use App\Models\TransactionStates;
use App\Models\Vehicle;
use App\Services\OperatingBoxHistoryService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use PhpOffice\PhpSpreadsheet\RichText\RichText;

class ImportExcelController extends Controller
{
    protected $operatingBoxHistoryService;

    public function __construct(OperatingBoxHistoryService $operatingBoxHistoryService)
    {
        $this->operatingBoxHistoryService = $operatingBoxHistoryService;
    }
    public function import(Request $request)
    {
        try {
            // ============== AUTENTICACIÓN Y AUTORIZACIÓN ==============
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

            // ============== VALIDACIÓN DE ARCHIVO ==============
            $validator = Validator::make($request->all(), [
                'archivo' => 'required|file|mimes:xlsx,xls,csv|max:10240',
            ], [
                'required' => 'Debe seleccionar un archivo',
                'file' => 'El archivo debe ser válido',
                'mimes' => 'El archivo debe ser de tipo: xlsx, xls, csv',
                'max' => 'El archivo no debe pesar más de 10MB',
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

            $archivo = $request->file('archivo');

            // ============== LECTURA DE EXCEL CON PHPSPREADSHEET ==============
            $reader = IOFactory::createReaderForFile($archivo->getPathname());
            $reader->setReadDataOnly(true);
            $spreadsheet = $reader->load($archivo->getPathname());
            $worksheet = $spreadsheet->getActiveSheet();

            // Deshabilitar caché de cálculos para mejorar rendimiento
            \PhpOffice\PhpSpreadsheet\Calculation\Calculation::getInstance($spreadsheet)->disableCalculationCache();

            // ============== MAPEO DE COLUMNAS ==============
            $expectedHeaders = [
                'negocio_id',
                'metodo_id',
                'categoria_id',
                'vehicle_id',
                'estado_de_transaccion_id',
                'caja_operativa_id',
                'fecha',
                'punto_de_partida',
                'destino',
                'millas',
                'tipo_de_transaccion',
                'item',
                'cantidad',
                'importe_total',
                'cliente_proveedor',
                'subcategoria',
                'observaciones',
                'numero_transaccion'
            ];
            $columnMap = array_flip($expectedHeaders);

            // ============== LECTURA DE FILAS ==============
            $highestRow = $worksheet->getHighestRow();
            $rows = [];
            $validationErrors = [];

            for ($row = 2; $row <= $highestRow; $row++) {
                $rowData = [];
                foreach ($expectedHeaders as $header) {
                    $colIndex = $columnMap[$header] + 1;
                    $cell = $worksheet->getCellByColumnAndRow($colIndex, $row);

                    // ========== PROCESAMIENTO ESPECIAL DE FECHAS ==========
                    if ($header === 'fecha') {
                        $rawValue = $cell->getValue();

                        if (empty($rawValue)) {
                            $rowData[$header] = null;
                        } elseif (is_numeric($rawValue)) {
                            // Fecha en formato numérico de Excel
                            try {
                                $dateObj = \PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject($rawValue);
                                $dateObj->setTimezone(new \DateTimeZone('UTC'));
                                $rowData[$header] = $dateObj->format('Y-m-d');
                            } catch (\Exception $e) {
                                $rowData[$header] = null;
                                $validationErrors[] = "Fila {$row}: El valor de fecha numérico '{$rawValue}' no pudo ser convertido.";
                            }
                        } else {
                            // Fecha en formato texto
                            try {
                                $formats = ['Y-m-d', 'd/m/Y', 'd-m-Y', 'm/d/Y', 'm-d-Y', 'Y/m/d'];
                                $rawValue = trim($rawValue);
                                $date = null;
                                $success = false;

                                foreach ($formats as $format) {
                                    try {
                                        $date = Carbon::createFromFormat($format, $rawValue, 'UTC');
                                        $success = true;
                                        break;
                                    } catch (\Exception $e) {
                                        continue;
                                    }
                                }

                                if ($success) {
                                    $rowData[$header] = $date->format('Y-m-d');
                                } else {
                                    try {
                                        $date = Carbon::parse($rawValue);
                                        $date->setTimezone(new \DateTimeZone('UTC'));
                                        $rowData[$header] = $date->format('Y-m-d');
                                    } catch (\Exception $e) {
                                        $rowData[$header] = null;
                                        $validationErrors[] = "Fila {$row}: El formato de fecha '{$rawValue}' no es válido.";
                                    }
                                }
                            } catch (\Exception $e) {
                                $rowData[$header] = null;
                                $validationErrors[] = "Fila {$row}: Error procesando fecha '{$rawValue}': {$e->getMessage()}";
                            }
                        }
                    } else {
                        $rowData[$header] = $cell->getValue();
                    }
                }
                $rows[] = $rowData;
            }

            // ============== VALIDACIÓN DE FILAS ==============
            $validRows = [];
            foreach ($rows as $index => $row) {
                // Saltar filas vacías
                if (empty(array_filter($row))) continue;

                $data = [
                    'negocio_id' => $row['negocio_id'] ?? null,
                    'metodo_id' => $row['metodo_id'] ?? null,
                    'categoria_id' => $row['categoria_id'] ?? null,
                    'vehicle_id' => $row['vehicle_id'] ?? null,
                    'estado_de_transaccion_id' => $row['estado_de_transaccion_id'] ?? null,
                    'caja_operativa_id' => $row['caja_operativa_id'] ?? null,
                    'fecha' => $row['fecha'] ?? null,
                    'punto_de_partida' => $row['punto_de_partida'] ?? null,
                    'destino' => $row['destino'] ?? null,
                    'millas' => $row['millas'] ?? null,
                    'tipo_de_transaccion' => $row['tipo_de_transaccion'] ?? null,
                    'item' => $row['item'] ?? null,
                    'cantidad' => $row['cantidad'] ?? null,
                    'importe_total' => $row['importe_total'] ?? null,
                    'cliente_proveedor' => $row['cliente_proveedor'] ?? null,
                    'subcategoria' => $row['subcategoria'] ?? null,
                    'observaciones' => $row['observaciones'] ?? null,
                    'numero_transaccion' => $row['numero_transaccion'] ?? null,
                ];

                // Conversiones numéricas
                $data['millas'] = (!empty($data['millas']) && is_numeric($data['millas'])) ? (int) $data['millas'] : null;
                $data['cantidad'] = (!empty($data['cantidad']) && is_numeric($data['cantidad'])) ? (float) $data['cantidad'] : null;
                $data['importe_total'] = (!empty($data['importe_total']) && is_numeric($data['importe_total'])) ? (float) $data['importe_total'] : null;

                // Normalizar tipo de transacción
                if (!empty($data['tipo_de_transaccion'])) {
                    $data['tipo_de_transaccion'] = ucfirst(strtolower(trim($data['tipo_de_transaccion'])));
                }

                // ========== LÓGICA MEJORADA: OBTENER SUBCATEGORÍA DE LA CATEGORÍA ==========
                // Si no se proporcionó subcategoría en el Excel, obtenerla de la categoría
                if (empty($data['subcategoria']) && !empty($data['categoria_id'])) {
                    $categoria = Category::find($data['categoria_id']);
                    if ($categoria && !empty($categoria->subcategoria)) {
                        $data['subcategoria'] = $categoria->subcategoria;
                    }
                }

                // Si se proporcionó subcategoría en el Excel, limpiarla y usarla
                if (!empty($data['subcategoria'])) {
                    $data['subcategoria'] = trim($data['subcategoria']);
                }

                // Validación con reglas de Laravel
                $rules = [
                    'negocio_id' => 'required|exists:businesses,id',
                    'metodo_id' => 'nullable|exists:payment_methods,id',
                    'categoria_id' => 'nullable|exists:categories,id',
                    'vehicle_id' => 'nullable|exists:vehicles,id',
                    'estado_de_transaccion_id' => 'required|exists:transaction_states,id',
                    'caja_operativa_id' => 'nullable|exists:operating_boxes,id',
                    'fecha' => 'required|date_format:Y-m-d',
                    'punto_de_partida' => 'nullable|string',
                    'destino' => 'nullable|string',
                    'millas' => 'nullable|integer',
                    'tipo_de_transaccion' => 'required|in:Ingreso,Egreso',
                    'item' => 'required|string',
                    'cantidad' => 'required|numeric',
                    'importe_total' => 'nullable|numeric|min:0.01',
                    'cliente_proveedor' => 'nullable|string',
                    'subcategoria' => 'nullable|string',
                    'observaciones' => 'nullable|string',
                    'numero_transaccion' => 'nullable',
                ];

                $validator = Validator::make($data, $rules);

                if ($validator->fails()) {
                    $validationErrors[] = "Fila " . ($index + 2) . ": " . implode(', ', $validator->errors()->all());
                    continue;
                }

                $validRows[] = ['data' => $data, 'originalIndex' => $index];
            }

            // Si hay errores de validación, rollback
            if (count($validationErrors) > 0) {
                DB::rollBack();
                return response()->json([
                    'status' => 'error',
                    'message' => 'Importación cancelada',
                    'details' => 'Corrija los errores e intente nuevamente.',
                    'errors' => $validationErrors
                ], 422);
            }

            // ============== INSERCIÓN DE REGISTROS ==============
            $importedCount = 0;
            $importErrors = [];
            $cajasActualizadas = [];
            $pagosPendientesCreados = [];
            $advertencias = [];

            foreach ($validRows as $validRow) {
                $data = $validRow['data'];
                $rowNumber = $validRow['originalIndex'] + 2;

                try {
                    // ========== YA NO ES NECESARIO: La subcategoría ya fue obtenida en la validación ==========
                    // La lógica de obtener subcategoría ahora está en el bloque de validación arriba

                    // ========== CREAR TRANSACCIÓN FINANCIERA ==========
                    $transaction = FinancialTransactions::create([
                        'negocio_id' => $data['negocio_id'],
                        'metodo_id' => $data['metodo_id'],
                        'categoria_id' => $data['categoria_id'],
                        'user_id' => $user->id,
                        'vehicle_id' => $data['vehicle_id'],
                        'estado_de_transaccion_id' => $data['estado_de_transaccion_id'],
                        'caja_operativa_id' => $data['caja_operativa_id'],
                        'fecha' => $data['fecha'],
                        'punto_de_partida' => !empty($data['punto_de_partida']) ? strtoupper($data['punto_de_partida']) : null,
                        'destino' => !empty($data['destino']) ? strtoupper($data['destino']) : null,
                        'millas' => $data['millas'],
                        'tipo_de_transaccion' => $data['tipo_de_transaccion'],
                        'item' => strtoupper($data['item']),
                        'cantidad' => $data['cantidad'],
                        'importe_total' => $data['importe_total'],
                        'cliente_proveedor' => !empty($data['cliente_proveedor']) ? strtoupper($data['cliente_proveedor']) : null,
                        'subcategoria' => $data['subcategoria'], // Ya tiene la subcategoría correcta
                        'observaciones' => !empty($data['observaciones']) ? strtoupper($data['observaciones']) : null,
                        'numero_transaccion' => !empty($data['numero_transaccion']) ? strtoupper($data['numero_transaccion']) : 'IMPORT-' . time() . '-' . $rowNumber,
                        'monto_excedido' => 0,
                    ]);

                    // ============== LÓGICA DE CAJAS OPERATIVAS ==============
                    if ($data['caja_operativa_id']) {
                        $cajaOperativa = OperatingBox::findOrFail($data['caja_operativa_id']);

                        $item = strtoupper($data['item']);
                        $importeTotal = (float) $data['importe_total'];
                        $numeroTransaccion = $transaction->numero_transaccion;

                        // ========== CREAR REGISTRO EN MOVEMENTS_BOXES ==========
                        $movementBox = MovementsBox::create([
                            'monto' => $importeTotal,
                            'tipo' => strtolower($data['tipo_de_transaccion']),
                            'descripcion' => $item,
                            'fecha_movimiento' => $data['fecha'],
                            'transaccion_financiera_id' => $transaction->id,
                            'user_id' => $user->id,
                            'numero_transaccion' => $numeroTransaccion,
                            'monto_excedido' => 0,
                        ]);

                        // ============== PROCESAMIENTO DE EGRESOS ==============
                        if ($data['tipo_de_transaccion'] === 'Egreso') {
                            $saldoAnterior = $cajaOperativa->saldo;

                            if ($cajaOperativa->saldo >= $importeTotal) {
                                // ===== EGRESO COMPLETO =====
                                $cajaOperativa->saldo -= $importeTotal;
                                $cajaOperativa->save();

                                $descripcionHistorial = "EGRESO COMPLETO (IMPORTACIÓN): {$item}. MONTO: " . number_format($importeTotal, 2);

                                if ($this->operatingBoxHistoryService) {
                                    $this->operatingBoxHistoryService->registrarMovimiento(
                                        $cajaOperativa,
                                        $importeTotal,
                                        'egreso',
                                        $descripcionHistorial,
                                        $transaction,
                                        $saldoAnterior,
                                        $cajaOperativa->saldo
                                    );
                                }

                                $cajasActualizadas[] = [
                                    'fila' => $rowNumber,
                                    'caja_id' => $cajaOperativa->id,
                                    'caja_nombre' => $cajaOperativa->nombre,
                                    'tipo' => 'EGRESO_COMPLETO',
                                    'saldo_anterior' => $saldoAnterior,
                                    'saldo_actual' => $cajaOperativa->saldo,
                                    'monto_descontado' => $importeTotal,
                                ];

                                if ($cajaOperativa->saldo <= 0) {
                                    $advertencias[] = "Fila {$rowNumber}: La caja '{$cajaOperativa->nombre}' quedó sin saldo después del egreso.";
                                }
                            } else {
                                // ===== EGRESO PARCIAL (SALDO INSUFICIENTE) =====
                                $saldoDisponible = $cajaOperativa->saldo;
                                $excedentePorPagar = $importeTotal - $saldoDisponible;

                                $cajaOperativa->saldo = 0;
                                $cajaOperativa->save();

                                $transaction->monto_excedido = $excedentePorPagar;
                                $transaction->save();

                                $movementBox->monto_excedido = $excedentePorPagar;
                                $movementBox->save();

                                $descripcionHistorial = "EGRESO PARCIAL (IMPORTACIÓN) - SALDO INSUFICIENTE: {$item}. " .
                                    "MONTO TOTAL: " . number_format($importeTotal, 2) . ". " .
                                    "MONTO DESCONTADO: " . number_format($saldoDisponible, 2) . ". " .
                                    "MONTO EXCEDIDO: " . number_format($excedentePorPagar, 2);

                                if ($this->operatingBoxHistoryService) {
                                    $this->operatingBoxHistoryService->registrarMovimiento(
                                        $cajaOperativa,
                                        $saldoDisponible,
                                        'EGRESO_PARCIAL',
                                        $descripcionHistorial,
                                        $transaction,
                                        $saldoAnterior,
                                        0
                                    );
                                }

                                // ===== CREAR PAGO PENDIENTE =====
                                if ($excedentePorPagar > 0) {
                                    try {
                                        $pendingPaymentData = [
                                            'negocio_id' => $data['negocio_id'],
                                            'driver_id' => null,
                                            'financial_transaction_id' => $transaction->id,
                                            'monto' => $excedentePorPagar,
                                            'descripcion' => "EXCEDENTE POR PAGAR (IMPORTACIÓN): {$item}. FILA: {$rowNumber}",
                                            'estado' => 'pendiente',
                                            'user_id' => $user->id,
                                        ];

                                        $tableColumns = DB::getSchemaBuilder()->getColumnListing('pending_payments');
                                        if (in_array('fecha_creacion', $tableColumns)) {
                                            $pendingPaymentData['fecha_creacion'] = now();
                                        }

                                        $pendingPayment = PendingPayment::create($pendingPaymentData);

                                        if ($pendingPayment && $pendingPayment->id) {
                                            $pagosPendientesCreados[] = [
                                                'fila' => $rowNumber,
                                                'pending_payment_id' => $pendingPayment->id,
                                                'monto' => $excedentePorPagar,
                                                'descripcion' => $pendingPayment->descripcion,
                                                'transaction_id' => $transaction->id,
                                                'numero_transaccion' => $numeroTransaccion,
                                            ];
                                        }
                                    } catch (\Exception $e) {
                                        $importErrors[] = "Fila {$rowNumber}: Error al crear pago pendiente: " . $e->getMessage();
                                        Log::error("Error creando pago pendiente", ['error' => $e->getMessage()]);
                                    }
                                }

                                $cajasActualizadas[] = [
                                    'fila' => $rowNumber,
                                    'caja_id' => $cajaOperativa->id,
                                    'caja_nombre' => $cajaOperativa->nombre,
                                    'tipo' => 'EGRESO_PARCIAL',
                                    'saldo_anterior' => $saldoAnterior,
                                    'saldo_actual' => 0,
                                    'monto_descontado' => $saldoDisponible,
                                    'excedente_por_pagar' => $excedentePorPagar,
                                ];

                                $advertencias[] = "Fila {$rowNumber}: Saldo insuficiente en caja '{$cajaOperativa->nombre}'. " .
                                    "Se descontó $" . number_format($saldoDisponible, 2) . " y $" . number_format($excedentePorPagar, 2) . " quedó como pago pendiente.";
                            }
                        }
                        // ============== PROCESAMIENTO DE INGRESOS ==============
                        elseif ($data['tipo_de_transaccion'] === 'Ingreso') {
                            $saldoAnterior = $cajaOperativa->saldo;

                            $cajaOperativa->saldo += $importeTotal;
                            $cajaOperativa->save();

                            $descripcionHistorial = "INGRESO COMPLETO (IMPORTACIÓN): {$item}. MONTO: " . number_format($importeTotal, 2);

                            if ($this->operatingBoxHistoryService) {
                                $this->operatingBoxHistoryService->registrarMovimiento(
                                    $cajaOperativa,
                                    $importeTotal,
                                    'ingreso',
                                    $descripcionHistorial,
                                    $transaction,
                                    $saldoAnterior,
                                    $cajaOperativa->saldo
                                );
                            }

                            $cajasActualizadas[] = [
                                'fila' => $rowNumber,
                                'caja_id' => $cajaOperativa->id,
                                'caja_nombre' => $cajaOperativa->nombre,
                                'tipo' => 'INGRESO_COMPLETO',
                                'saldo_anterior' => $saldoAnterior,
                                'saldo_actual' => $cajaOperativa->saldo,
                                'monto_agregado' => $importeTotal,
                            ];
                        }
                    }

                    $importedCount++;
                } catch (\Exception $e) {
                    $importErrors[] = "Fila {$rowNumber}: " . $e->getMessage();
                    Log::error("Error en fila {$rowNumber}: " . $e->getMessage());
                }
            }

            DB::commit();

            // ============== PREPARAR RESPUESTA ==============
            $response = [
                'status' => 'success',
                'message' => 'Importación completada exitosamente',
                'details' => "Se han importado {$importedCount} transacciones financieras.",
                'imported_count' => $importedCount,
                'total_rows_processed' => count($validRows),
            ];

            if (count($cajasActualizadas) > 0) {
                $response['cajas_actualizadas'] = $cajasActualizadas;
                $response['total_cajas_actualizadas'] = count($cajasActualizadas);
            }

            if (count($pagosPendientesCreados) > 0) {
                $response['pagos_pendientes_creados'] = $pagosPendientesCreados;
                $response['total_pagos_pendientes'] = count($pagosPendientesCreados);
            }

            if (count($advertencias) > 0) {
                $response['advertencias'] = $advertencias;
                $response['total_advertencias'] = count($advertencias);
            }

            if (count($importErrors) > 0) {
                $response['status'] = 'warning';
                $response['message'] = 'Importación completada con advertencias';
                $response['details'] = "Se importaron {$importedCount} transacciones, pero algunas filas tuvieron errores.";
                $response['errors'] = $importErrors;
                $response['total_errors'] = count($importErrors);
            }

            return response()->json($response, 200);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error crítico en importación', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return response()->json([
                'status' => 'error',
                'message' => 'Error en el servidor',
                'details' => 'Ha ocurrido un error al procesar la solicitud.',
                'technical_error' => $e->getMessage()
            ], 500);
        }
    }
    /**
     * Descargar plantilla para importar transacciones financieras
     */
    public function descargarPlantilla()
    {
        try {
            // Crear nuevo objeto Spreadsheet
            $spreadsheet = new Spreadsheet();

            // Obtener la hoja activa para la plantilla
            $sheet = $spreadsheet->getActiveSheet();
            $sheet->setTitle('Plantilla de Importación');

            // Encabezados
            $headers = [
                'negocio_id',
                'metodo_id',
                'categoria_id',
                'vehicle_id',
                'estado_de_transaccion_id',
                'caja_operativa_id',
                'fecha',
                'punto_de_partida',
                'destino',
                'millas',
                'tipo_de_transaccion',
                'item',
                'cantidad',
                'importe_total',
                'cliente_proveedor',
                'subcategoria',
                'observaciones',
                'numero_transaccion'
            ];

            // Agregar encabezados
            $sheet->fromArray($headers, null, 'A1');

            // Estilo para encabezados
            $headerStyle = [
                'font' => [
                    'bold' => true,
                    'color' => ['rgb' => 'FFFFFF'],
                ],
                'fill' => [
                    'fillType' => Fill::FILL_SOLID,
                    'startColor' => ['rgb' => '4472C4'],
                ],
                'alignment' => [
                    'horizontal' => Alignment::HORIZONTAL_CENTER,
                    'vertical' => Alignment::VERTICAL_CENTER,
                ],
                'borders' => [
                    'allBorders' => [
                        'borderStyle' => Border::BORDER_THIN,
                        'color' => ['rgb' => '000000'],
                    ],
                ],
            ];
            $sheet->getStyle('A1:R1')->applyFromArray($headerStyle);

            // Ajustar altura de la fila de encabezados
            $sheet->getRowDimension(1)->setRowHeight(25);

            // ========== AGREGAR COMENTARIOS IMPORTANTES ==========

            // Comentario en fecha (G1)
            $richTextFecha = new RichText();
            $richTextFecha->createText('IMPORTANTE: Ingrese las fechas en formato YYYY-MM-DD (ej: 2025-05-23). No cambie el formato de esta columna a "Fecha" en Excel.');
            $sheet->getComment('G1')
                ->setAuthor('Sistema')
                ->setText($richTextFecha);

            // ========== NUEVO: Comentario en subcategoria (P1) ==========
            $richTextSubcategoria = new RichText();
            $richTextSubcategoria->createText(
                "AUTOMÁTICO: Este campo es OPCIONAL. " .
                    "Si lo deja vacío, el sistema tomará automáticamente la subcategoría de la categoría seleccionada (categoria_id). " .
                    "Solo complete este campo si desea usar una subcategoría diferente. " .
                    "Valores permitidos: Transferencia, Compra, Venta, Servicio."
            );
            $sheet->getComment('P1')
                ->setAuthor('Sistema')
                ->setText($richTextSubcategoria);

            // Hacer la columna subcategoria más visible con color diferente
            $subcategoriaHeaderStyle = [
                'font' => [
                    'bold' => true,
                    'color' => ['rgb' => 'FFFFFF'],
                ],
                'fill' => [
                    'fillType' => Fill::FILL_SOLID,
                    'startColor' => ['rgb' => 'FFA500'], // Naranja para destacar
                ],
                'alignment' => [
                    'horizontal' => Alignment::HORIZONTAL_CENTER,
                    'vertical' => Alignment::VERTICAL_CENTER,
                ],
                'borders' => [
                    'allBorders' => [
                        'borderStyle' => Border::BORDER_THIN,
                        'color' => ['rgb' => '000000'],
                    ],
                ],
            ];
            $sheet->getStyle('P1')->applyFromArray($subcategoriaHeaderStyle);

            // ========== EJEMPLOS ACTUALIZADOS ==========
            // Ejemplo 1: subcategoria vacía (se tomará de la categoría)
            // Ejemplo 2: subcategoria vacía (se tomará de la categoría)
            // Ejemplo 3: subcategoria especificada (se usará la especificada)
            $exampleData = [
                [1, 2, 3, 1, 1, 1, '2025-09-10', 'Oficina Central', 'Sucursal Norte', 120, 'Ingreso', 'Venta de producto', 10, 1500.00, 'Cliente S.A.', '', 'Venta mensual', ''],
                [1, 1, 4, null, 2, 1, '2025-09-10', 'Sucursal Norte', 'Proveedor X', 80, 'Egreso', 'Compra de insumos', 5, 750.50, 'Proveedor Y', '', 'Compra urgente', ''],
                [1, 3, 2, 1, 1, 2, '2025-09-10', 'Almacén Central', 'Tienda Z', 45, 'Ingreso', 'Servicio técnico', 2, 350.00, 'Cliente Z', 'Servicio', 'Mantenimiento equipo', ''],
            ];

            // Agregar datos de ejemplo
            $sheet->fromArray($exampleData, null, 'A2');

            // IMPORTANTE: Formatear la columna de fechas como TEXTO
            $lastRow = count($exampleData) + 1;
            $sheet->getStyle('G2:G' . $lastRow)
                ->getNumberFormat()
                ->setFormatCode('@');

            // Establecer explícitamente cada celda de fecha como texto
            for ($row = 2; $row <= $lastRow; $row++) {
                $sheet->setCellValueExplicit('G' . $row, $sheet->getCell('G' . $row)->getValue(), DataType::TYPE_STRING);
            }

            // ========== NUEVO: Estilo para columna subcategoria en ejemplos ==========
            $sheet->getStyle('P2:P' . $lastRow)->applyFromArray([
                'fill' => [
                    'fillType' => Fill::FILL_SOLID,
                    'startColor' => ['rgb' => 'FFF4E6'], // Naranja claro
                ],
                'borders' => [
                    'allBorders' => [
                        'borderStyle' => Border::BORDER_THIN,
                        'color' => ['rgb' => 'D9D9D9'],
                    ],
                ],
            ]);

            // Estilo para datos de ejemplo
            $dataStyle = [
                'borders' => [
                    'allBorders' => [
                        'borderStyle' => Border::BORDER_THIN,
                        'color' => ['rgb' => 'D9D9D9'],
                    ],
                ],
                'alignment' => [
                    'vertical' => Alignment::VERTICAL_CENTER,
                ],
            ];
            $sheet->getStyle('A2:R' . $lastRow)->applyFromArray($dataStyle);

            // Formatear columnas numéricas
            $sheet->getStyle('J2:J' . $lastRow)->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_NUMBER);
            $sheet->getStyle('M2:M' . $lastRow)->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_NUMBER);
            $sheet->getStyle('N2:N' . $lastRow)->getNumberFormat()->setFormatCode('#,##0.00');

            // Autoajustar columnas
            foreach (range('A', 'R') as $column) {
                $sheet->getColumnDimension($column)->setAutoSize(true);
            }

            // Congelar la fila de encabezados
            $sheet->freezePane('A2');

            // ========== HOJA DE REFERENCIA DE IDS ==========
            $referenceSheet = $spreadsheet->createSheet();
            $referenceSheet->setTitle('Referencia de IDs');

            $referenceSheet->setCellValue('A1', 'Tabla');
            $referenceSheet->setCellValue('B1', 'ID');
            $referenceSheet->setCellValue('C1', 'Nombre/Descripción');

            $refHeaderStyle = [
                'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '70AD47']],
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
                'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => '000000']]],
            ];
            $referenceSheet->getStyle('A1:C1')->applyFromArray($refHeaderStyle);

            $refDataStyle = [
                'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'D9D9D9']]],
            ];

            $refCategoryStyle = [
                'font' => ['bold' => true],
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'E2EFDA']],
                'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => '000000']]],
            ];

            $row = 2;

            // Negocios
            $negocios = Business::all(['id', 'nombre']);
            $referenceSheet->setCellValue('A' . $row, 'Negocios');
            $referenceSheet->getStyle('A' . $row . ':C' . $row)->applyFromArray($refCategoryStyle);
            $row++;
            foreach ($negocios as $negocio) {
                $referenceSheet->setCellValue('B' . $row, $negocio->id);
                $referenceSheet->setCellValue('C' . $row, $negocio->nombre);
                $referenceSheet->getStyle('A' . $row . ':C' . $row)->applyFromArray($refDataStyle);
                $row++;
            }
            $row++;

            // Métodos de pago
            $metodos = PaymentMethod::all(['id', 'nombre']);
            $referenceSheet->setCellValue('A' . $row, 'Métodos de Pago');
            $referenceSheet->getStyle('A' . $row . ':C' . $row)->applyFromArray($refCategoryStyle);
            $row++;
            foreach ($metodos as $metodo) {
                $referenceSheet->setCellValue('B' . $row, $metodo->id);
                $referenceSheet->setCellValue('C' . $row, $metodo->nombre);
                $referenceSheet->getStyle('A' . $row . ':C' . $row)->applyFromArray($refDataStyle);
                $row++;
            }
            $row++;

            // ========== CATEGORÍAS CON SUBCATEGORÍA DESTACADA ==========
            $categorias = Category::all(['id', 'nombre', 'subcategoria']);
            $referenceSheet->setCellValue('A' . $row, 'Categorías (⭐ con subcategoría automática)');
            $referenceSheet->getStyle('A' . $row . ':C' . $row)->applyFromArray($refCategoryStyle);
            $row++;
            foreach ($categorias as $categoria) {
                $referenceSheet->setCellValue('B' . $row, $categoria->id);
                $subcategoriaText = $categoria->subcategoria ?: 'N/A';
                $referenceSheet->setCellValue('C' . $row, $categoria->nombre . ' → Subcategoría: ' . $subcategoriaText);
                $referenceSheet->getStyle('A' . $row . ':C' . $row)->applyFromArray($refDataStyle);
                $row++;
            }
            $row++;

            // Vehículos
            $vehiculos = Vehicle::all(['id', 'modelo', 'numero_placa']);
            $referenceSheet->setCellValue('A' . $row, 'Vehículos');
            $referenceSheet->getStyle('A' . $row . ':C' . $row)->applyFromArray($refCategoryStyle);
            $row++;
            foreach ($vehiculos as $vehiculo) {
                $referenceSheet->setCellValue('B' . $row, $vehiculo->id);
                $referenceSheet->setCellValue('C' . $row, $vehiculo->modelo . ' (' . $vehiculo->numero_placa . ')');
                $referenceSheet->getStyle('A' . $row . ':C' . $row)->applyFromArray($refDataStyle);
                $row++;
            }
            $row++;

            // Estados de transacción
            $estados = TransactionStates::all(['id', 'nombre']);
            $referenceSheet->setCellValue('A' . $row, 'Estados de Transacción');
            $referenceSheet->getStyle('A' . $row . ':C' . $row)->applyFromArray($refCategoryStyle);
            $row++;
            foreach ($estados as $estado) {
                $referenceSheet->setCellValue('B' . $row, $estado->id);
                $referenceSheet->setCellValue('C' . $row, $estado->nombre);
                $referenceSheet->getStyle('A' . $row . ':C' . $row)->applyFromArray($refDataStyle);
                $row++;
            }
            $row++;

            // Cajas operativas
            $cajasOperativas = OperatingBox::all(['id', 'nombre', 'saldo']);
            $referenceSheet->setCellValue('A' . $row, 'Cajas Operativas');
            $referenceSheet->getStyle('A' . $row . ':C' . $row)->applyFromArray($refCategoryStyle);
            $row++;
            foreach ($cajasOperativas as $caja) {
                $referenceSheet->setCellValue('B' . $row, $caja->id);
                $saldoFormateado = number_format($caja->saldo, 2, '.', ',');
                $referenceSheet->setCellValue('C' . $row, $caja->nombre . ' (Saldo: ' . $saldoFormateado . ')');
                $referenceSheet->getStyle('A' . $row . ':C' . $row)->applyFromArray($refDataStyle);
                $row++;
            }
            $row++;

            // ========== SECCIÓN ACTUALIZADA DE SUBCATEGORÍAS ==========
            $referenceSheet->setCellValue('A' . $row, '⭐ Campo subcategoria (OPCIONAL)');
            $referenceSheet->getStyle('A' . $row . ':C' . $row)->applyFromArray($refCategoryStyle);
            $row++;

            $referenceSheet->setCellValue('B' . $row, '(vacío)');
            $referenceSheet->setCellValue('C' . $row, '⭐ RECOMENDADO: Dejar vacío para usar la subcategoría de la categoría');
            $referenceSheet->getStyle('A' . $row . ':C' . $row)->applyFromArray([
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'FFEB9C']],
                'font' => ['bold' => true],
                'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => '000000']]],
            ]);
            $row++;

            $referenceSheet->setCellValue('B' . $row, 'Transferencia');
            $referenceSheet->setCellValue('C' . $row, 'Para transferencias entre cajas (solo si difiere de la categoría)');
            $referenceSheet->getStyle('A' . $row . ':C' . $row)->applyFromArray($refDataStyle);
            $row++;

            $referenceSheet->setCellValue('B' . $row, 'Compra');
            $referenceSheet->setCellValue('C' . $row, 'Para compras de productos o servicios (solo si difiere de la categoría)');
            $referenceSheet->getStyle('A' . $row . ':C' . $row)->applyFromArray($refDataStyle);
            $row++;

            $referenceSheet->setCellValue('B' . $row, 'Venta');
            $referenceSheet->setCellValue('C' . $row, 'Para ventas de productos o servicios (solo si difiere de la categoría)');
            $referenceSheet->getStyle('A' . $row . ':C' . $row)->applyFromArray($refDataStyle);
            $row++;

            $referenceSheet->setCellValue('B' . $row, 'Servicio');
            $referenceSheet->setCellValue('C' . $row, 'Para servicios técnicos o profesionales (solo si difiere de la categoría)');
            $referenceSheet->getStyle('A' . $row . ':C' . $row)->applyFromArray($refDataStyle);

            foreach (range('A', 'C') as $column) {
                $referenceSheet->getColumnDimension($column)->setAutoSize(true);
            }
            $referenceSheet->freezePane('A2');

            // ========== HOJA DE INSTRUCCIONES ACTUALIZADA ==========
            $instructionSheet = $spreadsheet->createSheet();
            $instructionSheet->setTitle('Instrucciones');

            $instructions = [
                '📋 Instrucciones para importar transacciones financieras:',
                '',
                '1. Complete la hoja "Plantilla de Importación" con los datos de las transacciones.',
                '2. Para los campos que requieren IDs (negocio_id, metodo_id, etc.), consulte la hoja "Referencia de IDs".',
                '3. El campo "vehicle_id" es opcional, puede dejarlo vacío si no aplica.',
                '',
                '⭐ 4. IMPORTANTE - Campo "subcategoria" (AUTOMÁTICO):',
                '   ✅ RECOMENDADO: Deje este campo VACÍO',
                '   → El sistema tomará automáticamente la subcategoría de la categoría que seleccionó (categoria_id)',
                '   → Por ejemplo: Si selecciona categoria_id = 3 (Ventas), y esa categoría tiene subcategoria = "Venta",',
                '     el sistema guardará "Venta" automáticamente en la transacción financiera.',
                '',
                '   ⚠️ Solo complete el campo "subcategoria" si desea usar una diferente a la de la categoría.',
                '   Valores permitidos si lo llena: Transferencia, Compra, Venta, Servicio',
                '',
                '5. El campo "numero_transaccion" es opcional. Si no se proporciona, se generará automáticamente.',
                '',
                '6. El campo "fecha" debe tener formato YYYY-MM-DD (por ejemplo: 2025-05-23).',
                '   IMPORTANTE: La columna de fecha está configurada como TEXTO para evitar que Excel la modifique.',
                '   SIEMPRE escriba las fechas en formato YYYY-MM-DD.',
                '',
                '7. Una vez completados los datos, guarde el archivo y súbalo al sistema.',
                '',
                '⚠️ ADVERTENCIA CRÍTICA SOBRE FECHAS ⚠️',
                '',
                'PROBLEMA COMÚN: Excel cambia automáticamente el formato de las fechas sin su consentimiento.',
                'SOLUCIÓN:',
                '',
                '1. NUNCA cambie el formato de la columna de fecha a "Fecha" de Excel.',
                '2. Si Excel muestra un número (ej: 45678) en lugar de la fecha, significa que la cambió a formato numérico.',
                '3. Para corregir: haga clic derecho en la celda > Formato de celdas > Texto, luego escriba la fecha como texto.',
                '4. Use SIEMPRE el formato YYYY-MM-DD (ej: 2025-05-23, 2025-12-31, 2025-01-15).',
                '',
                '💡 TÉCNICAS PARA EVITAR QUE EXCEL CAMBIE LAS FECHAS:',
                '',
                'Técnica 1: Escriba un apóstrofo antes de la fecha: \'2025-05-23',
                'Técnica 2: Copie la fecha desde un editor de texto y péguela en Excel.',
                'Técnica 3: Si Excel insiste en cambiar el formato, deshaga el cambio (Ctrl+Z) inmediatamente.',
                'Técnica 4: Si ve que la fecha cambió (ej: 23/5/2025), cámbiela de vuelta a YYYY-MM-DD (2025-05-23).',
                '',
                '📝 Notas importantes generales:',
                '- Los IDs deben coincidir exactamente con los listados en la hoja de referencia.',
                '- Verifique que los tipos de transacción sean "Ingreso" o "Egreso" (con mayúscula inicial).',
                '- Asegúrese de que los montos sean números positivos.',
                '- No modifique los encabezados de las columnas.',
                '- El campo "caja_operativa_id" es opcional.',
                '',
                '❌ ¿Qué pasa si no sigo estas instrucciones?',
                '- Si ingresa una fecha en formato incorrecto, la importación fallará.',
                '- Si Excel cambia el formato de la fecha, la importación fallará.',
                '- Si la fecha no está en formato YYYY-MM-DD, la importación fallará.',
                '',
                '✅ Ejemplos de uso correcto del campo subcategoria:',
                '',
                'Ejemplo 1 (RECOMENDADO):',
                '  categoria_id = 5, subcategoria = (vacío)',
                '  → El sistema busca la categoría con ID 5',
                '  → Si esa categoría tiene subcategoria = "Compra", guarda "Compra" automáticamente',
                '',
                'Ejemplo 2 (personalizado):',
                '  categoria_id = 5, subcategoria = "Servicio"',
                '  → El sistema respeta tu elección y guarda "Servicio" en lugar de la subcategoría de la categoría',
                '',
                'Recuerde: El sistema necesita fechas en formato YYYY-MM-DD para funcionar correctamente.'
            ];

            foreach ($instructions as $index => $instruction) {
                $instructionSheet->setCellValue('A' . ($index + 1), $instruction);
            }

            $instructionTitleStyle = [
                'font' => ['bold' => true, 'size' => 16, 'color' => ['rgb' => '4472C4']],
            ];
            $instructionSheet->getStyle('A1')->applyFromArray($instructionTitleStyle);

            $instructionSectionStyle = [
                'font' => ['bold' => true, 'size' => 14, 'color' => ['rgb' => '70AD47']],
            ];
            $instructionSheet->getStyle('A7')->applyFromArray($instructionSectionStyle);
            $instructionSheet->getStyle('A22')->applyFromArray($instructionSectionStyle);
            $instructionSheet->getStyle('A31')->applyFromArray($instructionSectionStyle);
            $instructionSheet->getStyle('A52')->applyFromArray($instructionSectionStyle);

            $instructionCellStyle = [
                'alignment' => ['vertical' => Alignment::VERTICAL_TOP, 'wrapText' => true],
            ];
            $instructionSheet->getStyle('A1:A' . count($instructions))->applyFromArray($instructionCellStyle);

            $instructionSheet->getColumnDimension('A')->setWidth(120);

            // Establecer la hoja de plantilla como activa
            $spreadsheet->setActiveSheetIndex(0);

            // Crear escritor
            $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);

            $fileName = 'plantilla_transacciones_financieras.xlsx';
            $tempFile = tempnam(sys_get_temp_dir(), $fileName);
            $writer->save($tempFile);

            return response()->download($tempFile, $fileName, [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'Content-Disposition' => 'attachment; filename="' . $fileName . '"',
            ])->deleteFileAfterSend(true);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Error al generar la plantilla: ' . $e->getMessage()
            ], 500);
        }
    }
}



 /*     public function import(Request $request)
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

            // Validar archivo
            $validator = Validator::make($request->all(), [
                'archivo' => 'required|file|mimes:xlsx,xls,csv|max:10240',
            ], [
                'required' => 'Debe seleccionar un archivo',
                'file' => 'El archivo debe ser válido',
                'mimes' => 'El archivo debe ser de tipo: xlsx, xls, csv',
                'max' => 'El archivo no debe pesar más de 10MB',
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

            $archivo = $request->file('archivo');

            $reader = \PhpOffice\PhpSpreadsheet\IOFactory::createReaderForFile($archivo->getPathname());
            $reader->setReadDataOnly(true);
            $spreadsheet = $reader->load($archivo->getPathname());
            $worksheet = $spreadsheet->getActiveSheet();

            \PhpOffice\PhpSpreadsheet\Calculation\Calculation::getInstance($spreadsheet)->disableCalculationCache();

            // Mapeo de columnas
            $expectedHeaders = [
                'negocio_id',
                'metodo_id',
                'categoria_id',
                'vehicle_id',
                'estado_de_transaccion_id',
                'caja_operativa_id',
                'fecha',
                'punto_de_partida',
                'destino',
                'millas',
                'tipo_de_transaccion',
                'item',
                'cantidad',
                'importe_total',
                'cliente_proveedor',
                'egreso_directo',
                'observaciones',
                'numero_transaccion'
            ];
            $columnMap = array_flip($expectedHeaders);

            // Leer datos
            $highestRow = $worksheet->getHighestRow();
            $rows = [];
            $validationErrors = [];

            for ($row = 2; $row <= $highestRow; $row++) {
                $rowData = [];
                foreach ($expectedHeaders as $header) {
                    $colIndex = $columnMap[$header] + 1;
                    $cell = $worksheet->getCellByColumnAndRow($colIndex, $row);

                    // Procesamiento de fechas
                    if ($header === 'fecha') {
                        $rawValue = $cell->getValue();

                        if (empty($rawValue)) {
                            $rowData[$header] = null;
                        } elseif (is_numeric($rawValue)) {
                            try {
                                $dateObj = \PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject($rawValue);
                                $dateObj->setTimezone(new \DateTimeZone('UTC'));
                                $rowData[$header] = $dateObj->format('Y-m-d');
                            } catch (\Exception $e) {
                                $rowData[$header] = null;
                                $validationErrors[] = "Fila " . $row . ": El valor de fecha numérico '{$rawValue}' no pudo ser convertido.";
                            }
                        } else {
                            try {
                                $formats = [
                                    'Y-m-d',
                                    'd/m/Y',
                                    'd-m-Y',
                                    'm/d/Y',
                                    'm-d-Y',
                                    'Y/m/d',
                                ];

                                $rawValue = trim($rawValue);
                                $date = null;
                                $success = false;

                                foreach ($formats as $format) {
                                    try {
                                        $date = Carbon::createFromFormat($format, $rawValue, 'UTC');
                                        $success = true;
                                        break;
                                    } catch (\Exception $e) {
                                        continue;
                                    }
                                }

                                if ($success) {
                                    $rowData[$header] = $date->format('Y-m-d');
                                } else {
                                    try {
                                        $date = Carbon::parse($rawValue);
                                        $date->setTimezone(new \DateTimeZone('UTC'));
                                        $rowData[$header] = $date->format('Y-m-d');
                                    } catch (\Exception $e) {
                                        $rowData[$header] = null;
                                        $validationErrors[] = "Fila " . $row . ": El formato de fecha '{$rawValue}' no es válido.";
                                    }
                                }
                            } catch (\Exception $e) {
                                $rowData[$header] = null;
                                $validationErrors[] = "Fila " . $row . ": Error procesando fecha '{$rawValue}': " . $e->getMessage();
                            }
                        }
                    } else {
                        $rowData[$header] = $cell->getValue();
                    }
                }
                $rows[] = $rowData;
            }

            // Validación previa
            $validRows = [];
            foreach ($rows as $index => $row) {
                if (empty(array_filter($row))) continue;

                $data = [
                    'negocio_id' => $row['negocio_id'] ?? null,
                    'metodo_id' => $row['metodo_id'] ?? null,
                    'categoria_id' => $row['categoria_id'] ?? null,
                    'vehicle_id' => $row['vehicle_id'] ?? null,
                    'estado_de_transaccion_id' => $row['estado_de_transaccion_id'] ?? null,
                    'caja_operativa_id' => $row['caja_operativa_id'] ?? null,
                    'fecha' => $row['fecha'] ?? null,
                    'punto_de_partida' => $row['punto_de_partida'] ?? null,
                    'destino' => $row['destino'] ?? null,
                    'millas' => $row['millas'] ?? null,
                    'tipo_de_transaccion' => $row['tipo_de_transaccion'] ?? null,
                    'item' => $row['item'] ?? null,
                    'cantidad' => $row['cantidad'] ?? null,
                    'importe_total' => $row['importe_total'] ?? null,
                    'cliente_proveedor' => $row['cliente_proveedor'] ?? null,
                    'egreso_directo' => $row['egreso_directo'] ?? null,
                    'observaciones' => $row['observaciones'] ?? null,
                    'numero_transaccion' => $row['numero_transaccion'] ?? null,
                ];

                // Conversiones numéricas
                $data['millas'] = (!empty($data['millas']) && is_numeric($data['millas'])) ? (int) $data['millas'] : null;
                $data['cantidad'] = (!empty($data['cantidad']) && is_numeric($data['cantidad'])) ? (float) $data['cantidad'] : null;
                $data['importe_total'] = (!empty($data['importe_total']) && is_numeric($data['importe_total'])) ? (float) $data['importe_total'] : null;

                // Normalizar tipo de transacción
                if (!empty($data['tipo_de_transaccion'])) {
                    $data['tipo_de_transaccion'] = ucfirst(strtolower(trim($data['tipo_de_transaccion'])));
                }

                // Convertir egreso_directo
                if ($data['egreso_directo'] !== null && $data['egreso_directo'] !== '') {
                    $data['egreso_directo'] = filter_var($data['egreso_directo'], FILTER_VALIDATE_BOOLEAN);
                }

                // Validación con reglas de Laravel
                $rules = [
                    'negocio_id' => 'required|exists:businesses,id',
                    'metodo_id' => 'nullable|exists:payment_methods,id',
                    'categoria_id' => 'nullable|exists:categories,id',
                    'vehicle_id' => 'nullable|exists:vehicles,id',
                    'estado_de_transaccion_id' => 'required|exists:transaction_states,id',
                    'caja_operativa_id' => 'nullable|exists:operating_boxes,id',
                    'fecha' => 'required|date_format:Y-m-d',
                    'punto_de_partida' => 'nullable|string',
                    'destino' => 'nullable|string',
                    'millas' => 'nullable|integer',
                    'tipo_de_transaccion' => 'required|in:Ingreso,Egreso',
                    'item' => 'required|string',
                    'cantidad' => 'required|numeric',
                    'importe_total' => 'nullable|numeric|min:0.01',
                    'cliente_proveedor' => 'nullable|string',
                    'egreso_directo' => 'nullable|boolean',
                    'observaciones' => 'nullable|string',
                    'numero_transaccion' => 'nullable',
                ];

                $validator = Validator::make($data, $rules);

                if ($validator->fails()) {
                    $validationErrors[] = "Fila " . ($index + 2) . ": " . implode(', ', $validator->errors()->all());
                    continue;
                }

                $validRows[] = ['data' => $data, 'originalIndex' => $index];
            }

            // Si hay errores, rollback
            if (count($validationErrors) > 0) {
                DB::rollBack();
                return response()->json([
                    'status' => 'error',
                    'message' => 'Importación cancelada',
                    'details' => 'Corrija los errores e intente nuevamente.',
                    'errors' => $validationErrors
                ], 422);
            }

            // Insertar registros con lógica de cajas operativas
            $importedCount = 0;
            $importErrors = [];
            $cajasActualizadas = [];
            $pagosPendientesCreados = [];
            $advertencias = [];

            foreach ($validRows as $validRow) {
                $data = $validRow['data'];
                $rowNumber = $validRow['originalIndex'] + 2;

                try {
                    // Crear la transacción financiera
                    $transaction = FinancialTransactions::create([
                        'negocio_id' => $data['negocio_id'],
                        'metodo_id' => $data['metodo_id'],
                        'categoria_id' => $data['categoria_id'],
                        'user_id' => $user->id,
                        'vehicle_id' => $data['vehicle_id'],
                        'estado_de_transaccion_id' => $data['estado_de_transaccion_id'],
                        'caja_operativa_id' => $data['caja_operativa_id'],
                        'fecha' => $data['fecha'],
                        'punto_de_partida' => !empty($data['punto_de_partida']) ? strtoupper($data['punto_de_partida']) : null,
                        'destino' => !empty($data['destino']) ? strtoupper($data['destino']) : null,
                        'millas' => $data['millas'],
                        'tipo_de_transaccion' => $data['tipo_de_transaccion'],
                        'item' => strtoupper($data['item']),
                        'cantidad' => $data['cantidad'],
                        'importe_total' => $data['importe_total'],
                        'cliente_proveedor' => !empty($data['cliente_proveedor']) ? strtoupper($data['cliente_proveedor']) : null,
                        'egreso_directo' => $data['tipo_de_transaccion'] === 'Egreso' ? ($data['egreso_directo'] ?? false) : null,
                        'observaciones' => !empty($data['observaciones']) ? strtoupper($data['observaciones']) : null,
                        'numero_transaccion' => !empty($data['numero_transaccion']) ? strtoupper($data['numero_transaccion']) : null,
                        'monto_excedido' => 0,
                    ]);

                    // ============== LÓGICA DE CAJAS OPERATIVAS ==============
                    if ($data['caja_operativa_id']) {
                        $cajaOperativa = OperatingBox::find($data['caja_operativa_id']);

                        if (!$cajaOperativa) {
                            throw new \Exception("La caja operativa ID {$data['caja_operativa_id']} no existe");
                        }

                        $item = strtoupper($data['item']);
                        $importeTotal = (float) $data['importe_total'];
                        $numeroTransaccion = !empty($data['numero_transaccion']) ? strtoupper($data['numero_transaccion']) : 'IMPORT-' . time() . '-' . $rowNumber;

                        // Crear registro en movements_boxes
                        $movementBox = MovementsBox::create([
                            'monto' => $importeTotal,
                            'tipo' => strtolower($data['tipo_de_transaccion']),
                            'descripcion' => $item,
                            'fecha_movimiento' => $data['fecha'],
                            'transaccion_financiera_id' => $transaction->id,
                            'user_id' => $user->id,
                            'numero_transaccion' => $numeroTransaccion,
                            'monto_excedido' => 0,
                        ]);

                        // ============== PROCESAMIENTO DE EGRESOS ==============
                        if ($data['tipo_de_transaccion'] === 'Egreso') {
                            $saldoAnterior = $cajaOperativa->saldo;

                            // Verificar si hay saldo suficiente
                            if ($cajaOperativa->saldo >= $importeTotal) {
                                // ===== EGRESO COMPLETO =====
                                $cajaOperativa->saldo -= $importeTotal;
                                $cajaOperativa->save();

                                $descripcionHistorial = "EGRESO COMPLETO (IMPORTACIÓN): {$item}. MONTO: {$importeTotal}";

                                // Registrar en historial
                                if (method_exists($this, 'operatingBoxHistoryService') && $this->operatingBoxHistoryService) {
                                    $this->operatingBoxHistoryService->registrarMovimiento(
                                        $cajaOperativa,
                                        $importeTotal,
                                        'egreso',
                                        $descripcionHistorial,
                                        $transaction,
                                        $saldoAnterior,
                                        $cajaOperativa->saldo
                                    );
                                }

                                $cajasActualizadas[] = [
                                    'fila' => $rowNumber,
                                    'caja_id' => $cajaOperativa->id,
                                    'caja_nombre' => $cajaOperativa->nombre,
                                    'tipo' => 'EGRESO_COMPLETO',
                                    'saldo_anterior' => $saldoAnterior,
                                    'saldo_actual' => $cajaOperativa->saldo,
                                    'monto_descontado' => $importeTotal,
                                ];

                                // Advertencia si quedó sin saldo
                                if ($cajaOperativa->saldo <= 0) {
                                    $advertencias[] = "Fila {$rowNumber}: La caja '{$cajaOperativa->nombre}' quedó sin saldo después del egreso.";
                                }
                            } else {
                                // ===== EGRESO PARCIAL (SALDO INSUFICIENTE) =====
                                $saldoDisponible = $cajaOperativa->saldo;
                                $excedentePorPagar = $importeTotal - $saldoDisponible;

                                // Actualizar saldo a cero
                                $cajaOperativa->saldo = 0;
                                $cajaOperativa->save();

                                // Actualizar monto excedido en transacción
                                $transaction->monto_excedido = $excedentePorPagar;
                                $transaction->save();

                                // Actualizar monto excedido en movement_box
                                $movementBox->monto_excedido = $excedentePorPagar;
                                $movementBox->save();

                                $descripcionHistorial = "EGRESO PARCIAL (IMPORTACIÓN) - SALDO INSUFICIENTE: {$item}. " .
                                    "MONTO TOTAL: {$importeTotal}. " .
                                    "MONTO DESCONTADO: {$saldoDisponible}. " .
                                    "MONTO EXCEDIDO: {$excedentePorPagar}";

                                // Registrar en historial
                                if (method_exists($this, 'operatingBoxHistoryService') && $this->operatingBoxHistoryService) {
                                    $this->operatingBoxHistoryService->registrarMovimiento(
                                        $cajaOperativa,
                                        $saldoDisponible,
                                        'EGRESO_PARCIAL',
                                        $descripcionHistorial,
                                        $transaction,
                                        $saldoAnterior,
                                        0
                                    );
                                }

                                // ===== SOLUCIÓN: CREAR PAGO PENDIENTE =====
                                if ($excedentePorPagar > 0) {
                                    try {
                                        // Verificar si el modelo PendingPayment existe
                                        if (!class_exists(\App\Models\PendingPayment::class)) {
                                            throw new \Exception("El modelo PendingPayment no existe");
                                        }

                                        // Preparar datos para el pago pendiente
                                        $pendingPaymentData = [
                                            'negocio_id' => $data['negocio_id'],
                                            'driver_id' => null, // Mantener como null si no se requiere
                                            'financial_transaction_id' => $transaction->id,
                                            'monto' => $excedentePorPagar,
                                            'descripcion' => "Excedente por pagar (IMPORTACIÓN): {$item}",
                                            'estado' => 'pendiente',
                                            'user_id' => $user->id,
                                            'fecha_creacion' => now(), // Añadir fecha de creación
                                        ];

                                        // Crear el pago pendiente
                                        $pendingPayment = \App\Models\PendingPayment::create($pendingPaymentData);

                                        // Verificar si se creó correctamente
                                        if (!$pendingPayment || !$pendingPayment->id) {
                                            throw new \Exception("No se pudo crear el pago pendiente");
                                        }

                                        // Agregar a la lista de pagos pendientes creados
                                        $pagosPendientesCreados[] = [
                                            'fila' => $rowNumber,
                                            'pending_payment_id' => $pendingPayment->id,
                                            'monto' => $excedentePorPagar,
                                            'descripcion' => $pendingPayment->descripcion,
                                        ];
                                    } catch (\Exception $e) {

                                        // Añadir error a la lista de errores
                                        $importErrors[] = "Fila {$rowNumber}: Error al crear pago pendiente: " . $e->getMessage();

                                        // Continuar con el proceso a pesar del error
                                        // Si necesitas detener el proceso, lanza la excepción aquí
                                    }
                                }

                                $cajasActualizadas[] = [
                                    'fila' => $rowNumber,
                                    'caja_id' => $cajaOperativa->id,
                                    'caja_nombre' => $cajaOperativa->nombre,
                                    'tipo' => 'EGRESO_PARCIAL',
                                    'saldo_anterior' => $saldoAnterior,
                                    'saldo_actual' => 0,
                                    'monto_descontado' => $saldoDisponible,
                                    'excedente_por_pagar' => $excedentePorPagar,
                                ];

                                $advertencias[] = "Fila {$rowNumber}: Saldo insuficiente en caja '{$cajaOperativa->nombre}'. " .
                                    "Se descontó \${$saldoDisponible} y \${$excedentePorPagar} quedó como pago pendiente.";
                            }
                        }
                        // ============== PROCESAMIENTO DE INGRESOS ==============
                        elseif ($data['tipo_de_transaccion'] === 'Ingreso') {
                            $saldoAnterior = $cajaOperativa->saldo;

                            // Agregar monto a la caja
                            $cajaOperativa->saldo += $importeTotal;
                            $cajaOperativa->save();

                            $descripcionHistorial = "INGRESO COMPLETO (IMPORTACIÓN): {$item}. MONTO: {$importeTotal}";

                            // Registrar en historial
                            if (method_exists($this, 'operatingBoxHistoryService') && $this->operatingBoxHistoryService) {
                                $this->operatingBoxHistoryService->registrarMovimiento(
                                    $cajaOperativa,
                                    $importeTotal,
                                    'ingreso',
                                    $descripcionHistorial,
                                    $transaction,
                                    $saldoAnterior,
                                    $cajaOperativa->saldo
                                );
                            }

                            $cajasActualizadas[] = [
                                'fila' => $rowNumber,
                                'caja_id' => $cajaOperativa->id,
                                'caja_nombre' => $cajaOperativa->nombre,
                                'tipo' => 'INGRESO_COMPLETO',
                                'saldo_anterior' => $saldoAnterior,
                                'saldo_actual' => $cajaOperativa->saldo,
                                'monto_agregado' => $importeTotal,
                            ];
                        }
                    }

                    $importedCount++;
                } catch (\Exception $e) {
                    $importErrors[] = "Fila {$rowNumber}: " . $e->getMessage();
                }
            }

            DB::commit();

            // Preparar respuesta
            $response = [
                'status' => 'success',
                'message' => 'Importación completada exitosamente',
                'details' => "Se han importado {$importedCount} transacciones financieras.",
                'imported_count' => $importedCount,
            ];

            // Agregar información de cajas actualizadas
            if (count($cajasActualizadas) > 0) {
                $response['cajas_actualizadas'] = $cajasActualizadas;
                $response['total_cajas_actualizadas'] = count($cajasActualizadas);
            }

            // Agregar información de pagos pendientes creados
            if (count($pagosPendientesCreados) > 0) {
                $response['pagos_pendientes_creados'] = $pagosPendientesCreados;
                $response['total_pagos_pendientes'] = count($pagosPendientesCreados);
            } else {
                // Añadir información de depuración si no se crearon pagos pendientes
                $response['debug_pagos_pendientes'] = "No se crearon pagos pendientes. Revisa los logs para más detalles.";
            }

            // Agregar advertencias
            if (count($advertencias) > 0) {
                $response['advertencias'] = $advertencias;
            }

            // Si hubo errores en algunas filas
            if (count($importErrors) > 0) {
                $response['status'] = 'warning';
                $response['message'] = 'Importación completada con advertencias';
                $response['details'] = "Se importaron {$importedCount} transacciones, pero algunas filas tuvieron errores.";
                $response['errors'] = $importErrors;
            }

            return response()->json($response, 200);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'status' => 'error',
                'message' => 'Error en el servidor',
                'details' => 'Ha ocurrido un error al procesar la solicitud.',
                'technical_error' => $e->getMessage() . ' en la línea ' . $e->getLine()
            ], 500);
        }
    } */
/**
 * Importar transacciones financieras desde un archivo Excel.
 * SOLUCIÓN DEFINITIVA para manejo de fechas, robusta y a prueba de zonas horarias.
 */
    /*  public function import(Request $request)
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

            // Validar archivo
            $validator = Validator::make($request->all(), [
                'archivo' => 'required|file|mimes:xlsx,xls,csv|max:10240',
            ], [
                'required' => 'Debe seleccionar un archivo',
                'file' => 'El archivo debe ser válido',
                'mimes' => 'El archivo debe ser de tipo: xlsx, xls, csv',
                'max' => 'El archivo no debe pesar más de 10MB',
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

            $archivo = $request->file('archivo');

            $reader = \PhpOffice\PhpSpreadsheet\IOFactory::createReaderForFile($archivo->getPathname());
            $reader->setReadDataOnly(true);
            $spreadsheet = $reader->load($archivo->getPathname());
            $worksheet = $spreadsheet->getActiveSheet();

            \PhpOffice\PhpSpreadsheet\Calculation\Calculation::getInstance($spreadsheet)->disableCalculationCache();

            // Mapeo de columnas
            $expectedHeaders = [
                'negocio_id',
                'metodo_id',
                'categoria_id',
                'vehicle_id',
                'estado_de_transaccion_id',
                'caja_operativa_id',
                'fecha',
                'punto_de_partida',
                'destino',
                'millas',
                'tipo_de_transaccion',
                'item',
                'cantidad',
                'importe_total',
                'cliente_proveedor',
                'egreso_directo',
                'observaciones',
                'numero_transaccion'
            ];
            $columnMap = array_flip($expectedHeaders); // Más eficiente para buscar índices

            // Leer datos
            $highestRow = $worksheet->getHighestRow();
            $rows = [];
            $validationErrors = []; // Inicializar errores de validación aquí

            for ($row = 2; $row <= $highestRow; $row++) {
                $rowData = [];
                foreach ($expectedHeaders as $header) {
                    $colIndex = $columnMap[$header] + 1; // +1 porque las columnas en PhpSpreadsheet son base 1
                    $cell = $worksheet->getCellByColumnAndRow($colIndex, $row);

                    // ============ SOLUCIÓN DEFINITIVA Y SIMPLIFICADA PARA FECHAS ============
                    if ($header === 'fecha') {
                        $rawValue = $cell->getValue();

                        if (empty($rawValue)) {
                            $rowData[$header] = null;
                        } elseif (is_numeric($rawValue)) { // Es un número de serie de Excel
                            try {
                                $dateObj = \PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject($rawValue);
                                // ¡Punto clave! Forzar UTC para evitar desplazamientos por zona horaria del servidor
                                $dateObj->setTimezone(new \DateTimeZone('UTC'));
                                $rowData[$header] = $dateObj->format('Y-m-d');
                            } catch (\Exception $e) {
                                $rowData[$header] = null;
                                $validationErrors[] = "Fila " . $row . ": El valor de fecha numérico '{$rawValue}' no pudo ser convertido.";
                            }
                        } else { // Es una cadena de texto
                            try {
                                // Lista de formatos comunes para fechas en español (México)
                                $formats = [
                                    'Y-m-d',    // 2025-10-08
                                    'd/m/Y',    // 08/10/2025
                                    'd-m-Y',    // 08-10-2025
                                    'm/d/Y',    // 10/08/2025
                                    'm-d-Y',    // 10-08-2025
                                    'Y/m/d',    // 2025/10/08
                                ];

                                $rawValue = trim($rawValue);
                                $date = null;
                                $success = false;

                                // Intentar con cada formato
                                foreach ($formats as $format) {
                                    try {
                                        $date = Carbon::createFromFormat($format, $rawValue, 'UTC');
                                        $success = true;
                                        break;
                                    } catch (\Exception $e) {
                                        // Continuar con el siguiente formato
                                    }
                                }

                                if ($success) {
                                    $rowData[$header] = $date->format('Y-m-d');
                                } else {
                                    // Último recurso: intentar parseo flexible
                                    try {
                                        $date = Carbon::parse($rawValue);
                                        $date->setTimezone(new \DateTimeZone('UTC'));
                                        $rowData[$header] = $date->format('Y-m-d');
                                    } catch (\Exception $e) {
                                        $rowData[$header] = null;
                                        $validationErrors[] = "Fila " . $row . ": El formato de fecha '{$rawValue}' no es válido. Use formatos como: YYYY-MM-DD, DD/MM/YYYY.";
                                    }
                                }
                            } catch (\Exception $e) {
                                $rowData[$header] = null;
                                $validationErrors[] = "Fila " . $row . ": Error procesando fecha '{$rawValue}': " . $e->getMessage();
                            }
                        }
                    } else {
                        $rowData[$header] = $cell->getValue();
                    }
                }
                $rows[] = $rowData;
            }

            // Validación previa
            $validRows = [];
            foreach ($rows as $index => $row) {
                if (empty(array_filter($row))) continue; // Saltar filas vacías

                $data = [
                    'negocio_id' => $row['negocio_id'] ?? null,
                    'metodo_id' => $row['metodo_id'] ?? null,
                    'categoria_id' => $row['categoria_id'] ?? null,
                    'vehicle_id' => $row['vehicle_id'] ?? null,
                    'estado_de_transaccion_id' => $row['estado_de_transaccion_id'] ?? null,
                    'caja_operativa_id' => $row['caja_operativa_id'] ?? null,
                    'fecha' => $row['fecha'] ?? null,
                    'punto_de_partida' => $row['punto_de_partida'] ?? null,
                    'destino' => $row['destino'] ?? null,
                    'millas' => $row['millas'] ?? null,
                    'tipo_de_transaccion' => $row['tipo_de_transaccion'] ?? null,
                    'item' => $row['item'] ?? null,
                    'cantidad' => $row['cantidad'] ?? null,
                    'importe_total' => $row['importe_total'] ?? null,
                    'cliente_proveedor' => $row['cliente_proveedor'] ?? null,
                    'egreso_directo' => $row['egreso_directo'] ?? null,
                    'observaciones' => $row['observaciones'] ?? null,
                    'numero_transaccion' => $row['numero_transaccion'] ?? null,
                ];

                // Conversiones numéricas
                $data['millas'] = (!empty($data['millas']) && is_numeric($data['millas'])) ? (int) $data['millas'] : null;
                $data['cantidad'] = (!empty($data['cantidad']) && is_numeric($data['cantidad'])) ? (float) $data['cantidad'] : null;
                $data['importe_total'] = (!empty($data['importe_total']) && is_numeric($data['importe_total'])) ? (float) $data['importe_total'] : null;

                // Normalizar tipo de transacción
                if (!empty($data['tipo_de_transaccion'])) {
                    $data['tipo_de_transaccion'] = ucfirst(strtolower(trim($data['tipo_de_transaccion'])));
                }

                // Convertir egreso_directo
                if ($data['egreso_directo'] !== null && $data['egreso_directo'] !== '') {
                    $data['egreso_directo'] = filter_var($data['egreso_directo'], FILTER_VALIDATE_BOOLEAN);
                }

                // Validación con reglas de Laravel
                $rules = [
                    'negocio_id' => 'required|exists:businesses,id',
                    'metodo_id' => 'nullable|exists:payment_methods,id',
                    'categoria_id' => 'nullable|exists:categories,id',
                    'vehicle_id' => 'nullable|exists:vehicles,id',
                    'estado_de_transaccion_id' => 'required|exists:transaction_states,id',
                    'caja_operativa_id' => 'nullable|exists:operating_boxes,id',
                    'fecha' => 'required|date_format:Y-m-d',
                    'punto_de_partida' => 'nullable|string',
                    'destino' => 'nullable|string',
                    'millas' => 'nullable|integer',
                    'tipo_de_transaccion' => 'required|in:Ingreso,Egreso',
                    'item' => 'required|string',
                    'cantidad' => 'required|numeric',
                    'importe_total' => 'nullable|numeric|min:0.01',
                    'cliente_proveedor' => 'nullable|string',
                    'egreso_directo' => 'nullable|boolean',
                    'observaciones' => 'nullable|string',
                    'numero_transaccion' => 'nullable',
                ];

                $validator = Validator::make($data, $rules);

                if ($validator->fails()) {
                    $validationErrors[] = "Fila " . ($index + 2) . ": " . implode(', ', $validator->errors()->all());
                    continue;
                }

                $validRows[] = ['data' => $data, 'originalIndex' => $index];
            }

            // Si hay errores, rollback
            if (count($validationErrors) > 0) {
                DB::rollBack();
                return response()->json([
                    'status' => 'error',
                    'message' => 'Importación cancelada',
                    'details' => 'Corrija los errores e intente nuevamente.',
                    'errors' => $validationErrors
                ], 422);
            }

            // Insertar registros
            $importedCount = 0;
            $importErrors = [];
            foreach ($validRows as $validRow) {
                $data = $validRow['data'];
                try {
                    FinancialTransactions::create([
                        'negocio_id' => $data['negocio_id'],
                        'metodo_id' => $data['metodo_id'],
                        'categoria_id' => $data['categoria_id'],
                        'user_id' => $user->id,
                        'vehicle_id' => $data['vehicle_id'],
                        'estado_de_transaccion_id' => $data['estado_de_transaccion_id'],
                        'caja_operativa_id' => $data['caja_operativa_id'],
                        'fecha' => $data['fecha'],
                        'punto_de_partida' => !empty($data['punto_de_partida']) ? strtoupper($data['punto_de_partida']) : null,
                        'destino' => !empty($data['destino']) ? strtoupper($data['destino']) : null,
                        'millas' => $data['millas'],
                        'tipo_de_transaccion' => $data['tipo_de_transaccion'],
                        'item' => strtoupper($data['item']),
                        'cantidad' => $data['cantidad'],
                        'importe_total' => $data['importe_total'],
                        'cliente_proveedor' => !empty($data['cliente_proveedor']) ? strtoupper($data['cliente_proveedor']) : null,
                        'egreso_directo' => $data['tipo_de_transaccion'] === 'Egreso' ? ($data['egreso_directo'] ?? false) : null,
                        'observaciones' => !empty($data['observaciones']) ? strtoupper($data['observaciones']) : null,
                        'numero_transaccion' => !empty($data['numero_transaccion']) ? strtoupper($data['numero_transaccion']) : null,
                    ]);
                    $importedCount++;
                } catch (\Exception $e) {
                    $importErrors[] = "Fila " . ($validRow['originalIndex'] + 2) . ": " . $e->getMessage();
                }
            }

            DB::commit();

            $response = [
                'status' => 'success',
                'message' => 'Importación completada',
                'details' => "Se han importado {$importedCount} transacciones financieras.",
                'imported_count' => $importedCount,
            ];

            if (count($importErrors) > 0) {
                $response['status'] = 'warning';
                $response['message'] = 'Importación completada con advertencias';
                $response['details'] = "Se importaron {$importedCount} transacciones, pero algunas filas tuvieron errores.";
                $response['errors'] = $importErrors;
            }

            return response()->json($response, 200);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'status' => 'error',
                'message' => 'Error en el servidor',
                'details' => 'Ha ocurrido un error al procesar la solicitud.',
                'technical_error' => $e->getMessage() . ' en la línea ' . $e->getLine()
            ], 500);
        }
    } */



/**
 * Importar transacciones financieras desde un archivo Excel
 */
    /*     public function import(Request $request)
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

            // Validar que se haya subido un archivo
            $validator = Validator::make($request->all(), [
                'archivo' => 'required|file|mimes:xlsx,xls,csv|max:10240',
            ], [
                'required' => 'Debe seleccionar un archivo',
                'file' => 'El archivo debe ser válido',
                'mimes' => 'El archivo debe ser de tipo: xlsx, xls, csv',
                'max' => 'El archivo no debe pesar más de 10MB',
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

            $archivo = $request->file('archivo');

            // Procesar el archivo Excel
            $spreadsheet = IOFactory::load($archivo);
            $worksheet = $spreadsheet->getActiveSheet();

            // Obtener la fila de encabezados para verificar las columnas
            $headerRow = $worksheet->rangeToArray('A1:R1')[0];

            // Mapeo de índices de columnas basado en los encabezados
            $columnMap = [];
            $expectedHeaders = [
                'negocio_id',
                'metodo_id',
                'categoria_id',
                'vehicle_id',
                'estado_de_transaccion_id',
                'caja_operativa_id',
                'fecha',
                'punto_de_partida',
                'destino',
                'millas',
                'tipo_de_transaccion',
                'item',
                'cantidad',
                'importe_total',
                'cliente_proveedor',
                'egreso_directo',
                'observaciones',
                'numero_transaccion'
            ];

            // Crear mapa de columnas
            foreach ($expectedHeaders as $index => $header) {
                $columnMap[$header] = $index;
            }

            // Leer todas las filas de datos
            $highestRow = $worksheet->getHighestRow();
            $rows = [];

            for ($row = 2; $row <= $highestRow; $row++) {
                $rowData = [];
                foreach ($expectedHeaders as $header) {
                    $colIndex = $columnMap[$header];
                    $cellValue = $worksheet->getCellByColumnAndRow($colIndex + 1, $row)->getValue();
                    $rowData[$header] = $cellValue;
                }
                $rows[] = $rowData;
            }

            // Fase de validación previa - sin insertar datos
            $validationErrors = [];
            $validRows = [];

            foreach ($rows as $index => $row) {
                // Saltar filas vacías
                if (empty(array_filter($row))) {
                    continue;
                }

                // Mapear columnas del Excel a los campos del modelo usando el mapa
                $data = [
                    'negocio_id' => $row['negocio_id'] ?? null,
                    'metodo_id' => $row['metodo_id'] ?? null,
                    'categoria_id' => $row['categoria_id'] ?? null,
                    'vehicle_id' => $row['vehicle_id'] ?? null,
                    'estado_de_transaccion_id' => $row['estado_de_transaccion_id'] ?? null,
                    'caja_operativa_id' => $row['caja_operativa_id'] ?? null,
                    'fecha' => $row['fecha'] ?? null,
                    'punto_de_partida' => $row['punto_de_partida'] ?? null,
                    'destino' => $row['destino'] ?? null,
                    'millas' => $row['millas'] ?? null,
                    'tipo_de_transaccion' => $row['tipo_de_transaccion'] ?? null,
                    'item' => $row['item'] ?? null,
                    'cantidad' => $row['cantidad'] ?? null,
                    'importe_total' => $row['importe_total'] ?? null,
                    'cliente_proveedor' => $row['cliente_proveedor'] ?? null,
                    'egreso_directo' => $row['egreso_directo'] ?? null,
                    'observaciones' => $row['observaciones'] ?? null,
                    'numero_transaccion' => $row['numero_transaccion'] ?? null,
                ];

                // Procesar y convertir datos
                // Convertir fecha - manejar diferentes formatos
                if (!empty($data['fecha'])) {
                    try {
                        // Si es un timestamp de Excel, convertirlo a fecha
                        if (is_numeric($data['fecha'])) {
                            $data['fecha'] = \PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject($data['fecha'])->format('Y-m-d');
                        } else {
                            // Usar la función convertirFecha para manejar diferentes formatos
                            $data['fecha'] = $this->convertirFecha($data['fecha']);
                        }
                    } catch (\Exception $e) {
                        $validationErrors[] = "Fila " . ($index + 2) . ": Formato de fecha inválido: " . $data['fecha'];
                        continue;
                    }
                }

                // Convertir valores numéricos
                if (!empty($data['millas']) && is_numeric($data['millas'])) {
                    $data['millas'] = (int) $data['millas'];
                } else {
                    $data['millas'] = null;
                }

                if (!empty($data['cantidad']) && is_numeric($data['cantidad'])) {
                    $data['cantidad'] = (float) $data['cantidad'];
                } else {
                    $data['cantidad'] = null;
                }

                if (!empty($data['importe_total']) && is_numeric($data['importe_total'])) {
                    $data['importe_total'] = (float) $data['importe_total'];
                } else {
                    $data['importe_total'] = null;
                }

                // Normalizar tipo de transacción
                if (!empty($data['tipo_de_transaccion'])) {
                    // Limpiar el valor (quitar espacios, convertir a minúsculas y luego a mayúscula la primera letra)
                    $data['tipo_de_transaccion'] = ucfirst(strtolower(trim($data['tipo_de_transaccion'])));
                    if (!in_array($data['tipo_de_transaccion'], ['Ingreso', 'Egreso'])) {
                        $validationErrors[] = "Fila " . ($index + 2) . ": Tipo de transacción inválido: " . $data['tipo_de_transaccion'] . ". Debe ser 'Ingreso' o 'Egreso'";
                        continue;
                    }
                }

                // Convertir el valor de egreso_directo a booleano si es necesario
                if ($data['egreso_directo'] !== null && $data['egreso_directo'] !== '') {
                    if (is_string($data['egreso_directo'])) {
                        // Convertir cadenas 'true', 'false' a booleanos
                        $data['egreso_directo'] = strtolower($data['egreso_directo']) === 'true' ? 1 : 0;
                    } else {
                        // Asegurarse de que sea un valor numérico (0 o 1)
                        $data['egreso_directo'] = $data['egreso_directo'] ? 1 : 0;
                    }
                }

                // Validar los datos
                $rules = [
                    'negocio_id' => 'required|exists:businesses,id',
                    'metodo_id' => 'nullable|exists:payment_methods,id',
                    'categoria_id' => 'nullable|exists:categories,id',
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
                    'importe_total' => 'nullable|numeric|min:0.01',
                    'cliente_proveedor' => 'nullable|string',
                    'observaciones' => 'nullable|string',
                    'numero_transaccion' => 'nullable',
                ];

                // Agregar regla para egreso_directo según el tipo de transacción
                if ($data['tipo_de_transaccion'] === 'Egreso') {
                    $rules['egreso_directo'] = 'nullable|boolean';
                } else {
                    $rules['egreso_directo'] = 'nullable';
                }

                $validator = Validator::make($data, $rules);

                if ($validator->fails()) {
                    $validationErrors[] = "Fila " . ($index + 2) . ": " . implode(', ', $validator->errors()->all());
                    continue;
                }

                // Si todas las validaciones pasaron, guardar la fila para procesamiento posterior
                $validRows[] = [
                    'data' => $data,
                    'originalIndex' => $index
                ];
            }

            // Si hay errores de validación, hacer rollback y retornar error
            if (count($validationErrors) > 0) {
                DB::rollBack();
                return response()->json([
                    'status' => 'error',
                    'message' => 'Importación cancelada',
                    'details' => 'No se pudo completar la importación porque hay filas con errores. Por favor, corrija todos los errores e intente nuevamente.',
                    'errors' => $validationErrors
                ], 422);
            }

            // Si no hay errores, proceder a insertar todos los registros
            $importedCount = 0;
            $importErrors = [];
            $warnings = [];

            foreach ($validRows as $validRow) {
                $data = $validRow['data'];
                $index = $validRow['originalIndex'];

                try {
                    // Convertir campos de texto a mayúsculas
                    $puntoPartida = !empty($data['punto_de_partida']) ? strtoupper($data['punto_de_partida']) : null;
                    $destino = !empty($data['destino']) ? strtoupper($data['destino']) : null;
                    $item = strtoupper($data['item']);
                    $clienteProveedor = !empty($data['cliente_proveedor']) ? strtoupper($data['cliente_proveedor']) : null;
                    $observaciones = !empty($data['observaciones']) ? strtoupper($data['observaciones']) : null;
                    $numeroTransaccion = strtoupper($data['numero_transaccion']);

                    // Determinar el valor de egreso_directo
                    $egresoDirecto = null;
                    if ($data['tipo_de_transaccion'] === 'Egreso') {
                        $egresoDirecto = $data['egreso_directo'] ?? false;
                    }

                    // Crear transacción financiera
                    $transaction = FinancialTransactions::create([
                        'negocio_id' => $data['negocio_id'],
                        'metodo_id' => $data['metodo_id'],
                        'categoria_id' => $data['categoria_id'],
                        'user_id' => $user->id,
                        'vehicle_id' => $data['vehicle_id'],
                        'estado_de_transaccion_id' => $data['estado_de_transaccion_id'],
                        'caja_operativa_id' => $data['caja_operativa_id'],
                        'fecha' => $data['fecha'],
                        'punto_de_partida' => $puntoPartida,
                        'destino' => $destino,
                        'millas' => $data['millas'],
                        'tipo_de_transaccion' => $data['tipo_de_transaccion'],
                        'item' => $item,
                        'cantidad' => $data['cantidad'],
                        'importe_total' => $data['importe_total'],
                        'cliente_proveedor' => $clienteProveedor,
                        'egreso_directo' => $egresoDirecto,
                        'observaciones' => $observaciones,
                        'numero_transaccion' => $numeroTransaccion,
                        'monto_excedido' => 0, // Inicializar monto excedido en 0
                    ]);

                    // Cargar relaciones necesarias
                    $transaction->load([
                        'categoria',
                        'estadoDeTransaccion',
                        'cajaOperativa'
                    ]);

                    // Crear registro en movements_boxes
                    $movementBox = MovementsBox::create([
                        'monto' => $data['importe_total'],
                        'tipo' => strtolower($data['tipo_de_transaccion']),
                        'descripcion' => $item,
                        'fecha_movimiento' => $data['fecha'],
                        'transaccion_financiera_id' => $transaction->id,
                        'user_id' => $user->id,
                        'numero_transaccion' => $numeroTransaccion,
                        'monto_excedido' => 0, // Inicializar monto excedido en 0
                    ]);

                    // Lógica para manejar la caja operativa según el estado de la transacción
                    $estadoTransaccion = $transaction->estadoDeTransaccion;
                    $operatingBoxActualizada = null;
                    $advertencia = null;
                    $excedentePorPagar = 0;
                    $pendingPayment = null;

                    // Verificar si se proporcionó una caja operativa
                    if ($data['caja_operativa_id']) {
                        $cajaOperativa = $transaction->cajaOperativa;
                        $importeTotal = (float) $data['importe_total'];

                        // Si es un egreso y estado "Pagado", descontar de la caja operativa
                        if ($data['tipo_de_transaccion'] === 'Egreso' && $estadoTransaccion->nombre === 'Pagado') {
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
                                    $saldoAnterior,
                                    $cajaOperativa->saldo
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
                                    $warnings[] = $advertencia;
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
                                    $saldoDisponible,
                                    'EGRESO_PARCIAL',
                                    $descripcionHistorial,
                                    $transaction,
                                    $saldoAnterior,
                                    0
                                );

                                // Crear registro en pending_payments si hay monto excedido
                                if ($excedentePorPagar > 0) {
                                    try {
                                        $pendingPayment = \App\Models\PendingPayment::create([
                                            'negocio_id' => $data['negocio_id'],
                                            'driver_id' => null,
                                            'financial_transaction_id' => $transaction->id,
                                            'monto' => $excedentePorPagar,
                                            'descripcion' => "Excedente por pagar de la transacción: {$item}",
                                            'estado' => 'pendiente',
                                            'user_id' => $user->id,
                                        ]);
                                    } catch (\Exception $e) {
                                        $pendingPayment = null;
                                    }
                                }

                                // Establecer una advertencia
                                $advertencia = "ADVERTENCIA: SALDO INSUFICIENTE. SE DESCANTÓ {$saldoDisponible} DE LA CAJA OPERATIVA Y EL EXCEDENTE ({$excedentePorPagar}) QUEDA COMO POR PAGAR.";
                                $warnings[] = $advertencia;

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
                        elseif ($data['tipo_de_transaccion'] === 'Ingreso' && $estadoTransaccion->nombre === 'Pagado') {
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
                                $saldoAnterior,
                                $cajaOperativa->saldo
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
                            // Descripción detallada para el historial
                            $descripcionHistorial = "REEMBOLSO: {$item}";

                            // Registrar movimiento en el historial de caja operativa
                            $this->operatingBoxHistoryService->registrarMovimiento(
                                $cajaOperativa,
                                0,
                                'reembolso',
                                $descripcionHistorial,
                                $transaction,
                                $cajaOperativa->saldo,
                                $cajaOperativa->saldo
                            );

                            if ($cajaOperativa->saldo <= 0) {
                                $advertencia = "ADVERTENCIA: LA CAJA OPERATIVA '{$cajaOperativa->nombre}' SE QUEDÓ SIN SALDO. DEBE REPONERSE FONDOS AL USUARIO.";
                                $warnings[] = $advertencia;
                            }

                            $operatingBoxActualizada = [
                                'id' => $cajaOperativa->id,
                                'nombre' => strtoupper($cajaOperativa->nombre),
                                'saldo_actual' => $cajaOperativa->saldo,
                                'nota' => 'NO SE AFECTÓ EL SALDO PORQUE ES UN REEMBOLSO',
                                'descripcion_historial' => $descripcionHistorial
                            ];
                        }
                    }

                    $importedCount++;
                } catch (\Exception $e) {
                    $importErrors[] = "Fila " . ($index + 2) . ": " . $e->getMessage();
                }
            }

            DB::commit();

            // Preparar respuesta
            $response = [
                'status' => 'success',
                'message' => 'Importación completada',
                'details' => "Se han importado {$importedCount} transacciones financieras.",
                'imported_count' => $importedCount,
            ];

            if (count($importErrors) > 0) {
                $response['status'] = 'warning';
                $response['message'] = 'Importación completada con errores';
                $response['details'] = "Se han importado {$importedCount} transacciones financieras, pero algunas filas tuvieron errores.";
                $response['errors'] = $importErrors;
            }

            if (count($warnings) > 0) {
                $response['warnings'] = $warnings;
            }

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
    } */
