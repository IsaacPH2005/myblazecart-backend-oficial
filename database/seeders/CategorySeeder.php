<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class CategorySeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        DB::table('categories')->insert([
            ['id' => 1, 'nombre' => 'Combustible', 'estado' => true],
            ['id' => 2, 'nombre' => 'Comisiones', 'estado' => true],
            ['id' => 3, 'nombre' => 'Dividendo', 'estado' => true],
            ['id' => 4, 'nombre' => 'Flip Camiones', 'estado' => true],
            ['id' => 5, 'nombre' => 'Honorarios', 'estado' => true],
            ['id' => 6, 'nombre' => 'Impuestos', 'estado' => true],
            ['id' => 7, 'nombre' => 'Mantenimiento', 'estado' => true],
            ['id' => 8, 'nombre' => 'Multas', 'estado' => true],
            ['id' => 9, 'nombre' => 'Otros', 'estado' => true],
            ['id' => 10, 'nombre' => 'Parqueo', 'estado' => true],
            ['id' => 11, 'nombre' => 'Peajes', 'estado' => true],
            ['id' => 12, 'nombre' => 'Plataforma Digital', 'estado' => true],
            ['id' => 13, 'nombre' => 'Provisión Reparación', 'estado' => true],
            ['id' => 14, 'nombre' => 'Publicidad', 'estado' => true],
            ['id' => 15, 'nombre' => 'Reparación', 'estado' => true],
            ['id' => 16, 'nombre' => 'Seguros', 'estado' => true],
            ['id' => 17, 'nombre' => 'Servicios Carrier', 'estado' => true],
            ['id' => 18, 'nombre' => 'Servicios Externos', 'estado' => true],
            ['id' => 19, 'nombre' => 'Viáticos de Alimentación', 'estado' => true],
            ['id' => 20, 'nombre' => 'Viáticos de Hospedaje', 'estado' => true],
            ['id' => 21, 'nombre' => 'Viáticos de Traslado', 'estado' => true],
        ]);
    }
}
