<?php

namespace Tests\Feature;

use App\Aplicacion\Gestion\ConsultarAuditoria;
use App\Aplicacion\Gestion\GestionUsuarios;
use App\Aplicacion\Seguridad\IngresarAlPanel;
use App\Dominio\Compartido\ErrorValidacion;
use App\Dominio\Panel\Rol;
use App\Models\Administrador;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\Concerns\ConDatosDePrueba;
use Tests\Concerns\OperaInscripciones;
use Tests\TestCase;

/** Usuarios del panel: reglas de gestión, roles, cierre de sesiones e ingreso con la autenticación de Laravel. */
class UsuariosPanelTest extends TestCase
{
    use ConDatosDePrueba, OperaInscripciones;

    private const CLAVE_ADMIN = 'clave-admin-segura';

    protected function setUp(): void
    {
        parent::setUp();
        $this->fijarAhora('2026-10-05T15:00:00Z');
        $this->usuarios()->guardar('admin', 'Administrador', self::CLAVE_ADMIN, Rol::Administrador);
    }

    private function usuarios(): GestionUsuarios
    {
        return app(GestionUsuarios::class);
    }

    private function ingresar(string $usuario, string $clave): array
    {
        return app(IngresarAlPanel::class)->ejecutar($usuario, $clave, '10.0.0.5');
    }

    /** Sesión abierta ficticia en la tabla sessions. */
    private function abrirSesion(string $usuario): void
    {
        DB::table('sessions')->insert([
            'id' => 'sesion-'.$usuario, 'user_id' => $usuario, 'ip_address' => '10.0.0.5', 'user_agent' => 'prueba',
            'payload' => '', 'last_activity' => time(),
        ]);
    }

    private function sesionesDe(string $usuario): int
    {
        return DB::table('sessions')->where('user_id', $usuario)->count();
    }

    public function test_la_clave_se_guarda_con_hash_bcrypt(): void
    {
        $clave = Administrador::query()->find('admin')->clave;

        $this->assertNotSame(self::CLAVE_ADMIN, $clave);
        $this->assertStringStartsWith('$2y$', $clave);
        $this->assertTrue(Hash::check(self::CLAVE_ADMIN, $clave));
    }

    public function test_ingreso_con_la_autenticacion_de_laravel(): void
    {
        $sesion = $this->ingresar('ADMIN ', self::CLAVE_ADMIN);
        $this->assertSame(['usuario' => 'admin', 'nombre' => 'Administrador', 'rol' => 'ADMINISTRADOR'], $sesion);
        $this->assertAuthenticatedAs(Administrador::query()->find('admin'), 'web');

        auth('web')->logout();
        $this->assertRechaza('/^Usuario o clave incorrectos\.$/', fn () => $this->ingresar('admin', 'clave-equivocada'));
        $this->assertRechaza('/^Usuario o clave incorrectos\.$/', fn () => $this->ingresar('nadie', self::CLAVE_ADMIN));
        $this->assertGuest('web');
    }

    public function test_clave_scrypt_de_la_version_anterior_se_convierte_al_ingresar(): void
    {
        $salt = 'f3a1c9e07b2d4856a9c0e1b2d3f4a5b6'; // 32 caracteres, como los generaba la versión en Node
        $clave = 'clave-heredada-node';
        Administrador::query()->create([
            'usuario' => 'heredado', 'nombre' => 'Usuario Heredado', 'rol' => 'ADMINISTRADOR', 'clave_salt' => $salt,
            'clave' => bin2hex(sodium_crypto_pwhash_scryptsalsa208sha256(64, $clave, $salt, 524288, 33554432)),
        ]);

        $this->assertRechaza('/incorrectos/', fn () => $this->ingresar('heredado', 'otra-clave-cualquiera'));
        $this->assertSame('heredado', $this->ingresar('heredado', $clave)['usuario']);

        $fila = Administrador::query()->find('heredado');
        $this->assertNull($fila->clave_salt);
        $this->assertStringStartsWith('$2y$', $fila->clave);
        $this->assertTrue(Hash::check($clave, $fila->clave));
    }

    public function test_crear_usuarios(): void
    {
        $this->assertSame(['usuario' => 'ana.lopez'],
            $this->usuarios()->crear(['usuario' => 'Ana.Lopez', 'nombre' => 'Ana López', 'clave' => 'clave-de-ana-1', 'rol' => 'REVISOR'], 'admin'));
        $this->assertSame(Rol::Revisor, Administrador::query()->find('ana.lopez')->rol);

        $this->assertRechaza('/ya existe/', fn () => $this->usuarios()->crear(['usuario' => 'ana.lopez', 'nombre' => 'Ana', 'clave' => 'clave-de-ana-1', 'rol' => 'REVISOR'], 'admin'));
        $this->assertRechaza('/10 caracteres/', fn () => $this->usuarios()->crear(['usuario' => 'pedro', 'nombre' => 'Pedro', 'clave' => '123', 'rol' => 'REVISOR'], 'admin'));
        $this->assertRechaza('/rol/', fn () => $this->usuarios()->crear(['usuario' => 'pedro', 'nombre' => 'Pedro', 'clave' => 'clave-de-pedro'], 'admin'));

        $this->assertSame(['admin', 'ana.lopez'], array_column($this->usuarios()->listar(), 'usuario'));
    }

    public function test_cambiar_la_clave_de_otro_usuario_cierra_sus_sesiones(): void
    {
        $this->usuarios()->crear(['usuario' => 'rev', 'nombre' => 'Revisor Uno', 'clave' => 'clave-del-revisor', 'rol' => 'REVISOR'], 'admin');
        $this->abrirSesion('rev');

        $this->usuarios()->editar('rev', ['nombre' => 'Revisor Uno', 'clave' => 'clave-nueva-revisor'], 'admin');

        $this->assertSame(0, $this->sesionesDe('rev'));
        $this->assertSame('rev', $this->ingresar('rev', 'clave-nueva-revisor')['usuario']);
    }

    public function test_eliminar_usuarios(): void
    {
        $this->usuarios()->crear(['usuario' => 'rev', 'nombre' => 'Revisor Uno', 'clave' => 'clave-del-revisor', 'rol' => 'REVISOR'], 'admin');

        $this->assertRechaza('/propio usuario/', fn () => $this->usuarios()->eliminar('admin', 'admin'));
        $this->assertRechaza('/al menos un administrador/', fn () => $this->usuarios()->eliminar('admin', 'rev'));

        $this->abrirSesion('rev');
        $this->usuarios()->eliminar('rev', 'admin');
        $this->assertNull(Administrador::query()->find('rev'));
        $this->assertSame(0, $this->sesionesDe('rev'));
    }

    public function test_reglas_de_roles(): void
    {
        $this->usuarios()->crear(['usuario' => 'rev', 'nombre' => 'Revisor Uno', 'clave' => 'clave-del-revisor', 'rol' => 'REVISOR'], 'admin');

        $this->assertRechaza('/propio rol/', fn () => $this->usuarios()->editar('admin', ['nombre' => 'Administrador', 'rol' => 'REVISOR'], 'admin'));
        $this->assertRechaza('/al menos un administrador/', fn () => $this->usuarios()->editar('admin', ['nombre' => 'Administrador', 'rol' => 'REVISOR'], 'rev'));

        $this->abrirSesion('rev');
        $this->usuarios()->editar('rev', ['nombre' => 'Revisor Uno', 'rol' => 'ADMINISTRADOR'], 'admin');
        $this->assertSame(Rol::Administrador, Administrador::query()->find('rev')->rol);
        $this->assertSame(0, $this->sesionesDe('rev'));
        $this->assertMatchesRegularExpression('/REVISOR → ADMINISTRADOR/', app(ConsultarAuditoria::class)->ejecutar()[0]['detalle']);
    }

    public function test_guardar_desde_consola_conserva_el_rol(): void
    {
        $this->usuarios()->crear(['usuario' => 'rev', 'nombre' => 'Revisor Uno', 'clave' => 'clave-del-revisor', 'rol' => 'REVISOR'], 'admin');

        $this->usuarios()->guardar('rev', null, 'otra-clave-del-revisor');

        $this->assertSame(Rol::Revisor, Administrador::query()->find('rev')->rol);
    }

    public function test_editar_usuario_inexistente(): void
    {
        $this->expectException(ErrorValidacion::class);

        $this->usuarios()->editar('nadie', ['nombre' => 'Nadie Nunca'], 'admin');
    }
}
