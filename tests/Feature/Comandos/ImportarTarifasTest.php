<?php

namespace Tests\Feature\Comandos;

use App\Infraestructura\Configuracion\SincronizadorTarifas;
use App\Models\AgenciaEvento;
use App\Models\Evento;
use App\Models\Tarifa;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** evento:tarifas — genera data/tarifas.json desde docs/Evento.xlsx (como el antiguo script de Python) y lo carga. */
class ImportarTarifasTest extends TestCase
{
    use ConCarpetaTemporal, RefreshDatabase;

    private string $datos;

    protected function setUp(): void
    {
        parent::setUp();
        // Nunca se escribe en data/ del proyecto: los JSON van a una carpeta temporal.
        $this->datos = $this->carpetaTemporal('datos');
        copy(base_path('data/agencias_evento.json'), "{$this->datos}/agencias_evento.json");
        config(['evento.datos' => $this->datos]);
        $this->app->forgetInstance(SincronizadorTarifas::class);
    }

    public function test_reproduce_data_tarifas_json_desde_el_excel_y_lo_carga(): void
    {
        $this->artisan('evento:tarifas', ['archivo' => base_path('docs/Evento.xlsx')])
            ->expectsOutputToContain('19 agencias exportadas')
            ->expectsOutput('Tarifas cargadas en la base de datos.')
            ->assertSuccessful();

        $generado = file_get_contents("{$this->datos}/tarifas.json");
        $actual = file_get_contents(base_path('data/tarifas.json'));
        $this->assertSame(json_decode($actual, true), json_decode($generado, true));
        // Mismo formato: sangría de 2 espacios y caracteres sin escapar.
        $this->assertSame(rtrim(str_replace("\r\n", "\n", $actual), "\n"), rtrim($generado, "\n"));

        $this->assertSame(19, Tarifa::query()->count());
        $this->assertSame('EVENTO FIN DE AÑO COOPETROL', Evento::query()->findOrFail(1)->nombre);
        $this->assertSame('CARTAGENA Y MAMONAL', AgenciaEvento::query()->findOrFail('PTO. MAMONAL')->evento);
    }

    public function test_solo_recargar_no_lee_el_excel(): void
    {
        copy(base_path('data/tarifas.json'), "{$this->datos}/tarifas.json");

        $this->artisan('evento:tarifas', ['archivo' => 'no-existe.xlsx', '--solo-recargar' => true])
            ->doesntExpectOutputToContain('agencias exportadas')
            ->assertSuccessful();

        $this->assertSame(110000, Tarifa::query()->findOrFail('ORITO')->valor_invitado);
    }

    public function test_falla_si_no_encuentra_el_excel(): void
    {
        $this->artisan('evento:tarifas', ['archivo' => 'no-existe.xlsx'])
            ->expectsOutput('No se encontró el archivo no-existe.xlsx.')
            ->assertFailed();
        $this->assertFileDoesNotExist("{$this->datos}/tarifas.json");
    }
}
