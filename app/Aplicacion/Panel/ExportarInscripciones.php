<?php

namespace App\Aplicacion\Panel;

use App\Aplicacion\Configuracion\ConfiguracionEvento;
use App\Dominio\Inscripcion\Pagos\CatalogoMediosPago;
use App\Dominio\Inscripcion\PersonaInscrita;
use App\Dominio\Inscripcion\RepositorioInscripciones;

/** Exporta las inscripciones como filas para un libro Excel; neutraliza posibles fórmulas. */
final class ExportarInscripciones
{
    public function __construct(
        private readonly RepositorioInscripciones $repositorio,
        private readonly CatalogoMediosPago $medios,
        private readonly ConfiguracionEvento $configuracion,
    ) {}

    public static function celda(mixed $valor): string
    {
        $t = $valor === null ? '' : (is_bool($valor) ? ($valor ? 'true' : 'false') : (string) $valor);
        if (preg_match('/^[=+\-@\t\r]/', $t)) {
            $t = "'{$t}";
        }

        return preg_match('/[;"\n\r]/', $t) ? '"'.str_replace('"', '""', $t).'"' : $t;
    }

    /** @return list<list<mixed>> */
    public function ejecutar(): array
    {
        $campos = $this->configuracion->camposSoporte;
        $encabezado = ['Referencia', 'Estado', 'Agencia del evento', 'Agencia del asociado', 'Documento titular', 'Titular', 'Personas',
            'Acompañantes', 'Total', 'Medio de pago', 'CUS', 'Banco', 'Agencia de pago', 'Recibo de caja', 'Comprobante', 'Fecha pago',
            'Valor pagado', 'Alerta', ...array_map(fn ($c) => $c['etiqueta'], $campos),
            'Motivo', 'Revisado por', 'Revisado en', 'Autorización datos (versión)', 'Autorización datos (fecha)', 'Autoriza uso de imagen', 'Creada en'];

        $filas = [$encabezado];
        foreach ($this->repositorio->todas() as $i) {
            $personas = $this->repositorio->personas($i->id);
            $s = $this->repositorio->ultimoSoporte($i->id);
            $acompanantes = array_filter($personas, fn (PersonaInscrita $p) => $p->tipo !== PersonaInscrita::TITULAR);
            $filas[] = [
                $i->referencia, $i->estado->value, $i->agencia, $i->agenciaAsociado, $i->documentoTitular, $i->nombreTitular, count($personas),
                implode(' | ', array_map(fn (PersonaInscrita $p) => "{$p->nombre} ({$p->documento}, {$p->tipo})", $acompanantes)),
                $i->total,
                $s ? $this->medios->etiqueta($s->medioPago) : null,
                $s?->cus ?: null, $s?->banco ?: null, $s?->agenciaPago, $s?->recibo,
                $s ? ($s->tieneArchivo() ? 'Sí' : 'No') : null,
                $s?->fechaPago, $s?->valorPagado, $s?->alerta,
                ...array_map(fn ($c) => $s?->campos[$c['id']] ?? null, $campos),
                $i->motivo, $i->revisadoPor, $i->revisadoEn, $i->autorizacionVersion, $i->autorizacionEn,
                $i->autorizacionImagen ? 'Sí' : 'No', $i->creadaEn,
            ];
        }

        return array_map(fn (array $fila) => array_map(self::celda(...), $fila), $filas);
    }
}
