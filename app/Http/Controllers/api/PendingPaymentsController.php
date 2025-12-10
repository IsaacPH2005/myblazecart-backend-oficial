<?php

namespace App\Http\Controllers\api;

use App\Http\Controllers\Controller;
use App\Models\FinancialTransactions;
use App\Models\MovementsBox;
use App\Models\OperatingBox;
use App\Models\PendingPayment;
use App\Services\OperatingBoxHistoryService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class PendingPaymentsController extends Controller
{
    protected $operatingBoxHistoryService;

    public function __construct(OperatingBoxHistoryService $operatingBoxHistoryService)
    {
        $this->operatingBoxHistoryService = $operatingBoxHistoryService;
    }

    /**
     * Listar todos los pagos
     */
    public function index(Request $request)
    {
        try {
            $user = Auth::user();

            // Verificar que sea administrador
            if (!$user->hasRole('admin')) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'PERMISOS INSUFICIENTES',
                    'details' => 'SOLO LOS ADMINISTRADORES PUEDEN VER ESTA INFORMACIÓN'
                ], 403);
            }

            $query = PendingPayment::with([
                'negocio',
                'driver.user.generalData',
                'financialTransaction',
                'user.generalData'
            ]);

            // Filtros aplicables para administradores
            if ($request->filled('estado')) {
                $query->where('estado', $request->estado);
            }
            if ($request->filled('negocio_id')) {
                $query->where('negocio_id', $request->negocio_id);
            }
            if ($request->filled('driver_id')) {
                $query->where('driver_id', $request->driver_id);
            }
            if ($request->filled('fecha_desde')) {
                $query->whereDate('created_at', '>=', $request->fecha_desde);
            }
            if ($request->filled('fecha_hasta')) {
                $query->whereDate('created_at', '<=', $request->fecha_hasta);
            }

            $pagos = $query->orderBy('created_at', 'desc')
                ->paginate($request->per_page ?? 15);

            return response()->json([
                'status' => 'success',
                'message' => 'PAGOS OBTENIDOS CORRECTAMENTE',
                'data' => $pagos
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'ERROR EN EL SERVIDOR',
                'details' => 'HA OCURRIDO UN ERROR AL OBTENER LOS PAGOS',
                'technical_error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Procesar un pago pendiente
     */
    public function processPayment(Request $request, $id)
    {
        try {
            $user = Auth::user();

            // Verificar permisos
            if (!$user->hasRole(['admin'])) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'PERMISOS INSUFICIENTES',
                    'details' => 'SOLO LOS ADMINISTRADORES PUEDEN PROCESAR PAGOS PENDIENTES'
                ], 403);
            }

            $pagoPendiente = PendingPayment::findOrFail($id);

            // Verificar que esté pendiente
            if ($pagoPendiente->estado !== 'pendiente') {
                return response()->json([
                    'status' => 'error',
                    'message' => 'PAGO NO PENDIENTE',
                    'details' => 'ESTE PAGO YA HA SIDO PROCESADO O CANCELADO'
                ], 422);
            }

            DB::beginTransaction();

            // Obtener la caja operativa del negocio
            $cajaOperativa = OperatingBox::where('negocio_id', $pagoPendiente->negocio_id)
                ->where('estado', true)
                ->first();

            // Si no hay caja operativa activa para el negocio, buscar cualquier caja operativa activa
            if (!$cajaOperativa) {
                $cajaOperativa = OperatingBox::where('estado', true)->first();
            }

            // Si todavía no hay caja operativa, crear una para el negocio
            if (!$cajaOperativa) {
                $cajaOperativa = OperatingBox::create([
                    'negocio_id' => $pagoPendiente->negocio_id,
                    'nombre' => 'Caja Operativa - ' . $pagoPendiente->negocio->nombre,
                    'saldo' => 0,
                    'descripcion' => 'Caja operativa creada automáticamente para procesar pagos pendientes',
                    'estado' => true,
                    'user_id' => $user->id,
                ]);
            }

            // Verificar saldo suficiente
            if ($cajaOperativa->saldo < $pagoPendiente->monto) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'SALDO INSUFICIENTE',
                    'details' => 'LA CAJA OPERATIVA NO TIENE SUFICIENTE SALDO PARA PROCESAR ESTE PAGO. SALDO ACTUAL: ' . $cajaOperativa->saldo . ', MONTO REQUERIDO: ' . $pagoPendiente->monto
                ], 422);
            }

            // Actualizar saldo de la caja operativa
            $saldoAnterior = $cajaOperativa->saldo;
            $cajaOperativa->saldo -= $pagoPendiente->monto;
            $cajaOperativa->save();

            // Obtener la transacción financiera original asociada al pago pendiente
            $transaction = FinancialTransactions::find($pagoPendiente->financial_transaction_id);

            if ($transaction) {
                // Actualizar el monto excedido a 0 en la transacción original
                $transaction->monto_excedido = 0;
                $transaction->save();

                // Actualizar el monto excedido a 0 en el movimiento de caja asociado
                $movimientoCaja = MovementsBox::where('transaccion_financiera_id', $transaction->id)->first();
                if ($movimientoCaja) {
                    $movimientoCaja->monto_excedido = 0;
                    $movimientoCaja->save();
                }
            }

            // Registrar movimiento en el historial de caja operativa
            $this->operatingBoxHistoryService->registrarMovimiento(
                $cajaOperativa,
                $pagoPendiente->monto,
                'EGRESO',
                'PAGO PENDIENTE PROCESADO ID: ' . $pagoPendiente->id,
                $transaction, // Pasar la transacción original o null si no existe
                $saldoAnterior,
                $cajaOperativa->saldo
            );

            // Actualizar estado del pago pendiente
            $pagoPendiente->estado = 'pagado';
            $pagoPendiente->fecha_pago = now();
            $pagoPendiente->save();

            DB::commit();

            return response()->json([
                'status' => 'success',
                'message' => 'PAGO PROCESADO CORRECTAMENTE',
                'data' => [
                    'pending_payment' => $pagoPendiente,
                    'transaction' => $transaction,
                    'operating_box' => [
                        'id' => $cajaOperativa->id,
                        'nombre' => $cajaOperativa->nombre,
                        'saldo_anterior' => $saldoAnterior,
                        'saldo_actual' => $cajaOperativa->saldo
                    ]
                ]
            ], 200);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'status' => 'error',
                'message' => 'ERROR EN EL SERVIDOR',
                'details' => 'HA OCURRIDO UN ERROR AL PROCESAR EL PAGO',
                'technical_error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Cancelar un pago pendiente
     */
    public function cancelPayment(Request $request, $id)
    {
        try {
            $user = Auth::user();

            // Verificar permisos
            if (!$user->hasRole(['admin'])) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'PERMISOS INSUFICIENTES',
                    'details' => 'SOLO LOS ADMINISTRADORES PUEDEN CANCELAR PAGOS PENDIENTES'
                ], 403);
            }

            $pagoPendiente = PendingPayment::findOrFail($id);

            // Verificar que esté pendiente
            if ($pagoPendiente->estado !== 'pendiente') {
                return response()->json([
                    'status' => 'error',
                    'message' => 'PAGO NO PENDIENTE',
                    'details' => 'ESTE PAGO YA HA SIDO PROCESADO O CANCELADO'
                ], 422);
            }

            // Actualizar estado del pago pendiente
            $pagoPendiente->estado = 'cancelado';
            $pagoPendiente->save();

            return response()->json([
                'status' => 'success',
                'message' => 'PAGO CANCELADO CORRECTAMENTE',
                'data' => [
                    'pending_payment' => $pagoPendiente
                ]
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'ERROR EN EL SERVIDOR',
                'details' => 'HA OCURRIDO UN ERROR AL CANCELAR EL PAGO',
                'technical_error' => $e->getMessage()
            ], 500);
        }
    }
}
