<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class BusinessSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        DB::table('businesses')->insert([
            ['id' => 1, 'nombre' => 'Lease on', 'estado' => true],
            ['id' => 2, 'nombre' => 'Dispatch', 'estado' => true],
            ['id' => 3, 'nombre' => 'Flip', 'estado' => true],
        ]);
    }
}
