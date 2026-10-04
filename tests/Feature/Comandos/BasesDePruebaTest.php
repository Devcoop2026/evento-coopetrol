<?php

namespace Tests\Feature\Comandos;

use App\Models\Asociado;
use App\Models\CargaBase;
use App\Models\Coopetrolito;
use Database\Seeders\TarifasSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/** evento:generar-datos-prueba y evento:cargar-base. */
class BasesDePruebaTest extends TestCase
{
    use ConCarpetaTemporal, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(TarifasSeeder::class);
    }

    private function generar(): string
    {
        $carpeta = $this->carpetaTemporal('prueba');
        $this->artisan('evento:generar-datos-prueba', ['carpeta' => $carpeta])->assertSuccessful();

        return $carpeta;
    }

    public function test_genera_los_csv_con_formato_de_excel_y_fechas_relativas_a_hoy(): void
    {
        $this->travelTo(Carbon::parse('2026-10-31 12:00:00', 'America/Bogota'));
        $carpeta = $this->generar();

        $asociados = file_get_contents("{$carpeta}/asociados_prueba.csv");
        $this->assertStringStartsWith("\u{FEFF}Cedula;Nombre;Agencia;Asociado;actualizacion_datos;fecha_expedicion;fecha_nacimiento\r\n", $asociados);
        $lineas = explode("\r\n", rtrim($asociados, "\r\n"));
        $this->assertCount(21, $lineas);
        // Día 28 como máximo, menos los meses de cada asociado.
        $this->assertSame('77000001;Andrea Paola Rincón Mejía;BOGOTA;SI;28/08/2026;14/03/2008;12/04/1980', $lineas[1]);
        $this->assertSame('77000019;Gloria Inés Restrepo Sáenz;BOGOTA;SI;28/07/2025;04/02/2000;13/10/1965', $lineas[19]);
        $this->assertSame('77000020;Wilson Enrique Mejía Barón;CALI;SI;;13/09/2011;20/08/1981', $lineas[20]);

        $coopetrolitos = file_get_contents("{$carpeta}/coopetrolitos_prueba.csv");
        $this->assertStringStartsWith("\u{FEFF}Documento;Nombre;Cedula asociado;fecha_nacimiento\r\n1100770001;Sofía Rincón Peña;77000001;14/02/2016\r\n", $coopetrolitos);
        $this->assertCount(21, explode("\r\n", rtrim($coopetrolitos, "\r\n")));
    }

    public function test_la_vista_previa_no_guarda_y_confirmar_reemplaza_la_base(): void
    {
        $carpeta = $this->generar();

        $this->artisan('evento:cargar-base', ['tipo' => 'asociados', 'archivo' => "{$carpeta}/asociados_prueba.csv"])
            ->expectsOutput('20 registros válidos (19 activos), 0 filas omitidas.')
            ->expectsOutput('Vista previa: no se guardó nada. Agregue --confirmar para reemplazar la base.')
            ->assertSuccessful();
        $this->assertSame(0, Asociado::query()->count());

        $this->artisan('evento:cargar-base', ['tipo' => 'asociados', 'archivo' => "{$carpeta}/asociados_prueba.csv", '--confirmar' => true])
            ->expectsOutput('Base de asociados reemplazada.')
            ->assertSuccessful();
        $this->artisan('evento:cargar-base', ['tipo' => 'coopetrolitos', 'archivo' => "{$carpeta}/coopetrolitos_prueba.csv", '--confirmar' => true])
            ->expectsOutput('20 registros válidos, 0 filas omitidas.')
            ->expectsOutput('Base de coopetrolitos reemplazada.')
            ->assertSuccessful();

        $this->assertSame(20, Asociado::query()->count());
        $this->assertSame(20, Coopetrolito::query()->count());
        $this->assertSame('INACTIVO', Asociado::query()->findOrFail('77000018')->estado);
        $carga = CargaBase::query()->where('tipo', 'asociados')->firstOrFail();
        $this->assertSame('asociados_prueba.csv', $carga->archivo);
        $this->assertStringStartsWith('consola:', $carga->usuario);
    }

    public function test_informa_las_filas_omitidas_y_los_errores_de_uso(): void
    {
        $archivo = $this->carpetaTemporal().'/con_errores.csv';
        file_put_contents($archivo, "Cedula;Nombre;Agencia;Asociado;actualizacion_datos;fecha_expedicion\n"
            ."123;Ana Torres;BOGOTA;SI;01/09/2026;14/03/2008\nABC;Pedro;BOGOTA;SI;01/09/2026;14/03/2008\n");

        $this->artisan('evento:cargar-base', ['tipo' => 'asociados', 'archivo' => $archivo])
            ->expectsOutput('1 registros válidos (1 activos), 1 filas omitidas.')
            ->assertSuccessful();

        $this->artisan('evento:cargar-base', ['tipo' => 'otros', 'archivo' => $archivo])
            ->expectsOutput('Tipo inválido. Use asociados o coopetrolitos.')
            ->assertFailed();
        $this->artisan('evento:cargar-base', ['tipo' => 'asociados', 'archivo' => $archivo.'.no'])->assertFailed();
    }
}
