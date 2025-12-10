<table>
    <thead>
        <tr>
            <th colspan="7" style="text-align: center; font-weight: bold; font-size: 16px;">
                RESUMEN DE CAJAS OPERATIVAS
            </th>
        </tr>
        <tr>
            <th colspan="7" style="text-align: center;">
                Período: {{ $datos[0]['periodo']['fecha_inicio'] }} al {{ $datos[0]['periodo']['fecha_fin'] }}
            </th>
        </tr>
        <tr>
            <th colspan="7" style="text-align: center;">
                Fecha de generación: {{ date('d/m/Y H:i') }}
            </th>
        </tr>
        <tr>
            <th colspan="7">&nbsp;</th>
        </tr>
    </thead>
    <tbody>
        @foreach ($datos as $dato)
        <tr>
            <td colspan="7" style="font-weight: bold; background-color: #f0f0f0;">
                Caja: {{ $dato['caja']['nombre'] }} (ID: {{ $dato['caja']['id'] }})
            </td>
        </tr>
        <tr>
            <td colspan="7">
                <table>
                    <tr>
                        <th style="background-color: #f2f2f2; border: 1px solid #ddd; padding: 5px;">Saldo Inicial</th>
                        <th style="background-color: #f2f2f2; border: 1px solid #ddd; padding: 5px;">Total Ingresos</th>
                        <th style="background-color: #f2f2f2; border: 1px solid #ddd; padding: 5px;">Total Egresos</th>
                        <th style="background-color: #f2f2f2; border: 1px solid #ddd; padding: 5px;">Saldo Final</th>
                    </tr>
                    <tr>
                        <td style="border: 1px solid #ddd; padding: 5px;">${{ number_format($dato['resumen']['saldo_inicial'], 2, ',', '.') }}</td>
                        <td style="border: 1px solid #ddd; padding: 5px;">${{ number_format($dato['resumen']['total_ingresos'], 2, ',', '.') }}</td>
                        <td style="border: 1px solid #ddd; padding: 5px;">${{ number_format($dato['resumen']['total_egresos'], 2, ',', '.') }}</td>
                        <td style="border: 1px solid #ddd; padding: 5px;">${{ number_format($dato['resumen']['saldo_final'], 2, ',', '.') }}</td>
                    </tr>
                </table>
            </td>
        </tr>
        <tr>
            <td colspan="7" style="font-weight: bold; margin-top: 10px;">Detalle de Egresos</td>
        </tr>
        <tr>
            <td colspan="7">
                <table>
                    <tr>
                        <th style="background-color: #f2f2f2; border: 1px solid #ddd; padding: 5px;">ID</th>
                        <th style="background-color: #f2f2f2; border: 1px solid #ddd; padding: 5px;">Item</th>
                        <th style="background-color: #f2f2f2; border: 1px solid #ddd; padding: 5px;">Fecha Movimiento</th>
                        <th style="background-color: #f2f2f2; border: 1px solid #ddd; padding: 5px;">Monto</th>
                        <th style="background-color: #f2f2f2; border: 1px solid #ddd; padding: 5px;">Descripción</th>
                        <th style="background-color: #f2f2f2; border: 1px solid #ddd; padding: 5px;">Observaciones</th>
                    </tr>
                    @foreach ($dato['egresos'] as $egreso)
                    <tr>
                        <td style="border: 1px solid #ddd; padding: 5px;">{{ $egreso['id'] }}</td>
                        <td style="border: 1px solid #ddd; padding: 5px;">{{ $egreso['item'] }}</td>
                        <td style="border: 1px solid #ddd; padding: 5px;">{{ $egreso['fecha_movimiento'] }}</td>
                        <td style="border: 1px solid #ddd; padding: 5px;">${{ number_format($egreso['monto'], 2, ',', '.') }}</td>
                        <td style="border: 1px solid #ddd; padding: 5px;">{{ $egreso['descripcion_movimiento'] }}</td>
                        <td style="border: 1px solid #ddd; padding: 5px;">{{ $egreso['observaciones'] }}</td>
                    </tr>
                    @endforeach
                    <tr style="font-weight: bold; background-color: #f9f9f9;">
                        <td colspan="3" style="border: 1px solid #ddd; padding: 5px;">TOTAL EGRESOS</td>
                        <td style="border: 1px solid #ddd; padding: 5px;">${{ number_format($dato['resumen']['total_egresos'], 2, ',', '.') }}</td>
                        <td colspan="2" style="border: 1px solid #ddd; padding: 5px;"></td>
                    </tr>
                </table>
            </td>
        </tr>
        <tr>
            <td colspan="7">&nbsp;</td>
        </tr>
        @endforeach
    </tbody>
</table>
