<?php

namespace App\Aplicacion\Simulacion;

use App\Aplicacion\Configuracion\ConfiguracionEvento;
use App\Dominio\Compartido\ErrorValidacion;
use App\Dominio\Compartido\Valores;
use App\Dominio\Inscripcion\Acompanantes\ClasificadorAcompanantes;
use App\Dominio\Inscripcion\Acompanantes\ContextoAcompanante;
use App\Dominio\Padron\RepositorioPadron;

/**
 * Caso de uso: liquidar el valor de ingreso.
 * - Los valores son los del evento al que asistirá (por defecto, el de la agencia del asociado).
 * - Cada acompañante: documento (solo dígitos), nombres y apellidos completos y tipo; se clasifica con las estrategias
 *   de Dominio\Inscripcion\Acompanantes (asociado, Coopetrolito o invitado).
 * - Se usa el nombre digitado: no se exponen nombres de la base por número de documento.
 */
final class SimularInscripcion
{
    public function __construct(
        private readonly IdentificarAsociado $identificar,
        private readonly RepositorioPadron $padron,
        private readonly ClasificadorAcompanantes $clasificador,
        private readonly ConfiguracionEvento $configuracion,
    ) {}

    /** @param  array{documento?: mixed, fechaExpedicion?: mixed, agenciaEvento?: mixed, acompanantes?: mixed}  $datos */
    public function ejecutar(array $datos): Simulacion
    {
        $identidad = $this->identificar->ejecutar($datos['documento'] ?? null, $datos['fechaExpedicion'] ?? null);
        $asociado = $identidad->asociado;
        $agencia = mb_strtoupper(trim(Valores::cadena($datos['agenciaEvento'] ?? null))) ?: $identidad->tarifa->agencia;
        $tarifa = $agencia === $identidad->tarifa->agencia ? $identidad->tarifa : $this->padron->tarifa($agencia);
        if (! $tarifa) {
            throw new ErrorValidacion("No existe un evento para la agencia {$agencia}.");
        }

        $acompanantes = $datos['acompanantes'] ?? [];
        if (! is_array($acompanantes) || ! array_is_list($acompanantes)) {
            throw new ErrorValidacion('Formato de acompañantes inválido.');
        }
        $maximo = $this->configuracion->maxAcompanantes;
        if (count($acompanantes) > $maximo) {
            throw new ErrorValidacion("Máximo {$maximo} acompañantes por asociado.");
        }

        $vistos = [$asociado->documento => true];
        $clasificados = [];
        foreach ($acompanantes as $i => $a) {
            $a = is_array($a) ? $a : [];
            $etiqueta = 'Acompañante '.($i + 1);
            $doc = Valores::validarDocumento($a['documento'] ?? null, $etiqueta);
            if ($doc === $asociado->documento) {
                throw new ErrorValidacion("{$etiqueta}: no puede ser el mismo asociado titular.");
            }
            if (isset($vistos[$doc])) {
                throw new ErrorValidacion("{$etiqueta}: el documento {$doc} está repetido.");
            }
            $vistos[$doc] = true;
            $nombre = Valores::validarNombreCompleto($a['nombre'] ?? null, $etiqueta);
            $contexto = new ContextoAcompanante(
                documento: $doc,
                etiqueta: $etiqueta,
                solicitado: isset($a['tipo']) ? Valores::cadena($a['tipo']) : null,
                titular: $asociado->documento,
                registro: $this->padron->asociado($doc),
                buscarCoopetrolito: fn () => $this->padron->coopetrolito($doc),
            );
            $clasificados[] = $this->clasificador->clasificar($contexto, $tarifa, $nombre);
        }

        return new Simulacion($asociado, $tarifa, $clasificados);
    }
}
