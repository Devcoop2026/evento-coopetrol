<?php

namespace App\Aplicacion\Simulacion;

use App\Aplicacion\Configuracion\ConfiguracionEvento;
use App\Dominio\Compartido\ErrorValidacion;
use App\Dominio\Compartido\Reloj;
use App\Dominio\Compartido\Valores;
use App\Dominio\Padron\RepositorioPadron;
use App\Dominio\Seguridad\HuellaFecha;
use App\Dominio\Seguridad\LimitadorIntentos;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Caso de uso: identificar al asociado titular.
 * - Segunda validación de identidad: fecha de expedición del documento (comparada contra su huella).
 * - Tras 10 fallos en 15 minutos el documento queda bloqueado ese tiempo (además se limitan los fallos por IP).
 * - El titular debe ser ACTIVO y con datos actualizados.
 * - Mismo mensaje si el documento no existe, no tiene fecha registrada o la fecha no coincide: no revela quién es asociado.
 */
final class IdentificarAsociado
{
    public const MENSAJE_IDENTIDAD = 'El documento y la fecha de expedición no coinciden con un asociado de Coopetrol. '
        .'Si cree que es un error, comuníquese con su agencia.';

    private const BLOQUEO_SEGUNDOS = 15 * 60;

    public function __construct(
        private readonly RepositorioPadron $padron,
        private readonly HuellaFecha $huella,
        private readonly LimitadorIntentos $limitador,
        private readonly Reloj $reloj,
        private readonly ConfiguracionEvento $configuracion,
        private readonly int $intentosPorDocumento = 10,
    ) {}

    public function ejecutar(mixed $documento, mixed $fechaExpedicion): IdentidadVerificada
    {
        $doc = Valores::validarDocumento($documento, minimo: 1, maximo: 20);
        $fecha = trim(Valores::cadena($fechaExpedicion));
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/D', $fecha)) {
            throw new ErrorValidacion('Ingrese la fecha de expedición del documento.');
        }
        $clave = "documento:{$doc}";
        $espera = $this->limitador->bloqueada($clave, $this->intentosPorDocumento);
        if ($espera > 0) {
            throw ErrorValidacion::identidad('Demasiados intentos fallidos. Intente de nuevo en '.(int) ceil($espera / 60).' minuto(s).');
        }
        $asociado = $this->padron->asociado($doc);
        if (! $asociado || ! $this->huella->coincide($fecha, $asociado->huellaExpedicion)) {
            $this->limitador->registrar($clave, self::BLOQUEO_SEGUNDOS);
            throw ErrorValidacion::identidad(self::MENSAJE_IDENTIDAD);
        }
        $this->limitador->reiniciar($clave);

        $hoy = new DateTimeImmutable(Valores::fechaColombia($this->reloj->ahora()), new DateTimeZone('America/Bogota'));
        $asociado->exigirHabilitadoComoTitular($hoy, $this->configuracion->mesesVigenciaDatos);

        // En un evento compartido (p. ej. CARTAGENA y PTO. MAMONAL) la tarifa y los cupos son los del evento.
        $evento = $this->padron->eventoDeAgencia($asociado->agencia);
        $tarifa = $evento ? $this->padron->tarifa($evento) : null;
        if (! $tarifa) {
            throw new ErrorValidacion("La agencia {$asociado->agencia} no tiene tarifa configurada para el evento.");
        }

        return new IdentidadVerificada($asociado, $tarifa);
    }
}
