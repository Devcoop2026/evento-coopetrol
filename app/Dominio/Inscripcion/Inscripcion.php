<?php

namespace App\Dominio\Inscripcion;

/** Inscripción registrada (lectura). Las marcas de tiempo son ISO 8601 en UTC. */
final readonly class Inscripcion
{
    public function __construct(
        public int $id,
        public string $referencia,
        public string $documentoTitular,
        public string $nombreTitular,
        public string $agencia,
        public ?string $agenciaAsociado,
        public int $total,
        public EstadoInscripcion $estado,
        public ?string $motivo,
        public ?string $revisadoPor,
        public ?string $revisadoEn,
        public ?string $autorizacionVersion,
        public ?string $autorizacionEn,
        public ?string $autorizacionIp,
        public bool $autorizacionImagen,
        public string $creadaEn,
        public string $actualizadaEn,
    ) {}

    /** Columnas tal como las muestra el panel y la exportación. */
    public function aArreglo(): array
    {
        return [
            'id' => $this->id,
            'referencia' => $this->referencia,
            'documento_titular' => $this->documentoTitular,
            'nombre_titular' => $this->nombreTitular,
            'agencia' => $this->agencia,
            'agencia_asociado' => $this->agenciaAsociado,
            'total' => $this->total,
            'estado' => $this->estado->value,
            'motivo' => $this->motivo,
            'revisado_por' => $this->revisadoPor,
            'revisado_en' => $this->revisadoEn,
            'autorizacion_version' => $this->autorizacionVersion,
            'autorizacion_en' => $this->autorizacionEn,
            'autorizacion_ip' => $this->autorizacionIp,
            'autorizacion_imagen' => $this->autorizacionImagen,
            'creada_en' => $this->creadaEn,
            'actualizada_en' => $this->actualizadaEn,
        ];
    }
}
