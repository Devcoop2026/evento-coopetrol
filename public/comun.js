// Utilidades compartidas por la página del asociado (app.js) y el panel (admin.js).

export const $ = (sel) => document.querySelector(sel);

export const pesos = new Intl.NumberFormat('es-CO', { style: 'currency', currency: 'COP', maximumFractionDigits: 0 });
export const fechaCorta = (iso) => (iso ? iso.slice(0, 10).split('-').reverse().join('/') : '—');
export const fechaHora = (iso) => (iso
  ? new Date(iso).toLocaleString('es-CO', { timeZone: 'America/Bogota', dateStyle: 'short', timeStyle: 'short' }) : '—');

export const ETIQUETA_TIPO = { TITULAR: 'Titular', ASOCIADO: 'Asociado', COOPETROLITO: 'Coopetrolito', INVITADO: 'No asociado' };

// Crea un elemento: el('span', { className: 'x', textContent: 'y' }, ...hijos).
export const el = (tag, props = {}, ...hijos) => {
  const nodo = Object.assign(document.createElement(tag), props);
  nodo.append(...hijos.filter((h) => h != null));
  return nodo;
};

export function mensaje(elemento, texto, tipo) {
  elemento.textContent = texto;
  elemento.className = `mensaje${tipo ? ` mensaje--${tipo}` : ''}`;
}

// Llama a la API con JSON. El error lleva `estado` (código HTTP) y `datos` (cuerpo de la respuesta).
export async function solicitar(url, cuerpo, metodo = cuerpo === undefined ? 'GET' : 'POST') {
  const opciones = { method: metodo };
  if (cuerpo !== undefined) Object.assign(opciones, { headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(cuerpo) });
  let res;
  try {
    res = await fetch(url, opciones);
  } catch {
    throw new Error('Sin conexión con el servidor. Verifique su conexión a internet.');
  }
  const datos = await res.json().catch(() => ({}));
  if (!res.ok) {
    throw Object.assign(new Error(datos.error || 'No fue posible procesar la solicitud.'), { estado: res.status, datos });
  }
  return datos;
}

// Contenido de un archivo en base64 (sin el prefijo data:...).
export const leerBase64 = (archivo) => new Promise((resolve, reject) => {
  const lector = new FileReader();
  lector.onload = () => resolve(String(lector.result).split(',')[1] || '');
  lector.onerror = () => reject(new Error('No se pudo leer el archivo.'));
  lector.readAsDataURL(archivo);
});
