<?php

namespace App\Infraestructura\Seguridad;

use App\Models\Administrador;
use Illuminate\Auth\EloquentUserProvider;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Hashing\Hasher;

/**
 * Proveedor de usuarios del panel.
 * - Si el usuario no existe igual se calcula un hash, para no revelar usuarios por el tiempo de respuesta.
 * - Acepta las claves scrypt importadas de la versión anterior (N=16384, r=8, p=1, 64 bytes; mismos parámetros que
 *   crypto.scryptSync de Node) y al ingresar las convierte al hash de Laravel.
 */
final class ProveedorAdministradores extends EloquentUserProvider
{
    // libsodium deriva N=2^14, r=8, p=1 con estos límites (ver crypto_pwhash_scryptsalsa208sha256).
    private const SCRYPT_OPS = 524288;

    private const SCRYPT_MEM = 33554432;

    private const HASH_FICTICIO = '$2y$12$71c8GC6XZLYpnZnV.aqJtep1d73PDB8Vcamp4BwZ1ERwXnGoCMvl6';

    public function __construct(Hasher $hasher)
    {
        parent::__construct($hasher, Administrador::class);
    }

    public function retrieveByCredentials(array $credentials): ?Authenticatable
    {
        $usuario = parent::retrieveByCredentials($credentials);
        if (! $usuario) {
            $this->hasher->check((string) ($credentials['password'] ?? ''), self::HASH_FICTICIO);
        }

        return $usuario;
    }

    public function validateCredentials(Authenticatable $user, array $credentials): bool
    {
        $clave = (string) ($credentials['password'] ?? '');
        if ($user instanceof Administrador && $user->clave_salt) {
            return self::coincideScrypt($clave, $user->clave_salt, $user->clave);
        }

        return parent::validateCredentials($user, $credentials);
    }

    public function rehashPasswordIfRequired(Authenticatable $user, array $credentials, bool $force = false): void
    {
        if ($user instanceof Administrador && $user->clave_salt) {
            $user->forceFill(['clave' => $this->hasher->make((string) $credentials['password']), 'clave_salt' => null])->save();

            return;
        }
        parent::rehashPasswordIfRequired($user, $credentials, $force);
    }

    private static function coincideScrypt(string $clave, string $salt, string $hashHex): bool
    {
        if (! function_exists('sodium_crypto_pwhash_scryptsalsa208sha256')) {
            return false;
        }
        $derivada = sodium_crypto_pwhash_scryptsalsa208sha256(64, $clave, $salt, self::SCRYPT_OPS, self::SCRYPT_MEM);

        return hash_equals(strtolower($hashHex), bin2hex($derivada));
    }
}
