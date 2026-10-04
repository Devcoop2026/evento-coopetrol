<?php

namespace App\Aplicacion\Inscripciones;

use App\Aplicacion\Configuracion\ConfiguracionEvento;
use App\Dominio\Compartido\ErrorValidacion;
use App\Dominio\Compartido\Reloj;
use App\Dominio\Compartido\Valores;
use App\Dominio\Inscripcion\Pagos\CatalogoMediosPago;
use App\Dominio\Inscripcion\Pagos\TipoComprobante;
use App\Dominio\Inscripcion\Pagos\VerificacionPagos;
use App\Dominio\Inscripcion\RepositorioInscripciones;
use App\Dominio\Padron\RepositorioPadron;

/**
 * Valida los datos del pago sin escribir nada. Cada medio de pago valida los suyos (Dominio\Inscripcion\Pagos);
 * aquí se validan la fecha, el valor, los campos adicionales y el comprobante (tipo real por firma y tamaño).
 */
final class ValidarPago implements VerificacionPagos
{
    public function __construct(
        private readonly CatalogoMediosPago $medios,
        private readonly RepositorioInscripciones $repositorio,
        private readonly RepositorioPadron $padron,
        private readonly ConfiguracionEvento $configuracion,
        private readonly Reloj $reloj,
    ) {}

    /**
     * @param  array{medioPago?: mixed, cus?: mixed, agenciaPago?: mixed, recibo?: mixed, fechaPago?: mixed, valorPagado?: mixed, campos?: mixed, archivo?: ?ArchivoRecibido}  $datos
     * @param  int  $idPropio  excluye la propia inscripción al buscar CUS o recibos repetidos (0 si aún no existe)
     */
    public function ejecutar(array $datos, int $idPropio): PagoValidado
    {
        $medio = $this->medios->medio($datos['medioPago'] ?? 'PSE');
        $datosMedio = $medio->validar($datos, $this, $idPropio);

        $fecha = trim(Valores::cadena($datos['fechaPago'] ?? null));
        if (! Valores::esFechaValida($fecha)) {
            throw new ErrorValidacion('Ingrese la fecha del pago.');
        }
        if ($fecha > Valores::fechaColombia($this->reloj->ahora())) {
            throw new ErrorValidacion('La fecha del pago no puede ser futura.');
        }
        $valor = (int) preg_replace('/\D/', '', Valores::cadena($datos['valorPagado'] ?? null));
        if ($valor <= 0) {
            throw new ErrorValidacion('Ingrese el valor pagado.');
        }
        $adicionales = $this->configuracion->camposAdicionales()->validar($datos['campos'] ?? []);

        // Si el medio no exige comprobante, es opcional (p. ej. foto del recibo de caja).
        $archivo = $datos['archivo'] ?? null;
        $archivo = $archivo instanceof ArchivoRecibido ? $archivo : null;
        if (! $medio->archivoObligatorio() && ! $archivo) {
            return new PagoValidado($medio->codigo(), $datosMedio, $fecha, $valor, $adicionales);
        }

        return $this->conComprobante(new PagoValidado($medio->codigo(), $datosMedio, $fecha, $valor, $adicionales), $archivo);
    }

    private function conComprobante(PagoValidado $pago, ?ArchivoRecibido $archivo): PagoValidado
    {
        if (! $archivo) {
            throw new ErrorValidacion('Adjunte el soporte de pago.');
        }
        if ($archivo->contenido === '') {
            throw new ErrorValidacion('El archivo adjunto está vacío.');
        }
        $maxMb = $this->configuracion->soporteMaxMb();
        if (strlen($archivo->contenido) > $maxMb * 1024 * 1024) {
            throw new ErrorValidacion("El soporte supera el tamaño máximo de {$maxMb} MB.");
        }
        $tipo = TipoComprobante::porFirma($archivo->contenido)
            ?? throw new ErrorValidacion('El soporte debe ser un archivo PDF, JPG o PNG.');
        $nombre = mb_substr(basename(str_replace('\\', '/', $archivo->nombre ?: 'soporte')), 0, 120);

        return new PagoValidado($pago->medio, $pago->datosMedio, $pago->fecha, $pago->valor, $pago->adicionales,
            $archivo->contenido, $tipo, $nombre);
    }

    // ---- VerificacionPagos: consultas que necesitan los medios de pago ----

    public function cusUsado(string $cus, int $inscripcionExcluida): ?string
    {
        return $this->repositorio->cusUsado($cus, $inscripcionExcluida);
    }

    public function reciboUsado(string $agencia, string $recibo, int $inscripcionExcluida): ?string
    {
        return $this->repositorio->reciboUsado($agencia, $recibo, $inscripcionExcluida);
    }

    public function existeAgencia(string $agencia): bool
    {
        return $this->padron->existeAgencia($agencia);
    }
}
