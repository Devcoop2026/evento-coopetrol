<?php

namespace Tests\Feature;

use App\Aplicacion\Gestion\GestionUsuarios;
use App\Aplicacion\Panel\AvisosPanel;
use App\Aplicacion\Panel\PermisoDenegado;
use App\Aplicacion\Panel\RevisarInscripcion;
use App\Aplicacion\Seguridad\AutenticadorPanel;
use App\Aplicacion\Seguridad\DemasiadosIntentos;
use App\Aplicacion\Seguridad\IngresarAlPanel;
use App\Aplicacion\Seguridad\ProteccionIdentidad;
use App\Aplicacion\Simulacion\IdentificarAsociado;
use App\Dominio\Compartido\ErrorValidacion;
use App\Dominio\Panel\Rol;
use App\Dominio\Seguridad\LimitadorIntentos;
use Tests\Concerns\ConDatosDePrueba;
use Tests\TestCase;

/** Límites de intentos, registro de seguridad sin datos sensibles, permisos por rol y avisos del panel. */
class SeguridadTest extends TestCase
{
    use ConDatosDePrueba;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cargarDatosPrueba();
        $this->fijarAhora('2026-10-05T15:00:00Z');
        $this->configurarEvento(['fecha_supresion_datos' => '2026-01-31']);
    }

    public function test_bloquea_la_ip_tras_varios_fallos_de_identidad_sin_registrar_la_fecha(): void
    {
        $registro = $this->registroSeguridadFalso();
        $proteccion = new ProteccionIdentidad(app(LimitadorIntentos::class), $registro, 4);
        $intento = fn () => $proteccion->ejecutar('10.1.1.1', '12345678', '/consulta',
            fn () => app(IdentificarAsociado::class)->ejecutar('12345678', '1990-07-23'));

        for ($i = 0; $i < 4; $i++) {
            try {
                $intento();
                $this->fail('La identidad no debía coincidir.');
            } catch (ErrorValidacion $error) {
                $this->assertTrue($error->identidad);
            }
        }

        try {
            $intento();
            $this->fail('Debió bloquear la IP.');
        } catch (DemasiadosIntentos $error) {
            $this->assertGreaterThan(0, $error->segundos);
        }

        $fallidos = $registro->de('identidad_fallida');
        $this->assertCount(4, $fallidos);
        $this->assertSame('*****678', $fallidos[0]['documento']);
        $this->assertCount(1, $registro->de('ip_bloqueada'));
        $this->assertStringNotContainsString('1990-07-23', $registro->comoTexto());
        $this->assertStringNotContainsString('12345678', $registro->comoTexto());
    }

    public function test_ingreso_fallido_se_registra_sin_la_clave_y_se_limita_por_ip(): void
    {
        app(GestionUsuarios::class)->guardar('admin', 'Administrador', 'clave-admin-segura', Rol::Administrador);
        $registro = $this->registroSeguridadFalso();
        $ingresar = new IngresarAlPanel(app(AutenticadorPanel::class), app(LimitadorIntentos::class), $registro, 3);

        for ($i = 0; $i < 3; $i++) {
            try {
                $ingresar->ejecutar('admin', 'clave-secreta-equivocada', '10.2.2.2');
                $this->fail('La clave no debía coincidir.');
            } catch (ErrorValidacion $error) {
                $this->assertSame('Usuario o clave incorrectos.', $error->getMessage());
            }
        }
        $this->assertSame('admin', $registro->de('login_fallido')[0]['usuario']);
        $this->assertStringNotContainsString('clave-secreta-equivocada', $registro->comoTexto());

        $this->expectException(DemasiadosIntentos::class);
        $ingresar->ejecutar('admin', 'clave-admin-segura', '10.2.2.2');
    }

    public function test_revisor_no_puede_anular(): void
    {
        $this->expectException(PermisoDenegado::class);

        app(RevisarInscripcion::class)->ejecutar(1, 'ANULAR', 'Duplicada', 'rev', Rol::Revisor);
    }

    public function test_aviso_de_supresion_de_datos_solo_para_administradores(): void
    {
        $avisos = app(AvisosPanel::class);

        $this->assertMatchesRegularExpression('/fecha de supresión/', $avisos->ejecutar(Rol::Administrador)[0]);
        $this->assertSame([], $avisos->ejecutar(Rol::Revisor));
    }
}
