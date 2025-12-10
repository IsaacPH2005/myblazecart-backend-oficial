<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Resumen de Cajas Operativas</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            font-size: 12px;
            margin: 0;
            padding: 20px;
        }
        .header {
            text-align: center;
            margin-bottom: 20px;
        }
        .header h1 {
            font-size: 18px;
            margin: 0;
            color: #2563EB;
        }
        .header p {
            margin: 5px 0;
            font-size: 14px;
        }
        .caja-section {
            margin-bottom: 30px;
            page-break-inside: avoid;
        }
        .caja-title {
            background-color: #f0f0f0;
            padding: 8px;
            font-weight: bold;
            border-bottom: 1px solid #ddd;
            color: #2563EB;
        }
        .resumen-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 15px;
        }
        .resumen-table th, .resumen-table td {
            border: 1px solid #ddd;
            padding: 6px;
            text-align: left;
        }
        .resumen-table th {
            background-color: #f2f2f2;
            font-weight: bold;
        }
        .egresos-table {
            width: 100%;
            border-collapse: collapse;
        }
        .egresos-table th, .egresos-table td {
            border: 1px solid #ddd;
            padding: 5px;
            text-align: left;
            font-size: 10px;
        }
        .egresos-table th {
            background-color: #f2f2f2;
            font-weight: bold;
        }
        .total-row {
            font-weight: bold;
            background-color: #f9f9f9;
        }
        .footer {
            text-align: center;
            margin-top: 20px;
            font-size: 10px;
            color: #666;
        }
    </style>
</head>
<body>
    <div class="header">
        <h1>RESUMEN DE CAJAS OPERATIVAS</h1>
        <p>Período: {{ $fechaInicio }} al {{ $fechaFin }}</p>
        <p>Fecha de generación: {{ date('d/m/Y H:i') }}</p>
    </div>

    @foreach ($datos as $dato)
    <div class="caja-section">
        <div class="caja-title">
            Caja: {{ $dato['caja']['nombre'] }} (ID: {{ $dato['caja']['id'] }})
        </div>

        <table class="resumen-table">
            <tr>
                <th width="25%">Saldo Inicial</th>
                <th width="25%">Total Ingresos</th>
                <th width="25%">Total Egresos</th>
                <th width="25%">Saldo Final</th>
            </tr>
            <tr>
                <td>${{ number_format($dato['resumen']['saldo_inicial'], 2, ',', '.') }}</td>
                <td>${{ number_format($dato['resumen']['total_ingresos'], 2, ',', '.') }}</td>
                <td>${{ number_format($dato['resumen']['total_egresos'], 2, ',', '.') }}</td>
                <td>${{ number_format($dato['resumen']['saldo_final'], 2, ',', '.') }}</td>
            </tr>
        </table>

        <h3>Detalle de Egresos</h3>
        <table class="egresos-table">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Item</th>
                    <th>Fecha Movimiento</th>
                    <th>Monto</th>
                    <th>Descripción</th>
                    <th>Observaciones</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($dato['egresos'] as $egreso)
                <tr>
                    <td>{{ $egreso['id'] }}</td>
                    <td>{{ $egreso['item'] }}</td>
                    <td>{{ $egreso['fecha_movimiento'] }}</td>
                    <td>${{ number_format($egreso['monto'], 2, ',', '.') }}</td>
                    <td>{{ $egreso['descripcion_movimiento'] }}</td>
                    <td>{{ $egreso['observaciones'] }}</td>
                </tr>
                @endforeach
                <tr class="total-row">
                    <td colspan="3">TOTAL EGRESOS</td>
                    <td>${{ number_format($dato['resumen']['total_egresos'], 2, ',', '.') }}</td>
                    <td colspan="2"></td>
                </tr>
            </tbody>
        </table>
    </div>
    @endforeach

    <div class="footer">
        <p>Documento generado automáticamente por el sistema</p>
    </div>
</body>
</html>
