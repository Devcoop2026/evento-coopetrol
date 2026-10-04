<?php

namespace App\Livewire\Formularios;

use App\Aplicacion\Inscripciones\ArchivoRecibido;
use App\Dominio\Inscripcion\CamposAdicionales;
use App\Dominio\Inscripcion\Pagos\TipoComprobante;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\Form;

/**
 * Formulario de pago, compartido por el módulo "pago en agencia" (crea la inscripción con el pago) y por
 * "Consulte su inscripción" (registra el pago de una inscripción existente, por PSE o en agencia).
 * Las reglas de negocio las valida el caso de uso; aquí solo se arman los datos y se revisan los obligatorios.
 */
class FormularioPago extends Form
{
    public string $medioPago = 'PSE';

    public string $cus = '';

    public string $agenciaPago = '';

    public string $recibo = '';

    public string $fechaPago = '';

    public string $valorPagado = '';

    /** @var array<string, string> campos adicionales de data/formulario_soporte.json */
    public array $campos = [];

    public bool $aceptaDatos = false;

    public function reiniciar(string $medio, ?int $total = null): void
    {
        $this->reset();
        $this->medioPago = $medio;
        $this->valorPagado = $total !== null ? (string) $total : '';
    }

    /** Campos adicionales que aplican según las respuestas actuales (los condicionales con `mostrarSi`). */
    public function camposVisibles(array $definicion): array
    {
        return array_values(array_filter($definicion, fn (array $c) => CamposAdicionales::aplica($c, $this->campos)));
    }

    /** Al ocultarse un campo condicional se borra su valor. */
    public function limpiarOcultos(array $definicion): void
    {
        foreach ($definicion as $campo) {
            if (! CamposAdicionales::aplica($campo, $this->campos)) {
                unset($this->campos[$campo['id']]);
            }
        }
    }

    /** @return list<string> obligatorios vacíos ('cus', 'agenciaPago', 'fechaPago', 'valorPagado', 'archivo', 'campo.ID') */
    public function obligatoriosVacios(array $definicion, bool $hayComprobante): array
    {
        $vacios = [];
        $obligatorios = $this->medioPago === 'PSE' ? ['cus', 'fechaPago', 'valorPagado'] : ['agenciaPago', 'fechaPago', 'valorPagado'];
        foreach ($obligatorios as $campo) {
            if (trim($this->{$campo}) === '') {
                $vacios[] = $campo;
            }
        }
        if ($this->medioPago === 'PSE' && ! $hayComprobante) {
            $vacios[] = 'archivo';
        }
        foreach ($this->camposVisibles($definicion) as $campo) {
            if (! empty($campo['requerido']) && trim((string) ($this->campos[$campo['id']] ?? '')) === '') {
                $vacios[] = 'campo.'.$campo['id'];
            }
        }

        return $vacios;
    }

    /** Datos para los casos de uso (RegistrarPago / InscribirConPago). Solo se envían los campos visibles. */
    public function datos(array $definicion, ?TemporaryUploadedFile $comprobante): array
    {
        $campos = [];
        foreach ($this->camposVisibles($definicion) as $campo) {
            $campos[$campo['id']] = (string) ($this->campos[$campo['id']] ?? '');
        }

        return [
            'medioPago' => $this->medioPago,
            ...($this->medioPago === 'PSE' ? ['cus' => $this->cus] : ['agenciaPago' => $this->agenciaPago, 'recibo' => $this->recibo]),
            'fechaPago' => $this->fechaPago,
            'valorPagado' => $this->valorPagado,
            'campos' => $campos,
            'archivo' => $comprobante
                ? new ArchivoRecibido($comprobante->getClientOriginalName(), (string) file_get_contents($comprobante->getRealPath()))
                : null,
        ];
    }

    /** Tipo real del archivo elegido (por su firma) o null si no es PDF, JPG ni PNG. */
    public static function tipoArchivo(mixed $archivo): ?TipoComprobante
    {
        if (! $archivo instanceof TemporaryUploadedFile) {
            return null;
        }
        $inicio = (string) file_get_contents($archivo->getRealPath(), false, null, 0, 8);

        return TipoComprobante::porFirma($inicio);
    }
}
