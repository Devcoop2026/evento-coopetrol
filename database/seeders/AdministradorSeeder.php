<?php

namespace Database\Seeders;

use App\Dominio\Panel\Rol;
use App\Models\Administrador;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

class AdministradorSeeder extends Seeder
{
    public function run(): void
    {
        $usuario = trim((string) env('ADMIN_USUARIO', 'admin'));
        $nombre = trim((string) env('ADMIN_NOMBRE', 'Administrador'));
        $clave = (string) env('ADMIN_CLAVE', '');

        if ($clave === '' || strlen($clave) < 10) {
            throw new RuntimeException('Defina ADMIN_CLAVE con al menos 10 caracteres para crear el administrador.');
        }

        if (Administrador::query()->whereKey($usuario)->exists()) {
            $this->command?->warn("El usuario \"{$usuario}\" ya existe; no se modifico su clave.");

            return;
        }

        Administrador::query()->create([
            'usuario' => $usuario,
            'nombre' => $nombre !== '' ? $nombre : 'Administrador',
            'clave' => Hash::make($clave),
            'clave_salt' => null,
            'rol' => Rol::Administrador,
        ]);

        $this->command?->info("Usuario administrador \"{$usuario}\" creado.");
    }
}
