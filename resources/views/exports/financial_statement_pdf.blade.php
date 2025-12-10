<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Estado Financiero</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            font-size: 12px;
            margin: 20px;
        }
        .header {
            text-align: center;
            margin-bottom: 30px;
            border-bottom: 2px solid #333;
            padding-bottom: 15px;
        }
        .company-name {
            font-size: 24px;
            font-weight: bold;
            color: #333;
            margin-bottom: 10px;
        }
        .report-title {
            font-size: 18px;
            color: #666;
            margin-bottom: 5px;
        }
        .period {
            font-size: 14px;
            color: #888;
        }
        .section {
            margin-bottom: 25px;
        }
        .section-title {
            font-size: 16px;
            font-weight: bold;
            color: #333;
            margin-bottom: 10px;
            border-bottom: 1px solid #ddd;
            padding-bottom: 5px;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 15px;
        }
        th, td {
            padding: 8px;
            text-align: left;
            border-bottom: 1px solid #ddd;
        }
        th {
            background-color: #f5f5f5;
            font-weight: bold;
        }
        .amount {
            text-align: right;
        }
        .summary-box {
            background-color: #f9f9f9;
            border: 1px solid #ddd;
            border-radius: 5px;
            padding: 15px;
            margin-bottom: 20px;
        }
        .summary-item {
            display: flex;
            justify-content: space-between;
            margin-bottom: 8px;
            padding: 5px 0;
        }
        .summary-label {
            font-weight: bold;
        }
        .summary-value {
            font-weight: bold;
            color: #333;
        }
        .positive {
            color: #008000;
        }
        .negative {
            color: #cc0000;
        }
        .footer {
            margin-top: 40px;
            text-align: center;
            font-size: 10px;
            color: #888;
            border-top: 1px solid #ddd;
            padding-top: 10px;
        }
        .two-column {
            display: flex;
            justify-content: space-between;
        }
        .column {
            width: 48%;
        }
    </style>
</head>
<body>
    <!-- Header -->
    <div class="header">
        <div class="company-name">{{ $data['negocio']['nombre'] ?? 'N/A' }}</div>
        <div class="report-title">Estado Financiero</div>
        <div class="period">
            Período: {{ $data['periodo']['fecha_inicial'] ?? '' }} - {{ $data['periodo']['fecha_final'] ?? '' }}
            ({{ $data['periodo']['dias_periodo'] ?? 0 }} días)
        </div>
    </div>

    <!-- Resumen Financiero -->
    <div class="section">
        <div class="section-title">Resumen Financiero</div>
        <div class="summary-box">
            <div class="summary-item">
                <span class="summary-label">Total Ingresos Brutos:</span>
                <span class="summary-value positive">${{ $data['resumen_financiero']['total_ingresos_brutos'] ?? '0.00' }}</span>
            </div>
            <div class="summary-item">
                <span class="summary-label">Total Egresos Brutos:</span>
                <span class="summary-value negative">${{ $data['resumen_financiero']['total_egresos_brutos'] ?? '0.00' }}</span>
            </div>
            <div class="summary-item">
                <span class="summary-label">Margen Bruto:</span>
                <span class="summary-value">${{ $data['resumen_financiero']['margen_bruto'] ?? '0.00' }}</span>
            </div>
            <div class="summary-item">
                <span class="summary-label">Margen Útil Antes de Impuestos:</span>
                <span class="summary-value">${{ $data['resumen_financiero']['margen_util_antes_impuestos'] ?? '0.00' }}</span>
            </div>
            <div class="summary-item">
                <span class="summary-label">Rentabilidad:</span>
                <span class="summary-value">{{ $data['resumen_financiero']['rentabilidad_porcentaje'] ?? '0.00' }}%</span>
            </div>
        </div>
    </div>

    <!-- Detalle por Estados -->
    @if(isset($data['detalle_por_estado']) && count($data['detalle_por_estado']) > 0)
    <div class="section">
        <div class="section-title">Detalle por Estados de Transacción</div>
        <table>
            <thead>
                <tr>
                    <th>Estado</th>
                    <th>Descripción</th>
                    <th class="amount">Ingresos</th>
                    <th class="amount">Egresos</th>
                    <th class="amount">Balance</th>
                </tr>
            </thead>
            <tbody>
                @foreach($data['detalle_por_estado'] as $estado)
                <tr>
                    <td>{{ $estado['estado_nombre'] ?? 'N/A' }}</td>
                    <td>{{ $estado['estado_descripcion'] ?? 'N/A' }}</td>
                    <td class="amount positive">${{ number_format($estado['ingresos'] ?? 0, 2) }}</td>
                    <td class="amount negative">${{ number_format($estado['egresos'] ?? 0, 2) }}</td>
                    <td class="amount">${{ number_format($estado['balance_estado'] ?? 0, 2) }}</td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    @endif

    <!-- Resumen por Categorías -->
    @if(isset($data['resumen_por_categoria']) && count($data['resumen_por_categoria']) > 0)
    <div class="section">
        <div class="section-title">Resumen por Categorías</div>
        <table>
            <thead>
                <tr>
                    <th>Categoría</th>
                    <th class="amount">Ingresos</th>
                    <th class="amount">Egresos</th>
                    <th class="amount">Balance</th>
                </tr>
            </thead>
            <tbody>
                @foreach($data['resumen_por_categoria'] as $categoria)
                <tr>
                    <td>{{ $categoria['categoria'] ?? 'N/A' }}</td>
                    <td class="amount positive">${{ number_format($categoria['total_ingresos'] ?? 0, 2) }}</td>
                    <td class="amount negative">${{ number_format($categoria['total_egresos'] ?? 0, 2) }}</td>
                    <td class="amount">${{ number_format($categoria['balance_categoria'] ?? 0, 2) }}</td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    @endif

    <!-- Estadísticas Adicionales -->
    @if(isset($data['estadisticas_adicionales']))
    <div class="section">
        <div class="section-title">Estadísticas Adicionales</div>
        <div class="two-column">
            <div class="column">
                <div class="summary-box">
                    <div class="summary-item">
                        <span class="summary-label">Total Transacciones:</span>
                        <span class="summary-value">{{ $data['estadisticas_adicionales']['total_transacciones'] ?? 0 }}</span>
                    </div>
                    <div class="summary-item">
                        <span class="summary-label">Promedio Ingreso/Trans:</span>
                        <span class="summary-value">${{ $data['estadisticas_adicionales']['promedio_ingreso_transaccion'] ?? '0.00' }}</span>
                    </div>
                </div>
            </div>
            <div class="column">
                <div class="summary-box">
                    <div class="summary-item">
                        <span class="summary-label">Estados más utilizados:</span>
                        <span class="summary-value">
                            @if(isset($data['estadisticas_adicionales']['estados_mas_utilizados']))
                                {{ implode(', ', array_slice($data['estadisticas_adicionales']['estados_mas_utilizados'], 0, 2)) }}
                            @else
                                N/A
                            @endif
                        </span>
                    </div>
                    <div class="summary-item">
                        <span class="summary-label">Promedio Egreso/Trans:</span>
                        <span class="summary-value">${{ $data['estadisticas_adicionales']['promedio_egreso_transaccion'] ?? '0.00' }}</span>
                    </div>
                </div>
            </div>
        </div>
    </div>
    @endif

    <!-- Footer -->
    <div class="footer">
        <p>Reporte generado el {{ date('d/m/Y H:i:s') }}</p>
        <p>Sistema de Gestión Financiera</p>
    </div>
</body>
</html>