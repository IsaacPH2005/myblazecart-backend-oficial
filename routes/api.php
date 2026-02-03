<?php

use App\Http\Controllers\api\auth\AuthController;
use App\Http\Controllers\api\BusinessController;
use App\Http\Controllers\api\CategoryController;
use App\Http\Controllers\api\Driver\DriverController;
use App\Http\Controllers\api\Driver\DriverDocumentController;
use App\Http\Controllers\api\investments\InvestmentController;
use App\Http\Controllers\api\investments\InvestorDashboardController;
use App\Http\Controllers\api\investments\VehicleFinancialController;
use App\Http\Controllers\api\MovementBox\MovementBoxController;
use App\Http\Controllers\api\OperatingBox\OperatingBoxController;
use App\Http\Controllers\api\OperatingBox\OperatingBoxHistoryController;
use App\Http\Controllers\api\PaymentMethodController;
use App\Http\Controllers\api\PendingPaymentsController;
use App\Http\Controllers\api\RolesAndPermissions\RoleController;
use App\Http\Controllers\api\TransactionFinancial\DashboardFinancial\DatosRelevantesController;
use App\Http\Controllers\api\TransactionFinancial\DashboardFinancial\EgresosPorCategoriaController;
use App\Http\Controllers\api\TransactionFinancial\DashboardFinancial\EstadoDeResultadosController;
use App\Http\Controllers\api\TransactionFinancial\DashboardFinancial\IngresosPorCategoriaController;
use App\Http\Controllers\api\TransactionFinancial\DashboardFinancial\IngresosPorNegocioController;
use App\Http\Controllers\api\TransactionFinancial\DashboardFinancial\NegocioConMayorEgresosController;
use App\Http\Controllers\api\TransactionFinancial\DashboardFinancial\RendicionCajaOperativasController;
use App\Http\Controllers\api\TransactionFinancial\DashboardFinancial\VehiculosDelNegocioController;
use App\Http\Controllers\api\TransactionFinancial\DriverFinancialTransactionController;
use App\Http\Controllers\api\TransactionFinancial\FinancialReportController;
use App\Http\Controllers\api\TransactionFinancial\FinancialStatementController;
use App\Http\Controllers\api\TransactionFinancial\FinancialTransactionController;
use App\Http\Controllers\api\TransactionFinancial\ImportExcelController;
use App\Http\Controllers\api\TransactionStateController;
use App\Http\Controllers\api\Users\ProfileController;
use App\Http\Controllers\api\Users\UserController;
use App\Http\Controllers\api\Vehicles\VehicleController;
use App\Http\Controllers\api\Vehicles\VehicleDocumentController;
use App\Http\Controllers\api\Vehicles\VehicleMaintenanceController;
use App\Http\Controllers\TimelineController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
*/

/*
|--------------------------------------------------------------------------
| Rutas de Autenticación (Públicas)
|--------------------------------------------------------------------------
|
*/

Route::prefix('auth')->group(function () {
    Route::post('/login', [AuthController::class, 'login']);
    Route::post('/logout', [AuthController::class, 'logout'])->middleware('auth:sanctum');
});

/*
|--------------------------------------------------------------------------
| Rutas Protegidas (Requieren Autenticación Sanctum)
|--------------------------------------------------------------------------
|
*/
Route::middleware('auth:sanctum')->group(function () {

    // Rutas de perfil de usuario (requieren autenticación)
    // Rutas de perfil
    Route::prefix('profile')->group(function () {
        Route::get('/', [ProfileController::class, 'show']);
        Route::get('/form-data', [ProfileController::class, 'getProfileFormData']);
        Route::put('/', [ProfileController::class, 'update']);
        Route::post('/change-password', [ProfileController::class, 'changePassword']);
    });

    /*
    |--------------------------------------------------------------------------
    | Rutas de Administrador
    |--------------------------------------------------------------------------
    | Solo accesibles por usuarios con rol de admin
    |
    */
    Route::middleware(['admin'])->group(function () {
        Route::prefix('investments-admin')->group(function () {
            Route::get('/', [InvestmentController::class, 'index']);
            Route::get('/create', [InvestmentController::class, 'create']);
            Route::get('/statistics', [InvestmentController::class, 'statistics']);
            Route::post('/', [InvestmentController::class, 'store']);
            Route::get('/user/{userId}', [InvestmentController::class, 'byUser']);
            Route::get('/{id}', [InvestmentController::class, 'show']);
            Route::put('/{id}', [InvestmentController::class, 'update']);
            Route::delete('/{id}', [InvestmentController::class, 'destroy']);
            Route::patch('/{id}/status', [InvestmentController::class, 'changeStatus']);
            Route::patch('/{id}/toggle-active', [InvestmentController::class, 'toggleActive']);
        });

        Route::prefix('users')->group(function () {
            // Ruta para obtener usuarios que no son conductores
            Route::get('/not-drivers', [DriverController::class, 'getUsersNotDrivers']);
            Route::get('/', [UserController::class, 'index']);
            Route::post('/', [UserController::class, 'store']);
            Route::get('/actives', [UserController::class, 'getActiveUsers']);
            Route::get('/{id}/complete', [UserController::class, 'getCompleteUserData']);
            Route::put('/{id}/password', [UserController::class, 'updatePassword']);
            Route::get('/{id}', [UserController::class, 'show']);
            Route::put('/{id}', [UserController::class, 'update']);
            Route::delete('/{id}', [UserController::class, 'destroy']);
            Route::get('/{id}/with-password', [UserController::class, 'getUser WithPassword']);
        });
        // Rutas para roles
        Route::prefix('roles')->group(function () {
            Route::get('/', [RoleController::class, 'index']);
            Route::get('/with-permissions', [RoleController::class, 'rolesWithPermissions']);
            Route::get('/names', [RoleController::class, 'roleNames']);
            Route::get('/stats', [RoleController::class, 'stats']);
            Route::get('/{id}', [RoleController::class, 'show']);
            Route::get('/{id}/permissions', [RoleController::class, 'showWithPermissions']);
        });

        Route::prefix('permissions')->controller(RoleController::class)->group(function () {
            Route::get('/', 'permissions');
        });

        Route::prefix('drivers')->group(function () {
            Route::get('/state-actives', [DriverController::class, 'getSimpleActiveDrivers']);
            Route::get('/', [DriverController::class, 'index']);
            Route::get('/{id}/info', [DriverController::class, 'getDriverInfo']);
            Route::get('/{id}', [DriverController::class, 'show']);
            Route::post('/', [DriverController::class, 'store']);
            Route::post('/with-user', [DriverController::class, 'createUserWithDriver']);
            Route::put('/{id}', [DriverController::class, 'update']);
            Route::delete('/{id}', [DriverController::class, 'destroy']);
            /* Route::get('/drivers-actives', 'getActiveDriversBasic'); */
            Route::post('/{id}/activate', [DriverController::class, 'activate']);
            Route::post('/{id}/desactivate', [DriverController::class, 'desactivate']);
        });

        Route::prefix('driver-documents')->group(function () {
            Route::get('/', [DriverDocumentController::class, 'index']);
            Route::post('/', [DriverDocumentController::class, 'store']);
            Route::get('/tipos', [DriverDocumentController::class, 'getTipos']);
            Route::put('/{id}/approve', [DriverDocumentController::class, 'approve']);
            Route::put('/{id}/reject', [DriverDocumentController::class, 'reject']);
            Route::get('/{driverId}', [DriverDocumentController::class, 'show']);
            Route::put('/{id}', [DriverDocumentController::class, 'update']);
            Route::delete('/{id}', [DriverDocumentController::class, 'destroy']);
            Route::get('/{id}/download', [DriverDocumentController::class, 'download']);
        });

        Route::prefix('vehicles')->group(function () {
            // Para API
            Route::get('/estado-activos', [VehicleController::class, 'obtenerVehiculosActivos']);
            Route::get('/', [VehicleController::class, 'index']);
            Route::get('/active', [VehicleController::class, 'getActiveVehicles']);
            Route::get('/active/simple', [VehicleController::class, 'getSimpleActiveVehicles']);
            Route::get('/{id}', [VehicleController::class, 'show']);
            Route::post('/', [VehicleController::class, 'store']);
            Route::post('/create-user', [VehicleController::class, 'createUserWithVehicle']);
            Route::put('/{id}', [VehicleController::class, 'update']);
            Route::delete('/{id}', [VehicleController::class, 'destroy']);
            Route::post('/{id}/activate', [VehicleController::class, 'activate']);
            Route::post('/{id}/desactivate', [VehicleController::class, 'desactivate']);
        });

        Route::prefix('vehicle-documents')->group(function () {
            Route::get('/', [VehicleDocumentController::class, 'index']);
            Route::get('/{id}', [VehicleDocumentController::class, 'show']); // Mostrar un documento específico
            /*  Route::get('/vehicle/{vehicleId}', [VehicleDocumentController::class, 'showByVehicle']); // Mostrar documentos de un vehículo */
            Route::post('/', [VehicleDocumentController::class, 'store']);
            Route::put('/{id}', [VehicleDocumentController::class, 'update']);
            Route::delete('/{id}', [VehicleDocumentController::class, 'destroy']);
            Route::get('/download/{id}', [VehicleDocumentController::class, 'download']);
        });

        // Rutas para mantenimientos de vehículos
        Route::prefix('vehicle-maintenances')->group(function () {
            // Obtener todos los mantenimientos
            Route::get('/', [VehicleMaintenanceController::class, 'index']);

            // Obtener mantenimientos activos (pendientes o atrasados)
            Route::get('/active', [VehicleMaintenanceController::class, 'getActiveMaintenances']);

            // Obtener mantenimientos de un vehículo específico
            Route::get('/vehicle/{vehicleId}', [VehicleMaintenanceController::class, 'show']);

            // Crear un nuevo mantenimiento
            Route::post('/', [VehicleMaintenanceController::class, 'store']);

            // Actualizar un mantenimiento existente
            Route::put('/{id}', [VehicleMaintenanceController::class, 'update']);

            // Eliminar un mantenimiento
            Route::delete('/{id}', [VehicleMaintenanceController::class, 'destroy']);

            // Descargar documento de mantenimiento
            Route::get('/download/{id}', [VehicleMaintenanceController::class, 'download']);
        });
        // Rutas para estados de transacción
        Route::prefix('transaction-states-admin')->group(function () {
            Route::get('/', [TransactionStateController::class, 'index']);
            /*   Route::get('/actives', [TransactionStateController::class, 'actives']); */
            Route::get('/{id}', [TransactionStateController::class, 'show']);
            Route::post('/', [TransactionStateController::class, 'store']);
            Route::put('/{id}', [TransactionStateController::class, 'update']);
            Route::delete('/{id}', [TransactionStateController::class, 'destroy']);
            Route::put('/{id}/activate', [TransactionStateController::class, 'activate']);
            Route::put('/{id}/deactivate', [TransactionStateController::class, 'deactivate']);
        });
        Route::get('/transaction-financial', [FinancialTransactionController::class, 'index']);
        Route::post('/transaction-financial/admin', [FinancialTransactionController::class, 'store']);
        Route::get('/transactions/export', [FinancialTransactionController::class, 'export']);
        Route::get('/transaction-financial/{id}', [FinancialTransactionController::class, 'show']);
        Route::put('/transaction-financial/{id}', [FinancialTransactionController::class, 'update']);
        Route::delete('/transaction-financial/{id}', [FinancialTransactionController::class, 'destroy']);
        Route::put('/transaction-financial/{id}/state', [FinancialTransactionController::class, 'updateTransactionState']);
        // Rutas para importación y exportación de transacciones financieras
        Route::post('/transactions/import', [ImportExcelController::class, 'import']);
        Route::get('/transactions/plantilla', [ImportExcelController::class, 'descargarPlantilla']);




        // Rutas para FinancialReportController
        Route::prefix('financial-report')->group(function () {
            // Obtener estado financiero de todos los negocios
            Route::get('get-all-businesses-financial-statement', [FinancialReportController::class, 'getAllBusinessesFinancialStatement']);

            // Exportar estado financiero de todos los negocios a Excel
            Route::get('export-all-businesses-financial-statement-to-excel', [FinancialReportController::class, 'exportAllBusinessesFinancialStatementToExcel']);

            // Obtener egresos por categoría filtrados por negocio y rango de fechas
            Route::get('get-expenses-by-category-by-business', [FinancialReportController::class, 'getExpensesByCategoryByBusiness']);

            // Exportar egresos por categoría a Excel
            Route::get('export-expenses-by-category-by-business-to-excel', [FinancialReportController::class, 'exportExpensesByCategoryByBusinessToExcel']);

            // Obtener el negocio con mayor egreso en un período
            Route::get('get-business-with-highest-expense', [FinancialReportController::class, 'getBusinessWithHighestExpense']);

            // Exportar negocio con mayor egreso a Excel
            Route::get('export-business-with-highest-expense-to-excel', [FinancialReportController::class, 'exportBusinessWithHighestExpenseToExcel']);

            // Obtener el negocio Lease On, sus vehículos y los egresos de cada vehículo
            Route::get('get-lease-on-vehicles-with-expenses', [FinancialReportController::class, 'getLeaseOnVehiclesWithExpenses']);

            // Obtener estado de resultados filtrado por negocio y rango de fechas
            Route::post('/statement-range', [EstadoDeResultadosController::class, 'getFinancialStatementByDateRange']);
            Route::post('/export-excel', [EstadoDeResultadosController::class, 'exportToExcel']);
            Route::post('/export-pdf', [EstadoDeResultadosController::class, 'exportToPDF']);
            Route::post('/preview-pdf', [EstadoDeResultadosController::class, 'previewPDF']);

            // Obtener egresos por categoría filtrados por negocio y rango de fechas
            Route::get('/expenses-by-category', [EgresosPorCategoriaController::class, 'getExpensesByCategoryByBusiness']);

            // Obtener datos
            Route::get('/expenses-by-category', [EgresosPorCategoriaController::class, 'getExpensesByCategoryByBusiness']);
            Route::get('/incomes-by-category', [EgresosPorCategoriaController::class, 'getIncomesByCategoryByBusiness']); // Si lo necesitas

            // Exportar a Excel
            Route::get('/export-expenses-excel', [EgresosPorCategoriaController::class, 'exportExpensesToExcel']);
            Route::get('/export-incomes-excel', [EgresosPorCategoriaController::class, 'exportIncomesToExcel']);


            // Ingresos por Negocio
            Route::get('/incomes-by-business', [IngresosPorNegocioController::class, 'getIncomesByBusiness']);
            Route::get('/export-incomes-by-business-excel', [IngresosPorNegocioController::class, 'exportIncomesByBusinessToExcel']);
            Route::get('/export-incomes-by-business-pdf', [IngresosPorNegocioController::class, 'exportIncomesByBusinessToPDF']);

            // Obtener vehículos por negocio
            Route::get('/vehicles-by-business', [IngresosPorNegocioController::class, 'getVehiclesByBusiness']);

            // Negocio con Mayor Egreso
            Route::get('/business-highest-expense', [NegocioConMayorEgresosController::class, 'getBusinessWithHighestExpense']);
            Route::get('/vehicles-by-business-expense', [NegocioConMayorEgresosController::class, 'getVehiclesByBusiness']);






            //Obtener estado financiero de vehículos de un negocio
            Route::get('/vehicles-financial-statement', [VehiculosDelNegocioController::class, 'getVehiclesFinancialStatementByBusiness']);
            Route::get('/vehicle-financial-statement', [VehiculosDelNegocioController::class, 'getVehicleFinancialStatement']);
            Route::get('/by-business', [VehiculosDelNegocioController::class, 'getVehiclesByBusiness']);






            //Obtener   rendición de cajas operativas
            Route::get('/rendicion-cajas-operativas', [RendicionCajaOperativasController::class, 'resumenCajasOperativas']);
            // Nuevas rutas para exportación
            Route::get('/rendicion-cajas-operativas/excel', [RendicionCajaOperativasController::class, 'exportarExcel']);
            Route::get('/rendicion-cajas-operativas/pdf', [RendicionCajaOperativasController::class, 'exportarPDF']);

            //Obtener datos relevantes del dashboard
            // Obtener vehículos por negocio
            Route::post('/vehicles-by-business', [DatosRelevantesController::class, 'getVehiclesByBusiness']);

            // Obtener datos relevantes del dashboard
            Route::post('/operation', [DatosRelevantesController::class, 'getOperationReport']);
            Route::post('/operation-summary', [DatosRelevantesController::class, 'getOperationSummary']);
            Route::post('/daily-productivity', [DatosRelevantesController::class, 'getDailyProductivity']);

            // Comparar vehículos de un negocio
            Route::post('/compare-vehicles', [DatosRelevantesController::class, 'compareVehicles']);

            // Exportar reportes
            Route::post('/export-excel', [DatosRelevantesController::class, 'exportToExcel']);
            Route::post('/export-excel-fiscal', [FinancialTransactionController::class, 'exportFiscal']);
        });


        Route::get('/transaction-financial-report/lease-on-vehicles', [FinancialReportController::class, 'getLeaseOnVehiclesWithExpenses']);
        Route::get('/transaction-financial-report/all-businesses-financial-statement', [FinancialReportController::class, 'getAllBusinessesFinancialStatement']);

        Route::get('movements-boxes', [MovementBoxController::class, 'index']);
        Route::get('movements-boxes/resumen-por-categoria', [MovementBoxController::class, 'resumenPorCategoria']);
        Route::get('movements-boxes/recientes', [MovementBoxController::class, 'recientes']);
        Route::get('movements-boxes/{id}', [MovementBoxController::class, 'show']);


        Route::apiResource('operating-boxes', OperatingBoxController::class);
        Route::post('operating-boxes/{id}/activate', [OperatingBoxController::class, 'activate']);
        Route::delete('/operating-boxes/{id}/delete-permanent', [OperatingBoxController::class, 'deletePermanent']);

        Route::apiResource('categories', CategoryController::class);
        Route::post('/categories-excel/import', [CategoryController::class, 'import']);
        Route::get('/categories-excel/template', [CategoryController::class, 'descargarPlantilla']);
        Route::get('/categories/activas', [CategoryController::class, 'activas']);
        Route::post('/categories/{id}/activate', [CategoryController::class, 'activate']);

        Route::get('operating-boxes/{id}/history', [OperatingBoxHistoryController::class, 'getHistory']);
        Route::post('/operating-box-history/{historyId}/pay-refund', [OperatingBoxHistoryController::class, 'payRefundTransaction']);


        Route::prefix('business')->group(function () {
            Route::get('/state-actives', [BusinessController::class, 'businessActives']);
            Route::get('/', [BusinessController::class, 'index']);
            /* Route::get('/actives', [BusinessController::class, 'businessActives']); */
            Route::post('/', [BusinessController::class, 'store']);
            Route::get('/{id}', [BusinessController::class, 'show']);
            Route::put('/{id}', [BusinessController::class, 'update']);
            Route::patch('/{id}', [BusinessController::class, 'update']);
            Route::delete('/{id}', [BusinessController::class, 'destroy']);
            // Nuevas rutas para activar/desactivar
            Route::post('/{id}/activate', [BusinessController::class, 'activate']);
            Route::post('/{id}/desactivate', [BusinessController::class, 'deactivate']);
        });

        Route::prefix('payment-method')->group(function () {
            Route::get('/actives', [PaymentMethodController::class, 'paymentMethodsActives']);
            Route::get('/', [PaymentMethodController::class, 'index']);
            /* Route::get('/actives', [PaymentMethodController::class, 'paymentMethodsActives']); */
            Route::post('/', [PaymentMethodController::class, 'store']);
            Route::get('/{id}', [PaymentMethodController::class, 'show']);
            Route::put('/{id}', [PaymentMethodController::class, 'update']);
            Route::delete('/{id}', [PaymentMethodController::class, 'destroy']);
            Route::post('/{id}/activate', [PaymentMethodController::class, 'activate']);
            Route::post('/{id}/desactivate', [PaymentMethodController::class, 'desactivate']);
        });

        Route::get('pending-payments', [PendingPaymentsController::class, 'index']);
        Route::post('pending-payments/{id}/process', [PendingPaymentsController::class, 'processPayment']);
        Route::post('pending-payments/{id}/cancel', [PendingPaymentsController::class, 'cancelPayment']);


        Route::get('/timeline/my', [TimelineController::class, 'myTimeline']);
        Route::get('/timeline/user/{userId}', [TimelineController::class, 'userTimeline'])->where('userId', '[0-9]+');
        Route::get('/timeline/business/{businessId}', [TimelineController::class, 'businessTimeline'])->where('businessId', '[0-9]+');
        Route::get('/timeline/{id}', [TimelineController::class, 'show'])->where('id', '[0-9]+');
        Route::post('/timeline', [TimelineController::class, 'store']);
        Route::put('/timeline/{id}', [TimelineController::class, 'update'])->where('id', '[0-9]+');
        Route::post('/timeline/{id}', [TimelineController::class, 'update'])->where('id', '[0-9]+');
        Route::delete('/timeline/{id}', [TimelineController::class, 'destroy'])->where('id', '[0-9]+');
        Route::post('/timeline/reordenar', [TimelineController::class, 'reordenar']);
        Route::post('/timeline/filtrar/estado', [TimelineController::class, 'filtrarPorEstado']);
        Route::post('/timeline/filtrar/fechas', [TimelineController::class, 'filtrarPorFechas']);
        Route::post('/timeline/estadisticas', [TimelineController::class, 'estadisticas']);
    });

    /*
    |--------------------------------------------------------------------------
    | Rutas de Transportista (Carrier)
    |--------------------------------------------------------------------------
    | Solo accesibles por usuarios con rol de carrier
    |
    */
    Route::middleware(['carrier'])->group(function () {
        Route::prefix('transaction-financial-driver')->group(function () {
            Route::get('/payment-pendings', [DriverFinancialTransactionController::class, 'indexPaymentPendingDriver']);


            Route::post('/new/driver', [DriverFinancialTransactionController::class, 'storeAuthDriver']);
            Route::get('/user-transactions', [DriverFinancialTransactionController::class, 'getUserTransactions']);
            Route::get('/driver/dashboard', [DriverFinancialTransactionController::class, 'getDriverDashboardData']);
            Route::get('/excel-driver', [DriverFinancialTransactionController::class, 'exportToExcelDriverTransaccion']);
        });
        Route::prefix('business-driver')->group(function () {
            Route::get('/state-actives-driver', [BusinessController::class, 'businessActives']);
        });
        Route::prefix('payment-method-driver')->group(function () {
            Route::get('/state-actives-driver', [PaymentMethodController::class, 'paymentMethodsActives']);
        });
    });
    Route::middleware(['auth:sanctum', 'inversionista'])->group(function () {

        // Dashboard y datos principales del inversionista
        Route::get('/dashboard', [InvestorDashboardController::class, 'index']);
        Route::get('/my-investments', [InvestorDashboardController::class, 'myInvestments']);
        Route::get('/my-businesses', [InvestorDashboardController::class, 'myBusinesses']);
        Route::get('/business/{businessId}/details', [InvestorDashboardController::class, 'businessDetails']);
        Route::get('/business/{businessId}/financial-statement', [InvestorDashboardController::class, 'businessFinancialStatement']);
        Route::get('/business/{businessId}/transactions', [InvestorDashboardController::class, 'businessTransactions']);
        // Vehículos financieros para inversionista
        Route::get('/vehicles-financial/business', [VehicleFinancialController::class, 'getVehiclesFinancialStatementByBusiness']);
        Route::get('/vehicles-financial/vehicle', [VehicleFinancialController::class, 'getVehicleFinancialStatement']);
        Route::get('/vehicles-financial/vehicle/{vehicleId}/transactions', [VehicleFinancialController::class, 'getVehicleTransactions']);
        Route::get('/vehicles-financial/performance-summary', [VehicleFinancialController::class, 'getVehiclesPerformanceSummary']);
        Route::get('/vehicles-financial/export/excel', [VehicleFinancialController::class, 'exportToExcel']);
        Route::get('/vehicles-financial/export/pdf', [VehicleFinancialController::class, 'exportToPDF']);
    });

    /*
    |--------------------------------------------------------------------------
    | Rutas Comunes (Admin y Carrier)
    |--------------------------------------------------------------------------
    | Accesibles por ambos roles
    |
    */
    Route::middleware(['role:admin|carrier'])->group(function () {
        Route::prefix('vehicles')->group(function () {
            Route::get('/autheticated/user', [VehicleController::class, 'getAuthenticatedUserVehicles']);
        });

        Route::get('/user', function (Request $request) {
            return response()->json([
                'success' => true,
                'message' => 'Usuario autenticado obtenido exitosamente',
                'data' => $request->user()
            ]);
        });
    });
});


/*
|--------------------------------------------------------------------------
| Rutas Públicas (Sin autenticación)
|--------------------------------------------------------------------------
|
*/
Route::get('/operating-box/active', [OperatingBoxController::class, 'boxActives']);
Route::prefix('category')->group(function () {
    Route::get('/actives', [CategoryController::class, 'categoryActives']);
});

Route::prefix('transaction-states')->group(function () {
    Route::get('/actives', [TransactionStateController::class, 'transactionStateActives']);
});

/*
|--------------------------------------------------------------------------
| Ruta de Health Check
|--------------------------------------------------------------------------
|
*/
Route::get('/health', function () {
    return response()->json([
        'success' => true,
        'message' => 'API funcionando correctamente',
        'timestamp' => now(),
        'version' => '1.0.0'
    ]);
});
