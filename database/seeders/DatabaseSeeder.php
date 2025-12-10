<?php

namespace Database\Seeders;

use App\Models\User;
// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call([
            PermissionSeeder::class,
            RoleSeeder::class,
            RolePermissionSeeder::class,
            AdminSeeder::class,
            /*   DriverSeeder::class,
            DriverDocumentsSeed::class, */
            //transacciones financieras
            /*             OperatingBoxSeeder::class, */
            PaymentMethodsSeeder::class,
            TransactionStateSeeder::class,
            BusinessSeeder::class,
            /* VehicleSeeder::class, */

            CategorySeeder::class,
            /*  FinancialTransactionsSeeder::class, */
        ]);
    }
}
