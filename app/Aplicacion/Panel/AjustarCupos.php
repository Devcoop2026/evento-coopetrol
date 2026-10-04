<?php

namespace App\Aplicacion\Panel;

use App\Dominio\Compartido\ErrorValidacion;
use App\Dominio\Compartido\Reloj;
use App\Dominio\Compartido\UnidadDeTrabajo;
use App\Dominio\Inscripcion\RepositorioInscripciones;

/**
 * Caso de uso (administrador): ajustar el cupo de un evento. No puede quedar por debajo de los ocupados.
 * null o vacío vuelve al valor de tarifas.json.
 */
final class AjustarCupos
{
    public function __construct(
        private readonly RepositorioInscripciones $repositorio,
        private readonly ConsultarCupos $cupos,
        private readonly UnidadDeTrabajo $unidad,
        private readonly Reloj $reloj,
    ) {}

    public function ejecutar(string $agencia, mixed $valor, string $usuario): array
    {
        $this->unidad->ejecutar(function () use ($agencia, $valor, $usuario) {
            $actual = $this->buscar($agencia) ?? throw new ErrorValidacion('Agencia no encontrada.');
            if ($valor === null || $valor === '') {
                $this->repositorio->quitarAjusteCupos($agencia);

                return;
            }
            $cantidad = filter_var($valor, FILTER_VALIDATE_INT);
            if ($cantidad === false || $cantidad < 0) {
                throw new ErrorValidacion('El cupo debe ser un número entero mayor o igual a cero.');
            }
            if ($cantidad < $actual['ocupados']) {
                throw new ErrorValidacion("{$agencia} ya tiene {$actual['ocupados']} cupos ocupados; no puede fijar un cupo menor.");
            }
            $this->repositorio->ajustarCupos($agencia, $cantidad, $usuario, $this->reloj->ahora());
        });

        return $this->buscar($agencia);
    }

    private function buscar(string $agencia): ?array
    {
        foreach ($this->cupos->ejecutar() as $c) {
            if ($c['agencia'] === $agencia) {
                return $c;
            }
        }

        return null;
    }
}
