<?php

use App\Http\Controllers\api\TransactionFinancial\ImportExcelController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});
Route::middleware(['auth'])->group(function () {
    Route::get('/transactions/plantilla', [ImportExcelController::class, 'descargarPlantilla'])
        ->name('transactions.plantilla');
});
