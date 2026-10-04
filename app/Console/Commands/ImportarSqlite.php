<?php

namespace App\Console\Commands;

use App\Dominio\Inscripcion\AlmacenComprobantes;
use App\Dominio\Inscripcion\Pagos\TipoComprobante;
use App\Infraestructura\Configuracion\SincronizadorTarifas;
use App\Infraestructura\Persistencia\FormatoFecha;
use DateTimeImmutable;
use Illuminate\Console\Command;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * Migración única de los datos de la versión anterior (Node.js + SQLite, esquema user_version 9) a la base actual.
 * Conserva los id y las referencias de las inscripciones y los id de los soportes; las claves de los usuarios del panel
 * (scrypt) se convierten al hash de Laravel en su primer ingreso. Los comprobantes se leen de la carpeta de soportes
 * anterior y se guardan con el almacén configurado (SOPORTES_ALMACEN). Las tarifas, el evento y las agencias de los
 * eventos compartidos no se importan: salen de data/tarifas.json y data/agencias_evento.json. Las sesiones se descartan.
 * Todo corre en una transacción: si algo falla, la base queda como estaba.
 */
final class ImportarSqlite extends Command
{
    private const VERSION_ESQUEMA = 9;

    private const LOTE = 500;

    protected $signature = 'evento:importar-sqlite
        {archivo : evento.db de la versión anterior}
        {--soportes= : Carpeta de comprobantes de la versión anterior}
        {--confirmar : Importa los datos (sin esta opción solo muestra la vista previa)}';

    protected $description = 'Importa una sola vez los datos de la versión anterior (SQLite) a la base actual';

    /** @var list<string> */
    private array $advertencias = [];

    public function handle(AlmacenComprobantes $almacen, SincronizadorTarifas $tarifas): int
    {
        $ruta = (string) $this->argument('archivo');
        if (! is_file($ruta)) {
            $this->error("No se encontró el archivo {$ruta}.");

            return self::FAILURE;
        }
        $carpetaSoportes = $this->option('soportes') !== null ? rtrim((string) $this->option('soportes'), '/\\') : null;
        if ($carpetaSoportes !== null && ! is_dir($carpetaSoportes)) {
            $this->error("No se encontró la carpeta de soportes {$carpetaSoportes}.");

            return self::FAILURE;
        }

        config(['database.connections.anterior' => [
            'driver' => 'sqlite', 'database' => realpath($ruta), 'prefix' => '', 'foreign_key_constraints' => false,
        ]]);
        DB::purge('anterior');
        try {
            /** @var Connection $anterior */
            $anterior = DB::connection('anterior');
            $version = (int) $anterior->scalar('PRAGMA user_version');
            $conteos = $this->conteos($anterior);
        } catch (Throwable $error) {
            $this->error("El archivo no es una base de la versión anterior: {$error->getMessage()}");

            return self::FAILURE;
        }
        if ($version !== self::VERSION_ESQUEMA) {
            $this->warn("Aviso: la base anterior tiene la versión de esquema {$version} (se esperaba ".self::VERSION_ESQUEMA.').');
        }

        $this->line("Base anterior: {$ruta}");
        foreach ($conteos as $tabla => $n) {
            $this->line(sprintf('  %-22s %d', $tabla, $n));
        }
        $conArchivo = $anterior->table('soportes')->where('archivo', '<>', '')->pluck('archivo');
        $faltantes = $conArchivo->reject(fn ($a) => $carpetaSoportes !== null && is_file($carpetaSoportes.'/'.basename($a)))->count();
        $this->line("  comprobantes            {$conArchivo->count()} (".($conArchivo->count() - $faltantes)." encontrados, {$faltantes} faltantes)");
        if ($conArchivo->isNotEmpty() && $carpetaSoportes === null) {
            $this->warn('Aviso: no indicó --soportes; los comprobantes no se importarán.');
        }

        $ocupadas = array_filter(['inscripciones', 'soportes', 'asociados', 'coopetrolitos'], fn ($t) => DB::table($t)->exists());
        if ($ocupadas !== []) {
            $this->error('La base actual ya tiene datos en: '.implode(', ', $ocupadas).'. La importación solo se hace sobre una base '
                .'vacía (use evento:limpiar todo --confirmar si son datos de prueba).');

            return self::FAILURE;
        }

        $this->warn('Recuerde: las fechas de expedición importadas (HMAC) solo validan si EVENTO_SECRETO es el mismo del servidor anterior.');
        if (! $this->option('confirmar')) {
            $this->comment('Vista previa: no se importó nada. Agregue --confirmar para importar.');

            return self::SUCCESS;
        }

        $guardados = [];
        try {
            DB::transaction(function () use ($anterior, $almacen, $tarifas, $carpetaSoportes, &$guardados) {
                if (! DB::table('tarifas')->exists()) {
                    $tarifas->cargar();
                }
                $this->revisarTarifas($anterior);
                $this->importarAdministradores($anterior);
                $this->copiar($anterior, 'asociados', 'documento', fn ($a) => [
                    'documento' => $a->documento, 'nombre' => $a->nombre, 'agencia' => $a->agencia, 'estado' => $a->estado,
                    'fecha_actualizacion' => self::fecha($a->fecha_actualizacion), 'expedicion_hmac' => $a->expedicion_hmac ?: null,
                    'fecha_nacimiento' => self::fecha($a->fecha_nacimiento),
                ]);
                $this->copiar($anterior, 'coopetrolitos', 'documento', fn ($c) => [
                    'documento' => $c->documento, 'nombre' => $c->nombre, 'documento_asociado' => $c->documento_asociado,
                    'fecha_nacimiento' => self::fecha($c->fecha_nacimiento),
                ]);
                $this->copiar($anterior, 'cargas_bases', 'id', fn ($c) => [
                    'tipo' => $c->tipo, 'archivo' => $c->archivo !== null ? mb_substr($c->archivo, 0, 200) : null,
                    'registros' => (int) $c->registros, 'usuario' => mb_substr($c->usuario, 0, 60), 'cargada_en' => self::momento($c->cargada_en),
                ]);
                foreach ($anterior->table('cupos_ajustados')->get() as $c) {
                    DB::table('cupos_ajustados')->updateOrInsert(['agencia' => $c->agencia], [
                        'cupos' => (int) $c->cupos, 'actualizado_por' => $c->actualizado_por, 'actualizado_en' => self::momento($c->actualizado_en),
                    ]);
                }
                $this->copiar($anterior, 'inscripciones', 'id', fn ($i) => [
                    'id' => (int) $i->id, 'referencia' => $i->referencia, 'documento_titular' => $i->documento_titular,
                    'nombre_titular' => $i->nombre_titular, 'agencia' => $i->agencia, 'agencia_asociado' => $i->agencia_asociado,
                    'total' => (int) $i->total, 'estado' => $i->estado, 'motivo' => $i->motivo, 'revisado_por' => $i->revisado_por,
                    'revisado_en' => self::momento($i->revisado_en), 'autorizacion_version' => $i->autorizacion_version,
                    'autorizacion_en' => self::momento($i->autorizacion_en), 'autorizacion_ip' => $i->autorizacion_ip,
                    'autorizacion_imagen' => (bool) $i->autorizacion_imagen, 'creada_en' => self::momento($i->creada_en),
                    'actualizada_en' => self::momento($i->actualizada_en),
                ]);
                // Sin id: los nuevos consecutivos conservan el orden de registro (rowid).
                $this->copiar($anterior, 'inscripcion_personas', 'rowid', fn ($p) => [
                    'inscripcion_id' => (int) $p->inscripcion_id, 'documento' => $p->documento, 'nombre' => $p->nombre,
                    'tipo' => $p->tipo, 'valor' => (int) $p->valor,
                ]);
                $this->copiar($anterior, 'soportes', 'id', function ($s) use ($almacen, $carpetaSoportes, &$guardados) {
                    [$archivo, $tipo] = $this->comprobante($s, $almacen, $carpetaSoportes);
                    if ($archivo !== '') {
                        $guardados[] = $archivo;
                    }
                    $campos = json_decode((string) $s->campos); // objeto: {} vacío no debe quedar como []

                    return [
                        'id' => (int) $s->id, 'inscripcion_id' => (int) $s->inscripcion_id, 'medio_pago' => $s->medio_pago ?: 'PSE',
                        'cus' => (string) $s->cus, 'banco' => (string) $s->banco, 'agencia_pago' => $s->agencia_pago, 'recibo' => $s->recibo,
                        'fecha_pago' => self::fecha($s->fecha_pago), 'valor_pagado' => (int) $s->valor_pagado,
                        'campos' => json_encode(is_object($campos) ? $campos : (object) [], JSON_UNESCAPED_UNICODE),
                        'archivo' => $archivo, 'tipo_archivo' => $tipo,
                        'nombre_original' => $s->nombre_original !== null ? mb_substr($s->nombre_original, 0, 120) : null,
                        'alerta' => $s->alerta !== null ? mb_substr($s->alerta, 0, 255) : null,
                        'autorizacion_version' => $s->autorizacion_version, 'cargado_en' => self::momento($s->cargado_en),
                    ];
                });
                $this->copiar($anterior, 'auditoria', 'id', fn ($a) => [
                    'usuario' => mb_substr($a->usuario, 0, 60), 'accion' => mb_substr($a->accion, 0, 10), 'entidad' => mb_substr($a->entidad, 0, 20),
                    'clave' => mb_substr($a->clave, 0, 60), 'detalle' => $a->detalle !== null ? mb_substr($a->detalle, 0, 255) : null,
                    'fecha' => self::momento($a->fecha),
                ]);
                $this->ajustarSecuencias();
            });
        } catch (Throwable $error) {
            // Los comprobantes guardados en un disco no se deshacen con la transacción.
            if (config('evento.almacen_soportes') !== 'base_datos') {
                foreach ($guardados as $nombre) {
                    $almacen->borrar($nombre);
                }
            }
            $this->error("No se importó nada: {$error->getMessage()}");

            return self::FAILURE;
        } finally {
            DB::purge('anterior');
        }

        foreach ($this->advertencias as $advertencia) {
            $this->warn("Advertencia: {$advertencia}");
        }
        $this->info('Importación terminada.');
        $this->warn('Recuerde: EVENTO_SECRETO debe ser el mismo del servidor anterior; si no, ningún asociado podrá identificarse.');

        return self::SUCCESS;
    }

    /** @return array<string, int> */
    private function conteos(Connection $anterior): array
    {
        $conteos = [];
        foreach (['tarifas', 'agencias_evento', 'administradores', 'sesiones', 'asociados', 'coopetrolitos', 'cargas_bases',
            'cupos_ajustados', 'inscripciones', 'inscripcion_personas', 'soportes', 'auditoria'] as $tabla) {
            $conteos[$tabla] = $anterior->table($tabla)->count();
        }

        return $conteos;
    }

    /** Copia una tabla por lotes, en el orden de `$orden`. */
    private function copiar(Connection $anterior, string $tabla, string $orden, callable $fila): void
    {
        $columnas = $orden === 'rowid' ? ['rowid', '*'] : ['*'];
        $anterior->table($tabla)->select($columnas)->orderBy($orden)
            ->chunk(self::LOTE, function ($lote) use ($tabla, $fila) {
                DB::table($tabla)->insert($lote->map($fila)->all());
            });
        $this->line("Importado: {$tabla} (".DB::table($tabla)->count().')');
    }

    private function importarAdministradores(Connection $anterior): void
    {
        foreach ($anterior->table('administradores')->orderBy('usuario')->get() as $a) {
            $usuario = mb_strtolower($a->usuario);
            if (DB::table('administradores')->where('usuario', $usuario)->exists()) {
                $this->advertencias[] = "el usuario del panel \"{$usuario}\" ya existe en la base actual; se conservó el actual.";

                continue;
            }
            // Clave scrypt de la versión anterior: se valida con clave_salt y se convierte al primer ingreso.
            DB::table('administradores')->insert([
                'usuario' => $usuario, 'nombre' => mb_substr($a->nombre, 0, 100), 'clave' => $a->hash, 'clave_salt' => $a->salt,
                'rol' => in_array($a->rol, ['ADMINISTRADOR', 'REVISOR'], true) ? $a->rol : 'REVISOR',
            ]);
        }
        $this->line('Importado: administradores ('.DB::table('administradores')->count().')');
    }

    /** Las inscripciones y los cupos se refieren a eventos de tarifas.json: avisa si alguno ya no existe. */
    private function revisarTarifas(Connection $anterior): void
    {
        $actuales = DB::table('tarifas')->pluck('agencia')->all();
        $usados = $anterior->table('inscripciones')->distinct()->pluck('agencia')
            ->merge($anterior->table('cupos_ajustados')->pluck('agencia'))->unique();
        foreach ($usados->diff($actuales) as $agencia) {
            $this->advertencias[] = "el evento \"{$agencia}\" de la base anterior no está en data/tarifas.json.";
        }
    }

    /** @return array{0: string, 1: string} nombre interno y tipo del comprobante ('' si no se pudo importar) */
    private function comprobante(object $soporte, AlmacenComprobantes $almacen, ?string $carpeta): array
    {
        if ((string) $soporte->archivo === '') {
            return ['', ''];
        }
        $ruta = $carpeta !== null ? $carpeta.'/'.basename((string) $soporte->archivo) : null;
        if ($ruta === null || ! is_file($ruta)) {
            $this->advertencias[] = "no se encontró el comprobante del soporte {$soporte->id} ({$soporte->archivo}); queda sin archivo.";

            return ['', ''];
        }
        $contenido = (string) file_get_contents($ruta);
        $tipo = TipoComprobante::porFirma($contenido) ?? TipoComprobante::tryFrom((string) $soporte->tipo_archivo);
        if ($tipo === null) {
            $this->advertencias[] = "el comprobante del soporte {$soporte->id} no es PDF, PNG ni JPG; queda sin archivo.";

            return ['', ''];
        }

        return [$almacen->guardar($contenido, $tipo), $tipo->value];
    }

    /** PostgreSQL: los consecutivos siguen después de los id importados (la próxima referencia no se repite). */
    private function ajustarSecuencias(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return; // SQLite actualiza sqlite_sequence al insertar id explícitos
        }
        foreach (['inscripciones', 'inscripcion_personas', 'soportes', 'cargas_bases', 'auditoria'] as $tabla) {
            DB::select("SELECT setval(pg_get_serial_sequence('{$tabla}', 'id'), COALESCE((SELECT MAX(id) FROM {$tabla}), 1), "
                ."(SELECT COUNT(*) > 0 FROM {$tabla}))");
        }
    }

    private static function fecha(?string $valor): ?string
    {
        return $valor === null || trim($valor) === '' ? null : substr(trim($valor), 0, 10);
    }

    /** Marca de tiempo ISO 8601 de la versión anterior -> timestamptz en UTC. */
    private static function momento(?string $valor): ?string
    {
        if ($valor === null || trim($valor) === '') {
            return null;
        }
        try {
            return FormatoFecha::aBaseDatos(new DateTimeImmutable($valor));
        } catch (Throwable) {
            throw new RuntimeException("Fecha inválida en la base anterior: {$valor}");
        }
    }
}
