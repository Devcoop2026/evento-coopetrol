<?php

namespace App\Aplicacion\Inscripciones;

/** Caso de uso: el asociado consulta su inscripción con documento y fecha de expedición. */
final class ConsultarInscripcion
{
    public function __construct(
        private readonly AutenticarInscripcion $autenticar,
        private readonly PresentadorInscripcion $presentador,
    ) {}

    /** @param  array{referencia?: mixed, documento?: mixed, fechaExpedicion?: mixed}  $credenciales */
    public function ejecutar(array $credenciales): array
    {
        return $this->presentador->publico($this->autenticar->ejecutar($credenciales));
    }
}
