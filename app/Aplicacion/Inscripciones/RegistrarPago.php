<?php

namespace App\Aplicacion\Inscripciones;

use App\Dominio\Compartido\Reloj;
use App\Dominio\Inscripcion\Acompanantes\ClasificadorAcompanantes;
use App\Dominio\Inscripcion\PoliticaCupos;
use App\Dominio\Inscripcion\RepositorioInscripciones;

/**
 * Caso de uso: registrar el pago de una inscripción existente (PREINSCRITO o RECHAZADO). El cupo se ocupa en este
 * momento: si ya no alcanza, no se acepta el formulario. El revisor verifica el pago en el panel.
 */
final class RegistrarPago
{
    public function __construct(
        private readonly AutenticarInscripcion $autenticar,
        private readonly PoliticaInscripcion $politica,
        private readonly ValidarPago $validarPago,
        private readonly RegistradorPagos $registrador,
        private readonly RepositorioInscripciones $repositorio,
        private readonly ClasificadorAcompanantes $clasificador,
        private readonly PresentadorInscripcion $presentador,
        private readonly Reloj $reloj,
    ) {}

    /** @param  array<string, mixed>  $datos  credenciales + datos del pago + autorizaDatos */
    public function ejecutar(array $datos): array
    {
        $this->politica->exigirAutorizacion($datos['autorizaDatos'] ?? null);
        $inscripcion = $this->autenticar->ejecutar($datos);
        $inscripcion->estado->exigirEditable('cargar el soporte');
        $pago = $this->validarPago->ejecutar($datos, $inscripcion->id);
        $this->registrador->conComprobante($pago, function (string $archivo) use ($inscripcion, $pago) {
            PoliticaCupos::exigir(
                $inscripcion->agencia,
                $this->clasificador->cuposRequeridos($this->repositorio->personas($inscripcion->id)),
                $this->repositorio->cuposDisponibles($inscripcion->agencia, $inscripcion->id),
                ' Modifique su inscripción o comuníquese con su agencia.',
            );
            $this->registrador->registrar($inscripcion->id, $inscripcion->total, $pago, $archivo, $this->reloj->ahora());
        });

        return $this->presentador->publico($this->repositorio->porId($inscripcion->id));
    }
}
