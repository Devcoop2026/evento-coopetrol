// Comportamiento del navegador que complementa a los componentes Livewire (sin scripts en línea: CSP estricta).
//   - Notificaciones emergentes:     $this->dispatch('notificar', titulo: ..., mensaje: ..., tono: 'exito'|'aviso'|'error'|'info')
//   - Desplazar a una sección:        $this->dispatch('desplazar', selector: '#resultado')
//   - Recordar el documento:          $this->dispatch('recordar-documento', documento: '...') (solo durante la sesión del navegador)
//   - Modales: <dialog data-modal wire:ignore.self> se abre con showModal() al aparecer; Esc pulsa su [data-cerrar-modal].
//   - Zonas de carga (.zona-archivo con un input file): arrastrar y soltar.
//   - [data-copiar="texto"]: copia al portapapeles.
//   - Enlaces directos del portal: #pse, #agencia y #consulta.
import { notificar } from './notificacion.js';
import './validacion.js';

// ---- Modales ----
function abrirModales() {
  for (const dialogo of document.querySelectorAll('dialog[data-modal]')) {
    if (!dialogo.open && dialogo.isConnected) {
      dialogo.showModal();
      (dialogo.querySelector('[data-foco]') || dialogo.querySelector('[data-confirmar-modal]'))?.focus();
    }
  }
}
new MutationObserver(abrirModales).observe(document.documentElement, { childList: true, subtree: true });
document.addEventListener('DOMContentLoaded', abrirModales);

// Esc o clic en el fondo: se cierra con el botón del propio modal, para que el componente actualice su estado.
document.addEventListener('cancel', (e) => {
  if (!e.target.matches?.('dialog[data-modal]')) return;
  e.preventDefault();
  e.target.querySelector('[data-cerrar-modal]')?.click();
}, true);
document.addEventListener('click', (e) => {
  if (e.target.matches?.('dialog[data-modal][data-cerrar-fondo]')) e.target.querySelector('[data-cerrar-modal]')?.click();
});

// ---- Campos ----
// Al corregir un campo marcado como inválido, se quita la marca.
document.addEventListener('input', (e) => { if (e.target.matches?.('.campo')) e.target.removeAttribute('aria-invalid'); });

// Copiar la referencia de pago.
document.addEventListener('click', async (e) => {
  const boton = e.target.closest?.('[data-copiar]');
  if (!boton) return;
  const texto = boton.dataset.copiar;
  const original = boton.textContent;
  try {
    await navigator.clipboard.writeText(texto);
    boton.textContent = '¡Copiada!';
  } catch {
    boton.textContent = texto;
  }
  setTimeout(() => { boton.textContent = original; }, 2000);
});

// ---- Zonas de carga de archivos (comprobantes y bases): arrastrar y soltar ----
document.addEventListener('dragover', (e) => {
  const zona = e.target.closest?.('.zona-archivo');
  e.preventDefault(); // evita que el navegador abra el archivo si se suelta fuera de la zona
  if (zona) zona.classList.add('zona-archivo--arrastre');
});
document.addEventListener('dragleave', (e) => {
  const zona = e.target.closest?.('.zona-archivo');
  if (zona && !zona.contains(e.relatedTarget)) zona.classList.remove('zona-archivo--arrastre');
});
document.addEventListener('drop', (e) => {
  e.preventDefault();
  const zona = e.target.closest?.('.zona-archivo');
  if (!zona) return;
  zona.classList.remove('zona-archivo--arrastre');
  const input = zona.querySelector('input[type=file]');
  if (!input || !e.dataTransfer.files.length) return;
  const seleccion = new DataTransfer();
  seleccion.items.add(e.dataTransfer.files[0]);
  input.files = seleccion.files;
  input.dispatchEvent(new Event('change', { bubbles: true })); // Livewire sube el archivo al detectar el cambio
});

// ---- Eventos de Livewire ----
function conLivewire(Livewire) {
  Livewire.on('notificar', (datos) => notificar(Array.isArray(datos) ? datos[0] : datos));
  Livewire.on('desplazar', ({ selector }) => {
    requestAnimationFrame(() => document.querySelector(selector)?.scrollIntoView({ behavior: 'smooth', block: 'start' }));
  });
  // El documento se recuerda solo durante la sesión del navegador: en equipos compartidos (agencias) no queda guardado.
  Livewire.on('recordar-documento', ({ documento }) => {
    try { sessionStorage.setItem('ultima-inscripcion', JSON.stringify({ documento })); } catch {}
  });
}

function alIniciar() {
  try { localStorage.removeItem('ultima-inscripcion'); } catch {} // lo que guardaban versiones anteriores
  let previo = {};
  try { previo = JSON.parse(sessionStorage.getItem('ultima-inscripcion')) || {}; } catch {}
  for (const input of document.querySelectorAll('input[data-recordar-documento]')) {
    if (previo.documento && !input.value) {
      input.value = previo.documento;
      input.dispatchEvent(new Event('input', { bubbles: true }));
    }
  }
  // Enlaces directos a un módulo (p. ej. para compartir desde una agencia).
  const enlace = location.hash.toLowerCase();
  if (['#pse', '#agencia', '#consulta'].includes(enlace)) window.Livewire.dispatch('abrir-enlace', { enlace });
}

if (window.Livewire) conLivewire(window.Livewire);
else document.addEventListener('livewire:init', () => conLivewire(window.Livewire));
document.addEventListener('livewire:initialized', alIniciar);

// ---- PWA ----
const sw = document.body?.dataset.sw;
if (sw && 'serviceWorker' in navigator) navigator.serviceWorker.register(sw).catch(() => {});
