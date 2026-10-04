<?php

namespace App\Aplicacion\Panel;

use App\Aplicacion\Configuracion\ConfiguracionEvento;
use App\Dominio\Inscripcion\Pagos\CatalogoMediosPago;
use App\Dominio\Inscripcion\PersonaInscrita;
use App\Dominio\Inscripcion\RepositorioInscripciones;

/**
 * Caso de uso: exportar las inscripciones a CSV (separador ";" y BOM para que Excel lo abra con tildes).
 * Las celdas que empiezan por =, +, -, @ se prefijan con ' para evitar inyección de fórmulas al abrirlo en Excel.
 */
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

    public function ejecutar(): string
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

        return "\u{FEFF}".implode("\r\n", array_map(fn (array $f) => implode(';', array_map(self::celda(...), $f)), $filas))."\r\n";
    }
}
