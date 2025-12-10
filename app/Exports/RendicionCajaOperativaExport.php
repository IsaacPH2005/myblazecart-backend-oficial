<?php

namespace App\Exports;

use Illuminate\Contracts\View\View;
use Maatwebsite\Excel\Concerns\FromView;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;

class RendicionCajaOperativaExport implements FromView, ShouldAutoSize
{
    protected $datos;

    public function __construct($datos)
    {
        $this->datos = $datos;
    }

    public function view(): View
    {
        return view('exports.rendicion_caja_operativa', [
            'datos' => $this->datos
        ]);
    }
}
