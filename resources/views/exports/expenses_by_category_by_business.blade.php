<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Egresos por Categoría</title>
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
    <h1>Egresos por Categoría</h1>

    <div class="section-title">Información General</div>
    <table>
        <tr>
            <th>Negocio</th>
            <td>{{ $negocio ? $negocio->nombre : 'Todos los negocios' }}</td>
        </tr>
        <tr>
            <th>Período</th>
            <td>{{ $fechaInicial }} a {{ $fechaFinal }}</td>
        </tr>
        <tr>
            <th>Total Egresos</th>
            <td>${{ number_format($totalGlobal, 2) }}</td>
        </tr>
        <tr>
            <th>Cantidad de Transacciones</th>
            <td>{{ $cantidadGlobal }}</td>
        </tr>
        <tr>
            <th>Promedio por Transacción</th>
            <td>${{ $cantidadGlobal > 0 ? number_format($totalGlobal / $cantidadGlobal, 2) : '0.00' }}</td>
        </tr>
    </table>

    <div class="section-title">Estadísticas Adicionales</div>
    <table>
        <tr>
            <th>Categoría con Mayor Egreso</th>
            <td>{{ $categoriaMayorEgreso['categoria_nombre'] ?? 'N/A' }} - ${{ $categoriaMayorEgreso['total_categoria'] ?? '0.00' }}</td>
        </tr>
        <tr>
            <th>Categoría con Menor Egreso</th>
            <td>{{ $categoriaMenorEgreso['categoria_nombre'] ?? 'N/A' }} - ${{ $categoriaMenorEgreso['total_categoria'] ?? '0.00' }}</td>
        </tr>
    </table>

    <div class="section-title">Detalle por Categoría</div>
    <table>
        <thead>
            <tr>
                <th>Categoría</th>
                <th>Total Egresos</th>
                <th>Cantidad Transacciones</th>
                <th>Promedio</th>
                <th>Detalles por Negocio</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($todasCategorias as $categoria)
            <tr>
                <td>{{ $categoria['categoria_nombre'] }}</td>
                <td>${{ $categoria['total_categoria'] }}</td>
                <td>{{ $categoria['cantidad_transacciones'] }}</td>
                <td>${{ $categoria['promedio_egreso'] }}</td>
                <td>
                    @if (!empty($categoria['negocios']))
                        <table style="width: 100%; border-collapse: collapse; margin-top: 5px;">
                            <thead>
                                <tr>
                                    <th style="background-color: #f9f9f9; font-size: 12px;">Negocio</th>
                                    <th style="background-color: #f9f9f9; font-size: 12px;">Total Egresos</th>
                                    <th style="background-color: #f9f9f9; font-size: 12px;">Cantidad</th>
                                    <th style="background-color: #f9f9f9; font-size: 12px;">Promedio</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($categoria['negocios'] as $negocio)
                                <tr>
                                    <td style="font-size: 12px;">{{ $negocio['negocio_nombre'] }}</td>
                                    <td style="font-size: 12px;">${{ $negocio['total_egresos'] }}</td>
                                    <td style="font-size: 12px;">{{ $negocio['cantidad_transacciones'] }}</td>
                                    <td style="font-size: 12px;">${{ $negocio['promedio_egreso'] }}</td>
                                </tr>
                                @endforeach
                            </tbody>
                        </table>
                    @else
                        No hay datos
                    @endif
                </td>
            </tr>
            @endforeach
        </tbody>
    </table>
</body>
</html>
