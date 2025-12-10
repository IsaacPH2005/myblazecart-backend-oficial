<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Negocio con Mayor Egreso</title>
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
    <h1>Negocio con Mayor Egreso</h1>

    <div class="section-title">Información del Negocio</div>
    <table>
        <tr>
            <th>Negocio</th>
            <td>{{ $negocioMayor->nombre }}</td>
        </tr>
        <tr>
            <th>Total Egresos</th>
            <td>${{ number_format($negocioMayor->total_egresos, 2) }}</td>
        </tr>
        <tr>
            <th>Cantidad de Transacciones</th>
            <td>{{ $negocioMayor->cantidad_transacciones }}</td>
        </tr>
        <tr>
            <th>Período</th>
            <td>{{ $fechaInicial }} a {{ $fechaFinal }}</td>
        </tr>
    </table>

    <div class="section-title">Desglose por Categorías</div>
    <table>
        <thead>
            <tr>
                <th>Categoría</th>
                <th>Total Egresos</th>
                <th>Cantidad Transacciones</th>
                <th>Porcentaje</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($desgloseCategorias as $categoria)
            <tr>
                <td>{{ $categoria->nombre }}</td>
                <td>${{ number_format($categoria->total_categoria, 2) }}</td>
                <td>{{ $categoria->cantidad_transacciones }}</td>
                <td>{{ number_format(($categoria->total_categoria / $totalEgresosNegocio) * 100, 2) }}%</td>
            </tr>
            @endforeach
        </tbody>
    </table>
</body>
</html>
