<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reporte de Ingresos por Negocio</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            font-size: 12px;
        }
        .header {
            text-align: center;
            margin-bottom: 20px;
        }
        .title {
            font-size: 18px;
            font-weight: bold;
            margin-bottom: 10px;
        }
        .period {
            font-size: 14px;
            margin-bottom: 5px;
        }
        .business {
            font-size: 14px;
            margin-bottom: 10px;
        }
        .summary {
            background-color: #f2f2f2;
            padding: 10px;
            margin-bottom: 20px;
            border-radius: 5px;
        }
        .summary-title {
            font-weight: bold;
            margin-bottom: 5px;
        }
        .summary-item {
            margin-bottom: 3px;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 20px;
        }
        th, td {
            border: 1px solid #ddd;
            padding: 8px;
            text-align: left;
        }
        th {
            background-color: #4F81BD;
            color: white;
            font-weight: bold;
        }
        .stats {
            background-color: #C6E0B4;
            padding: 10px;
            margin-bottom: 20px;
            border-radius: 5px;
        }
        .stats-title {
            font-weight: bold;
            margin-bottom: 5px;
        }
        .stats-item {
            margin-bottom: 3px;
        }
    </style>
</head>
<body>
    <div class="header">
        <div class="title">REPORTE DE INGRESOS POR NEGOCIO</div>
        <div class="period">Periodo: {{ $periodo['fecha_inicial'] }} al {{ $periodo['fecha_final'] }} ({{ $periodo['dias'] }} días)</div>
        @if($negocio)
            <div class="business">Negocio: {{ $negocio->nombre }}</div>
        @else
            <div class="business">Todos los negocios</div>
        @endif
    </div>

    <div class="summary">
        <div class="summary-title">RESUMEN GLOBAL</div>
        <div class="summary-item">Total de ingresos: ${{ number_format($resumen_global['total_ingresos'], 2) }}</div>
        <div class="summary-item">Cantidad de transacciones: {{ $resumen_global['cantidad_transacciones'] }}</div>
        <div class="summary-item">Promedio por transacción: ${{ number_format($resumen_global['promedio_ingreso'], 2) }}</div>
    </div>

    <table>
        <thead>
            <tr>
                <th>NEGOCIO</th>
                <th>TOTAL INGRESOS</th>
                <th>CANTIDAD TRANSACCIONES</th>
                <th>PROMEDIO</th>
            </tr>
        </thead>
        <tbody>
            @foreach($negocios as $negocio)
                <tr>
                    <td>{{ $negocio['negocio_nombre'] }}</td>
                    <td>${{ number_format($negocio['total_ingresos'], 2) }}</td>
                    <td>{{ $negocio['cantidad_transacciones'] }}</td>
                    <td>${{ number_format($negocio['promedio_ingreso'], 2) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    @if($estadisticas_adicionales['negocio_mayor_ingreso'] || $estadisticas_adicionales['negocio_menor_ingreso'])
        <div class="stats">
            <div class="stats-title">ESTADÍSTICAS ADICIONALES</div>
            @if($estadisticas_adicionales['negocio_mayor_ingreso'])
                <div class="stats-item">
                    Negocio con mayor ingreso: {{ $estadisticas_adicionales['negocio_mayor_ingreso']['negocio_nombre'] }}
                    - ${{ number_format($estadisticas_adicionales['negocio_mayor_ingreso']['total_ingresos'], 2) }}
                </div>
            @endif
            @if($estadisticas_adicionales['negocio_menor_ingreso'])
                <div class="stats-item">
                    Negocio con menor ingreso: {{ $estadisticas_adicionales['negocio_menor_ingreso']['negocio_nombre'] }}
                    - ${{ number_format($estadisticas_adicionales['negocio_menor_ingreso']['total_ingresos'], 2) }}
                </div>
            @endif
        </div>
    @endif

    @if(isset($estadisticas_adicionales['distribucion_porcentual']) && count($estadisticas_adicionales['distribucion_porcentual']) > 0)
        <table>
            <thead>
                <tr>
                    <th>NEGOCIO</th>
                    <th>TOTAL INGRESOS</th>
                    <th>PORCENTAJE</th>
                </tr>
            </thead>
            <tbody>
                @foreach($estadisticas_adicionales['distribucion_porcentual'] as $item)
                    <tr>
                        <td>{{ $item['negocio_nombre'] }}</td>
                        <td>${{ number_format($item['total_ingresos'], 2) }}</td>
                        <td>{{ number_format($item['porcentaje'], 2) }}%</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
</body>
</html>
