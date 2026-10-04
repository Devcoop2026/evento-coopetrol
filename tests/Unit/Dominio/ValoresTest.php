<?php

namespace Tests\Unit\Dominio;

use App\Dominio\Compartido\ErrorValidacion;
use App\Dominio\Compartido\Valores;
use App\Dominio\Inscripcion\CamposAdicionales;
use PHPUnit\Framework\TestCase;

/** Valores del dominio (documento, nombre, fechas) y campos adicionales del formulario de pago. */
class ValoresTest extends TestCase
{
    public function test_documento_ignora_puntos_y_guiones(): void
    {
        $this->assertSame('12345678', Valores::validarDocumento('1.234.567-8'));
    }

    public function test_documento_vacio(): void
    {
        try {
            Valores::validarDocumento('');
            $this->fail('Debió rechazar el documento vacío.');
        } catch (ErrorValidacion $error) {
            $this->assertSame('Ingrese el número de documento.', $error->getMessage());
        }
    }

    public function test_documento_corto_con_etiqueta(): void
    {
        try {
            Valores::validarDocumento('12', 'Acompañante 1');
            $this->fail('Debió rechazar el documento corto.');
        } catch (ErrorValidacion $error) {
            $this->assertStringStartsWith('Acompañante 1: el número de documento debe tener entre 4 y 15', $error->getMessage());
        }
    }

    public function test_nombre_completo_normaliza_espacios(): void
    {
        $this->assertSame('Ana Pérez', Valores::validarNombreCompleto('  Ana   Pérez '));
    }

    public function test_nombre_de_una_sola_palabra_se_rechaza(): void
    {
        $this->expectException(ErrorValidacion::class);
        $this->expectExceptionMessageMatches('/nombres y apellidos/');

        Valores::validarNombreCompleto('Ana');
    }

    public function test_fecha_opcional_vacia_es_null(): void
    {
        $this->assertNull(Valores::validarFecha('', 'la fecha', opcional: true));
    }

    public function test_fecha_inexistente(): void
    {
        try {
            Valores::validarFecha('2026-02-30', 'la fecha de pago');
            $this->fail('Debió rechazar el 30 de febrero.');
        } catch (ErrorValidacion $error) {
            $this->assertSame('La fecha de pago no es válida.', $error->getMessage());
        }
    }

    private static function camposVehiculo(): CamposAdicionales
    {
        return new CamposAdicionales([
            ['id' => 'vehiculo', 'etiqueta' => 'Vehículo', 'tipo' => 'seleccion', 'opciones' => ['Sí', 'No'], 'requerido' => true],
            ['id' => 'placa', 'etiqueta' => 'Placa', 'tipo' => 'placa', 'requerido' => true, 'mostrarSi' => ['campo' => 'vehiculo', 'valor' => 'Sí']],
        ]);
    }

    public function test_campo_condicional_que_no_aplica_se_descarta(): void
    {
        $this->assertSame(['vehiculo' => 'No'], self::camposVehiculo()->validar(['vehiculo' => 'No', 'placa' => 'basura']));
    }

    public function test_placa_se_normaliza_cuando_aplica(): void
    {
        $this->assertSame('ABC12D', self::camposVehiculo()->validar(['vehiculo' => 'Sí', 'placa' => 'abc-12d'])['placa']);
    }

    public function test_campo_condicional_obligatorio_cuando_aplica(): void
    {
        $this->expectException(ErrorValidacion::class);
        $this->expectExceptionMessageMatches('/Complete el campo "Placa"/');

        self::camposVehiculo()->validar(['vehiculo' => 'Sí']);
    }
}
