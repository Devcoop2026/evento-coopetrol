<?php

namespace Tests\Concerns;

use Carbon\CarbonImmutable;
use Database\Seeders\DatosPruebaSeeder;
use Database\Seeders\TarifasSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * Base limpia en cada prueba (RefreshDatabase) con las tarifas de data/tarifas.json y, si se pide, los asociados y
 * Coopetrolitos ficticios de data/*.seed.json. El reloj se fija con fijarAhora() (RelojSistema usa CarbonImmutable::now()).
 */
trait ConDatosDePrueba
{
    use RefreshDatabase;

    protected function cargarDatosPrueba(): void
    {
        $this->seed(DatosPruebaSeeder::class);
    }

    protected function cargarTarifas(): void
    {
        $this->seed(TarifasSeeder::class);
    }

    /** Fija la fecha y hora actuales (ISO 8601, p. ej. '2026-10-05T15:00:00Z'). */
    protected function fijarAhora(string $momento): void
    {
        $this->travelTo(CarbonImmutable::parse($momento));
    }
}
