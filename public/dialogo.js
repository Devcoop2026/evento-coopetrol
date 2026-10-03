// Diálogos modales con la identidad de Coopetrol (reemplazan window.confirm / window.alert).
//   import { confirmar, avisar } from './dialogo.js';
//   await confirmar({ titulo, mensaje, detalles: [[etiqueta, valor]], aceptar, cancelar, tono })  -> true | false
//   await avisar({ titulo, mensaje, tono })
// tono: 'normal' (verde), 'peligro' (rojo) o 'aviso' (amarillo).
const ICONOS = { normal: '✓', peligro: '!', aviso: 'i' };
let dialogo;

function crear() {
  dialogo = document.createElement('dialog');
  dialogo.className = 'modal';
  dialogo.setAttribute('aria-labelledby', 'modal-titulo');
  dialogo.setAttribute('aria-describedby', 'modal-mensaje');
  dialogo.innerHTML = `
    <div class="modal__icono" aria-hidden="true"></div>
    <h2 class="modal__titulo" id="modal-titulo"></h2>
    <p class="modal__mensaje" id="modal-mensaje"></p>
    <dl class="modal__detalles"></dl>
    <div class="modal__acciones">
      <button type="button" class="boton boton--linea" data-respuesta="no"></button>
      <button type="button" class="boton boton--primario" data-respuesta="si"></button>
    </div>`;
  document.body.append(dialogo);
}

function abrir({ titulo, mensaje = '', detalles = [], aceptar = 'Aceptar', cancelar = 'Cancelar', tono = 'normal', soloAceptar = false }) {
  if (!dialogo) crear();
  if (dialogo.open) dialogo.close('no');
  dialogo.dataset.tono = tono;
  dialogo.querySelector('.modal__icono').textContent = ICONOS[tono] || ICONOS.normal;
  dialogo.querySelector('.modal__titulo').textContent = titulo;
  dialogo.querySelector('.modal__mensaje').textContent = mensaje;
  dialogo.querySelector('.modal__detalles').replaceChildren(...detalles.flatMap(([etiqueta, valor]) => [
    Object.assign(document.createElement('dt'), { textContent: etiqueta }),
    Object.assign(document.createElement('dd'), { textContent: valor }),
  ]));
  const si = dialogo.querySelector('[data-respuesta="si"]');
  const no = dialogo.querySelector('[data-respuesta="no"]');
  si.textContent = aceptar;
  si.className = `boton ${tono === 'peligro' ? 'boton--peligro-lleno' : 'boton--primario'}`;
  no.textContent = cancelar;
  no.hidden = soloAceptar;

  return new Promise((resolve) => {
    const responder = (e) => {
      const boton = e.target.closest('[data-respuesta]');
      if (boton) dialogo.close(boton.dataset.respuesta);
    };
    dialogo.addEventListener('click', responder);
    dialogo.addEventListener('close', () => {
      dialogo.removeEventListener('click', responder);
      resolve(dialogo.returnValue === 'si');
    }, { once: true });
    dialogo.returnValue = 'no'; // Esc o cierre sin elegir = cancelar
    dialogo.showModal();
    (soloAceptar || tono !== 'peligro' ? si : no).focus();
  });
}

export const confirmar = (opciones) => abrir(opciones);
export const avisar = (opciones) => abrir({ aceptar: 'Entendido', ...opciones, soloAceptar: true });
