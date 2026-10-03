// Reglas de validación por tipo de campo (las mismas que aplica el navegador en public/validacion.js).
//   numérico:      solo dígitos (documentos, CUS, valor, celular)
//   texto:         letras (con tildes y ñ), espacios, apóstrofo, punto y guion (nombres, banco)
//   alfanumérico:  letras y números en mayúsculas (placa, recibo de caja)
const LETRAS = "A-Za-zÁÉÍÓÚÜÑáéíóúüñ";

const REGLAS = {
  numerico: { patron: /^\d+$/, mensaje: 'solo debe contener números' },
  texto: { patron: new RegExp(`^[${LETRAS}][${LETRAS}' .-]*$`), mensaje: 'solo debe contener letras y espacios' },
  alfanumerico: { patron: /^[A-Z0-9-]+$/, mensaje: 'solo debe contener letras y números' },
};

// Devuelve el mensaje de error o null. `etiqueta` encabeza el mensaje (p. ej. "El documento").
function revisar(tipo, valor, etiqueta) {
  const regla = REGLAS[tipo];
  return regla && !regla.patron.test(String(valor ?? '')) ? `${etiqueta} ${regla.mensaje}.` : null;
}

const esNumerico = (v) => REGLAS.numerico.patron.test(String(v ?? ''));
const esTexto = (v) => REGLAS.texto.patron.test(String(v ?? ''));

module.exports = { REGLAS, revisar, esNumerico, esTexto };
