<?php

namespace App\Aplicacion\Gestion;

use App\Dominio\Compartido\ErrorValidacion;
use App\Dominio\Compartido\Reloj;
use App\Dominio\Compartido\Valores;
use App\Dominio\Inscripcion\RepositorioInscripciones;
use App\Dominio\Padron\DatosCoopetrolito;
use App\Dominio\Padron\RepositorioGestionPadron;
use App\Dominio\Panel\RegistroAuditoria;

/** Gestión manual de Coopetrolitos. La cédula del asociado debe existir en la base. */
final class GestionCoopetrolitos
{
    public function __construct(
        private readonly RepositorioGestionPadron $padron,
        private readonly RepositorioInscripciones $inscripciones,
        private readonly RegistroAuditoria $auditoria,
        private readonly Reloj $reloj,
    ) {}

    public function listar(?string $busqueda = null, int|string|null $pagina = 1): array
    {
        return $this->padron->listarCoopetrolitos(GestionAsociados::texto($busqueda) ?: null, max(1, (int) $pagina), GestionAsociados::POR_PAGINA);
    }

    public function crear(array $datos, string $usuario): array
    {
        $documento = Valores::validarDocumento($datos['documento'] ?? null);
        if ($this->padron->existeCoopetrolito($documento)) {
            throw new ErrorValidacion("Ya existe un Coopetrolito con el documento {$documento}.");
        }
        $this->padron->crearCoopetrolito($documento, $this->datos($datos));
        $this->auditoria->registrar($usuario, 'CREAR', 'coopetrolito', $documento, null, $this->reloj->ahora());

        return ['documento' => $documento];
    }

    public function editar(string $documento, array $datos, string $usuario): array
    {
        if (! $this->padron->existeCoopetrolito($documento)) {
            throw new ErrorValidacion('Coopetrolito no encontrado.');
        }
        $this->padron->actualizarCoopetrolito($documento, $this->datos($datos));
        $this->auditoria->registrar($usuario, 'EDITAR', 'coopetrolito', $documento, null, $this->reloj->ahora());

        return ['documento' => $documento];
    }

    public function eliminar(string $documento, string $usuario): array
    {
        if (! $this->padron->existeCoopetrolito($documento)) {
            throw new ErrorValidacion('Coopetrolito no encontrado.');
        }
        if ($activa = $this->inscripciones->personaActiva($documento)) {
            throw new ErrorValidacion("El Coopetrolito está en la inscripción activa {$activa['referencia']}. Anule o modifique la inscripción primero.");
        }
        $this->padron->eliminarCoopetrolito($documento);
        $this->auditoria->registrar($usuario, 'ELIMINAR', 'coopetrolito', $documento, null, $this->reloj->ahora());

        return ['documento' => $documento];
    }

    private function datos(array $d): DatosCoopetrolito
    {
        $padre = Valores::validarDocumento($d['documentoAsociado'] ?? null, 'Cédula del asociado');
        if (! $this->padron->existeAsociado($padre)) {
            throw new ErrorValidacion("No existe un asociado con la cédula {$padre}.");
        }

        return new DatosCoopetrolito(
            nombre: Valores::validarNombreCompleto($d['nombre'] ?? null),
            documentoAsociado: $padre,
            fechaNacimiento: GestionAsociados::validarNacimiento($d['fechaNacimiento'] ?? null, $this->reloj),
        );
    }
}
