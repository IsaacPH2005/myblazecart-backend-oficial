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

    <div class="section-title">Información del Período</div>
    <table>
        <tr>
            <th>Período</th>
            <td>{{ $fechaInicial }} a {{ $fechaFinal }}</td>
        </tr>
    </table>

    <div class="section-title">Mensaje</div>
    <p>No se encontraron egresos en el período especificado.</p>
</body>
</html>
