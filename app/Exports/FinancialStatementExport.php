<?php

namespace App\Exports;

use App\Http\Controllers\api\TransactionFinancial\DashboardFinancial\EstadoDeResultadosController;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithColumnWidths;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Font;
use Carbon\Carbon;

class FinancialStatementExport implements FromCollection, WithHeadings, WithTitle, WithStyles, WithColumnWidths, WithEvents
{
    protected $request;
    protected $data;

    public function __construct(Request $request)
    {
        $this->request = $request;
        $controller = new EstadoDeResultadosController();
        $response = $controller->getFinancialStatementByDateRange($this->request);
        $this->data = $response->getData(true)['datos'];
    }

    public function collection()
    {
        $collection = collect([]);

        // Fila 1: Título principal
        $collection->push(['ESTADO DE RESULTADOS', '', '', '', '', '']);

        // Fila 2: Info del negocio - VALORES
        $collection->push([
            $this->data['negocio']['nombre'] ?? 'NOMBRE NEGOCIO',
            '',
            $this->data['periodo']['fecha_inicial'] ?? '01/06/2025',
            '',
            $this->data['periodo']['fecha_final'] ?? '25/07/2025',
            ''
        ]);

        // Fila 3: Info del negocio - LABELS
        $collection->push(['NEGOCIO', '', 'FECHA INICIAL', '', 'FECHA FINAL', '']);

        // Fila 4: Headers de tabla
        $collection->push(['TRANSACCIÓN', '', 'STATUS', '', 'TOTAL', '']);

        // Fila 5: Sección 1 INGRESO
        $collection->push(['1', 'INGRESO', '', '', '', '']);

        // Detalle de ingresos por estado
        $totalIngresos = 0;
        foreach ($this->data['detalle_por_estado'] ?? [] as $estado) {
            $ingresos = floatval($estado['ingresos'] ?? 0);
            if ($ingresos > 0) {
                $totalIngresos += $ingresos;
                $collection->push([
                    '',
                    '',
                    '',
                    $estado['estado_nombre'] ?? 'N/A',
                    '$' . number_format($ingresos, 2),
                    ''
                ]);
            }
        }

        // Línea en blanco
        $collection->push(['', '', '', '', '', '']);

        // Sección 2 EGRESO
        $collection->push(['2', 'EGRESO', '', '', '', '']);

        // Detalle de egresos por estado
        $totalEgresos = 0;
        foreach ($this->data['detalle_por_estado'] ?? [] as $estado) {
            $egresos = floatval($estado['egresos'] ?? 0);
            if ($egresos > 0) {
                $totalEgresos += $egresos;
                $collection->push([
                    '',
                    '',
                    '',
                    $estado['estado_nombre'] ?? 'N/A',
                    '$' . number_format($egresos, 2),
                    ''
                ]);
            }
        }

        // Línea en blanco
        $collection->push(['', '', '', '', '', '']);

        // RESUMEN FINANCIERO
        $margenBruto = $totalIngresos - $totalEgresos;

        $collection->push(['', 'TOTAL INGRESOS BRUTOS', '', '', '$' . number_format($totalIngresos, 2), '']);
        $collection->push(['', 'TOTAL EGRESOS BRUTOS', '', '', '$' . number_format($totalEgresos, 2), '']);
        $collection->push(['', 'MARGEN BRUTO', '', '', '$' . number_format($margenBruto, 2), '']);
        $collection->push(['', 'MARGEN UTIL ANTES DE IMPUESTOS', '', '', '$' . number_format($margenBruto, 2), '']);

        // Línea en blanco
        $collection->push(['', '', '', '', '', '']);

        // Resumen por categorías (opcional)
        foreach ($this->data['resumen_por_categoria'] ?? [] as $categoria) {
            if (!empty($categoria['categoria'])) {
                $collection->push([
                    '',
                    strtoupper($categoria['categoria']),
                    '',
                    '',
                    '$' . number_format($categoria['total_ingresos'] ?? 0, 2),
                    ''
                ]);
            }
        }

        // Línea final
        $collection->push(['', '', '', '', '', '']);
        $collection->push(['', 'Generado: ' . Carbon::now()->format('d/m/Y H:i:s'), '', '', '', '']);

        return $collection;
    }

    public function headings(): array
    {
        return [];
    }

    public function title(): string
    {
        return 'Estado Financiero';
    }

    public function columnWidths(): array
    {
        return [
            'A' => 5,
            'B' => 30,
            'C' => 5,
            'D' => 20,
            'E' => 15,
            'F' => 5,
        ];
    }

    public function styles(Worksheet $sheet)
    {
        // TÍTULO PRINCIPAL (Fila 1)
        $sheet->mergeCells('A1:F1');
        $sheet->getStyle('A1')->applyFromArray([
            'font' => [
                'bold' => true,
                'size' => 16,
                'color' => ['rgb' => 'FFFFFF']
            ],
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['rgb' => 'FF8C00']
            ],
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_CENTER,
                'vertical' => Alignment::VERTICAL_CENTER
            ]
        ]);
        $sheet->getRowDimension(1)->setRowHeight(30);

        // FILA 2 - Valores (Negocio, Fecha Inicial, Fecha Final)
        $sheet->mergeCells('A2:B2');
        $sheet->getStyle('A2:B2')->applyFromArray([
            'font' => ['bold' => true, 'size' => 12],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'FFFFFF']],
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]]
        ]);

        $sheet->mergeCells('C2:D2');
        $sheet->getStyle('C2:D2')->applyFromArray([
            'font' => ['size' => 11],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]]
        ]);

        $sheet->mergeCells('E2:F2');
        $sheet->getStyle('E2:F2')->applyFromArray([
            'font' => ['size' => 11],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]]
        ]);

        // FILA 3 - Labels (NEGOCIO, FECHA INICIAL, FECHA FINAL)
        $sheet->mergeCells('A3:B3');
        $sheet->mergeCells('C3:D3');
        $sheet->mergeCells('E3:F3');
        $sheet->getStyle('A3:F3')->applyFromArray([
            'font' => ['bold' => true, 'size' => 10, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'FF8C00']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]]
        ]);

        // FILA 4 - Headers de tabla (TRANSACCIÓN, STATUS, TOTAL)
        $sheet->getStyle('A4:F4')->applyFromArray([
            'font' => ['bold' => true, 'size' => 10, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '000000']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]]
        ]);

        // Bordes generales
        $sheet->getStyle('A5:F100')->applyFromArray([
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'CCCCCC']]]
        ]);

        return [];
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event) {
                $sheet = $event->sheet->getDelegate();
                $highestRow = $sheet->getHighestRow();

                for ($row = 5; $row <= $highestRow; $row++) {
                    $cellA = $sheet->getCell('A' . $row)->getValue();
                    $cellB = $sheet->getCell('B' . $row)->getValue();

                    // Detectar secciones principales: "1" INGRESO o "2" EGRESO
                    if ($cellA === '1' || $cellA === '2') {
                        $sheet->mergeCells("A{$row}:F{$row}");
                        $sheet->getStyle("A{$row}")->applyFromArray([
                            'font' => [
                                'bold' => true,
                                'size' => 12,
                                'color' => ['rgb' => 'FFFFFF']
                            ],
                            'fill' => [
                                'fillType' => Fill::FILL_SOLID,
                                'startColor' => ['rgb' => '4472C4']
                            ],
                            'alignment' => [
                                'horizontal' => Alignment::HORIZONTAL_LEFT,
                                'vertical' => Alignment::VERTICAL_CENTER
                            ],
                            'borders' => [
                                'allBorders' => ['borderStyle' => Border::BORDER_MEDIUM]
                            ]
                        ]);
                        $sheet->getRowDimension($row)->setRowHeight(25);
                    }

                    // Detectar filas de TOTALES y MÁRGENES
                    if (stripos($cellB, 'TOTAL') !== false || stripos($cellB, 'MARGEN') !== false) {
                        $sheet->mergeCells("B{$row}:D{$row}");
                        $sheet->getStyle("B{$row}:E{$row}")->applyFromArray([
                            'font' => ['bold' => true, 'size' => 11],
                            'fill' => [
                                'fillType' => Fill::FILL_SOLID,
                                'startColor' => ['rgb' => 'E7E6E6']
                            ],
                            'borders' => [
                                'allBorders' => ['borderStyle' => Border::BORDER_MEDIUM]
                            ]
                        ]);
                    }

                    // Alinear montos a la derecha
                    $cellE = $sheet->getCell('E' . $row)->getValue();
                    if (is_string($cellE) && strpos($cellE, '$') !== false) {
                        $sheet->getStyle("E{$row}")->applyFromArray([
                            'alignment' => ['horizontal' => Alignment::HORIZONTAL_RIGHT],
                            'font' => ['size' => 11]
                        ]);
                    }
                }
            }
        ];
    }
}
