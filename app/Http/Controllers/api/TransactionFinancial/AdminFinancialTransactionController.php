<?php

namespace App\Http\Controllers\api\TransactionFinancial;

use App\Http\Controllers\Controller;
use App\Models\FinancialTransactions;
use App\Models\MovementsBox;
use App\Services\OperatingBoxHistoryService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class AdminFinancialTransactionController extends Controller
{
    protected $operatingBoxHistoryService;

    public function __construct(OperatingBoxHistoryService $operatingBoxHistoryService)
    {
        $this->operatingBoxHistoryService = $operatingBoxHistoryService;
    }
}
