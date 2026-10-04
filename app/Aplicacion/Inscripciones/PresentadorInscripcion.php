<?php

namespace App\Aplicacion\Inscripciones;

use App\Dominio\Inscripcion\Inscripcion;
use App\Dominio\Inscripcion\PersonaInscrita;
use App\Dominio\Inscripcion\RepositorioInscripciones;

/** Lo que ve el asociado de su inscripción (sin datos internos del soporte). */
final class PresentadorInscripcion
{
    public function __construct(private readonly RepositorioInscripciones $repositorio) {}

    public function publico(Inscripcion $i): array
    {
        $ultimo = $this->repositorio->ultimoSoporte($i->id);

        return [
            'referencia' => $i->referencia,
            'estado' => $i->estado->value,
            'motivo' => $i->motivo,
            'agencia' => $i->agencia,
            'agenciaAsociado' => $i->agenciaAsociado,
            'titular' => $i->nombreTitular,
            'total' => $i->total,
            'personas' => array_map(fn (PersonaInscrita $p) => $p->aArreglo(), $this->repositorio->personas($i->id)),
            'creadaEn' => $i->creadaEn,
            'actualizadaEn' => $i->actualizadaEn,
            'editable' => $i->estado->esEditable(),
            'soporte' => $ultimo ? [
                'medioPago' => $ultimo->medioPago,
                'cus' => $ultimo->cus,
                'banco' => $ultimo->banco,
                'agenciaPago' => $ultimo->agenciaPago,
                'recibo' => $ultimo->recibo,
                'fechaPago' => $ultimo->fechaPago,
                'valorPagado' => $ultimo->valorPagado,
                'conArchivo' => $ultimo->tieneArchivo(),
                'cargadoEn' => $ultimo->cargadoEn,
            ] : null,
        ];
    }
}
