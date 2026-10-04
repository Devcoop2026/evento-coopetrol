<?php

namespace App\Console\Commands;

use App\Infraestructura\Respaldo\CifradoRespaldo;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use RuntimeException;

/**
 * Descifra un respaldo de evento:respaldo (archivos .enc) en otra carpeta e indica cómo restaurarlo. No modifica la base:
 * la restauración se hace a mano con pg_restore, después de revisar a qué base apunta.
 */
final class RestaurarRespaldo extends Command
{
    protected $signature = 'evento:restaurar
        {origen : Carpeta del respaldo (AAAAMMDD-HHMM)}
        {destino : Carpeta donde se dejan los archivos descifrados}';

    protected $description = 'Descifra un respaldo e indica el comando pg_restore para restaurarlo';

    public function handle(): int
    {
        $origen = rtrim((string) $this->argument('origen'), '/\\');
        $destino = rtrim((string) $this->argument('destino'), '/\\');
        $cifrados = glob("{$origen}/*.enc") ?: [];
        if (! is_dir($origen) || $cifrados === []) {
            $this->error("No hay archivos cifrados (.enc) en {$origen}.");

            return self::FAILURE;
        }

        try {
            $clave = CifradoRespaldo::clave(config('evento.respaldo.clave'))
                ?? throw new RuntimeException('Defina RESPALDO_CLAVE (la misma con la que se hizo el respaldo).');
            File::ensureDirectoryExists($destino, 0700);
            foreach ($cifrados as $archivo) {
                $salida = $destino.'/'.basename($archivo, '.enc');
                CifradoRespaldo::descifrar($archivo, $salida, $clave);
                $this->line('Descifrado: '.basename($salida));
            }
        } catch (RuntimeException $error) {
            $this->error($error->getMessage());

            return self::FAILURE;
        }

        $this->newLine();
        $this->info("Archivos descifrados en {$destino}. Para restaurar:");
        if (is_file("{$destino}/evento.dump")) {
            $this->line('  Base PostgreSQL (borra y vuelve a crear las tablas de la base indicada; use la conexión directa de Neon, sin -pooler):');
            $this->line("    pg_restore --clean --if-exists --no-owner --no-privileges --dbname=\"postgresql://usuario:clave@servidor/base?sslmode=require\" \"{$destino}/evento.dump\"");
        }
        if (is_file("{$destino}/evento.db")) {
            $this->line("  Base SQLite: copie {$destino}/evento.db en la ruta de DB_DATABASE con la aplicación detenida.");
        }
        if (is_file("{$destino}/soportes.tar.gz")) {
            $this->line("  Comprobantes en disco: tar -xzf \"{$destino}/soportes.tar.gz\" -C <carpeta del disco de soportes>");
        }
        if (is_file("{$destino}/datos.tar.gz")) {
            $this->line("  Configuración (data/*.json): tar -xzf \"{$destino}/datos.tar.gz\" -C data   (revise antes de reemplazar)");
        }
        $this->warn('Los archivos descifrados contienen datos personales: bórrelos al terminar.');

        return self::SUCCESS;
    }
}
