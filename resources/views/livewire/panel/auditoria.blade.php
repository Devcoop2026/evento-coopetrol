@use('App\Livewire\Formato')
@php $acciones = ['CREAR' => 'Creó', 'EDITAR' => 'Editó', 'ELIMINAR' => 'Eliminó']; @endphp
<section class="tarjeta">
  <h2 class="tarjeta__titulo">Registro de cambios</h2>
  <p class="nota">Últimos 100 cambios manuales hechos desde el panel (quién, qué y cuándo).</p>
  <div class="tabla-scroll"><table class="tabla">
    <thead><tr><th>Fecha</th><th>Usuario</th><th>Acción</th><th>Registro</th><th>Detalle</th></tr></thead>
    <tbody>
      @forelse ($filas as $a)
        <tr>
          <td>{{ Formato::fechaHora($a['fecha']) }}</td>
          <td>{{ $a['usuario'] }}</td>
          <td>{{ ($acciones[$a['accion']] ?? $a['accion']).' '.$a['entidad'] }}</td>
          <td>{{ $a['clave'] }}</td>
          <td>{{ $a['detalle'] ?? '' }}</td>
        </tr>
      @empty
        <tr><td colspan="5" class="nota">Sin cambios registrados.</td></tr>
      @endforelse
    </tbody>
  </table></div>
</section>
