<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /** `php artisan db:seed` solo carga tarifas; los datos ficticios se cargan aparte (DatosPruebaSeeder). */
    public function run(): void
    {
        $this->call(TarifasSeeder::class);
    }
}
