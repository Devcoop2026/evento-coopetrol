<?php

namespace Tests;

use App\Aplicacion\Configuracion\ConfiguracionEvento;
use App\Dominio\Seguridad\RegistroSeguridad;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    /** Base exclusiva de las pruebas: RefreshDatabase la borra, así que nunca debe ser la de desarrollo. */
    private const BASE_PRUEBAS_PGSQL = 'postgres_evento_test';

    protected function setUp(): void
    {
        parent::setUp();

        $conexion = config('database.default');
        if (config("database.connections.{$conexion}.driver") === 'pgsql'
            && config("database.connections.{$conexion}.database") !== self::BASE_PRUEBAS_PGSQL) {
            throw new RuntimeException('Las pruebas solo pueden usar la base '.self::BASE_PRUEBAS_PGSQL.' (revise phpunit.xml y DB_DATABASE).');
        }
    }

    /**
     * Reemplaza la configuración del evento (data/config.json, formulario de pago y habeas data). Debe llamarse antes
     * de resolver los casos de uso, porque estos reciben la configuración al construirse.
     *
     * @param  list<array<string, mixed>>|null  $campos  null = solo el campo "Correo" obligatorio
     */
    protected function configurarEvento(array $config = [], ?array $campos = null, string $version = 'v-prueba'): ConfiguracionEvento
    {
        $configuracion = new ConfiguracionEvento(
            config: $config,
            camposSoporte: $campos ?? [['id' => 'correo', 'etiqueta' => 'Correo', 'tipo' => 'correo', 'requerido' => true]],
            habeasData: ['version' => $version],
        );
        $this->app->instance(ConfiguracionEvento::class, $configuracion);

        return $configuracion;
    }

    /** Registro de seguridad en memoria: guarda los eventos para revisarlos en la prueba. */
    protected function registroSeguridadFalso(): RegistroSeguridadFalso
    {
        $registro = new RegistroSeguridadFalso;
        $this->app->instance(RegistroSeguridad::class, $registro);

        return $registro;
    }

    /** Fecha de expedición de un asociado ficticio de data/asociados.seed.json. */
    protected static function expedicion(string $documento): string
    {
        static $fechas = null;
        $fechas ??= array_column(
            json_decode(file_get_contents(dirname(__DIR__).'/data/asociados.seed.json'), true),
            'fecha_expedicion', 'documento',
        );

        return $fechas[$documento] ?? throw new RuntimeException("El asociado {$documento} no está en data/asociados.seed.json.");
    }

    /** Documento + fecha de expedición correcta de un asociado ficticio. */
    protected static function credenciales(string $documento, array $extra = []): array
    {
        return ['documento' => $documento, 'fechaExpedicion' => self::expedicion($documento), ...$extra];
    }

    protected function contarFilas(string $tabla): int
    {
        return DB::table($tabla)->count();
    }
}
