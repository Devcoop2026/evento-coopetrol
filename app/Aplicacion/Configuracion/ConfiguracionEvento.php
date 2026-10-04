<?php

namespace App\Aplicacion\Configuracion;

use App\Dominio\Inscripcion\CamposAdicionales;

/**
 * Configuración del evento que cambia sin programar (data/config.json, data/formulario_soporte.json y
 * data/habeas_data.json) más los parámetros del entorno. La construye Infraestructura\Configuracion\LectorConfiguracion.
 */
final readonly class ConfiguracionEvento
{
    /**
     * @param  array<string, mixed>  $config  data/config.json
     * @param  list<array<string, mixed>>  $camposSoporte  data/formulario_soporte.json
     * @param  array<string, mixed>  $habeasData  data/habeas_data.json
     */
    public function __construct(
        public array $config = [],
        public array $camposSoporte = [],
        public array $habeasData = ['version' => 'sin-version', 'texto' => []],
        public int $maxAcompanantes = 5,
        public int $mesesVigenciaDatos = 12,
    ) {}

    public function camposAdicionales(): CamposAdicionales
    {
        return new CamposAdicionales($this->camposSoporte);
    }

    public function versionHabeasData(): string
    {
        return (string) ($this->habeasData['version'] ?? 'sin-version');
    }

    public function soporteMaxMb(): int|float
    {
        return $this->config['soporte_max_mb'] ?? 5;
    }

    public function enlacePago(): ?string
    {
        return $this->config['enlace_pago_pse'] ?? null;
    }

    public function enlaceCanales(): ?string
    {
        return $this->config['enlace_canales_pago'] ?? null;
    }

    public function inscripcionesDesde(): ?string
    {
        return $this->config['inscripciones_desde'] ?? null;
    }

    public function inscripcionesHasta(): ?string
    {
        return $this->config['inscripciones_hasta'] ?? null;
    }

    /** Interruptor para pausar la validación del periodo (p. ej. durante pruebas). */
    public function validarPeriodo(): bool
    {
        return ($this->config['validar_periodo_inscripcion'] ?? true) !== false;
    }

    /** Fecha (AAAA-MM-DD) desde la que debe suprimirse la información personal (finalidad cumplida). */
    public function fechaSupresionDatos(): ?string
    {
        return $this->config['fecha_supresion_datos'] ?? null;
    }
}
