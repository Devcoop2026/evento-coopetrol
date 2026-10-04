<?php

namespace App\Aplicacion\Panel;

use App\Dominio\Compartido\ErrorValidacion;
use App\Dominio\Inscripcion\AlmacenComprobantes;
use App\Dominio\Inscripcion\Pagos\TipoComprobante;
use App\Dominio\Inscripcion\RepositorioInscripciones;

/** Caso de uso: obtener el comprobante de un pago para verlo en el panel. */
final class ObtenerComprobante
{
    public function __construct(
        private readonly RepositorioInscripciones $repositorio,
        private readonly AlmacenComprobantes $almacen,
    ) {}

    /** @return array{contenido: string, tipo: TipoComprobante, nombre: string} */
    public function ejecutar(int $soporteId): array
    {
        $soporte = $this->repositorio->soporte($soporteId) ?? throw new ErrorValidacion('Soporte no encontrado.');
        if (! $soporte->tieneArchivo()) {
            throw new ErrorValidacion('Este pago se registró sin comprobante adjunto.');
        }
        $contenido = $this->almacen->leer($soporte->archivo) ?? throw new ErrorValidacion('No se encontró el archivo del comprobante.');
        $tipo = TipoComprobante::tryFrom($soporte->tipoArchivo) ?? TipoComprobante::porFirma($contenido) ?? TipoComprobante::Pdf;

        return ['contenido' => $contenido, 'tipo' => $tipo, 'nombre' => $soporte->nombreOriginal ?: 'soporte'];
    }
}
