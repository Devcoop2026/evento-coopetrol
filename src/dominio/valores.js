// Valores del dominio: normalización y validación de documento, nombre y fechas, compartidas por los casos de uso.
// `etiqueta` antepone el sujeto al mensaje (p. ej. "Acompañante 2" -> "Acompañante 2: ingrese el número de documento.").
const { ErrorValidacion } = require('./errores');
const { esNumerico, esTexto } = require('./reglas');

const error = (etiqueta, texto) => new ErrorValidacion(etiqueta ? `${etiqueta}: ${texto}` : `${texto[0].toUpperCase()}${texto.slice(1)}`);

// Documento: se ignoran espacios, puntos, comas y guiones ("1.234.567-8" -> "12345678").
const normalizarDocumento = (valor) => String(valor ?? '').replace(/[\s.,-]/g, '').toUpperCase();

// Devuelve el documento normalizado (solo dígitos, entre `minimo` y `maximo`) o lanza el error.
function validarDocumento(valor, { etiqueta, minimo = 4, maximo = 15 } = {}) {
  const doc = normalizarDocumento(valor);
  if (!doc) throw error(etiqueta, 'ingrese el número de documento.');
  if (!esNumerico(doc)) throw error(etiqueta, 'el número de documento solo debe contener números.');
  if (doc.length < minimo || doc.length > maximo) {
    throw error(etiqueta, `el número de documento debe tener entre ${minimo} y ${maximo} números.`);
  }
  return doc;
}

// Nombres y apellidos completos: al menos dos palabras de dos letras, solo letras y espacios, máximo 100 caracteres.
function validarNombreCompleto(valor, { etiqueta } = {}) {
  const nombre = String(valor ?? '').trim().replace(/\s+/g, ' ');
  if (nombre && !esTexto(nombre)) throw error(etiqueta, 'el nombre solo debe contener letras y espacios.');
  if (nombre.split(' ').filter((p) => p.length >= 2).length < 2) throw error(etiqueta, 'ingrese nombres y apellidos completos.');
  if (nombre.length > 100) throw error(etiqueta, 'el nombre es demasiado largo.');
  return nombre;
}

// Fechas AAAA-MM-DD reales (rechaza 2026-02-30).
const esFechaValida = (v) => /^\d{4}-\d{2}-\d{2}$/.test(v) && !Number.isNaN(Date.parse(`${v}T00:00:00Z`))
  && new Date(`${v}T00:00:00Z`).toISOString().startsWith(v);

// Devuelve la fecha AAAA-MM-DD, null si es opcional y viene vacía, o lanza el error. `nombre`: "la fecha de expedición".
function validarFecha(valor, nombre, { opcional = false } = {}) {
  const f = String(valor ?? '').trim();
  if (!f) {
    if (opcional) return null;
    throw error(null, `ingrese ${nombre}.`);
  }
  if (!esFechaValida(f)) throw error(null, `${nombre} no es válida.`);
  return f;
}

// Fecha calendario en Colombia (AAAA-MM-DD), independiente de la zona horaria del servidor.
const fechaColombia = (d) => new Intl.DateTimeFormat('en-CA', { timeZone: 'America/Bogota' }).format(d);

// Fecha local AAAA-MM-DD de un Date.
const aISO = (f) => `${f.getFullYear()}-${String(f.getMonth() + 1).padStart(2, '0')}-${String(f.getDate()).padStart(2, '0')}`;

// AAAA-MM-DD -> DD/MM/AAAA (para mensajes).
const formatoFecha = (iso) => iso.split('-').reverse().join('/');

module.exports = {
  normalizarDocumento, validarDocumento, validarNombreCompleto, esFechaValida, validarFecha, fechaColombia, aISO, formatoFecha,
};
