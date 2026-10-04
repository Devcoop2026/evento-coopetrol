<?php

namespace App\Dominio\Inscripcion;

use DateTimeImmutable;

/** Puerto de persistencia de inscripciones, personas inscritas, soportes de pago y cupos. */
interface RepositorioInscripciones
{
    /** Crea la inscripción PREINSCRITO y devuelve su id. `referencia(id)` calcula la referencia definitiva. */
    public function crear(NuevaInscripcion $datos, callable $referencia): int;

    public function porId(int $id): ?Inscripcion;

    public function porReferencia(string $referencia): ?Inscripcion;

    /** Inscripción vigente del titular o, si no hay, la más reciente (un asociado tiene a lo sumo una activa). */
    public function deTitular(string $documento): ?Inscripcion;

    /** @return list<Inscripcion> */
    public function todas(): array;

    /** @return list<PersonaInscrita> en el orden en que se registraron */
    public function personas(int $inscripcionId): array;

    /** @param  list<PersonaInscrita>  $personas */
    public function guardarPersonas(int $inscripcionId, array $personas): void;

    /** @return list<Soporte> del más reciente al más antiguo */
    public function soportes(int $inscripcionId): array;

    public function ultimoSoporte(int $inscripcionId): ?Soporte;

    public function soporte(int $soporteId): ?Soporte;

    public function insertarSoporte(NuevoSoporte $soporte): void;

    /** Otra inscripción vigente (distinta de `excluida`) en la que figura el documento: ['referencia', 'documento_titular']. */
    public function personaActiva(string $documento, int $excluida = 0): ?array;

    /** Cupos libres del evento sin contar la inscripción `excluida`. */
    public function cuposDisponibles(string $agencia, int $excluida = 0): int;

    public function cusUsado(string $cus, int $excluida): ?string;

    public function reciboUsado(string $agencia, string $recibo, int $excluida): ?string;

    public function actualizarLiquidacion(int $id, int $total, string $agencia, DateTimeImmutable $marca): void;

    public function cambiarEstado(int $id, EstadoInscripcion $estado, ?string $motivo, DateTimeImmutable $marca): void;

    public function registrarRevision(int $id, CambioDeEstado $cambio, string $usuario, DateTimeImmutable $marca): void;

    /**
     * Contador de cupos por evento. Solo cuentan quienes ocupan cupo; preinscritos e invitados se informan aparte.
     *
     * @return list<array{agencia: string, cupos_excel: int, cupos_ajustados: ?int, cupos: int, ocupados: int, pendientes: int, confirmados: int, invitados: int}>
     */
    public function cupos(): array;

    public function ajustarCupos(string $agencia, int $cupos, string $usuario, DateTimeImmutable $marca): void;

    public function quitarAjusteCupos(string $agencia): void;

    /**
     * Listado del panel (máximo 1000, las más recientes primero).
     *
     * @param  array{estado?: ?string, agencia?: ?string, busqueda?: ?string}  $filtros
     * @return list<array<string, mixed>>
     */
    public function listar(array $filtros): array;
}
