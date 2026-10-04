<?php

namespace App\Livewire\Panel;

use App\Aplicacion\Bases\CargarBase;
use App\Dominio\Seguridad\RegistroSeguridad;
use App\Livewire\Concerns\ConConfirmacion;
use App\Livewire\Concerns\ConMensajes;
use App\Livewire\Concerns\ConPermisos;
use App\Livewire\Formato;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

/**
 * Carga masiva de la base de asociados o de Coopetrolitos (solo ADMINISTRADOR): al elegir el Excel o CSV se valida y se
 * muestra la vista previa (registros válidos, filas omitidas con motivo, advertencias); al confirmar se reemplaza la
 * base completa. Los datos personales no se guardan en archivos: el archivo temporal se borra al terminar.
 */
class Base extends Component
{
    use ConConfirmacion, ConMensajes, ConPermisos, WithFileUploads;

    private const MAX_MB = 20;

    #[Locked]
    public string $tipo = 'asociados';

    /** @var TemporaryUploadedFile|null */
    public $archivo = null;

    #[Locked]
    public ?array $resumen = null;

    public function mount(string $tipo): void
    {
        $this->exigirAdministrador('cargar bases');
        $this->tipo = $tipo === 'coopetrolitos' ? 'coopetrolitos' : 'asociados';
    }

    public function updatedArchivo(): void
    {
        $this->resumen = null;
        $this->mensaje('base');
        if (! $this->archivo instanceof TemporaryUploadedFile) {
            return;
        }
        if ($this->archivo->getSize() > self::MAX_MB * 1024 * 1024) {
            $this->descartar();
            $this->mensaje('base', 'El archivo supera 20 MB.', 'error');

            return;
        }
        $this->resumen = $this->intentar('base', function () {
            $this->exigirAdministrador('cargar bases');

            return app(CargarBase::class)->ejecutar($this->tipo, $this->contenido(), false, $this->archivo->getClientOriginalName());
        });
        if ($this->resumen) {
            $this->mensaje('base', 'Revise la vista previa y confirme para reemplazar la base.');
        } else {
            $this->archivo?->delete();
            $this->archivo = null;
        }
    }

    public function pedirReemplazo(): void
    {
        if (! $this->resumen) {
            return;
        }
        $this->pedirConfirmacion('reemplazar', [
            'titulo' => "¿Reemplazar la base de {$this->etiqueta()}?",
            'mensaje' => 'Los registros actuales se reemplazarán por los '.Formato::numero($this->resumen['registros']).' del archivo. Las inscripciones existentes no se modifican.',
            'detalles' => $this->datosResumen(),
            'aceptar' => 'Sí, reemplazar',
            'cancelar' => 'Volver',
            'tono' => 'aviso',
        ]);
    }

    protected function reemplazar(): void
    {
        $nombre = $this->archivo?->getClientOriginalName();
        $resumen = $this->intentar('base', function () use ($nombre) {
            $this->exigirAdministrador('cargar bases');

            return app(CargarBase::class)->ejecutar($this->tipo, $this->contenido(), true, $nombre, $this->usuario()->usuario);
        });
        if (! $resumen) {
            $this->dispatch('notificar', titulo: 'No se pudo reemplazar la base', mensaje: $this->mensajes['base']['texto'] ?? '', tono: 'error');

            return;
        }
        app(RegistroSeguridad::class)->registrar('carga_base', [
            'tipo' => $this->tipo, 'registros' => $resumen['registros'], 'usuario' => $this->usuario()->usuario, 'ip' => request()->ip(),
        ]);
        $this->descartar();
        $this->dispatch('notificar', titulo: "Base de {$this->etiqueta()} actualizada",
            mensaje: Formato::numero($resumen['registros']).' registros cargados.', tono: 'exito');
    }

    public function descartar(): void
    {
        $this->archivo?->delete();
        $this->archivo = null;
        $this->resumen = null;
        $this->mensaje('base');
    }

    private function contenido(): string
    {
        return $this->archivo instanceof TemporaryUploadedFile ? (string) file_get_contents($this->archivo->getRealPath()) : '';
    }

    private function etiqueta(): string
    {
        return $this->tipo === 'asociados' ? 'asociados' : 'Coopetrolitos';
    }

    /** @return list<array{0: string, 1: string}> */
    private function datosResumen(): array
    {
        return array_values(array_filter([
            ['Archivo', (string) $this->archivo?->getClientOriginalName()],
            ['Registros válidos', Formato::numero($this->resumen['registros'])],
            isset($this->resumen['activos']) ? ['Asociados activos', Formato::numero($this->resumen['activos'])] : null,
            ['Filas omitidas', (string) $this->resumen['omitidas']],
        ]));
    }

    public function render(CargarBase $cargar): View
    {
        return view('livewire.panel.base', [
            'estado' => $cargar->estado()[$this->tipo],
            'etiqueta' => $this->etiqueta(),
            'datos' => $this->resumen ? $this->datosResumen() : [],
        ]);
    }
}
