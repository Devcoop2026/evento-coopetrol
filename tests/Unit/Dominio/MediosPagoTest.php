<?php

namespace Tests\Unit\Dominio;

use App\Dominio\Compartido\ErrorValidacion;
use App\Dominio\Inscripcion\Pagos\CatalogoMediosPago;
use App\Dominio\Inscripcion\Pagos\MedioPagoAgencia;
use App\Dominio\Inscripcion\Pagos\MedioPagoPse;
use App\Dominio\Inscripcion\Pagos\VerificacionPagos;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Estrategias de medio de pago (PSE y agencia) y catálogo de medios. */
class MediosPagoTest extends TestCase
{
    /** Verificación falsa: solo existe la agencia BOGOTA; `cusUsado` devuelve la referencia indicada. */
    private static function verificacion(?string $cusUsadoEn = null): VerificacionPagos
    {
        return new class($cusUsadoEn) implements VerificacionPagos
        {
            public function __construct(private readonly ?string $cusUsadoEn) {}

            public function cusUsado(string $cus, int $inscripcionExcluida): ?string
            {
                return $this->cusUsadoEn;
            }

            public function reciboUsado(string $agencia, string $recibo, int $inscripcionExcluida): ?string
            {
                return null;
            }

            public function existeAgencia(string $agencia): bool
            {
                return $agencia === 'BOGOTA';
            }
        };
    }

    public function test_pse_normaliza_el_cus_y_no_guarda_banco_ni_agencia(): void
    {
        $datos = (new MedioPagoPse)->validar(['cus' => '12 345'], self::verificacion(), 0);

        $this->assertSame('12345', $datos->cus);
        $this->assertSame('', $datos->banco);
        $this->assertNull($datos->agenciaPago);
        $this->assertNull($datos->recibo);
    }

    public function test_pse_ignora_el_banco_enviado(): void
    {
        $datos = (new MedioPagoPse)->validar(['cus' => '12345', 'banco' => 'Banco X'], self::verificacion(), 0);

        $this->assertSame('', $datos->banco);
    }

    public function test_pse_rechaza_cus_no_numerico(): void
    {
        $this->expectException(ErrorValidacion::class);
        $this->expectExceptionMessageMatches('/CUS/');

        (new MedioPagoPse)->validar(['cus' => 'abc'], self::verificacion(), 0);
    }

    public function test_pse_rechaza_cus_ya_registrado(): void
    {
        $this->expectException(ErrorValidacion::class);
        $this->expectExceptionMessageMatches('/ya fue registrado/');

        (new MedioPagoPse)->validar(['cus' => '12345'], self::verificacion('EVT26-000001'), 0);
    }

    public function test_agencia_normaliza_el_nombre_y_el_recibo_es_opcional(): void
    {
        $datos = (new MedioPagoAgencia)->validar(['agenciaPago' => 'bogota'], self::verificacion(), 0);

        $this->assertSame('BOGOTA', $datos->agenciaPago);
        $this->assertNull($datos->recibo);
    }

    public function test_agencia_inexistente_se_rechaza(): void
    {
        $this->expectException(ErrorValidacion::class);
        $this->expectExceptionMessageMatches('/agencia/');

        (new MedioPagoAgencia)->validar(['agenciaPago' => 'CALI'], self::verificacion(), 0);
    }

    public function test_solo_pse_exige_comprobante(): void
    {
        $this->assertTrue((new MedioPagoPse)->archivoObligatorio());
        $this->assertFalse((new MedioPagoAgencia)->archivoObligatorio());
    }

    public static function mediosDesconocidos(): array
    {
        return ['efectivo' => ['EFECTIVO'], 'propiedad de objeto' => ['toString'], 'no texto' => [['PSE']]];
    }

    #[DataProvider('mediosDesconocidos')]
    public function test_medio_de_pago_desconocido_se_rechaza(mixed $medio): void
    {
        $this->expectException(ErrorValidacion::class);
        $this->expectExceptionMessageMatches('/cómo realizó el pago/');

        (new CatalogoMediosPago)->medio($medio);
    }
}
