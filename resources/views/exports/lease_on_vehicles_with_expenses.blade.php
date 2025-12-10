<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Vehículos Lease On con Egresos</title>
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
    <h1>Vehículos Lease On con Egresos</h1>

    <div class="section-title">Información General</div>
    <table>
        <tr>
            <th>Negocio</th>
            <td>{{ $leaseOn->nombre }}</td>
        </tr>
        <tr>
            <th>Período</th>
            <td>{{ $fechaInicial }} a {{ $fechaFinal }}</td>
        </tr>
        <tr>
            <th>Total Egresos</th>
            <td>${{ number_format($totalGlobalExpenses, 2) }}</td>
        </tr>
        <tr>
            <th>Cantidad de Transacciones</th>
            <td>{{ $totalGlobalTransactions }}</td>
        </tr>
        <tr>
            <th>Promedio por Transacción</th>
            <td>${{ $totalGlobalTransactions > 0 ? number_format($totalGlobalExpenses / $totalGlobalTransactions, 2) : '0.00' }}</td>
        </tr>
        <tr>
            <th>Cantidad de Vehículos</th>
            <td>{{ count($vehiclesWithExpenses) }}</td>
        </tr>
    </table>

    <div class="section-title">Detalle por Vehículo</div>
    @foreach ($vehiclesWithExpenses as $vehicle)
    <div class="section-title">Vehículo: {{ $vehicle['vehicle_info']['marca'] }} {{ $vehicle['vehicle_info']['modelo'] }} ({{ $vehicle['vehicle_info']['numero_placa'] }})</div>

    <table>
        <tr>
            <th>Usuario Asignado</th>
            <td>{{ $vehicle['vehicle_info']['usuario_asignado'] }}</td>
        </tr>
        <tr>
            <th>Total Egresos</th>
            <td>${{ $vehicle['resumen_egresos']['total_egresos'] }}</td>
        </tr>
        <tr>
            <th>Cantidad de Transacciones</th>
            <td>{{ $vehicle['resumen_egresos']['cantidad_transacciones'] }}</td>
        </tr>
        <tr>
            <th>Promedio por Transacción</th>
            <td>${{ $vehicle['resumen_egresos']['promedio_egreso'] }}</td>
        </tr>
    </table>

    <div class="section-title">Egresos por Categoría</div>
    <table>
        <thead>
            <tr>
                <th>Categoría</th>
                <th>Total Egresos</th>
                <th>Cantidad Transacciones</th>
                <th>Promedio</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($vehicle['egresos_por_categoria'] as $categoria)
            <tr>
                <td>{{ $categoria['categoria_nombre'] }}</td>
                <td>${{ $categoria['total_egresos'] }}</td>
                <td>{{ $categoria['cantidad_transacciones'] }}</td>
                <td>${{ $categoria['promedio_egreso'] }}</td>
            </tr>
            @endforeach
        </tbody>
    </table>

    <div class="section-title">Transacciones</div>
    <table>
        <thead>
            <tr>
                <th>ID</th>
                <th>Fecha</th>
                <th>Ítem</th>
                <th>Cantidad</th>
                <th>Importe</th>
                <th>Categoría</th>
                <th>Estado</th>
                <th>Usuario Registro</th>
                <th>Observaciones</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($vehicle['egresos'] as $egreso)
            <tr>
                <td>{{ $egreso['id'] }}</td>
                <td>{{ $egreso['fecha'] }}</td>
                <td>{{ $egreso['item'] }}</td>
                <td>{{ $egreso['cantidad'] }}</td>
                <td>${{ $egreso['importe_total'] }}</td>
                <td>{{ $egreso['categoria'] }}</td>
                <td>{{ $egreso['estado'] }}</td>
                <td>{{ $egreso['usuario_registro'] }}</td>
                <td>{{ $egreso['observaciones'] }}</td>
            </tr>
            @endforeach
        </tbody>
    </table>
    @endforeach
</body>
</html>
