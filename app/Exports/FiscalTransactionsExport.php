<?php

namespace App\Exports;

use App\Models\FinancialTransactions;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithColumnWidths;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use Illuminate\Http\Request;

class FiscalTransactionsExport implements
    FromQuery,
    WithHeadings,
    WithMapping,
    WithStyles,
    WithColumnWidths,
    WithEvents
{
    protected $request;

    public function __construct(Request $request)
    {
        $this->request = $request;
    }

    public function query()
    {
        $query = FinancialTransactions::with('categoria');

        // Aplicar los mismos filtros que en el método index
        if ($this->request->has('fecha_desde') && $this->request->fecha_desde) {
            $query->whereDate('fecha', '>=', $this->request->fecha_desde);
        }

        if ($this->request->has('fecha_hasta') && $this->request->fecha_hasta) {
            $query->whereDate('fecha', '<=', $this->request->fecha_hasta);
        }

        if ($this->request->has('search')) {
            $search = $this->request->search;
            $query->where(function ($q) use ($search) {
                $q->where('item', 'like', '%' . $search . '%')
                    ->orWhere('cliente_proveedor', 'like', '%' . $search . '%')
                    ->orWhere('observaciones', 'like', '%' . $search . '%')
                    ->orWhere('numero_transaccion', 'like', '%' . $search . '%');
            });
        }

        if ($this->request->has('tipo')) {
            $query->where('tipo_de_transaccion', $this->request->tipo);
        }

        if ($this->request->has('estado')) {
            $query->where('estado_de_transaccion_id', $this->request->estado);
        }

        if ($this->request->has('categoria')) {
            $query->where('categoria_id', $this->request->categoria);
        }

        if ($this->request->has('subcategoria')) {
            $query->where('subcategoria', $this->request->subcategoria);
        }

        return $query->orderBy('fecha', 'asc');
    }

    public function headings(): array
    {
        return [
            'Item',
            'Monto',
            'Código',
            'Nombre',
            'Clasificación',
            'Subcategoría',
            'Agrupación',
            'Descripción'
        ];
    }

    public function map($transaction): array
    {
        return [
            $transaction->item,
            $transaction->importe_total,
            $transaction->categoria ? $transaction->categoria->codigo : '',
            $transaction->categoria ? $transaction->categoria->nombre : '',
            $transaction->categoria ? $transaction->categoria->clasificacion : '',
            $transaction->categoria ? $transaction->categoria->subcategoria : '',
            $transaction->categoria ? $transaction->categoria->agrupacion : '',
            $transaction->categoria ? $transaction->categoria->descripcion : '',
        ];
    }

    /**
     * Define anchos de columna para que se vea todo el contenido
     */
    public function columnWidths(): array
    {
        return [
            'A' => 35,  // Item
            'B' => 15,  // Monto
            'C' => 12,  // Código
            'D' => 30,  // Nombre
            'E' => 20,  // Clasificación
            'F' => 25,  // Subcategoría
            'G' => 20,  // Agrupación
            'H' => 40,  // Descripción
        ];
    }

    /**
     * Estilos para el encabezado
     */
    public function styles(Worksheet $sheet)
    {
        return [
            // Estilo de la primera fila (encabezados)
            1 => [
                'font' => [
                    'bold' => true,
                    'size' => 12,
                    'color' => ['rgb' => 'FFFFFF']
                ],
                'fill' => [
                    'fillType' => Fill::FILL_SOLID,
                    'startColor' => ['rgb' => '4F46E5'] // Color azul profesional
                ],
                'alignment' => [
                    'horizontal' => Alignment::HORIZONTAL_CENTER,
                    'vertical' => Alignment::VERTICAL_CENTER,
                    'wrapText' => true
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

    /**
     * Eventos adicionales para mejorar el formato
     */
    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event) {
                $sheet = $event->sheet->getDelegate();
                $highestRow = $sheet->getHighestRow();
                $highestColumn = $sheet->getHighestColumn();

                // Altura de la fila de encabezado
                $sheet->getRowDimension(1)->setRowHeight(30);

                // Aplicar bordes a todas las celdas con datos
                $sheet->getStyle("A1:{$highestColumn}{$highestRow}")
                    ->getBorders()
                    ->getAllBorders()
                    ->setBorderStyle(Border::BORDER_THIN)
                    ->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color('CCCCCC'));

                // Alineación del contenido
                $sheet->getStyle("A2:A{$highestRow}")
                    ->getAlignment()
                    ->setHorizontal(Alignment::HORIZONTAL_LEFT)
                    ->setVertical(Alignment::VERTICAL_TOP)
                    ->setWrapText(true);

                // Formato de número para la columna de Monto
                $sheet->getStyle("B2:B{$highestRow}")
                    ->getNumberFormat()
                    ->setFormatCode('#,##0.00');

                $sheet->getStyle("B2:B{$highestRow}")
                    ->getAlignment()
                    ->setHorizontal(Alignment::HORIZONTAL_RIGHT);

                // Centrar columnas específicas
                $sheet->getStyle("C2:C{$highestRow}")
                    ->getAlignment()
                    ->setHorizontal(Alignment::HORIZONTAL_CENTER);

                // Alineación izquierda y wrap text para columnas de texto largo
                foreach (['D', 'E', 'F', 'G', 'H'] as $column) {
                    $sheet->getStyle("{$column}2:{$column}{$highestRow}")
                        ->getAlignment()
                        ->setHorizontal(Alignment::HORIZONTAL_LEFT)
                        ->setVertical(Alignment::VERTICAL_TOP)
                        ->setWrapText(true);
                }

                // Filas alternas con color de fondo
                for ($row = 2; $row <= $highestRow; $row++) {
                    if ($row % 2 == 0) {
                        $sheet->getStyle("A{$row}:{$highestColumn}{$row}")
                            ->getFill()
                            ->setFillType(Fill::FILL_SOLID)
                            ->getStartColor()
                            ->setRGB('F9FAFB'); // Gris claro
                    }
                }

                // Altura mínima para las filas de datos
                for ($row = 2; $row <= $highestRow; $row++) {
                    $sheet->getRowDimension($row)->setRowHeight(-1); // Auto altura
                }

                // Congelar primera fila (encabezados)
                $sheet->freezePane('A2');

                // Autofiltro en los encabezados
                $sheet->setAutoFilter("A1:{$highestColumn}1");
            },
        ];
    }
}
