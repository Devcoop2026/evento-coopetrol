<?php

namespace App\Aplicacion\Seguridad;

use App\Dominio\Compartido\ErrorValidacion;
use App\Dominio\Seguridad\Enmascarar;
use App\Dominio\Seguridad\LimitadorIntentos;
use App\Dominio\Seguridad\RegistroSeguridad;

/**
 * Envuelve las acciones que validan documento + fecha de expedición: cuenta los fallos de identidad por IP (frena la
 * prueba de fechas sobre muchos documentos) y los deja en el registro de seguridad con el documento enmascarado.
 */
final class ProteccionIdentidad
{
    private const VENTANA_SEGUNDOS = 15 * 60;

    public function __construct(
        private readonly LimitadorIntentos $limitador,
        private readonly RegistroSeguridad $registro,
        private readonly int $maximoPorIp = 20,
    ) {}

    /**
     * @template T
     *
     * @param  callable(): T  $accion
     * @return T
     */
    public function ejecutar(string $ip, mixed $documento, string $ruta, callable $accion): mixed
    {
        $clave = "identidad-ip:{$ip}";
        $espera = $this->limitador->bloqueada($clave, $this->maximoPorIp);
        if ($espera > 0) {
            $this->registro->registrar('ip_bloqueada', ['ip' => $ip, 'ruta' => $ruta]);
            throw new DemasiadosIntentos('Demasiados intentos fallidos desde su conexión. Espere unos minutos e intente de nuevo.', $espera);
        }
        try {
            return $accion();
        } catch (ErrorValidacion $error) {
            if ($error->identidad) {
                $this->limitador->registrar($clave, self::VENTANA_SEGUNDOS);
                $this->registro->registrar('identidad_fallida', ['documento' => Enmascarar::documento($documento), 'ip' => $ip, 'ruta' => $ruta]);
            }
            throw $error;
        }
    }
}
