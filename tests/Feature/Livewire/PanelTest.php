<?php

namespace Tests\Feature\Livewire;

use App\Aplicacion\Gestion\GestionUsuarios;
use App\Aplicacion\Inscripciones\ArchivoRecibido;
use App\Aplicacion\Inscripciones\Preinscribir;
use App\Aplicacion\Inscripciones\RegistrarPago;
use App\Dominio\Panel\Rol;
use App\Livewire\Panel\Auditoria;
use App\Livewire\Panel\Base;
use App\Livewire\Panel\Cupos;
use App\Livewire\Panel\Gestion;
use App\Livewire\Panel\Ingreso;
use App\Livewire\Panel\Inscripciones;
use App\Livewire\Panel\Panel;
use App\Models\Administrador;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Concerns\ConDatosDePrueba;
use Tests\TestCase;

/** Panel de administración (Livewire): ingreso, roles, revisión de pagos, cupos, cargas masivas y gestión manual. */
class PanelTest extends TestCase
{
    use ConDatosDePrueba;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cargarDatosPrueba();
        $this->configurarEvento(['validar_periodo_inscripcion' => false]);
        $usuarios = app(GestionUsuarios::class);
        $usuarios->guardar('jefe', 'Jefe Área', 'clave-segura-123', Rol::Administrador);
        $usuarios->guardar('rev', 'Revisora Uno', 'clave-segura-456', Rol::Revisor);
    }

    private function como(string $usuario): static
    {
        return $this->actingAs(Administrador::query()->findOrFail($usuario));
    }

    /** Inscripción de 1001 con el pago PSE registrado (EN_REVISION). */
    private function inscripcionEnRevision(): int
    {
        $ins = app(Preinscribir::class)->ejecutar([...self::credenciales('1001'), 'autorizaDatos' => true]);
        app(RegistrarPago::class)->ejecutar([...self::credenciales('1001'), 'referencia' => $ins['referencia'], 'autorizaDatos' => true,
            'cus' => '123456789', 'fechaPago' => now('America/Bogota')->toDateString(), 'valorPagado' => (string) $ins['total'],
            'campos' => ['correo' => 'ana@correo.com'], 'archivo' => new ArchivoRecibido('pago.pdf', '%PDF-1.4 prueba')]);

        return (int) DB::table('inscripciones')->value('id');
    }

    public function test_ingreso_y_salida_del_panel(): void
    {
        $this->get('/admin')->assertRedirect('/admin/ingreso');
        $this->get('/admin/ingreso')->assertOk()->assertSee('Ingreso administradores');

        Livewire::test(Ingreso::class)->set('usuario', 'jefe')->set('clave', 'mala-clave-xx')
            ->call('ingresar')->assertSee('Usuario o clave incorrectos.')->assertSet('clave', '');
        Livewire::test(Ingreso::class)->set('usuario', 'JEFE')->set('clave', 'clave-segura-123')
            ->call('ingresar')->assertRedirect(route('panel'));
        $this->assertAuthenticatedAs(Administrador::query()->find('jefe'));

        $this->get('/admin')->assertOk()->assertSee('Jefe Área · Administrador')->assertSee('Usuarios del panel');
        Livewire::test(Panel::class)->call('salir')->assertRedirect(route('panel.ingreso'));
        $this->assertGuest();
    }

    public function test_el_revisor_no_ve_ni_puede_usar_las_secciones_de_administrador(): void
    {
        $this->como('rev')->get('/admin')->assertOk()->assertSee('Revisora Uno · Revisor')->assertDontSee('Usuarios del panel');
        Livewire::test(Panel::class)->call('mostrar', 'usuarios')->assertSet('vista', 'inscripciones')
            ->call('mostrar', 'cupos')->assertSet('vista', 'cupos')->assertDontSee('Bases de asociados y Coopetrolitos');

        $registro = $this->registroSeguridadFalso();
        Livewire::test(Cupos::class)->set('ajustes.'.Cupos::clave('BOGOTA'), '400')->call('ajustar', 'BOGOTA')
            ->assertSee('Su rol no tiene permiso para esta acción.');
        $this->assertTrue(collect($registro->eventos)->contains(fn ($e) => $e['evento'] === 'permiso_denegado'));

        $this->expectExceptionMessage('Su rol no tiene permiso para esta acción.');
        Livewire::test(Gestion::class, ['tipo' => 'usuarios']);
    }

    public function test_revision_rechazar_exige_motivo_y_aprobar_confirma(): void
    {
        $id = $this->inscripcionEnRevision();
        $this->como('rev');
        $componente = Livewire::test(Inscripciones::class)
            ->assertSee('EVT26-')->assertSee('En revisión')
            ->call('abrir', $id)->assertSee('Soportes de pago')->assertSee('123456789')
            ->assertSee('Aprobar pago')->assertDontSee('Anular inscripción')
            ->call('pedirRevision', 'RECHAZAR')->call('confirmarAccion')->assertSee('Indique el motivo.')
            ->set('motivo', 'Soporte ilegible')->call('pedirRevision', 'RECHAZAR')->assertSet('confirmacion.tono', 'aviso')
            ->call('confirmarAccion')
            ->assertSet('detalle', null)->assertDispatched('notificar');
        $this->assertSame('RECHAZADO', DB::table('inscripciones')->value('estado'));

        $this->como('jefe');
        Livewire::test(Inscripciones::class)->call('abrir', $id)->assertSee('Anular inscripción')
            ->set('motivo', 'Duplicada')->call('pedirRevision', 'ANULAR')->call('confirmarAccion');
        $this->assertSame('ANULADO', DB::table('inscripciones')->value('estado'));
        $componente->set('estado', 'ANULADO')->assertSee('Anulado');
    }

    public function test_el_comprobante_y_la_exportacion_se_descargan_con_sesion(): void
    {
        $this->inscripcionEnRevision();
        $soporte = DB::table('soportes')->value('id');
        $this->get("/admin/soportes/{$soporte}")->assertRedirect('/admin/ingreso');
        $this->como('rev')->get("/admin/soportes/{$soporte}")->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->get('/admin/exportar.csv')->assertOk()->assertSee('Referencia;Estado', false);
        $this->get('/admin/bases/asociados/plantilla.csv')->assertForbidden();
        $this->como('jefe')->get('/admin/bases/asociados/plantilla.csv')->assertOk();
    }

    public function test_el_administrador_ajusta_cupos_y_no_puede_bajar_de_los_ocupados(): void
    {
        $this->inscripcionEnRevision();
        $this->como('jefe');
        Livewire::test(Cupos::class)
            ->set('ajustes.'.Cupos::clave('BOGOTA'), '0')->call('ajustar', 'BOGOTA')->assertSee('cupos ocupados')
            ->set('ajustes.'.Cupos::clave('PTO. MAMONAL'), '5')->call('ajustar', 'PTO. MAMONAL')->assertSee('Agencia no encontrada.')
            ->set('ajustes.'.Cupos::clave('CARTAGENA Y MAMONAL'), '7')->call('ajustar', 'CARTAGENA Y MAMONAL')
            ->assertDispatched('notificar');
        $this->assertSame(7, (int) DB::table('cupos_ajustados')->where('agencia', 'CARTAGENA Y MAMONAL')->value('cupos'));
    }

    public function test_carga_masiva_con_vista_previa_y_confirmacion(): void
    {
        $this->como('jefe');
        $csv = "Documento;Nombre;Cedula asociado\n1100009;Hija Prueba Uno;1001\n1100010;Otro Prueba;999\n";
        $archivo = UploadedFile::fake()->create('coopetrolitos.csv');
        file_put_contents($archivo->getPathname(), $csv);
        Livewire::test(Base::class, ['tipo' => 'coopetrolitos'])
            ->set('archivo', $archivo)
            ->assertSee('Revise la vista previa')->assertSee('1 Coopetrolito(s) con una cédula de asociado que no está en la base')
            ->call('pedirReemplazo')->assertSet('confirmacion.titulo', '¿Reemplazar la base de Coopetrolitos?')
            ->call('confirmarAccion')->assertDispatched('notificar')->assertSet('resumen', null);
        $this->assertSame(2, $this->contarFilas('coopetrolitos'));
        $this->assertSame('jefe', DB::table('cargas_bases')->value('usuario'));
    }

    public function test_gestion_manual_crea_edita_y_elimina_con_auditoria(): void
    {
        $this->como('jefe');
        Livewire::test(Gestion::class, ['tipo' => 'asociados'])
            ->assertSee('Ana María Torres')
            ->call('nuevo')->call('guardar')->assertSee('Complete los campos obligatorios')
            ->set('valores.documento', '80123456')->set('valores.nombre', 'Laura Gómez Díaz')->set('valores.agencia', 'CALI')
            ->set('valores.fechaExpedicion', '2004-06-15')
            ->call('guardar')->assertSet('formulario', null)->assertDispatched('notificar')
            ->set('busqueda', 'Laura')->call('buscar')->assertSee('Laura Gómez Díaz')
            ->call('editar', '80123456')->set('valores.estado', 'INACTIVO')->call('guardar')
            ->set('busqueda', '')->call('buscar')->call('eliminar', '1001')->call('confirmarAccion')->assertDispatched('notificar', tono: 'error');
        $this->assertSame('INACTIVO', DB::table('asociados')->where('documento', '80123456')->value('estado'));

        Livewire::test(Gestion::class, ['tipo' => 'usuarios'])
            ->assertSee('(usted)')
            ->call('nuevo')->set('valores.usuario', 'ana.lopez')->set('valores.nombre', 'Ana López')
            ->set('valores.clave', 'otra-clave-segura')->set('valores.clave2', 'distinta-clave-x')
            ->call('guardar')->assertSee('Las contraseñas no coinciden.')
            ->set('valores.clave2', 'otra-clave-segura')->call('guardar')->assertSet('formulario', null);
        $this->assertSame('REVISOR', DB::table('administradores')->where('usuario', 'ana.lopez')->value('rol'));
        Livewire::test(Auditoria::class)->assertSee('Creó usuario')->assertSee('Editó asociado');
    }
}
