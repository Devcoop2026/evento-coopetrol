<?php

use App\Http\Controllers\Panel\ControladorDescargas;
use App\Livewire\Panel\Ingreso;
use App\Livewire\Panel\Panel;
use App\Livewire\Portal\Portal;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;

// Acciones de los componentes Livewire: límite general por IP (600 solicitudes cada 5 minutos).
Livewire::setUpdateRoute(fn ($handle, $path) => Route::post($path, $handle)->middleware(['web', 'throttle:publico']));

// ---- Portal del asociado: módulos de inscripción y pago (#pse, #agencia) y consulta (#consulta) ----
Route::get('/', Portal::class)->name('inicio');
Route::redirect('/index.html', '/');

// ---- Panel de administración (restringible por red con PANEL_REDES) ----
Route::redirect('/admin.html', '/admin');
Route::middleware('red.panel')->prefix('admin')->group(function () {
    Route::get('/ingreso', Ingreso::class)->middleware('guest')->name('panel.ingreso');

    Route::middleware('auth')->group(function () {
        Route::get('/', Panel::class)->name('panel');
        Route::get('/soportes/{soporte}', [ControladorDescargas::class, 'comprobante'])->whereNumber('soporte')->name('panel.comprobante');
        Route::get('/exportar.xlsx', [ControladorDescargas::class, 'exportar'])->name('panel.exportar');
        Route::get('/bases/{tipo}/plantilla.xlsx', [ControladorDescargas::class, 'plantilla'])
            ->whereIn('tipo', ['asociados', 'coopetrolitos'])->middleware('can:administrar')->name('panel.plantilla');
    });
});
