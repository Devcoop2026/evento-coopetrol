<?php

namespace App\Aplicacion\Inscripciones;

use App\Aplicacion\Configuracion\ConfiguracionEvento;
use App\Aplicacion\Simulacion\SimularInscripcion;
use App\Dominio\Compartido\Reloj;
use App\Dominio\Inscripcion\NuevaInscripcion;
use App\Dominio\Inscripcion\Referencia;
use App\Dominio\Inscripcion\RepositorioInscripciones;

/**
 * Caso de uso (módulo "Inscripción y pago en agencia"): inscribirse y registrar el pago en un solo paso. Crea la
 * inscripción EN_REVISION y ocupa el cupo. Todo o nada: si el pago no es válido o no hay cupo, no queda nada a medias.
 */
final class InscribirConPago
{
    public function __construct(
        private readonly SimularInscripcion $simular,
        private readonly PoliticaInscripcion $politica,
        private readonly ValidarPago $validarPago,
        private readonly RegistradorPagos $registrador,
        private readonly RepositorioInscripciones $repositorio,
        private readonly PresentadorInscripcion $presentador,
        private readonly ConfiguracionEvento $configuracion,
        private readonly Reloj $reloj,
    ) {}

    /** @param  array<string, mixed>  $datos  datos de la simulación + datos del pago + autorizaciones */
    public function ejecutar(array $datos, ?string $ip = null): array
    {
        $this->politica->exigirAutorizacion($datos['autorizaDatos'] ?? null);
        $this->politica->validarPeriodo();
        $simulacion = $this->simular->ejecutar($datos);
        $pago = $this->validarPago->ejecutar($datos, 0);
        $marca = $this->reloj->ahora();
        $id = $this->registrador->conComprobante($pago, function (string $archivo) use ($simulacion, $pago, $datos, $ip, $marca) {
            $this->politica->validarDisponibilidad($simulacion); // incluye el cupo: se ocupa al registrar el pago
            $id = $this->repositorio->crear(new NuevaInscripcion(
                titular: $simulacion->asociado,
                agencia: $simulacion->agenciaEvento(),
                total: $simulacion->total(),
                personas: $simulacion->personas(),
                versionAutorizacion: $this->configuracion->versionHabeasData(),
                ip: $ip,
                autorizaImagen: ($datos['autorizaImagen'] ?? null) === true,
                marca: $marca,
            ), fn (int $nuevoId) => Referencia::generar($nuevoId, $marca));
            $this->registrador->registrar($id, $simulacion->total(), $pago, $archivo, $marca);

            return $id;
        });

        return $this->presentador->publico($this->repositorio->porId($id));
    }
}
