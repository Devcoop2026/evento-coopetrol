<?php

namespace App\Aplicacion\Inscripciones;

use App\Aplicacion\Configuracion\ConfiguracionEvento;
use App\Aplicacion\Simulacion\SimularInscripcion;
use App\Dominio\Compartido\Reloj;
use App\Dominio\Compartido\UnidadDeTrabajo;
use App\Dominio\Inscripcion\NuevaInscripcion;
use App\Dominio\Inscripcion\Referencia;
use App\Dominio\Inscripcion\RepositorioInscripciones;

/**
 * Caso de uso (módulo PSE): preinscribirse con el valor calculado. No ocupa cupo: el cupo se asigna al registrar el
 * pago. Exige la autorización de habeas data y registra versión, fecha e IP; el uso de imagen es opcional.
 */
final class Preinscribir
{
    public function __construct(
        private readonly SimularInscripcion $simular,
        private readonly PoliticaInscripcion $politica,
        private readonly RepositorioInscripciones $repositorio,
        private readonly UnidadDeTrabajo $unidad,
        private readonly PresentadorInscripcion $presentador,
        private readonly ConfiguracionEvento $configuracion,
        private readonly Reloj $reloj,
    ) {}

    /** @param  array{documento?: mixed, fechaExpedicion?: mixed, agenciaEvento?: mixed, acompanantes?: mixed, autorizaDatos?: mixed, autorizaImagen?: mixed}  $datos */
    public function ejecutar(array $datos, ?string $ip = null): array
    {
        $this->politica->exigirAutorizacion($datos['autorizaDatos'] ?? null);
        $this->politica->validarPeriodo();
        $simulacion = $this->simular->ejecutar($datos);
        $marca = $this->reloj->ahora();
        $id = $this->unidad->ejecutar(function () use ($simulacion, $datos, $ip, $marca) {
            $this->politica->validarDisponibilidad($simulacion);

            return $this->repositorio->crear(new NuevaInscripcion(
                titular: $simulacion->asociado,
                agencia: $simulacion->agenciaEvento(),
                total: $simulacion->total(),
                personas: $simulacion->personas(),
                versionAutorizacion: $this->configuracion->versionHabeasData(),
                ip: $ip,
                autorizaImagen: ($datos['autorizaImagen'] ?? null) === true,
                marca: $marca,
            ), fn (int $nuevoId) => Referencia::generar($nuevoId, $marca));
        });

        return $this->presentador->publico($this->repositorio->porId($id));
    }
}
