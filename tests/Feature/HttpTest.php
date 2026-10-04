<?php

namespace Tests\Feature;

use App\Aplicacion\Inscripciones\ArchivoRecibido;
use App\Dominio\Panel\Rol;
use App\Models\Administrador;
use App\Models\Soporte;
use Illuminate\Support\Facades\Hash;
use Tests\Concerns\ConDatosDePrueba;
use Tests\Concerns\OperaInscripciones;
use Tests\TestCase;

/** Rutas HTTP (sin probar los componentes Livewire): cabeceras de seguridad, restricción del panel y descargas. */
class HttpTest extends TestCase
{
    use ConDatosDePrueba, OperaInscripciones;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fijarAhora('2026-10-05T15:00:00Z');
    }

    private function usuario(Rol $rol): Administrador
    {
        return Administrador::query()->create([
            'usuario' => strtolower($rol->value), 'nombre' => $rol->etiqueta(), 'clave' => Hash::make('clave-de-prueba-1'), 'rol' => $rol,
        ]);
    }

    public function test_cabeceras_de_seguridad(): void
    {
        $respuesta = $this->get('/');

        $csp = $respuesta->headers->get('Content-Security-Policy');
        $this->assertStringContainsString("default-src 'self'", $csp);
        $this->assertStringContainsString("script-src 'self' 'nonce-", $csp);
        $this->assertStringContainsString("object-src 'none'", $csp);
        $respuesta->assertHeader('X-Content-Type-Options', 'nosniff');
        $respuesta->assertHeader('X-Frame-Options', 'SAMEORIGIN');
        $respuesta->assertHeader('Permissions-Policy');
    }

    public function test_panel_exige_ingreso(): void
    {
        $this->get('/admin')->assertRedirect('/admin/ingreso');
    }

    public function test_panel_restringido_a_la_red_interna(): void
    {
        $registro = $this->registroSeguridadFalso();
        config(['evento.panel_redes' => '10.0.0.0/8']);

        $this->get('/admin/ingreso', ['REMOTE_ADDR' => '127.0.0.1'])->assertForbidden();
        $this->get('/')->assertOk();
        $this->assertSame('127.0.0.1', $registro->de('acceso_panel_denegado')[0]['ip']);
    }

    public function test_comprobante_solo_para_usuarios_del_panel_y_aislado(): void
    {
        $this->cargarDatosPrueba();
        $this->configurarEvento();
        $r = $this->preinscribir('1001');
        $this->pagar('1001', $r['referencia'], ['archivo' => new ArchivoRecibido('pago.png', self::PNG)]);
        $soporte = Soporte::query()->value('id');

        $this->get("/admin/soportes/{$soporte}")->assertRedirect('/admin/ingreso');

        $respuesta = $this->actingAs($this->usuario(Rol::Revisor))->get("/admin/soportes/{$soporte}");
        $respuesta->assertOk();
        $respuesta->assertHeader('Content-Type', 'image/png');
        $respuesta->assertHeader('Cross-Origin-Resource-Policy', 'same-origin');
        $this->assertStringContainsString('sandbox', $respuesta->headers->get('Content-Security-Policy'));
        $this->assertSame(self::PNG, $respuesta->getContent());
    }

    public function test_exportar_para_revisor(): void
    {
        $respuesta = $this->actingAs($this->usuario(Rol::Revisor))->get('/admin/exportar.csv');

        $respuesta->assertOk();
        $this->assertStringStartsWith('text/csv', $respuesta->headers->get('Content-Type'));
        $this->assertStringStartsWith("\u{FEFF}Referencia;", $respuesta->getContent());
    }

    public function test_plantillas_solo_para_administradores(): void
    {
        $this->actingAs($this->usuario(Rol::Revisor))->get('/admin/bases/asociados/plantilla.csv')->assertForbidden();
        $this->actingAs($this->usuario(Rol::Administrador))->get('/admin/bases/asociados/plantilla.csv')->assertOk();
    }
}
