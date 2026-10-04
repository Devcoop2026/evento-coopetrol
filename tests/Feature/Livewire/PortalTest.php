<?php

namespace Tests\Feature\Livewire;

use App\Aplicacion\Configuracion\ConsultarDatosEvento;
use App\Livewire\Portal\Consulta;
use App\Livewire\Portal\Inscripcion;
use App\Livewire\Portal\Portal;
use App\Livewire\Portal\TablaTarifas;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Concerns\ConDatosDePrueba;
use Tests\TestCase;

/** Portal del asociado (Livewire): módulos PSE y agencia, consulta, modificación, cancelación y registro del pago. */
class PortalTest extends TestCase
{
    use ConDatosDePrueba;

    private const PDF = '%PDF-1.4 prueba';

    protected function setUp(): void
    {
        parent::setUp();
        $this->cargarDatosPrueba();
        $this->fijarAhora('2026-10-05T15:00:00Z');
        // Con el reloj adelantado, Livewire borraría sus archivos temporales recién creados por "antiguos".
        config(['livewire.temporary_file_upload.cleanup' => false]);
        $this->configurarEvento(['validar_periodo_inscripcion' => false, 'soporte_max_mb' => 1]);
    }

    /** Archivo real en disco (los archivos falsos con contenido pueden llegar vacíos a la carga temporal de Livewire). */
    private static function archivo(string $nombre, string $contenido): UploadedFile
    {
        $archivo = UploadedFile::fake()->create($nombre);
        file_put_contents($archivo->getPathname(), $contenido);

        return $archivo;
    }

    private function evento(): array
    {
        return app(ConsultarDatosEvento::class)->ejecutar();
    }

    private function inscripcion(string $modulo = 'PSE')
    {
        return Livewire::test(Inscripcion::class, ['evento' => $this->evento()])->call('elegirModulo', $modulo);
    }

    public function test_la_portada_muestra_los_modulos_las_tarifas_y_la_csp_con_nonce(): void
    {
        $respuesta = $this->get('/');
        $respuesta->assertOk()
            ->assertSee('Inscripción y pago por PSE')
            ->assertSee('Inscripción y pago en agencia')
            ->assertSee('Ver tarifas y cupos por evento')
            ->assertSee('Evento fin de año Coopetrol');
        $this->assertMatchesRegularExpression("/script-src 'self' 'nonce-[^']+'/", $respuesta->headers->get('Content-Security-Policy'));
        Livewire::test(TablaTarifas::class)->assertSee('BOGOTA')->assertSee('$ 43.500');
    }

    public function test_los_enlaces_directos_abren_el_modulo_o_la_consulta(): void
    {
        Livewire::test(Portal::class)
            ->dispatch('abrir-enlace', enlace: '#agencia')->assertSet('vista', 'inscribir')->assertSet('modulo', 'AGENCIA')
            ->dispatch('abrir-enlace', enlace: '#consulta')->assertSet('vista', 'consulta');
    }

    public function test_exige_la_autorizacion_de_datos_y_valida_al_asociado(): void
    {
        $this->inscripcion()
            ->set('documento', '1001')->set('fechaExpedicion', self::expedicion('1001'))
            ->call('validar')->assertSee('Debe aceptar la autorización')
            ->set('aceptaDatos', true)->set('fechaExpedicion', '1990-01-01')
            ->call('validar')->assertSee('no coinciden')->assertSet('identidad', null)
            ->set('fechaExpedicion', self::expedicion('1001'))
            ->call('validar')->assertSee('Asociado validado correctamente.')->assertSet('agenciaEvento', 'BOGOTA');
    }

    public function test_modulo_pse_simula_confirma_y_preinscribe_limpiando_el_formulario(): void
    {
        $componente = $this->inscripcion()
            ->set('documento', '2001')->set('fechaExpedicion', self::expedicion('2001'))->set('aceptaDatos', true)
            ->call('validar')
            ->set('modalidad', 'ACOMPAÑADO')
            ->assertCount('acompanantes', 1)
            ->set('acompanantes.0.documento', '2002')->set('acompanantes.0.nombre', 'Ana')
            ->call('simular')->assertSee('nombres y apellidos completos')
            ->set('acompanantes.0.nombre', 'Persona Prueba')
            ->call('agregarAcompanante')
            ->set('acompanantes.1.documento', '90777')->set('acompanantes.1.nombre', 'Invitado Prueba')
            ->call('simular')
            ->assertSee('Resumen de su simulación')->assertSee('$ 176.000')
            ->call('preinscribir')
            ->assertSet('confirmacion.titulo', 'Confirmar preinscripción')
            ->call('confirmarAccion');

        $referencia = DB::table('inscripciones')->value('referencia');
        $componente->assertSee($referencia)->assertSee('¡Preinscripción registrada!')
            ->assertSet('documento', '')->assertSet('identidad', null)->assertSet('acompanantes', [])
            ->assertDispatched('recordar-documento', documento: '2001')
            ->call('verInscripcion')->assertDispatched('ver-inscripcion');
        $this->assertSame('PREINSCRITO', DB::table('inscripciones')->value('estado'));
    }

    public function test_una_segunda_preinscripcion_ofrece_ir_a_la_existente(): void
    {
        $preinscribir = fn () => $this->inscripcion()
            ->set('documento', '1001')->set('fechaExpedicion', self::expedicion('1001'))->set('aceptaDatos', true)
            ->call('validar')->call('simular')->call('preinscribir')->call('confirmarAccion');
        $preinscribir();
        $preinscribir()->assertSee('Ya tiene una preinscripción activa')->assertSee('Ver mi inscripción y pagar')
            ->assertSet('tieneInscripcionExistente', true);
    }

    public function test_modulo_agencia_inscribe_y_registra_el_pago_en_un_solo_paso(): void
    {
        $this->inscripcion('AGENCIA')
            ->set('documento', '1001')->set('fechaExpedicion', self::expedicion('1001'))->set('aceptaDatos', true)
            ->call('validar')->call('simular')
            ->assertSee('Registre el pago realizado en la agencia')
            ->assertSet('pago.valorPagado', '43500')
            ->call('registrarPagoAgencia')->assertSee('Complete los campos obligatorios')
            ->set('pago.agenciaPago', 'BOGOTA')->set('pago.recibo', 'rc-100')->set('pago.fechaPago', '2026-10-05')
            ->set('pago.campos.correo', 'ana@correo.com')
            ->call('registrarPagoAgencia')->assertSet('confirmacion.titulo', 'Confirmar inscripción y pago')
            ->call('confirmarAccion')
            ->assertSee('¡Inscripción y pago registrados!');

        $soporte = DB::table('soportes')->first();
        $this->assertSame(['AGENCIA', 'BOGOTA', 'RC-100'], [$soporte->medio_pago, $soporte->agencia_pago, $soporte->recibo]);
        $this->assertSame('EN_REVISION', DB::table('inscripciones')->value('estado'));
    }

    public function test_consulta_modifica_registra_el_pago_pse_y_no_permite_cancelar_en_revision(): void
    {
        $this->inscripcion()
            ->set('documento', '1001')->set('fechaExpedicion', self::expedicion('1001'))->set('aceptaDatos', true)
            ->call('validar')->call('simular')->call('preinscribir')->call('confirmarAccion');

        $consulta = Livewire::test(Consulta::class, ['evento' => $this->evento()])
            ->call('consultar')->assertSee('Ingrese su número de documento')
            ->dispatch('consultar-inscripcion', credenciales: self::credenciales('1001'))
            ->assertSee('Preinscrito · pendiente de pago')->assertSee('Modificar inscripción')
            ->call('modificar')->assertSet('editando', true)
            ->call('agregarAcompananteModificar')
            ->set('acompanantesModificar.0.documento', '90555')->set('acompanantesModificar.0.nombre', 'Invitado Prueba')
            ->call('guardarModificacion')
            ->assertSee('Nuevo total: $ 188.500');

        $consulta->set('comprobante', self::archivo('malo.exe', 'MZ ejecutable'))
            ->assertSee('El comprobante debe ser un archivo PDF, JPG o PNG.')->assertSet('comprobante', null)
            ->set('pago.cus', '123456789')->set('pago.fechaPago', '2026-10-05')->set('pago.campos.correo', 'ana@correo.com')
            ->set('comprobante', self::archivo('pago.pdf', self::PDF))
            ->call('enviarSoporte')->assertSee('Debe aceptar la autorización')
            ->set('pago.aceptaDatos', true)
            ->call('enviarSoporte')
            ->assertSee('Soporte enviado correctamente.')->assertSee('Soporte en revisión')
            ->assertDontSee('Cancelar preinscripción');

        $this->assertSame(1, $this->contarFilas('soportes_archivos'));
        $this->assertSame('EN_REVISION', DB::table('inscripciones')->value('estado'));
    }

    public function test_cancelar_pide_confirmacion_y_libera_la_inscripcion(): void
    {
        $this->inscripcion()
            ->set('documento', '1002')->set('fechaExpedicion', self::expedicion('1002'))->set('aceptaDatos', true)
            ->call('validar')->call('simular')->call('preinscribir')->call('confirmarAccion');

        Livewire::test(Consulta::class, ['evento' => $this->evento()])
            ->set('documento', '1002')->set('fechaExpedicion', self::expedicion('1002'))->call('consultar')
            ->call('cancelar')->assertSet('confirmacion.tono', 'peligro')
            ->call('confirmarAccion')
            ->assertSee('Usted canceló esta preinscripción');
        $this->assertSame('CANCELADO', DB::table('inscripciones')->value('estado'));
    }
}
