<?php

namespace App\Exports;

use App\Models\FinancialTransactions;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Concerns\FromView;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;

class IncomesByBusinessExport implements FromView, ShouldAutoSize, WithStyles, WithColumnFormatting
{
    protected $request;

    public function __construct(Request $request)
    {
        $this->request = $request;
    }

    public function view(): View
    {
        $negocioId = $this->request->input('negocio_id');
        $fechaInicial = $this->request->input('fecha_inicial');
        $fechaFinal = $this->request->input('fecha_final');

        // Construir consulta base para ingresos
        $query = FinancialTransactions::where('tipo_de_transaccion', 'Ingreso')
            ->whereBetween('fecha', [$fechaInicial, $fechaFinal])
            ->with(['categoria', 'negocio']);

        // Aplicar filtro de negocio si se especificó
        if ($negocioId) {
            $query->where('negocio_id', $negocioId);
        }

        // Obtener los ingresos agrupados por negocio
        $ingresos = $query->get()
            ->groupBy('negocio_id')
            ->map(function ($negocioGroup, $negocioId) {
                $negocio = $negocioGroup->first()->negocio;

                // Agrupar por categoría dentro de cada negocio
                $categorias = $negocioGroup->groupBy('categoria_id')
                    ->map(function ($categoriaGroup) {
                        $categoria = $categoriaGroup->first()->categoria;

                        return [
                            'categoria_id' => $categoria->id,
                            'categoria_nombre' => $categoria->nombre ?? 'Sin categoría',
                            'total_ingresos' => $categoriaGroup->sum('importe_total'),
                            'cantidad_transacciones' => $categoriaGroup->count(),
                            'promedio_ingreso' => $categoriaGroup->count() > 0 ? $categoriaGroup->avg('importe_total') : 0,
                        ];
                    });

                return [
                    'negocio_id' => $negocioId,
                    'negocio_nombre' => $negocio->nombre ?? 'Sin negocio',
                    'total_ingresos' => $negocioGroup->sum('importe_total'),
                    'cantidad_transacciones' => $negocioGroup->count(),
                    'promedio_ingreso' => $negocioGroup->count() > 0 ? $negocioGroup->avg('importe_total') : 0,
                    'categorias' => $categorias->values()->all()
                ];
            });

        // Calcular totales globales
        $totalGlobal = $query->sum('importe_total');
        $cantidadGlobal = $query->count();

        // Obtener todos los negocios para mostrar incluso los que no tienen ingresos
        $todosNegocios = \App\Models\Business::all()
            ->map(function ($negocio) use ($ingresos) {
                $negocioIngresos = $ingresos->firstWhere('negocio_id', $negocio->id);

                return [
                    'negocio_id' => $negocio->id,
                    'negocio_nombre' => $negocio->nombre,
                    'total_ingresos' => $negocioIngresos ? $negocioIngresos['total_ingresos'] : 0,
                    'cantidad_transacciones' => $negocioIngresos ? $negocioIngresos['cantidad_transacciones'] : 0,
                    'promedio_ingreso' => $negocioIngresos ? $negocioIngresos['promedio_ingreso'] : 0,
                    'categorias' => $negocioIngresos ? $negocioIngresos['categorias'] : []
                ];
            });

        // Obtener información del negocio si se especificó
        $negocio = null;
        if ($negocioId) {
            $negocio = \App\Models\Business::find($negocioId);
        }

        return view('exports.incomes_by_business_pdf', [
            'periodo' => [
                'fecha_inicial' => $fechaInicial,
                'fecha_final' => $fechaFinal,
                'dias' => \Carbon\Carbon::parse($fechaInicial)->diffInDays(\Carbon\Carbon::parse($fechaFinal)) + 1
            ],
            'negocio' => $negocio,
            'resumen_global' => [
                'total_ingresos' => $totalGlobal,
                'cantidad_transacciones' => $cantidadGlobal,
                'promedio_ingreso' => $cantidadGlobal > 0 ? $totalGlobal / $cantidadGlobal : 0
            ],
            'negocios' => $todosNegocios,
            'estadisticas_adicionales' => [
                'negocio_mayor_ingreso' => $ingresos->sortByDesc(function ($negocio) {
                    return $negocio['total_ingresos'];
                })->first(),
                'negocio_menor_ingreso' => $ingresos->sortBy(function ($negocio) {
                    return $negocio['total_ingresos'];
                })->first(),
                'distribucion_porcentual' => $this->getDistribucionPorcentualIngresos($ingresos, $totalGlobal)
            ]
        ]);
    }

    public function styles(Worksheet $sheet)
    {
        // Estilo para el encabezado
        $sheet->getStyle('1')->getFont()->setBold(true);
        $sheet->getStyle('1')->getFill()
            ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
            ->getStartColor()->setARGB('FF4F81BD');
        $sheet->getStyle('1')->getFont()->getColor()->setARGB('FFFFFFFF');

        // Estilo para la fila de resumen global
        $sheet->getStyle('2')->getFont()->setBold(true);
        $sheet->getStyle('2')->getFill()
            ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
            ->getStartColor()->setARGB('FF9BC2E6');

        // Estilo para la fila de estadísticas adicionales
        $sheet->getStyle('3')->getFont()->setBold(true);
        $sheet->getStyle('3')->getFill()
            ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
            ->getStartColor()->setARGB('FFC6E0B4');

        // Ajustar altura de filas
        $sheet->getDefaultRowDimension()->setRowHeight(20);
        $sheet->getRowDimension(1)->setRowHeight(25);
        $sheet->getRowDimension(2)->setRowHeight(25);
        $sheet->getRowDimension(3)->setRowHeight(25);
    }

    public function columnFormats(): array
    {
        return [
            'D' => NumberFormat::FORMAT_CURRENCY_USD_SIMPLE,
            'E' => NumberFormat::FORMAT_NUMBER,
            'F' => NumberFormat::FORMAT_CURRENCY_USD_SIMPLE,
        ];
    }

    /**
     * Obtener distribución porcentual de ingresos por negocio
     */
    private function getDistribucionPorcentualIngresos($ingresos, $totalGlobal)
    {
        if ($totalGlobal <= 0) {
            return [];
        }

        return $ingresos->map(function ($negocio) use ($totalGlobal) {
            return [
                'negocio_id' => $negocio['negocio_id'],
                'negocio_nombre' => $negocio['negocio_nombre'],
                'total_ingresos' => $negocio['total_ingresos'],
                'porcentaje' => ($negocio['total_ingresos'] / $totalGlobal) * 100
            ];
        })->sortByDesc(function ($item) {
            return $item['porcentaje'];
        })->values()->all();
    }
}
