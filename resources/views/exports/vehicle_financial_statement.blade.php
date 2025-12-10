<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Estado Financiero del Vehículo</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            margin: 20px;
        }
        h1 {
            text-align: center;
            color: #333;
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
            background-color: #f2f2f2;
            font-weight: bold;
        }
        .section-title {
            font-weight: bold;
            margin-top: 20px;
            margin-bottom: 10px;
            color: #444;
        }
    </style>
</head>
<body>
    <h1>Estado Financiero del Vehículo</h1>

    <div class="section-title">Información del Vehículo</div>
    <table>
        <tr>
            <th>Vehículo</th>
            <td>{{ $vehicle->marca }} {{ $vehicle->modelo }} ({{ $vehicle->numero_placa }})</td>
        </tr>
        <tr>
            <th>Año</th>
            <td>{{ $vehicle->anio }}</td>
        </tr>
        <tr>
            <th>Color</th>
            <td>{{ $vehicle->color }}</td>
        </tr>
        <tr>
            <th>Usuario Asignado</th>
            <td>{{ $assignedUserName }}</td>
        </tr>
        <tr>
            <th>Negocio</th>
            <td>{{ $vehicle->negocio->nombre }}</td>
        </tr>
        <tr>
            <th>Período</th>
            <td>{{ $fechaInicial }} a {{ $fechaFinal }}</td>
        </tr>
    </table>

    <div class="section-title">Resumen Financiero</div>
    <table>
        <tr>
            <th>Total Ingresos</th>
            <td>${{ number_format($totalIngresos, 2) }}</td>
        </tr>
        <tr>
            <th>Total Egresos</th>
            <td>${{ number_format($totalEgresos, 2) }}</td>
        </tr>
        <tr>
            <th>Margen Bruto</th>
            <td>${{ number_format($margenBruto, 2) }}</td>
        </tr>
        <tr>
            <th>Rentabilidad</th>
            <td>{{ number_format($rentabilidad, 2) }}%</td>
        </tr>
        <tr>
            <th>Total Transacciones</th>
            <td>{{ $transacciones->count() }}</td>
        </tr>
    </table>

    <div class="section-title">Transacciones por Estado</div>
    <table>
        <thead>
            <tr>
                <th>Estado</th>
                <th>Ingresos</th>
                <th>Egresos</th>
                <th>Balance</th>
                <th>Total Transacciones</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($estadosFinancieros as $estado)
            <tr>
                <td>{{ $estado['estado_nombre'] }}</td>
                <td>${{ number_format($estado['ingresos'], 2) }}</td>
                <td>${{ number_format($estado['egresos'], 2) }}</td>
                <td>${{ number_format($estado['balance_estado'], 2) }}</td>
                <td>{{ $estado['total_transacciones_ingresos'] + $estado['total_transacciones_egresos'] }}</td>
            </tr>
            @endforeach
        </tbody>
    </table>

    <div class="section-title">Transacciones por Categoría</div>
    <table>
        <thead>
            <tr>
                <th>Categoría</th>
                <th>Ingresos</th>
                <th>Egresos</th>
                <th>Balance</th>
                <th>Total Transacciones</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($categoriasFinancieras as $categoria)
            <tr>
                <td>{{ $categoria['categoria_nombre'] }}</td>
                <td>${{ number_format($categoria['ingresos'], 2) }}</td>
                <td>${{ number_format($categoria['egresos'], 2) }}</td>
                <td>${{ number_format($categoria['balance_categoria'], 2) }}</td>
                <td>{{ $categoria['total_transacciones_ingresos'] + $categoria['total_transacciones_egresos'] }}</td>
            </tr>
            @endforeach
        </tbody>
    </table>

    <div class="section-title">Transacciones Detalladas</div>
    <table>
        <thead>
            <tr>
                <th>ID</th>
                <th>Fecha</th>
                <th>Ítem</th>
                <th>Cantidad</th>
                <th>Importe</th>
                <th>Tipo</th>
                <th>Categoría</th>
                <th>Estado</th>
                <th>Usuario Registro</th>
                <th>Observaciones</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($transacciones as $transaccion)
            <tr>
                <td>{{ $transaccion->id }}</td>
                <td>{{ $transaccion->fecha }}</td>
                <td>{{ $transaccion->item }}</td>
                <td>{{ $transaccion->cantidad }}</td>
                <td>${{ number_format($transaccion->importe_total, 2) }}</td>
                <td>{{ $transaccion->tipo_de_transaccion }}</td>
                <td>{{ $transaccion->categoria->nombre ?? 'Sin categoría' }}</td>
                <td>{{ $transaccion->estadoDeTransaccion->nombre ?? 'Sin estado' }}</td>
                <td>{{ $transaccion->user->generalData->nombre . ' ' . $transaccion->user->generalData->apellido ?? 'N/A' }}</td>
                <td>{{ $transaccion->observaciones ?? '' }}</td>
            </tr>
            @endforeach
        </tbody>
    </table>
</body>
</html>
