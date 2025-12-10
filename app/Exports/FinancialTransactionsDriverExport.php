<?php

namespace App\Exports;

use App\Models\FinancialTransactions;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Carbon\Carbon; // Para formateo seguro de fechas

class FinancialTransactionsDriverExport implements FromCollection, WithHeadings, WithMapping, ShouldAutoSize, WithStyles, WithColumnFormatting
{
    protected $transactions;

    public function __construct($transactions)
    {
        $this->transactions = $transactions;
    }

    public function collection()
    {
        return $this->transactions;
    }

    public function headings(): array
    {
        return [
            'ID',
            'NÚMERO DE TRANSACCIÓN',
            'FECHA',
            'ITEM',
            'CANTIDAD',
            'IMPORTE TOTAL',
            'ESTADO',
            'NEGOCIO',
            'MÉTODO DE PAGO',
            'CATEGORÍA',
            'VEHÍCULO (CÓDIGO ÚNICO)',
            'CLIENTE/PROVEEDOR',
            'EGRESO DIRECTO',
            'OBSERVACIONES',
            'NÚMERO DE ARCHIVOS',
            'CAJA OPERATIVA',
            'USUARIO',
            'FECHA DE CREACIÓN'
        ];
    }

    public function map($transaction): array
    {
        // Formateo seguro de fechas con Carbon para evitar errores si son null
        $fechaFormatted = $transaction->fecha ? $transaction->fecha->format('Y-m-d') : '';
        $createdAtFormatted = $transaction->created_at ? $transaction->created_at->format('Y-m-d H:i:s') : '';

        // Conteo seguro de archivos (si relación no cargada, usa 0)
        $archivosCount = $transaction->archivos ? $transaction->archivos->count() : 0;

        return [
            $transaction->id,
            $transaction->numero_transaccion ?? '',
            $fechaFormatted,
            $transaction->item ?? '',
            $transaction->cantidad ?? 0,
            $transaction->importe_total ?? 0,
            optional($transaction->estadoDeTransaccion)->nombre ?? 'Sin Estado', // Seguro con optional()
            optional($transaction->negocio)->nombre ?? 'Sin Negocio',
            optional($transaction->metodo)->nombre ?? 'Sin Método',
            optional($transaction->categoria)->nombre ?? 'Sin Categoría',
            optional($transaction->vehicle)->codigo_unico ?? '', // Solo código único del vehículo
            $transaction->cliente_proveedor ?? '',
            $transaction->egreso_directo ? 'Sí' : 'No',
            $transaction->observaciones ?? '',
            $archivosCount, // Conteo seguro
            optional($transaction->cajaOperativa)->nombre ?? 'Sin Caja', // Seguro para caja
            (optional($transaction->user->generalData)->nombre ?? '') . ' ' . (optional($transaction->user->generalData)->apellido ?? ''), // Seguro para user
            $createdAtFormatted
        ];
    }

    public function styles(Worksheet $sheet)
    {
        // Obtener el número máximo de filas para aplicar borders solo a datos (rendimiento)
        $highestRow = $sheet->getHighestRow();

        return [
            // Estilos para la fila de headers (fila 1)
            1 => [
                'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
                'fill' => [
                    'fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID,
                    'startColor' => ['rgb' => '4F46E5'] // Azul indigo para headers
                ],
                'alignment' => [
                    'horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER,
                    'vertical' => \PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER
                ]
            ],
            // Estilos para filas de datos (filas 2 a highestRow, borders sutiles) - Rango A:R (18 columnas)
            "2:$highestRow" => [ // Aplica solo a datos, no headers
                'borders' => [
                    'allBorders' => [
                        'borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN,
                        'color' => ['rgb' => 'D1D5DB'] // Gris claro
                    ]
                ]
            ]
        ];
    }

    public function columnFormats(): array
    {
        return [
            // Formato de fecha para columna de fecha (columna C: dd/mm/yyyy) - Cadena segura
            'C' => 'dd/mm/yyyy',
            // Formato de fecha de creación con hora (columna R: dd/mm/yyyy hh:mm) - Cadena segura
            'R' => 'dd/mm/yyyy hh:mm',
            // Formato de moneda para importe total (columna F, USD) - Cadena compatible
            'F' => '$#,##0.00', // Formato USD simple: símbolo $, miles y 2 decimales
            // Formato numérico para cantidad (columna E, 2 decimales) - Cadena segura
            'E' => '0.00',
            // Formato numérico para número de archivos (columna P, entero) - Cadena segura
            'P' => '0'
        ];
    }
}
