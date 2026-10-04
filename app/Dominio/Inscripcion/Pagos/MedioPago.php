<?php

namespace App\Dominio\Inscripcion\Pagos;

/**
 * Estrategia de un medio de pago: valida sus propios datos y dice si el comprobante es obligatorio.
 * Para agregar un medio nuevo basta con implementar esta interfaz y registrarlo en CatalogoMediosPago
 * (y su opción en el formulario de pago).
 */
interface MedioPago
{
    public function codigo(): string;

    public function etiqueta(): string;

    public function archivoObligatorio(): bool;

    /**
     * @param  array<string, mixed>  $datos  datos del formulario (cus, agenciaPago, recibo...)
     * @param  int  $inscripcionExcluida  id de la propia inscripción al buscar repetidos (0 si aún no existe)
     */
    public function validar(array $datos, VerificacionPagos $verificacion, int $inscripcionExcluida): DatosMedioPago;
}
