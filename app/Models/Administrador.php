<?php

namespace App\Models;

use App\Dominio\Panel\Rol;
use Illuminate\Foundation\Auth\User as Authenticatable;

/**
 * Usuario del panel. Se autentica con el guard "web" (sesión de Laravel); la clave se guarda con el hash de Laravel.
 * `clave_salt` solo existe en claves scrypt importadas de la versión anterior: se convierten al ingresar.
 */
class Administrador extends Authenticatable
{
    protected $table = 'administradores';

    protected $primaryKey = 'usuario';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $guarded = [];

    protected $hidden = ['clave', 'clave_salt'];

    protected function casts(): array
    {
        return ['rol' => Rol::class];
    }

    public function getAuthPasswordName(): string
    {
        return 'clave';
    }

    /** Sin "recordarme": la sesión dura lo que indique SESSION_LIFETIME (8 horas). */
    public function getRememberTokenName(): string
    {
        return '';
    }

    public function esAdministrador(): bool
    {
        return $this->rol === Rol::Administrador;
    }
}
