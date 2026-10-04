<?php

namespace App\Dominio\Padron;

use App\Dominio\Compartido\ErrorValidacion;
use App\Dominio\Compartido\Valores;
use DateTimeImmutable;

/**
 * Asociado de la base cargada. Para inscribirse como titular debe estar ACTIVO y haber actualizado sus datos en los
 * últimos `mesesVigencia` meses. La fecha de expedición solo se conoce por su huella (HMAC), nunca en texto plano.
 */
final readonly class Asociado
{
    public function __construct(
        public string $documento,
        public string $nombre,
        public string $agencia,
        public string $estado,
        public ?string $fechaActualizacion = null,
        public ?string $huellaExpedicion = null,
        public ?string $fechaNacimiento = null,
    ) {}

    public function esActivo(): bool
    {
        return $this->estado === 'ACTIVO';
    }

    /** Lanza el error si no puede inscribirse como titular. `hoy` es la fecha en Colombia. */
    public function exigirHabilitadoComoTitular(DateTimeImmutable $hoy, int $mesesVigencia): void
    {
        if (! $this->esActivo()) {
            throw new ErrorValidacion('El documento no figura como asociado activo de Coopetrol. Comuníquese con su agencia.');
        }
        if (! $this->fechaActualizacion) {
            throw new ErrorValidacion('No registra actualización de datos. Actualice sus datos en su agencia para continuar.');
        }
        if ($this->fechaActualizacion < self::fechaLimiteActualizacion($hoy, $mesesVigencia)) {
            throw new ErrorValidacion('Sus datos fueron actualizados por última vez el '.Valores::formatoFecha($this->fechaActualizacion).'. '
                ."Debe actualizarlos (vigencia máxima de {$mesesVigencia} meses) para continuar.");
        }
    }

    /** Fecha mínima aceptada de actualización de datos (AAAA-MM-DD): hoy menos `meses` meses. */
    public static function fechaLimiteActualizacion(DateTimeImmutable $hoy, int $meses): string
    {
        return $hoy->modify("-{$meses} months")->format('Y-m-d');
    }

    /** Datos que se pueden mostrar (sin la huella de la fecha de expedición). */
    public function publico(): array
    {
        return [
            'documento' => $this->documento,
            'nombre' => $this->nombre,
            'agencia' => $this->agencia,
            'estado' => $this->estado,
            'fecha_actualizacion' => $this->fechaActualizacion,
        ];
    }
}
