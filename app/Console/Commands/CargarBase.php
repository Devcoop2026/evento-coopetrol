<?php

namespace App\Console\Commands;

use App\Aplicacion\Bases\CargarBase as CasoCargarBase;
use App\Dominio\Compartido\ErrorValidacion;
use Illuminate\Console\Command;

/** Carga masiva de la base de asociados o de Coopetrolitos desde un Excel o CSV (vista previa sin --confirmar). */
final class CargarBase extends Command
{
    protected $signature = 'evento:cargar-base
        {tipo : asociados|coopetrolitos}
        {archivo : Ruta del Excel (.xlsx) o CSV}
        {--confirmar : Reemplaza la base (sin esta opción solo muestra la vista previa)}';

    protected $description = 'Carga la base de asociados o de Coopetrolitos desde un Excel o CSV';

    public function handle(CasoCargarBase $cargar): int
    {
        $tipo = mb_strtolower((string) $this->argument('tipo'));
        if (! in_array($tipo, ['asociados', 'coopetrolitos'], true)) {
            $this->error('Tipo inválido. Use asociados o coopetrolitos.');

            return self::FAILURE;
        }
        $ruta = (string) $this->argument('archivo');
        if (! is_file($ruta) || ! is_readable($ruta)) {
            $this->error("No se encontró el archivo {$ruta}.");

            return self::FAILURE;
        }

        try {
            $resumen = $cargar->ejecutar($tipo, (string) file_get_contents($ruta), (bool) $this->option('confirmar'),
                basename($ruta), 'consola:'.self::usuarioSistema());
        } catch (ErrorValidacion $error) {
            $this->error($error->getMessage());

            return self::FAILURE;
        }

        $activos = isset($resumen['activos']) ? " ({$resumen['activos']} activos)" : '';
        $this->line("{$resumen['registros']} registros válidos{$activos}, {$resumen['omitidas']} filas omitidas.");
        foreach ($resumen['advertencias'] as $advertencia) {
            $this->warn("Advertencia: {$advertencia}");
        }
        foreach ($resumen['errores'] as $error) {
            $this->line("  - {$error}");
        }
        if ($resumen['omitidas'] > count($resumen['errores'])) {
            $this->line('  ... y '.($resumen['omitidas'] - count($resumen['errores'])).' filas omitidas más.');
        }
        $resumen['guardado']
            ? $this->info("Base de {$tipo} reemplazada.")
            : $this->comment('Vista previa: no se guardó nada. Agregue --confirmar para reemplazar la base.');

        return self::SUCCESS;
    }

    /** Usuario del sistema operativo que ejecuta la consola (queda en el registro de cargas). */
    public static function usuarioSistema(): string
    {
        $usuario = getenv('USERNAME') ?: getenv('USER') ?: get_current_user();

        return mb_substr(preg_replace('/[^\w.\-]/u', '', (string) $usuario) ?: 'desconocido', 0, 50);
    }
}
