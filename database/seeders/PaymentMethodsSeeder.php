<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class PaymentMethodsSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        DB::table('payment_methods')->insert([
            ['id' => 1, 'nombre' => 'Efectivo', 'estado' => true],
            ['id' => 2, 'nombre' => 'Tarjeta Crédito', 'estado' => true],
            ['id' => 3, 'nombre' => 'Transferencia Bancaria Crédito', 'estado' => true],
            ['id' => 4, 'nombre' => 'Tarjeta de Descuento Diesel', 'estado' => true],
            ['id' => 5, 'nombre' => 'Tarjeta Débito', 'estado' => true],
            ['id' => 6, 'nombre' => 'ACH', 'estado' => true],
            ['id' => 7, 'nombre' => 'Wire Transfer', 'estado' => true],
            ['id' => 8, 'nombre' => 'Zelle', 'estado' => true],
            ['id' => 9, 'nombre' => 'Débito Directo', 'estado' => true],
        ]);
    }
}
