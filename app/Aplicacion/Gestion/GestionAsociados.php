<?php

namespace App\Aplicacion\Gestion;

use App\Dominio\Compartido\ErrorValidacion;
use App\Dominio\Compartido\Reloj;
use App\Dominio\Compartido\Valores;
use App\Dominio\Inscripcion\RepositorioInscripciones;
use App\Dominio\Padron\DatosAsociado;
use App\Dominio\Padron\RepositorioGestionPadron;
use App\Dominio\Panel\RegistroAuditoria;
use App\Dominio\Seguridad\HuellaFecha;

/**
 * Gestión manual de asociados desde el panel (complementa la carga masiva con las mismas validaciones).
 * La fecha de expedición es de solo escritura: se guarda como huella y nunca se devuelve. Cada cambio queda en la auditoría.
 */
final class GestionAsociados
{
    public const POR_PAGINA = 50;

    public function __construct(
        private readonly RepositorioGestionPadron $padron,
        private readonly RepositorioInscripciones $inscripciones,
        private readonly HuellaFecha $huella,
        private readonly RegistroAuditoria $auditoria,
        private readonly Reloj $reloj,
    ) {}

    public function listar(?string $busqueda = null, int|string|null $pagina = 1): array
    {
        return $this->padron->listarAsociados(self::texto($busqueda) ?: null, max(1, (int) $pagina), self::POR_PAGINA);
    }

    public function crear(array $datos, string $usuario): array
    {
        $documento = Valores::validarDocumento($datos['documento'] ?? null);
        if ($this->padron->existeAsociado($documento)) {
            throw new ErrorValidacion("Ya existe un asociado con el documento {$documento}.");
        }
        $this->padron->crearAsociado($documento, $this->datos($datos, parcial: false));
        $this->auditoria->registrar($usuario, 'CREAR', 'asociado', $documento, null, $this->reloj->ahora());

        return ['documento' => $documento];
    }

    /** La fecha de expedición solo cambia si se envía; vacía conserva la registrada. */
    public function editar(string $documento, array $datos, string $usuario): array
    {
        if (! $this->padron->existeAsociado($documento)) {
            throw new ErrorValidacion('Asociado no encontrado.');
        }
        $nuevos = $this->datos($datos, parcial: true);
        $this->padron->actualizarAsociado($documento, $nuevos);
        $this->auditoria->registrar($usuario, 'EDITAR', 'asociado', $documento,
            $nuevos->huellaExpedicion ? 'incluye fecha de expedición' : null, $this->reloj->ahora());

        return ['documento' => $documento];
    }

    public function eliminar(string $documento, string $usuario): array
    {
        if (! $this->padron->existeAsociado($documento)) {
            throw new ErrorValidacion('Asociado no encontrado.');
        }
        if ($activa = $this->inscripciones->personaActiva($documento)) {
            throw new ErrorValidacion("El asociado está en la inscripción activa {$activa['referencia']}. Márquelo como inactivo o anule la inscripción.");
        }
        if ($hijos = $this->padron->coopetrolitosDe($documento)) {
            throw new ErrorValidacion("El asociado tiene {$hijos} Coopetrolito(s) vinculado(s). Elimínelos o reasígnelos primero.");
        }
        $this->padron->eliminarAsociado($documento);
        $this->auditoria->registrar($usuario, 'ELIMINAR', 'asociado', $documento, null, $this->reloj->ahora());

        return ['documento' => $documento];
    }

    private function datos(array $d, bool $parcial): DatosAsociado
    {
        $agencia = mb_strtoupper(self::texto($d['agencia'] ?? null));
        if (! in_array($agencia, $this->padron->agenciasValidas(), true)) {
            throw new ErrorValidacion("La agencia \"{$agencia}\" no existe en las tarifas del evento.");
        }
        $expedicion = Valores::validarFecha($d['fechaExpedicion'] ?? null, 'la fecha de expedición', opcional: $parcial);

        return new DatosAsociado(
            nombre: Valores::validarNombreCompleto($d['nombre'] ?? null),
            agencia: $agencia,
            estado: ($d['estado'] ?? null) === 'INACTIVO' ? 'INACTIVO' : 'ACTIVO',
            fechaActualizacion: Valores::validarFecha($d['fechaActualizacion'] ?? null, 'la fecha de actualización de datos', opcional: true),
            fechaNacimiento: self::validarNacimiento($d['fechaNacimiento'] ?? null, $this->reloj),
            huellaExpedicion: $expedicion ? $this->huella->calcular($expedicion) : null,
        );
    }

    /** Fecha de nacimiento opcional: real, no futura y posterior a 1900. */
    public static function validarNacimiento(mixed $valor, Reloj $reloj): ?string
    {
        $fecha = Valores::validarFecha($valor, 'la fecha de nacimiento', opcional: true);
        if ($fecha && ($fecha < '1900-01-01' || $fecha > Valores::fechaColombia($reloj->ahora()))) {
            throw new ErrorValidacion('La fecha de nacimiento no es válida.');
        }

        return $fecha;
    }

    public static function texto(mixed $valor): string
    {
        return preg_replace('/\s+/u', ' ', trim(Valores::cadena($valor)));
    }
}
