<?php

namespace App\Aplicacion\Seguridad;

use App\Dominio\Compartido\ErrorValidacion;
use App\Dominio\Seguridad\LimitadorIntentos;
use App\Dominio\Seguridad\RegistroSeguridad;

/**
 * Caso de uso: ingresar al panel. Máximo `maximoPorIp` intentos cada 15 minutos por IP (cuentan todos, no solo los
 * fallidos); cada intento queda en el registro de seguridad, sin la clave.
 */
final class IngresarAlPanel
{
    private const VENTANA_SEGUNDOS = 15 * 60;

    public function __construct(
        private readonly AutenticadorPanel $autenticador,
        private readonly LimitadorIntentos $limitador,
        private readonly RegistroSeguridad $registro,
        private readonly int $maximoPorIp = 10,
    ) {}

    /** @return array{usuario: string, nombre: string, rol: string} */
    public function ejecutar(mixed $usuario, mixed $clave, string $ip): array
    {
        $llave = "login-ip:{$ip}";
        $espera = $this->limitador->bloqueada($llave, $this->maximoPorIp);
        if ($espera > 0) {
            $this->registro->registrar('login_bloqueado', ['ip' => $ip]);
            throw new DemasiadosIntentos('Demasiados intentos. Espere unos minutos.', $espera);
        }
        $this->limitador->registrar($llave, self::VENTANA_SEGUNDOS);
        $usuario = is_string($usuario) ? mb_strtolower(trim($usuario)) : '';
        $sesion = $this->autenticador->intentar($usuario, is_string($clave) ? $clave : '');
        if (! $sesion) {
            $this->registro->registrar('login_fallido', ['usuario' => mb_substr($usuario, 0, 40), 'ip' => $ip]);
            throw new ErrorValidacion('Usuario o clave incorrectos.');
        }
        $this->registro->registrar('login_ok', ['usuario' => $sesion['usuario'], 'rol' => $sesion['rol'], 'ip' => $ip]);

        return $sesion;
    }
}
