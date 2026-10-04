<?php

namespace Database\Seeders;

use App\Infraestructura\Configuracion\SincronizadorTarifas;
use Illuminate\Database\Seeder;

/** Carga data/tarifas.json y data/agencias_evento.json (sin datos personales). */
class TarifasSeeder extends Seeder
{
    public function run(SincronizadorTarifas $sincronizador): void
    {
        $sincronizador->cargar();
    }
}
