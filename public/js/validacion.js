// Validación por tipo de campo mientras se escribe (las mismas reglas que aplica el servidor en app/Dominio/Compartido/Reglas.php).
// Uso: <input data-validar="numerico|texto|alfanumerico|usuario">
//   numerico      solo dígitos (documentos, CUS, valor, celular)
//   texto         letras (con tildes y ñ), espacios, apóstrofo, punto y guion (nombres, banco)
//   alfanumerico  letras y números, en mayúsculas (placa, recibo de caja)
//   usuario       minúsculas, números, punto, guion y guion bajo (usuario del panel)
// Los caracteres no permitidos se descartan al escribir o pegar y se muestra un aviso breve bajo el campo.
const REGLAS = {
  numerico: { quitar: /[^0-9]/g, aviso: 'Solo se permiten números.', modo: 'numeric' },
  texto: { quitar: /[^A-Za-zÁÉÍÓÚÜÑáéíóúüñ' .-]/g, aviso: 'Solo se permiten letras y espacios.' },
  alfanumerico: { quitar: /[^A-Za-z0-9-]/g, aviso: 'Solo se permiten letras y números.', mayusculas: true },
  usuario: { quitar: /[^a-z0-9._-]/g, aviso: 'Solo minúsculas, números, punto, guion y guion bajo.', minusculas: true },
};
const temporizadores = new WeakMap();

// El aviso va debajo del campo. Si el campo tiene texto de ayuda, lo reemplaza mientras se muestra (no altera la rejilla).
// En la fila de acompañantes se ubica al final de la fila (ocupa una línea propia).
function avisar(campo, texto) {
  const contenedor = campo.parentElement;
  const ayuda = contenedor.querySelector(':scope > .campo__ayuda');
  let aviso = contenedor.querySelector(`:scope > .campo__aviso[data-de="${campo.name || campo.id}"]`);
  if (!aviso) {
    aviso = Object.assign(document.createElement('small'), { className: 'campo__aviso', role: 'status' });
    aviso.dataset.de = campo.name || campo.id;
    if (contenedor.classList.contains('acompanante')) contenedor.append(aviso);
    else (ayuda || campo).insertAdjacentElement('afterend', aviso);
  }
  aviso.textContent = texto;
  aviso.hidden = false;
  if (ayuda) ayuda.hidden = true;
  clearTimeout(temporizadores.get(campo));
  temporizadores.set(campo, setTimeout(() => { aviso.hidden = true; if (ayuda) ayuda.hidden = false; }, 2500));
}

function limpiar(campo) {
  const regla = REGLAS[campo.dataset.validar];
  if (!regla) return;
  const original = campo.value;
  let valor = regla.mayusculas ? original.toUpperCase() : regla.minusculas ? original.toLowerCase() : original;
  valor = valor.replace(regla.quitar, '');
  if (campo.dataset.validar === 'texto') valor = valor.replace(/^[' .-]+/, '').replace(/ {2,}/g, ' ');
  if (valor === original) return;
  const pos = Math.max(0, (campo.selectionStart ?? valor.length) - (original.length - valor.length));
  campo.value = valor;
  try { campo.setSelectionRange(pos, pos); } catch {}
  if (valor.length < original.length) avisar(campo, regla.aviso);
}

function preparar(campo) {
  const regla = REGLAS[campo.dataset.validar];
  if (regla?.modo && !campo.inputMode) campo.inputMode = regla.modo;
  if (regla?.mayusculas) campo.classList.add('campo--mayus');
}

document.addEventListener('input', (e) => { if (e.target.matches?.('[data-validar]')) limpiar(e.target); }, true);
// Campos creados dinámicamente (acompañantes, formularios del panel): se preparan al aparecer.
new MutationObserver((cambios) => {
  for (const c of cambios) for (const n of c.addedNodes) {
    if (n.nodeType !== 1) continue;
    if (n.matches('[data-validar]')) preparar(n);
    n.querySelectorAll?.('[data-validar]').forEach(preparar);
  }
}).observe(document.documentElement, { childList: true, subtree: true });
document.querySelectorAll('[data-validar]').forEach(preparar);

export { limpiar as validarCampo };
