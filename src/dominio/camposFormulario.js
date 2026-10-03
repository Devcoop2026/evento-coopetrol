// Campos adicionales del formulario de pago (definidos en data/formulario_soporte.json).
// Un campo con `mostrarSi: { campo, valor }` solo aplica (y solo se guarda) cuando la otra respuesta coincide.
const { ErrorValidacion } = require('./errores');
const { esNumerico } = require('./reglas');
const { esFechaValida } = require('./valores');

const campoAplica = (campo, valores) => !campo.mostrarSi
  || String(valores?.[campo.mostrarSi.campo] ?? '').trim() === campo.mostrarSi.valor;

// Normalización previa por tipo. Placas colombianas: carro ABC123, moto ABC12D.
const NORMALIZAR = {
  placa: (v) => v.toUpperCase().replace(/[\s-]/g, ''),
  telefono: (v) => v.replace(/[\s-]/g, ''),
};

// Devuelve true si el valor (no vacío) es válido para el tipo.
const VALIDOS = {
  numero: (v) => /^-?\d+([.,]\d+)?$/.test(v),
  fecha: (v) => esFechaValida(v),
  correo: (v) => /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(v),
  telefono: (v) => esNumerico(v) && v.length >= 7 && v.length <= 15,
  numerico: (v) => esNumerico(v),
  seleccion: (v, campo) => (campo.opciones || []).includes(v),
  placa: (v) => /^[A-Z]{3}\d{2}[A-Z0-9]$/.test(v),
};

// Devuelve solo los campos que aplican, normalizados; lanza el primer error encontrado.
function validarCampos(definicion, valores = {}) {
  const limpio = {};
  for (const campo of definicion) {
    if (!campoAplica(campo, valores)) continue;
    let valor = String(valores?.[campo.id] ?? '').trim();
    valor = NORMALIZAR[campo.tipo]?.(valor) ?? valor;
    if (!valor) {
      if (campo.requerido) throw new ErrorValidacion(`Complete el campo "${campo.etiqueta}".`);
      continue;
    }
    if (valor.length > (campo.max || 200)) throw new ErrorValidacion(`"${campo.etiqueta}" es demasiado largo.`);
    const valido = VALIDOS[campo.tipo];
    if (valido && !valido(valor, campo)) throw new ErrorValidacion(`"${campo.etiqueta}" no tiene un formato válido.`);
    limpio[campo.id] = valor;
  }
  return limpio;
}

module.exports = { validarCampos, campoAplica };
