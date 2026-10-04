<?php

namespace App\Livewire\Panel;

use App\Aplicacion\Panel\AjustarCupos;
use App\Aplicacion\Panel\ConsultarCupos;
use App\Livewire\Concerns\ConMensajes;
use App\Livewire\Concerns\ConPermisos;
use App\Livewire\Formato;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Cupos por evento. El ADMINISTRADOR puede ajustar el cupo vigente (no por debajo de los ocupados); vacío vuelve al
 * valor de tarifas.json. Se actualiza cada 30 segundos.
 */
class Cupos extends Component
{
    use ConMensajes, ConPermisos;

    /** @var array<string, string> cupo digitado por agencia (clave: Cupos::clave(agencia), los nombres tienen puntos y espacios) */
    public array $ajustes = [];

    #[On('cupos-actualizados')]
    public function actualizar(): void {}

    public function ajustar(string $agencia): void
    {
        $valor = trim((string) ($this->ajustes[self::clave($agencia)] ?? ''));
        $cupo = $this->intentar('cupos', function () use ($agencia, $valor) {
            $this->exigirAdministrador('ajustar cupos');

            return app(AjustarCupos::class)->ejecutar($agencia, $valor === '' ? null : $valor, $this->usuario()->usuario);
        });
        if (! $cupo) {
            return;
        }
        unset($this->ajustes[self::clave($agencia)]);
        $this->dispatch('cupos-actualizados');
        $this->dispatch('notificar', titulo: 'Cupo actualizado',
            mensaje: "{$agencia}: ".($valor === '' ? 'vuelve al valor del Excel' : Formato::numero((int) $valor).' cupos').'.', tono: 'exito');
    }

    public static function clave(string $agencia): string
    {
        return 'a'.substr(md5($agencia), 0, 12);
    }

    public function render(ConsultarCupos $consultar): View
    {
        $cupos = $consultar->ejecutar();
        foreach ($cupos as $c) {
            $this->ajustes[self::clave($c['agencia'])] ??= $c['cupos_ajustados'] === null ? '' : (string) $c['cupos_ajustados'];
        }

        return view('livewire.panel.cupos', ['cupos' => $cupos, 'administrador' => $this->esAdministrador()]);
    }
}
