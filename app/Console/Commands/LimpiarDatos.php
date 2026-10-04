<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Borra datos de prueba o de un evento terminado (p. ej. al cumplirse fecha_supresion_datos). Conserva los usuarios del
 * panel, las tarifas y la configuración. Sin --confirmar solo muestra cuántos registros se borrarían; con --confirmar
 * hace antes un respaldo (evento:respaldo) y borra en una transacción.
 */
final class LimpiarDatos extends Command
{
    /** @var array<string, list<string>> grupo -> tablas (en orden de borrado) */
    public const GRUPOS = [
        'inscripciones' => ['soportes', 'inscripcion_personas', 'inscripciones', 'soportes_archivos'],
        'bases' => ['coopetrolitos', 'asociados', 'cargas_bases'],
        'auditoria' => ['auditoria'],
        'cupos' => ['cupos_ajustados'],
    ];

    protected $signature = 'evento:limpiar
        {que : inscripciones|bases|auditoria|cupos|todo}
        {--confirmar : Borra los datos (sin esta opción solo muestra la vista previa)}';

    protected $description = 'Borra inscripciones, bases de asociados, auditoría o cupos ajustados (con respaldo previo)';

    public function handle(): int
    {
        $que = mb_strtolower((string) $this->argument('que'));
        if ($que !== 'todo' && ! isset(self::GRUPOS[$que])) {
            $this->error('Indique qué limpiar: inscripciones, bases, auditoria, cupos o todo.');

            return self::FAILURE;
        }
        $grupos = $que === 'todo' ? array_keys(self::GRUPOS) : [$que];
        $tablas = array_merge(...array_map(fn ($g) => self::GRUPOS[$g], $grupos));
        $almacen = (string) config('evento.almacen_soportes');
        $discoSoportes = in_array('inscripciones', $grupos, true) && $almacen !== 'base_datos' ? Storage::disk($almacen) : null;

        $this->line($this->option('confirmar') ? 'Se borrarán:' : 'Se borrarían:');
        foreach ($tablas as $tabla) {
            $this->line(sprintf('  %-22s %d', $tabla, DB::table($tabla)->count()));
        }
        if ($discoSoportes) {
            $this->line(sprintf('  %-22s %d', "archivos ({$almacen})", count($discoSoportes->allFiles())));
        }
        if (! $this->option('confirmar')) {
            $this->comment('Vista previa: no se borró nada. Agregue --confirmar para limpiar (se hará un respaldo antes).');

            return self::SUCCESS;
        }

        if ($this->call('evento:respaldo') !== self::SUCCESS) {
            $this->error('No se limpió nada porque el respaldo falló.');

            return self::FAILURE;
        }

        DB::transaction(function () use ($tablas) {
            if (DB::getDriverName() === 'pgsql') {
                // RESTART IDENTITY: los consecutivos vuelven a empezar (la próxima referencia es EVTaa-000001).
                DB::statement('TRUNCATE TABLE '.implode(', ', $tablas).' RESTART IDENTITY CASCADE');

                return;
            }
            foreach ($tablas as $tabla) {
                DB::table($tabla)->delete();
            }
            if (DB::getDriverName() === 'sqlite' && DB::table('sqlite_master')->where('name', 'sqlite_sequence')->exists()) {
                DB::table('sqlite_sequence')->whereIn('name', $tablas)->delete();
            }
        });
        if ($discoSoportes) {
            foreach ($discoSoportes->allFiles() as $archivo) {
                $discoSoportes->delete($archivo);
            }
        }
        $this->info('Limpieza terminada. Se conservan los usuarios del panel, las tarifas y la configuración.');

        return self::SUCCESS;
    }
}
