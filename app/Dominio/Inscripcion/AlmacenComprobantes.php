<?php

namespace App\Dominio\Inscripcion;

use App\Dominio\Inscripcion\Pagos\TipoComprobante;

/** Puerto: almacenamiento de los comprobantes de pago. Nombres internos aleatorios, nunca el nombre original. */
interface AlmacenComprobantes
{
    /** Guarda el archivo y devuelve su nombre interno. */
    public function guardar(string $contenido, TipoComprobante $tipo): string;

    public function leer(string $nombre): ?string;

    public function borrar(string $nombre): void;
}
