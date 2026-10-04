<?php

namespace Database\Seeders;

use App\Dominio\Seguridad\HuellaFecha;
use App\Models\Asociado;
use App\Models\Coopetrolito;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Asociados y Coopetrolitos FICTICIOS (data/asociados.seed.json y data/coopetrolitos.seed.json) para desarrollo y
 * pruebas. Reemplaza ambas bases. Nunca en producción: php artisan db:seed --class=DatosPruebaSeeder
 */
class DatosPruebaSeeder extends Seeder
{
    public function run(HuellaFecha $huella): void
    {
        if (app()->isProduction()) {
            throw new RuntimeException('Los datos de prueba no se cargan en producción.');
        }
        $this->call(TarifasSeeder::class);
        $leer = fn (string $archivo) => json_decode(file_get_contents(config('evento.datos')."/{$archivo}"), true);

        DB::transaction(function () use ($leer, $huella) {
            Coopetrolito::query()->delete();
            Asociado::query()->delete();
            Asociado::query()->insert(array_map(fn (array $a) => [
                'documento' => $a['documento'],
                'nombre' => $a['nombre'],
                'agencia' => $a['agencia'],
                'estado' => $a['estado'],
                'fecha_actualizacion' => $a['fecha_actualizacion'] ?? null,
                'expedicion_hmac' => ! empty($a['fecha_expedicion']) ? $huella->calcular($a['fecha_expedicion']) : null,
                'fecha_nacimiento' => $a['fecha_nacimiento'] ?? null,
            ], $leer('asociados.seed.json')));
            Coopetrolito::query()->insert(array_map(fn (array $c) => [
                'documento' => $c['documento'],
                'nombre' => $c['nombre'],
                'documento_asociado' => $c['documento_asociado'],
                'fecha_nacimiento' => $c['fecha_nacimiento'] ?? null,
            ], $leer('coopetrolitos.seed.json')));
        });
    }
}
