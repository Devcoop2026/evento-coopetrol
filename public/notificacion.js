// Notificaciones emergentes (toasts) con la identidad de Coopetrol, para confirmar el resultado de una acción.
//   import { notificar } from './notificacion.js';
//   notificar({ titulo: 'Pago aprobado', mensaje: 'EVT26-000001 quedó confirmada.', tono: 'exito' });
// tono: 'exito' (verde), 'aviso' (amarillo), 'error' (rojo) o 'info' (gris). Se cierran solas tras `duracion` ms
// (se pausa al pasar el puntero o al enfocarlas) o con el botón ×. Se anuncian a lectores de pantalla.
const ICONOS = { exito: '✓', aviso: '!', error: '×', info: 'i' };
const MAXIMO = 4;
let contenedor;

function obtenerContenedor() {
  if (contenedor) return contenedor;
  contenedor = Object.assign(document.createElement('div'), { className: 'notificaciones' });
  contenedor.setAttribute('aria-live', 'polite');
  contenedor.setAttribute('aria-relevant', 'additions');
  // En la capa superior (popover) se ve incluso sobre un diálogo modal abierto.
  if ('popover' in HTMLElement.prototype) contenedor.popover = 'manual';
  document.body.append(contenedor);
  return contenedor;
}

// Vuelve a mostrar el contenedor al final de la capa superior (por encima de un modal abierto después).
function traerAlFrente(c) {
  if (!c.popover) return;
  try {
    if (c.matches(':popover-open')) c.hidePopover();
    c.showPopover();
  } catch {}
}

function cerrar(nota) {
  if (nota.dataset.cerrando) return;
  nota.dataset.cerrando = '1';
  nota.classList.add('notificacion--saliendo');
  const quitar = () => {
    nota.remove();
    if (contenedor && !contenedor.children.length && contenedor.popover) try { contenedor.hidePopover(); } catch {}
  };
  nota.addEventListener('animationend', quitar, { once: true });
  setTimeout(quitar, 400); // por si no hay animación (movimiento reducido)
}

export function notificar({ titulo, mensaje = '', tono = 'exito', duracion = 5000 }) {
  const c = obtenerContenedor();
  const nota = document.createElement('div');
  nota.className = `notificacion notificacion--${ICONOS[tono] ? tono : 'info'}`;
  nota.setAttribute('role', tono === 'error' ? 'alert' : 'status');

  const icono = Object.assign(document.createElement('span'), { className: 'notificacion__icono', textContent: ICONOS[tono] || ICONOS.info });
  icono.setAttribute('aria-hidden', 'true');
  const texto = Object.assign(document.createElement('div'), { className: 'notificacion__texto' });
  texto.append(Object.assign(document.createElement('strong'), { className: 'notificacion__titulo', textContent: titulo }));
  if (mensaje) texto.append(Object.assign(document.createElement('p'), { className: 'notificacion__mensaje', textContent: mensaje }));
  const boton = Object.assign(document.createElement('button'), { type: 'button', className: 'notificacion__cerrar', textContent: '×' });
  boton.setAttribute('aria-label', 'Cerrar notificación');
  boton.addEventListener('click', () => cerrar(nota));
  const barra = Object.assign(document.createElement('span'), { className: 'notificacion__barra' });
  barra.style.animationDuration = `${duracion}ms`; // CSSOM: permitido por la CSP (no es un atributo style)
  barra.addEventListener('animationend', () => cerrar(nota));
  nota.append(icono, texto, boton, barra);

  // Pausa mientras el usuario la lee.
  for (const evento of ['mouseenter', 'focusin']) nota.addEventListener(evento, () => nota.classList.add('notificacion--pausada'));
  for (const evento of ['mouseleave', 'focusout']) nota.addEventListener(evento, () => nota.classList.remove('notificacion--pausada'));

  c.append(nota);
  while (c.children.length > MAXIMO) c.firstElementChild.remove();
  traerAlFrente(c);
  return { cerrar: () => cerrar(nota) };
}
