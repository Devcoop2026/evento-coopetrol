<?php

namespace App\Aplicacion\Gestion;

use App\Dominio\Compartido\ErrorValidacion;
use App\Dominio\Compartido\Reloj;
use App\Dominio\Compartido\Valores;
use App\Dominio\Panel\HashClaves;
use App\Dominio\Panel\RegistroAuditoria;
use App\Dominio\Panel\RepositorioUsuarios;
use App\Dominio\Panel\Rol;

/**
 * Usuarios del panel. Reglas: usuario de 3 a 40 caracteres (letras, números, . _ -), clave de 10 caracteres o más,
 * siempre queda al menos un administrador, nadie cambia su propio rol ni se elimina a sí mismo, y cambiar la clave o
 * el rol cierra las sesiones abiertas de ese usuario.
 */
final class GestionUsuarios
{
    public function __construct(
        private readonly RepositorioUsuarios $usuarios,
        private readonly HashClaves $hash,
        private readonly RegistroAuditoria $auditoria,
        private readonly Reloj $reloj,
    ) {}

    /**
     * Crea el usuario o cambia su clave (consola). `rol` solo aplica al crear o si se indica; si no, conserva el actual.
     */
    public function guardar(string $usuario, ?string $nombre, string $clave, ?Rol $rol = null): string
    {
        if (! preg_match('/^[a-z0-9._-]{3,40}$/Di', $usuario)) {
            throw new ErrorValidacion('Usuario inválido (3 a 40 letras, números, . _ -).');
        }
        if (mb_strlen($clave) < 10) {
            throw new ErrorValidacion('La clave debe tener al menos 10 caracteres.');
        }
        $id = mb_strtolower($usuario);
        $this->usuarios->guardar($id, GestionAsociados::texto($nombre) ?: $id, $this->hash->hash($clave), $rol);

        return $id;
    }

    public function listar(): array
    {
        return $this->usuarios->listar();
    }

    public function crear(array $datos, string $autor): array
    {
        $id = mb_strtolower(trim(Valores::cadena($datos['usuario'] ?? null)));
        if ($this->usuarios->buscar($id)) {
            throw new ErrorValidacion("El usuario \"{$id}\" ya existe.");
        }
        $rol = self::rol($datos['rol'] ?? null);
        $this->guardar($id, Valores::cadena($datos['nombre'] ?? null), Valores::cadena($datos['clave'] ?? null), $rol);
        $this->auditoria->registrar($autor, 'CREAR', 'usuario', $id, "rol {$rol->value}", $this->reloj->ahora());

        return ['usuario' => $id];
    }

    public function editar(string $usuario, array $datos, string $autor): array
    {
        $actual = $this->usuarios->buscar($usuario) ?? throw new ErrorValidacion('Usuario no encontrado.');
        $nuevoRol = ! empty($datos['rol']) ? self::rol($datos['rol']) : $actual['rol'];
        if ($nuevoRol !== $actual['rol']) {
            if ($usuario === $autor) {
                throw new ErrorValidacion('No puede cambiar su propio rol.');
            }
            if ($actual['rol'] === Rol::Administrador && $this->usuarios->contarAdministradores() <= 1) {
                throw new ErrorValidacion('Debe quedar al menos un administrador.');
            }
            $this->usuarios->cambiarRol($usuario, $nuevoRol);
            $this->usuarios->cerrarSesiones($usuario); // el nuevo rol aplica desde el próximo ingreso
        }
        $clave = Valores::cadena($datos['clave'] ?? null);
        $nombre = GestionAsociados::texto($datos['nombre'] ?? null);
        if ($clave !== '') {
            $this->guardar($usuario, $nombre ?: $actual['nombre'], $clave);
            if ($usuario !== $autor) {
                $this->usuarios->cerrarSesiones($usuario);
            }
        } else {
            if ($nombre === '') {
                throw new ErrorValidacion('Ingrese el nombre.');
            }
            $this->usuarios->cambiarNombre($usuario, $nombre);
        }
        $detalle = implode('; ', array_filter([
            $clave !== '' ? 'cambio de clave' : null,
            $nuevoRol !== $actual['rol'] ? "rol {$actual['rol']->value} → {$nuevoRol->value}" : null,
        ]));
        $this->auditoria->registrar($autor, 'EDITAR', 'usuario', $usuario, $detalle ?: null, $this->reloj->ahora());

        return ['usuario' => $usuario];
    }

    public function eliminar(string $usuario, string $autor): array
    {
        if ($usuario === $autor) {
            throw new ErrorValidacion('No puede eliminar su propio usuario.');
        }
        $actual = $this->usuarios->buscar($usuario) ?? throw new ErrorValidacion('Usuario no encontrado.');
        if ($actual['rol'] === Rol::Administrador && $this->usuarios->contarAdministradores() <= 1) {
            throw new ErrorValidacion('Debe quedar al menos un administrador.');
        }
        $this->usuarios->cerrarSesiones($usuario);
        $this->usuarios->eliminar($usuario);
        $this->auditoria->registrar($autor, 'ELIMINAR', 'usuario', $usuario, null, $this->reloj->ahora());

        return ['usuario' => $usuario];
    }

    private static function rol(mixed $valor): Rol
    {
        return (is_string($valor) ? Rol::tryFrom($valor) : null) ?? throw new ErrorValidacion('Seleccione el rol del usuario.');
    }
}
