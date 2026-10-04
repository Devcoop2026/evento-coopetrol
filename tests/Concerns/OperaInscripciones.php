<?php

namespace Tests\Concerns;

use App\Aplicacion\Inscripciones\ArchivoRecibido;
use App\Aplicacion\Inscripciones\ConsultarInscripcion;
use App\Aplicacion\Inscripciones\Preinscribir;
use App\Aplicacion\Inscripciones\RegistrarPago;
use App\Aplicacion\Panel\AjustarCupos;
use App\Aplicacion\Panel\ConsultarCupos;
use App\Aplicacion\Panel\RevisarInscripcion;
use App\Dominio\Compartido\ErrorValidacion;
use App\Dominio\Panel\Rol;
use App\Models\Inscripcion;

/** Atajos para preinscribir, pagar, revisar y consultar cupos en las pruebas de inscripciones. */
trait OperaInscripciones
{
    protected const PDF = '%PDF-1.4 prueba';

    protected const PNG = "\x89PNG\r\n\x1a\nprueba";

    private int $siguienteCus = 10000000;

    protected function preinscribir(string $documento, array $acompanantes = [], array $extra = []): array
    {
        return app(Preinscribir::class)->ejecutar([
            ...self::credenciales($documento),
            'acompanantes' => $acompanantes,
            'autorizaDatos' => true,
            ...$extra,
        ], '10.0.0.1');
    }

    /** Pago PSE válido (CUS nuevo, comprobante PDF, correo); `extra` reemplaza cualquier dato. */
    protected function pagar(string $documento, ?string $referencia, array $extra = []): array
    {
        return app(RegistrarPago::class)->ejecutar([
            ...self::credenciales($documento),
            'referencia' => $referencia,
            'medioPago' => 'PSE',
            'cus' => (string) $this->siguienteCus++,
            'fechaPago' => '2026-10-05',
            'valorPagado' => '1',
            'campos' => ['correo' => 'asociado@correo.com'],
            'archivo' => new ArchivoRecibido('soporte.pdf', self::PDF),
            'autorizaDatos' => true,
            ...$extra,
        ]);
    }

    protected function consultar(string $documento, ?string $referencia = null): array
    {
        return app(ConsultarInscripcion::class)->ejecutar(self::credenciales($documento, ['referencia' => $referencia]));
    }

    protected function idDe(string $referencia): int
    {
        return (int) Inscripcion::query()->where('referencia', $referencia)->value('id');
    }

    protected function revisar(string $referencia, string $accion, ?string $motivo = null, Rol $rol = Rol::Administrador): array
    {
        return app(RevisarInscripcion::class)->ejecutar($this->idDe($referencia), $accion, $motivo, 'revisor', $rol);
    }

    protected function cupos(string $agencia): array
    {
        foreach (app(ConsultarCupos::class)->ejecutar() as $c) {
            if ($c['agencia'] === $agencia) {
                return $c;
            }
        }
        $this->fail("No hay cupos para {$agencia}.");
    }

    protected function ajustarCupos(string $agencia, mixed $cupos): array
    {
        return app(AjustarCupos::class)->ejecutar($agencia, $cupos, 'admin');
    }

    /** Ejecuta la acción y exige un ErrorValidacion cuyo mensaje cumpla el patrón. */
    protected function assertRechaza(string $patron, callable $accion): ErrorValidacion
    {
        try {
            $accion();
        } catch (ErrorValidacion $error) {
            $this->assertMatchesRegularExpression($patron, $error->getMessage());

            return $error;
        }
        $this->fail("Se esperaba un error de validación que cumpliera {$patron}.");
    }
}
