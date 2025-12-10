<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class FinancialTransactionsSeeder extends Seeder
{
    public function run(): void
    {
        // Todas las transacciones son de tipo Egreso, negocio Lease on (id=2) y usan estado_de_transaccion_id = 2 (Pagado)
        // Usamos IDs de categorías que existen en la tabla categories (1-17)
        DB::table('financial_transactions')->insert([
            // Transacciones de egresos para Lease on
            [
                'id' => 1,
                'negocio_id' => 2, // Lease on
                'metodo_id' => 3, // Transferencia Bancaria
                'categoria_id' => 17, // Servicios Carrier
                'user_id' => 2, // Carlos Mendoza
                'vehicle_id' => 1, // No aplica vehículo
                'estado_de_transaccion_id' => 2, // Pagado
                'fecha' => '2025-05-12',
                'punto_de_partida' => null,
                'destino' => null,
                'millas' => 0,
                'tipo_de_transaccion' => 'Egreso',
                'item' => 'Servicio de soporte logístico para la adquisición de camiones de carga en Estados Unidos. Mes de Mayo 2025',
                'cantidad' => 1.00,
                'importe_total' => 109.00,
                'cliente_proveedor' => 'Pulsar',
                'egreso_directo' => true,
                'observaciones' => 'Transacción #1',
                'estado' => true,
                'archivo' => null,
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ],
            [
                'id' => 2,
                'negocio_id' => 2, // Lease on
                'metodo_id' => 5, // Tarjeta Débito
                'categoria_id' => 16, // Viáticos de Alimentación
                'user_id' => 2, // Carlos Mendoza
                'vehicle_id' => 1,
                'estado_de_transaccion_id' => 2, // Pagado
                'fecha' => '2025-05-16',
                'punto_de_partida' => null,
                'destino' => null,
                'millas' => null,
                'tipo_de_transaccion' => 'Egreso',
                'item' => '5 Ltr Perrier, 1 Deposit Beverage Sin, 2PK Corn Dog',
                'cantidad' => 3.00,
                'importe_total' => 7.56,
                'cliente_proveedor' => 'Pilot Corbin, KY',
                'egreso_directo' => false,
                'observaciones' => 'Transacción #15357129',
                'estado' => true,
                'archivo' => null,
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ],
            [
                'id' => 3,
                'negocio_id' => 2, // Lease on
                'metodo_id' => 5, // Tarjeta Débito
                'categoria_id' => 16, // Viáticos de Alimentación
                'user_id' => 2, // Carlos Mendoza
                'vehicle_id' => 1,
                'estado_de_transaccion_id' => 2, // Pagado
                'fecha' => '2025-05-16',
                'punto_de_partida' => null,
                'destino' => null,
                'millas' => null,
                'tipo_de_transaccion' => 'Egreso',
                'item' => 'Big AZ Beef Charbro, BA Berry Cheese Dan, S. Pellegrino Spark, Regular Donut',
                'cantidad' => 4.00,
                'importe_total' => 13.16,
                'cliente_proveedor' => 'TA Jeffersonville OH',
                'egreso_directo' => false,
                'observaciones' => 'Transacción #41219387',
                'estado' => true,
                'archivo' => null,
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ],
            [
                'id' => 4,
                'negocio_id' => 2, // Lease on
                'metodo_id' => 4, // Efectivo
                'categoria_id' => 15, // Combustible
                'user_id' => 2, // Carlos Mendoza
                'vehicle_id' => 1,
                'estado_de_transaccion_id' => 2, // Pagado
                'fecha' => '2025-05-10',
                'punto_de_partida' => null,
                'destino' => null,
                'millas' => null,
                'tipo_de_transaccion' => 'Egreso',
                'item' => 'Combustible - Camión Freightliner Cascadia',
                'cantidad' => 150.00,
                'importe_total' => 450.00,
                'cliente_proveedor' => 'Shell Gas Station',
                'egreso_directo' => false,
                'observaciones' => 'Recarga de combustible en ruta',
                'estado' => true,
                'archivo' => null,
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ],
            [
                'id' => 5,
                'negocio_id' => 2, // Lease on
                'metodo_id' => 1, // Crédito
                'categoria_id' => 14, // Mantenimiento
                'user_id' => 2, // Carlos Mendoza
                'vehicle_id' => 1,
                'estado_de_transaccion_id' => 2, // Pagado
                'fecha' => '2025-05-05',
                'punto_de_partida' => null,
                'destino' => null,
                'millas' => null,
                'tipo_de_transaccion' => 'Egreso',
                'item' => 'Cambio de neumáticos delanteros',
                'cantidad' => 2.00,
                'importe_total' => 650.00,
                'cliente_proveedor' => 'Tire Service Center',
                'egreso_directo' => false,
                'observaciones' => 'Reemplazo por desgaste normal',
                'estado' => true,
                'archivo' => null,
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ],
            [
                'id' => 6,
                'negocio_id' => 2, // Lease on
                'metodo_id' => 2, // Cheque
                'categoria_id' => 13, // Seguros
                'user_id' => 2, // Carlos Mendoza
                'vehicle_id' => 1,
                'estado_de_transaccion_id' => 2, // Pagado
                'fecha' => '2025-05-01',
                'punto_de_partida' => null,
                'destino' => null,
                'millas' => null,
                'tipo_de_transaccion' => 'Egreso',
                'item' => 'Prima de seguro mensual - Camión',
                'cantidad' => 1.00,
                'importe_total' => 850.00,
                'cliente_proveedor' => 'Commercial Truck Insurance Co.',
                'egreso_directo' => false,
                'observaciones' => 'Correspondiente al mes de mayo 2025',
                'estado' => true,
                'archivo' => null,
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ],
            [
                'id' => 7,
                'negocio_id' => 2, // Lease on
                'metodo_id' => 3, // Transferencia Bancaria
                'categoria_id' => 12, // Peajes
                'user_id' => 2, // Carlos Mendoza
                'vehicle_id' => 1,
                'estado_de_transaccion_id' => 2, // Pagado
                'fecha' => '2025-05-08',
                'punto_de_partida' => 'Chicago, IL',
                'destino' => 'New York, NY',
                'millas' => 790,
                'tipo_de_transaccion' => 'Egreso',
                'item' => 'Peajes en ruta',
                'cantidad' => 5.00,
                'importe_total' => 75.00,
                'cliente_proveedor' => 'Various Toll Booths',
                'egreso_directo' => false,
                'observaciones' => 'Peajes en autopistas I-80, I-90 y I-76',
                'estado' => true,
                'archivo' => null,
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ],
            [
                'id' => 8,
                'negocio_id' => 2, // Lease on
                'metodo_id' => 5, // Tarjeta Débito
                'categoria_id' => 11, // Alojamiento
                'user_id' => 2, // Carlos Mendoza
                'vehicle_id' => 1,
                'estado_de_transaccion_id' => 2, // Pagado
                'fecha' => '2025-05-12',
                'punto_de_partida' => 'Atlanta, GA',
                'destino' => 'Miami, FL',
                'millas' => 660,
                'tipo_de_transaccion' => 'Egreso',
                'item' => 'Alojamiento en ruta',
                'cantidad' => 1.00,
                'importe_total' => 120.00,
                'cliente_proveedor' => 'Rest Inn',
                'egreso_directo' => false,
                'observaciones' => 'Parada nocturna en Jacksonville, FL',
                'estado' => true,
                'archivo' => null,
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ],
            [
                'id' => 9,
                'negocio_id' => 2, // Lease on
                'metodo_id' => 3, // Transferencia Bancaria
                'categoria_id' => 10, // Licencias y Permisos
                'user_id' => 2, // Carlos Mendoza
                'vehicle_id' => 1, // No aplica vehículo
                'estado_de_transaccion_id' => 2, // Pagado
                'fecha' => '2025-05-15',
                'punto_de_partida' => null,
                'destino' => null,
                'millas' => 0,
                'tipo_de_transaccion' => 'Egreso',
                'item' => 'Renovación de licencia comercial',
                'cantidad' => 1.00,
                'importe_total' => 350.00,
                'cliente_proveedor' => 'Department of Transportation',
                'egreso_directo' => true,
                'observaciones' => 'Licencia de operador comercial',
                'estado' => true,
                'archivo' => null,
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ],
            [
                'id' => 10,
                'negocio_id' => 2, // Lease on
                'metodo_id' => 4, // Efectivo
                'categoria_id' => 9, // Limpieza
                'user_id' => 2, // Carlos Mendoza
                'vehicle_id' => 1,
                'estado_de_transaccion_id' => 2, // Pagado
                'fecha' => '2025-05-14',
                'punto_de_partida' => null,
                'destino' => null,
                'millas' => null,
                'tipo_de_transaccion' => 'Egreso',
                'item' => 'Servicio de lavado de camión',
                'cantidad' => 1.00,
                'importe_total' => 65.00,
                'cliente_proveedor' => 'Truck Wash Express',
                'egreso_directo' => false,
                'observaciones' => 'Lavado completo interior y exterior',
                'estado' => true,
                'archivo' => null,
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ],
            [
                'id' => 11,
                'negocio_id' => 2, // Lease on
                'metodo_id' => 5, // Tarjeta Débito
                'categoria_id' => 8, // Repuestos y Accesorios
                'user_id' => 2, // Carlos Mendoza
                'vehicle_id' => 1,
                'estado_de_transaccion_id' => 2, // Pagado
                'fecha' => '2025-05-09',
                'punto_de_partida' => null,
                'destino' => null,
                'millas' => null,
                'tipo_de_transaccion' => 'Egreso',
                'item' => 'Filtro de aceite y filtro de combustible',
                'cantidad' => 2.00,
                'importe_total' => 85.00,
                'cliente_proveedor' => 'Truck Parts Store',
                'egreso_directo' => false,
                'observaciones' => 'Repuestos para mantenimiento programado',
                'estado' => true,
                'archivo' => null,
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ],
            [
                'id' => 12,
                'negocio_id' => 2, // Lease on
                'metodo_id' => 3, // Transferencia Bancaria
                'categoria_id' => 7, // Servicios Profesionales
                'user_id' => 2, // Carlos Mendoza
                'vehicle_id' => 1, // No aplica vehículo
                'estado_de_transaccion_id' => 2, // Pagado
                'fecha' => '2025-05-11',
                'punto_de_partida' => null,
                'destino' => null,
                'millas' => 0,
                'tipo_de_transaccion' => 'Egreso',
                'item' => 'Servicios contables mensuales',
                'cantidad' => 1.00,
                'importe_total' => 275.00,
                'cliente_proveedor' => 'Accounting Services LLC',
                'egreso_directo' => true,
                'observaciones' => 'Servicios de contabilidad para mayo 2025',
                'estado' => true,
                'archivo' => null,
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ],
            [
                'id' => 13,
                'negocio_id' => 2, // Lease on
                'metodo_id' => 5, // Tarjeta Débito
                'categoria_id' => 16, // Viáticos de Alimentación
                'user_id' => 2, // Carlos Mendoza
                'vehicle_id' => 1,
                'estado_de_transaccion_id' => 2, // Pagado
                'fecha' => '2025-05-17',
                'punto_de_partida' => null,
                'destino' => null,
                'millas' => null,
                'tipo_de_transaccion' => 'Egreso',
                'item' => 'Comida en restaurante',
                'cantidad' => 1.00,
                'importe_total' => 24.50,
                'cliente_proveedor' => 'Roadside Diner',
                'egreso_directo' => false,
                'observaciones' => 'Cena durante ruta',
                'estado' => true,
                'archivo' => null,
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ],
        ]);
        $this->command->info('✅ Se han creado 13 transacciones de egreso para Lease on con estado "Pagado" exitosamente');
    }
}
