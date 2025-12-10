<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class TransactionStateSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        DB::table('transaction_states')->insert([
            ['id' => 1, 'nombre' => 'Reembolso', 'estado' => true],
            ['id' => 2, 'nombre' => 'Pagado', 'estado' => true],
            ['id' => 3, 'nombre' => 'Por Cobrar', 'estado' => true],
            ['id' => 4, 'nombre' => 'Por Pagar', 'estado' => true],
            ['id' => 5, 'nombre' => 'Pago Parcial', 'estado' => true],
        ]);
    }
}
