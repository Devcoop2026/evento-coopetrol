<?php

namespace App\Livewire\Concerns;

use App\Aplicacion\Configuracion\ConfiguracionEvento;
use App\Livewire\Formularios\FormularioPago;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

/**
 * Formulario de pago con carga del comprobante. El archivo se revisa apenas se elige (tipo real por su firma y
 * tamaño): si no sirve, se descarta y se explica por qué. Requiere ConMensajes.
 */
trait ConFormularioPago
{
    use WithFileUploads;

    public FormularioPago $pago;

    /** Comprobante elegido (archivo temporal de Livewire). */
    public $comprobante = null;

    public function updatedComprobante(): void
    {
        $this->mensaje('archivo');
        $this->invalidos = array_values(array_diff($this->invalidos, ['archivo']));
        if (! $this->comprobante) {
            return;
        }
        $maxMb = app(ConfiguracionEvento::class)->soporteMaxMb();
        if (! FormularioPago::tipoArchivo($this->comprobante)) {
            $this->quitarArchivo();
            $this->mensaje('archivo', 'El comprobante debe ser un archivo PDF, JPG o PNG.', 'error');
        } elseif ($this->comprobante->getSize() > $maxMb * 1024 * 1024) {
            $peso = self::peso($this->comprobante->getSize());
            $this->quitarArchivo();
            $this->mensaje('archivo', "El archivo pesa {$peso}; el máximo es {$maxMb} MB.", 'error');
        }
    }

    public function quitarArchivo(): void
    {
        if ($this->comprobante instanceof TemporaryUploadedFile) {
            $this->comprobante->delete();
        }
        $this->comprobante = null;
    }

    /** Al cambiar una respuesta, se ocultan (y borran) los campos condicionales que dejan de aplicar. */
    public function updatedPagoCampos(): void
    {
        $this->pago->limpiarOcultos(app(ConfiguracionEvento::class)->camposSoporte);
    }

    public static function peso(int $bytes): string
    {
        return $bytes < 1024 * 1024 ? max(1, (int) round($bytes / 1024)).' KB' : number_format($bytes / 1024 / 1024, 1, ',', '.').' MB';
    }
}
