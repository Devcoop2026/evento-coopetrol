<?php

namespace App\Aplicacion\Configuracion;

use App\Dominio\Padron\RepositorioPadron;
use App\Dominio\Padron\Tarifa;

/** Datos del evento que necesita la interfaz: nombre, tarifas, agencias de pago, formulario, habeas data y enlaces. */
final class ConsultarDatosEvento
{
    public function __construct(
        private readonly RepositorioPadron $padron,
        private readonly ConfiguracionEvento $configuracion,
    ) {}

    public function ejecutar(): array
    {
        $tarifas = array_map(fn (Tarifa $t) => $t->aArreglo(), $this->padron->tarifas());
        $agencias = array_column($this->padron->agencias(), 'agencia');

        return [
            'evento' => $this->padron->evento(),
            'tarifas' => $tarifas,
            // Agencias y puntos de atención donde se paga (en un evento compartido aparecen por separado).
            'agenciasPago' => $agencias ?: array_column($tarifas, 'agencia'),
            'maxAcompanantes' => $this->configuracion->maxAcompanantes,
            'camposSoporte' => $this->configuracion->camposSoporte,
            'habeasData' => $this->configuracion->habeasData,
            'enlacePago' => $this->configuracion->enlacePago(),
            'enlaceCanales' => $this->configuracion->enlaceCanales(),
            'soporteMaxMb' => $this->configuracion->soporteMaxMb(),
            'inscripcionesDesde' => $this->configuracion->inscripcionesDesde(),
            'inscripcionesHasta' => $this->configuracion->inscripcionesHasta(),
        ];
    }
}
