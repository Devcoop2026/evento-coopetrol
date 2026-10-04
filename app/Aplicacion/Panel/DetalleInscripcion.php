<?php

namespace App\Aplicacion\Panel;

use App\Dominio\Compartido\ErrorValidacion;
use App\Dominio\Inscripcion\PersonaInscrita;
use App\Dominio\Inscripcion\RepositorioInscripciones;
use App\Dominio\Inscripcion\Soporte;

/** Detalle de una inscripción para el revisor. El nombre interno del comprobante no se expone. */
final class DetalleInscripcion
{
    public function __construct(private readonly RepositorioInscripciones $repositorio) {}

    public function ejecutar(int $id): array
    {
        $i = $this->repositorio->porId($id) ?? throw new ErrorValidacion('Inscripción no encontrada.');

        return [
            ...$i->aArreglo(),
            'personas' => array_map(fn (PersonaInscrita $p) => $p->aArreglo(), $this->repositorio->personas($i->id)),
            'soportes' => array_map(fn (Soporte $s) => [
                'id' => $s->id,
                'medio_pago' => $s->medioPago,
                'cus' => $s->cus,
                'banco' => $s->banco,
                'agencia_pago' => $s->agenciaPago,
                'recibo' => $s->recibo,
                'fecha_pago' => $s->fechaPago,
                'valor_pagado' => $s->valorPagado,
                'campos' => $s->campos,
                'tiene_archivo' => $s->tieneArchivo(),
                'tipo_archivo' => $s->tipoArchivo,
                'nombre_original' => $s->nombreOriginal,
                'alerta' => $s->alerta,
                'cargado_en' => $s->cargadoEn,
            ], $this->repositorio->soportes($i->id)),
        ];
    }
}
