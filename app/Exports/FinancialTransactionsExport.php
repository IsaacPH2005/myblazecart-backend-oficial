<?php

namespace App\Exports;

use App\Models\FinancialTransactions;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Font;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Style\Color;
use Illuminate\Http\Request;
use Carbon\Carbon;

class FinancialTransactionsExport implements
    FromCollection,
    WithHeadings,
    WithMapping,
    ShouldAutoSize,
    WithStyles,
    WithColumnFormatting,
    WithTitle,
    WithEvents
{
    protected $request;
    protected $totalIngresos = 0;
    protected $totalEgresos = 0;
    protected $totalTransacciones = 0;

    public function __construct(Request $request)
    {
        $this->request = $request;
    }

    public function collection()
    {
        $query = FinancialTransactions::with([
            'user.generalData',
            'user.driver',
            'negocio',
            'metodo',
            'categoria',
            'vehicle',
            'estadoDeTransaccion'
        ]);

        // Aplicar los mismos filtros que en el método index
        if ($this->request->has('search')) {
            $search = $this->request->search;
            $query->where(function ($q) use ($search) {
                $q->where('item', 'like', '%' . $search . '%')
                    ->orWhere('cliente_proveedor', 'like', '%' . $search . '%')
                    ->orWhere('observaciones', 'like', '%' . $search . '%');
            });
        }

        if ($this->request->has('tipo')) {
            $query->where('tipo_de_transaccion', $this->request->tipo);
        }

        if ($this->request->has('estado')) {
            $query->where('estado_de_transaccion_id', $this->request->estado);
        }

        // Filtro por subcategoría
        if ($this->request->has('subcategoria')) {
            $query->where('subcategoria', $this->request->subcategoria);
        }

        if ($this->request->has('fecha_desde') && $this->request->fecha_desde) {
            $query->whereDate('fecha', '>=', $this->request->fecha_desde);
        }

        if ($this->request->has('fecha_hasta') && $this->request->fecha_hasta) {
            $query->whereDate('fecha', '<=', $this->request->fecha_hasta);
        }

        $transactions = $query->orderBy('fecha', 'desc')->get();

        // Calcular totales para el resumen
        foreach ($transactions as $transaction) {
            $this->totalTransacciones++;
            if ($transaction->tipo_de_transaccion === 'Ingreso') {
                $this->totalIngresos += $transaction->importe_total;
            } else {
                $this->totalEgresos += $transaction->importe_total;
            }
        }

        return $transactions;
    }

    public function headings(): array
    {
        return [
            'USUARIO RESPONSABLE',
            'FECHA',
            'NEGOCIO',
            'VEHÍCULO',
            'PUNTO DE PARTIDA',
            'DESTINO',
            'MILLAS',
            'TIPO',
            'ITEM/DESCRIPCIÓN',
            'CANTIDAD',
            'IMPORTE TOTAL',
            'MÉTODO DE PAGO',
            'CATEGORÍA',
            'SUBCATEGORÍA',
            'CLIENTE/PROVEEDOR',
            'ESTADO',
            'OBSERVACIONES',
            'ARCHIVO ADJUNTO'
        ];
    }

    public function map($transaction): array
    {
        // Generar URL completa para el archivo si existe
        $archivoUrl = $transaction->archivo ? asset($transaction->archivo) : '';

        return [
            $this->getUserFullName($transaction),
            $transaction->fecha ? Carbon::parse($transaction->fecha)->format('d/m/Y') : 'N/A',
            $transaction->negocio ? $transaction->negocio->nombre : 'N/A',
            $this->getVehicleInfo($transaction),
            $transaction->punto_de_partida ?: '',
            $transaction->destino ?: '',
            $transaction->millas ?: 0,
            $this->getTipoWithIcon($transaction->tipo_de_transaccion),
            $transaction->item ?: 'N/A',
            $transaction->cantidad ?: 0,
            $transaction->importe_total ?: 0,
            $transaction->metodo ? $transaction->metodo->nombre : 'N/A',
            $transaction->categoria ? $transaction->categoria->nombre : 'N/A',
            $transaction->subcategoria ?: 'N/A', // Mostrar subcategoría
            $transaction->cliente_proveedor ?: 'N/A',
            $this->getEstadoWithIcon($transaction->estadoDeTransaccion),
            $transaction->observaciones ?: '',
            $archivoUrl ?: 'Sin archivo'
        ];
    }

    public function styles(Worksheet $sheet)
    {
        return [
            // Estilo para los encabezados
            1 => [
                'font' => [
                    'bold' => true,
                    'size' => 12,
                    'color' => ['rgb' => 'FFFFFF']
                ],
                'fill' => [
                    'fillType' => Fill::FILL_SOLID,
                    'startColor' => ['rgb' => '2E86AB'] // Azul profesional
                ],
                'alignment' => [
                    'horizontal' => Alignment::HORIZONTAL_CENTER,
                    'vertical' => Alignment::VERTICAL_CENTER,
                ],
                'borders' => [
                    'allBorders' => [
                        'borderStyle' => Border::BORDER_THIN,
                        'color' => ['rgb' => '000000']
                    ]
                ]
            ],
        ];
    }

    public function columnFormats(): array
    {
        return [
            'B' => NumberFormat::FORMAT_DATE_DDMMYYYY, // Fecha
            'H' => NumberFormat::FORMAT_NUMBER, // Millas
            'L' => NumberFormat::FORMAT_NUMBER_00, // Cantidad
            'M' => NumberFormat::FORMAT_CURRENCY_USD_SIMPLE, // Importe Total
        ];
    }

    public function title(): string
    {
        $fechaActual = Carbon::now()->format('d-m-Y');
        return "Transacciones Financieras - {$fechaActual}";
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event) {
                $sheet = $event->sheet->getDelegate();
                $highestRow = $sheet->getHighestRow();
                $highestColumn = $sheet->getHighestColumn();

                // Aplicar bordes a toda la tabla
                $sheet->getStyle("A1:{$highestColumn}{$highestRow}")
                    ->getBorders()
                    ->getAllBorders()
                    ->setBorderStyle(Border::BORDER_THIN)
                    ->setColor(new Color('CCCCCC'));

                // Aplicar colores alternos a las filas
                for ($row = 2; $row <= $highestRow; $row++) {
                    if ($row % 2 == 0) {
                        $sheet->getStyle("A{$row}:{$highestColumn}{$row}")
                            ->getFill()
                            ->setFillType(Fill::FILL_SOLID)
                            ->setStartColor(new Color('F8F9FA')); // Gris muy claro
                    }
                }

                // Congelar la primera fila
                $sheet->freezePane('A2');

                // Aplicar filtros automáticos
                $sheet->setAutoFilter("A1:{$highestColumn}{$highestRow}");

                // Ajustar altura de la fila de encabezados
                $sheet->getRowDimension(1)->setRowHeight(25);

                // Aplicar colores condicionales para tipos de transacción
                $this->applyConditionalFormatting($sheet, $highestRow);

                // Crear hipervínculos para archivos adjuntos
                $this->createHyperlinks($sheet, $highestRow);

                // Agregar resumen al final
                $this->addSummarySection($sheet, $highestRow + 2);

                // Establecer orientación de página
                $sheet->getPageSetup()->setOrientation(\PhpOffice\PhpSpreadsheet\Worksheet\PageSetup::ORIENTATION_LANDSCAPE);

                // Ajustar ancho de columnas específicas
                $sheet->getColumnDimension('R')->setWidth(20); // Columna de archivo adjunto
            },
        ];
    }

    private function getTipoWithIcon($tipo)
    {
        switch ($tipo) {
            case 'Ingreso':
                return '💰 ' . $tipo;
            case 'Egreso':
                return '💸 ' . $tipo;
            default:
                return $tipo ?: 'N/A';
        }
    }

    private function getUserFullName($transaction)
    {
        if (!$transaction->user || !$transaction->user->generalData) {
            return 'N/A';
        }

        $nombre = $transaction->user->generalData->nombre;
        $apellido = $transaction->user->generalData->apellido;

        return trim($nombre . ' ' . $apellido) ?: 'N/A';
    }

    private function getVehicleInfo($transaction)
    {
        if (!$transaction->vehicle) {
            return 'N/A';
        }

        return trim($transaction->vehicle->marca . ' ' . $transaction->vehicle->modelo);
    }

    private function getEstadoWithIcon($estado)
    {
        if (!$estado) return 'N/A';

        $iconos = [
            'Pendiente' => '⏳',
            'Completado' => '✅',
            'Completada' => '✅',
            'Cancelado' => '❌',
            'Cancelada' => '❌',
            'En Proceso' => '🔄',
            'Aprobado' => '✅',
            'Rechazado' => '❌'
        ];

        $icono = $iconos[$estado->nombre] ?? '📋';
        return $icono . ' ' . $estado->nombre;
    }

    private function applyConditionalFormatting($sheet, $highestRow)
    {
        // Colorear filas de ingresos en verde claro
        for ($row = 2; $row <= $highestRow; $row++) {
            $tipoCell = $sheet->getCell("I{$row}")->getValue();
            if (strpos($tipoCell, 'Ingreso') !== false) {
                $sheet->getStyle("A{$row}:S{$row}")
                    ->getFill()
                    ->setFillType(Fill::FILL_SOLID)
                    ->setStartColor(new Color('E8F5E8')); // Verde muy claro
            } elseif (strpos($tipoCell, 'Egreso') !== false) {
                $sheet->getStyle("A{$row}:S{$row}")
                    ->getFill()
                    ->setFillType(Fill::FILL_SOLID)
                    ->setStartColor(new Color('FFF2F2')); // Rojo muy claro
            }
        }
    }

    private function createHyperlinks($sheet, $highestRow)
    {
        // Crear hipervínculos para archivos adjuntos
        for ($row = 2; $row <= $highestRow; $row++) {
            $cellValue = $sheet->getCell("R{$row}")->getValue();

            // Verificar si es una URL válida
            if (filter_var($cellValue, FILTER_VALIDATE_URL)) {
                // Crear hipervínculo
                $sheet->getCell("R{$row}")->getHyperlink()
                    ->setUrl($cellValue)
                    ->setTooltip('Abrir archivo');

                // Aplicar estilo de enlace
                $sheet->getStyle("R{$row}")
                    ->getFont()
                    ->setColor(new Color('0000FF'))
                    ->setUnderline(true);

                // Cambiar el texto a "Ver archivo"
                $sheet->setCellValue("R{$row}", "Ver archivo");
            }
        }
    }

    private function addSummarySection($sheet, $startRow)
    {
        $saldoNeto = $this->totalIngresos - $this->totalEgresos;

        // Título del resumen
        $sheet->setCellValue("A{$startRow}", "RESUMEN FINANCIERO");
        $sheet->getStyle("A{$startRow}:F{$startRow}")
            ->getFont()
            ->setBold(true)
            ->setSize(14);
        $sheet->getStyle("A{$startRow}:F{$startRow}")
            ->getFill()
            ->setFillType(Fill::FILL_SOLID)
            ->setStartColor(new Color('34495E'));
        $sheet->getStyle("A{$startRow}:F{$startRow}")
            ->getFont()
            ->setColor(new Color('FFFFFF'));

        $startRow++;

        // Datos del resumen
        $summaryData = [
            ['📊 Total de Transacciones:', $this->totalTransacciones],
            ['💰 Total Ingresos:', '$' . number_format($this->totalIngresos, 2)],
            ['💸 Total Egresos:', '$' . number_format($this->totalEgresos, 2)],
            ['📈 Saldo Neto:', '$' . number_format($saldoNeto, 2)],
            ['📅 Fecha de Reporte:', Carbon::now()->format('d/m/Y H:i:s')]
        ];

        foreach ($summaryData as $index => $data) {
            $currentRow = $startRow + $index;
            $sheet->setCellValue("A{$currentRow}", $data[0]);
            $sheet->setCellValue("B{$currentRow}", $data[1]);

            // Estilo para las celdas del resumen
            $sheet->getStyle("A{$currentRow}:B{$currentRow}")
                ->getFont()
                ->setBold(true);

            // Color especial para el saldo neto
            if ($index === 3) {
                $color = $saldoNeto >= 0 ? 'E8F5E8' : 'FFF2F2';
                $sheet->getStyle("A{$currentRow}:B{$currentRow}")
                    ->getFill()
                    ->setFillType(Fill::FILL_SOLID)
                    ->setStartColor(new Color($color));
            }
        }

        // Agregar bordes al resumen
        $endRow = $startRow + count($summaryData) - 1;
        $sheet->getStyle("A{$startRow}:B{$endRow}")
            ->getBorders()
            ->getAllBorders()
            ->setBorderStyle(Border::BORDER_THIN);
    }
}
