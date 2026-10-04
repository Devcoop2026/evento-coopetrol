<?php

namespace App\Console\Commands;

use App\Infraestructura\Respaldo\CifradoRespaldo;
use Illuminate\Console\Command;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Phar;
use PharData;
use RuntimeException;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Respaldo cifrado en RESPALDO_DIR/AAAAMMDD-HHMM (hora de Colombia):
 *   evento.dump      base PostgreSQL (pg_dump, formato custom; incluye los comprobantes si se guardan en la base)
 *   evento.db        base SQLite (solo si la conexión por defecto es SQLite)
 *   soportes.tar.gz  comprobantes guardados en un disco (SOPORTES_ALMACEN distinto de base_datos)
 *   datos.tar.gz     data/*.json (configuración del evento)
 * Con RESPALDO_CLAVE cada archivo se cifra con AES-256-GCM (.enc) y se borra la copia sin cifrar. Se eliminan las
 * carpetas de respaldo con más de RETENCION_DIAS días.
 */
final class Respaldar extends Command
{
    protected $signature = 'evento:respaldo';

    protected $description = 'Respaldo cifrado de la base de datos, los comprobantes y la configuración (data/*.json)';

    public function handle(): int
    {
        $base = self::directorioRespaldos();
        $nombre = now('America/Bogota')->format('Ymd-Hi');
        $carpeta = "{$base}/{$nombre}";
        if (is_dir($carpeta)) {
            $carpeta .= '-'.now('America/Bogota')->format('s'); // dos respaldos en el mismo minuto
        }

        try {
            $clave = CifradoRespaldo::clave(config('evento.respaldo.clave'));
            File::ensureDirectoryExists($carpeta, 0700);

            $this->respaldarBase($carpeta);
            $this->respaldarComprobantes($carpeta);
            $this->empaquetar("{$carpeta}/datos.tar", glob(rtrim((string) config('evento.datos'), '/\\').'/*.json') ?: []);

            if ($clave !== null) {
                foreach (File::files($carpeta) as $archivo) {
                    CifradoRespaldo::cifrar($archivo->getPathname(), $archivo->getPathname().'.enc', $clave);
                    unlink($archivo->getPathname());
                }
            } else {
                $this->warn('Aviso: RESPALDO_CLAVE no está definida; el respaldo NO está cifrado.');
            }
        } catch (Throwable $error) {
            File::deleteDirectory($carpeta);
            $this->error("No se pudo hacer el respaldo: {$error->getMessage()}");

            return self::FAILURE;
        }

        $this->eliminarAntiguos($base);
        $tamano = array_sum(array_map(fn ($a) => $a->getSize(), File::files($carpeta)));
        $this->info("Respaldo listo en {$carpeta} (".self::tamanoLegible($tamano).').');

        return self::SUCCESS;
    }

    public static function directorioRespaldos(): string
    {
        return rtrim((string) (config('evento.respaldo.dir') ?: base_path('respaldos')), '/\\');
    }

    private function respaldarBase(string $carpeta): void
    {
        /** @var Connection $db */
        $db = DB::connection();
        match ($db->getDriverName()) {
            'pgsql' => $this->pgDump($db->getConfig(), "{$carpeta}/evento.dump"),
            'sqlite' => $db->statement('VACUUM INTO ?', ["{$carpeta}/evento.db"]),
            default => throw new RuntimeException("El respaldo no admite la base {$db->getDriverName()}."),
        };
        $this->line('Base de datos respaldada.');
    }

    /** @param  array<string, mixed>  $config  configuración de la conexión (DB_URL ya separada en sus partes) */
    private function pgDump(array $config, string $destino): void
    {
        $pgDump = self::ubicarPgDump();
        $proceso = new Process([
            $pgDump, '--format=custom', '--no-owner', '--no-privileges', '--no-password',
            '--host='.($config['host'] ?? '127.0.0.1'), '--port='.($config['port'] ?? 5432),
            '--username='.($config['username'] ?? 'postgres'), '--file='.$destino, (string) ($config['database'] ?? ''),
        ], null, [
            'PGPASSWORD' => (string) ($config['password'] ?? ''),
            'PGSSLMODE' => (string) ($config['sslmode'] ?? 'prefer'),
            'PGCONNECT_TIMEOUT' => '30',
        ], null, 3600);
        $proceso->run();
        if (! $proceso->isSuccessful()) {
            throw new RuntimeException('pg_dump falló: '.trim($proceso->getErrorOutput() ?: $proceso->getOutput()));
        }
    }

    /**
     * Ruta de pg_dump: PG_DUMP, el PATH o las carpetas de instalación habituales del cliente de PostgreSQL.
     * Debe ser de la misma versión mayor del servidor o superior (Neon: PostgreSQL 16 o 17).
     */
    public static function ubicarPgDump(): string
    {
        $configurado = (string) (config('evento.respaldo.pg_dump') ?: 'pg_dump');
        if (is_file($configurado)) {
            return $configurado;
        }
        $carpetas = array_merge(
            glob('C:/Program Files/PostgreSQL/*/bin', GLOB_ONLYDIR) ?: [],
            glob('/usr/lib/postgresql/*/bin', GLOB_ONLYDIR) ?: [],
            ['/usr/local/bin', '/opt/homebrew/bin'],
        );
        rsort($carpetas, SORT_NATURAL); // la versión más reciente primero

        return (new ExecutableFinder)->find($configurado, null, $carpetas) ?? throw new RuntimeException(
            "No se encontró pg_dump ({$configurado}). Instale el cliente de PostgreSQL (misma versión del servidor o superior; "
            .'en Debian/Ubuntu: apt install postgresql-client; en Windows: instalador de PostgreSQL, componente '
            .'"Command Line Tools") o indique su ruta en PG_DUMP. En Neon también puede restaurar la base a un momento '
            .'anterior (point-in-time restore) desde su consola.'
        );
    }

    private function respaldarComprobantes(string $carpeta): void
    {
        $almacen = (string) config('evento.almacen_soportes');
        if ($almacen === 'base_datos') {
            $this->line('Comprobantes: incluidos en la base de datos.');

            return;
        }
        $disco = Storage::disk($almacen);
        $archivos = $disco->allFiles();
        $tar = "{$carpeta}/soportes.tar";
        if ($archivos === []) {
            $this->line("Comprobantes: el disco \"{$almacen}\" no tiene archivos.");

            return;
        }
        self::crearTarGz($tar, function (PharData $archivo) use ($archivos, $disco) {
            foreach ($archivos as $ruta) {
                $archivo->addFromString($ruta, (string) $disco->get($ruta));
            }
        });
        $this->line(count($archivos)." comprobantes respaldados (disco \"{$almacen}\").");
    }

    /** @param  list<string>  $rutas */
    private function empaquetar(string $tar, array $rutas): void
    {
        if ($rutas === []) {
            return;
        }
        self::crearTarGz($tar, function (PharData $archivo) use ($rutas) {
            foreach ($rutas as $ruta) {
                $archivo->addFile($ruta, basename($ruta));
            }
        });
    }

    /** Crea {$tar}.gz con lo que agregue `$llenar` y borra el .tar intermedio. */
    private static function crearTarGz(string $tar, callable $llenar): void
    {
        $archivo = new PharData($tar);
        $llenar($archivo);
        $archivo->compress(Phar::GZ);
        unset($archivo); // en Windows el .tar queda bloqueado mientras exista el objeto
        unlink($tar);
    }

    private function eliminarAntiguos(string $base): void
    {
        $dias = (int) config('evento.respaldo.retencion_dias', 30);
        if ($dias <= 0 || ! is_dir($base)) {
            return;
        }
        $limite = now('America/Bogota')->subDays($dias)->format('Ymd');
        foreach (File::directories($base) as $directorio) {
            if (preg_match('/^(\d{8})-\d{4}(-\d{2})?$/D', basename($directorio), $m) && $m[1] < $limite) {
                File::deleteDirectory($directorio);
                $this->line('Respaldo antiguo eliminado: '.basename($directorio));
            }
        }
    }

    public static function tamanoLegible(int $bytes): string
    {
        return match (true) {
            $bytes >= 1048576 => number_format($bytes / 1048576, 1, ',', '.').' MB',
            $bytes >= 1024 => number_format($bytes / 1024, 1, ',', '.').' KB',
            default => "{$bytes} bytes",
        };
    }
}
