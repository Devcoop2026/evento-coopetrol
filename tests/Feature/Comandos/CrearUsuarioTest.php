<?php

namespace Tests\Feature\Comandos;

use App\Dominio\Panel\Rol;
use App\Models\Administrador;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/** evento:usuario — crea o actualiza usuarios del panel desde la consola. */
class CrearUsuarioTest extends TestCase
{
    use RefreshDatabase;

    private const CLAVE = 'clave-de-consola-segura';

    private function crear(array $argumentos, ?string $clave = self::CLAVE, ?string $repetida = null)
    {
        return $this->artisan('evento:usuario', $argumentos)
            ->expectsQuestion('Clave (mínimo 10 caracteres)', $clave)
            ->expectsQuestion('Repita la clave', $repetida ?? $clave);
    }

    public function test_crea_un_administrador_por_defecto(): void
    {
        $this->crear(['usuario' => 'DReina', 'nombre' => 'Diego Reina'])
            ->expectsOutput('Usuario "dreina" listo (rol ADMINISTRADOR). Ingrese en /admin')
            ->assertSuccessful();

        $usuario = Administrador::query()->findOrFail('dreina');
        $this->assertSame('Diego Reina', $usuario->nombre);
        $this->assertSame(Rol::Administrador, $usuario->rol);
        $this->assertTrue(Hash::check(self::CLAVE, $usuario->clave));
    }

    public function test_el_rol_no_distingue_mayusculas_y_se_conserva_al_cambiar_la_clave(): void
    {
        $this->crear(['usuario' => 'tesoreria', 'nombre' => 'Tesorería', '--rol' => 'revisor'])
            ->expectsOutput('Usuario "tesoreria" listo (rol REVISOR). Ingrese en /admin')
            ->assertSuccessful();

        $this->crear(['usuario' => 'tesoreria'], 'otra-clave-segura-2026')
            ->expectsOutput('Usuario "tesoreria" listo (rol REVISOR). Ingrese en /admin')
            ->assertSuccessful();

        $usuario = Administrador::query()->findOrFail('tesoreria');
        $this->assertSame(Rol::Revisor, $usuario->rol);
        $this->assertTrue(Hash::check('otra-clave-segura-2026', $usuario->clave));
    }

    public function test_rechaza_claves_distintas_cortas_o_un_rol_invalido(): void
    {
        $this->crear(['usuario' => 'admin'], self::CLAVE, 'otra-clave-distinta')
            ->expectsOutput('Las claves no coinciden.')
            ->assertFailed();

        $this->crear(['usuario' => 'admin'], 'corta')
            ->expectsOutput('La clave debe tener al menos 10 caracteres.')
            ->assertFailed();

        $this->artisan('evento:usuario', ['usuario' => 'admin', '--rol' => 'jefe'])
            ->expectsOutput('Rol inválido. Use ADMINISTRADOR o REVISOR.')
            ->assertFailed();

        $this->assertSame(0, Administrador::query()->count());
    }
}
