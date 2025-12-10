<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Estado Financiero de Todos los Negocios</title>
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
    <h1>Estado Financiero de Todos los Negocios</h1>

    <div class="section-title">Información del Período</div>
    <table>
        <tr>
            <th>Período</th>
            <td>{{ $fechaInicial }} a {{ $fechaFinal }}</td>
        </tr>
    </table>

    <div class="section-title">Resumen General</div>
    <table>
        <tr>
            <th>Total Ingresos</th>
            <td>${{ number_format($totalGeneralIngresos, 2) }}</td>
        </tr>
        <tr>
            <th>Total Egresos</th>
            <td>${{ number_format($totalGeneralEgresos, 2) }}</td>
        </tr>
        <tr>
            <th>Margen Bruto</th>
            <td>${{ number_format($totalGeneralMargen, 2) }}</td>
        </tr>
        <tr>
            <th>Rentabilidad</th>
            <td>{{ number_format($rentabilidadGeneral, 2) }}%</td>
        </tr>
        <tr>
            <th>Cantidad de Negocios</th>
            <td>{{ count($businessesFinancialData) }}</td>
        </tr>
    </table>

    <div class="section-title">Detalle por Negocio</div>
    <table>
        <thead>
            <tr>
                <th>Negocio</th>
                <th>Estado</th>
                <th>Total Ingresos</th>
                <th>Total Egresos</th>
                <th>Margen Bruto</th>
                <th>Rentabilidad</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($businessesFinancialData as $business)
            <tr>
                <td>{{ $business['negocio']['nombre'] }}</td>
                <td>{{ $business['negocio']['estado'] }}</td>
                <td>${{ $business['resumen_financiero']['total_ingresos'] }}</td>
                <td>${{ $business['resumen_financiero']['total_egresos'] }}</td>
                <td>${{ $business['resumen_financiero']['margen_bruto'] }}</td>
                <td>{{ $business['resumen_financiero']['rentabilidad_porcentaje'] }}</td>
            </tr>
            @endforeach
        </tbody>
    </table>
</body>
</html>
