<?php

namespace App\Aplicacion\Inscripciones;

use App\Aplicacion\Configuracion\ConfiguracionEvento;
use App\Aplicacion\Simulacion\Simulacion;
use App\Dominio\Compartido\ErrorValidacion;
use App\Dominio\Compartido\Reloj;
use App\Dominio\Compartido\Valores;
use App\Dominio\Inscripcion\Acompanantes\ClasificadorAcompanantes;
use App\Dominio\Inscripcion\PersonaInscrita;
use App\Dominio\Inscripcion\PoliticaCupos;
use App\Dominio\Inscripcion\RepositorioInscripciones;

/** Reglas comunes a los casos de uso que crean o cambian inscripciones. */
final class PoliticaInscripcion
{
    public const MENSAJE_AUTORIZACION = 'Debe aceptar la autorización para el tratamiento de datos personales para continuar.';

    public function __construct(
        private readonly RepositorioInscripciones $repositorio,
        private readonly ClasificadorAcompanantes $clasificador,
        private readonly ConfiguracionEvento $configuracion,
        private readonly Reloj $reloj,
    ) {}

    /** Habeas data (Ley 1581 de 2012): sin autorización expresa no se procesa la solicitud. */
    public function exigirAutorizacion(mixed $autorizaDatos): void
    {
        if ($autorizaDatos !== true) {
            throw new ErrorValidacion(self::MENSAJE_AUTORIZACION);
        }
    }

    /** Las inscripciones solo se reciben entre inscripciones_desde e inscripciones_hasta (data/config.json). */
    public function validarPeriodo(): void
    {
        if (! $this->configuracion->validarPeriodo()) {
            return;
        }
        $hoy = Valores::fechaColombia($this->reloj->ahora());
        $desde = $this->configuracion->inscripcionesDesde();
        $hasta = $this->configuracion->inscripcionesHasta();
        if ($desde && $hoy < $desde) {
            throw new ErrorValidacion('Las inscripciones abren el '.Valores::formatoFecha($desde).'.');
        }
        if ($hasta && $hoy > $hasta) {
            throw new ErrorValidacion('Las inscripciones cerraron el '.Valores::formatoFecha($hasta).'.');
        }
    }

    /**
     * Valida que nadie esté ya inscrito y que haya cupo. `idPropio` excluye la inscripción que se modifica.
     * Debe llamarse dentro de la unidad de trabajo.
     */
    public function validarDisponibilidad(Simulacion $simulacion, int $idPropio = 0): void
    {
        foreach ($simulacion->personas() as $persona) {
            $otra = $this->repositorio->personaActiva($persona->documento, $idPropio);
            if (! $otra) {
                continue;
            }
            $esTitular = $persona->tipo === PersonaInscrita::TITULAR;
            if ($esTitular && $otra['documento_titular'] === $persona->documento) {
                throw new ErrorValidacion("Ya tiene una preinscripción activa ({$otra['referencia']}). Consúltela en \"Mi inscripción\".",
                    ['referenciaExistente' => $otra['referencia']]);
            }
            throw new ErrorValidacion($esTitular
                ? 'Usted ya está registrado como acompañante en otra inscripción del evento.'
                : "El documento {$persona->documento} ya está registrado en otra inscripción del evento.");
        }
        $agencia = $simulacion->agenciaEvento();
        PoliticaCupos::exigir($agencia, $this->clasificador->cuposRequeridos($simulacion->personas()),
            $this->repositorio->cuposDisponibles($agencia, $idPropio));
    }
}
