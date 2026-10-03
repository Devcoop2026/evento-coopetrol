// Exportación de inscripciones a CSV (separador ";" y BOM para que Excel lo abra con tildes).
const { MEDIOS_PAGO } = require('../dominio/mediosPago');

// Las celdas que empiezan por =, +, -, @ se prefijan con ' para evitar inyección de fórmulas al abrir en Excel.
function celda(v) {
  let t = v == null ? '' : String(v);
  if (/^[=+\-@\t\r]/.test(t)) t = `'${t}`;
  return /[;"\n\r]/.test(t) ? `"${t.replace(/"/g, '""')}"` : t;
}

function crearExportacion(repositorio, { camposSoporte = [] } = {}) {
  function inscripcionesCsv() {
    const encabezado = ['Referencia', 'Estado', 'Agencia del evento', 'Agencia del asociado', 'Documento titular', 'Titular', 'Personas',
      'Acompañantes', 'Total', 'Medio de pago', 'CUS', 'Banco', 'Agencia de pago', 'Recibo de caja', 'Comprobante', 'Fecha pago',
      'Valor pagado', 'Alerta', ...camposSoporte.map((c) => c.etiqueta),
      'Motivo', 'Revisado por', 'Revisado en', 'Autorización datos (versión)', 'Autorización datos (fecha)', 'Autoriza uso de imagen', 'Creada en'];
    const filas = repositorio.todas().map((i) => {
      const personas = repositorio.personas(i.id);
      const s = repositorio.ultimoSoporte(i.id);
      const campos = s ? JSON.parse(s.campos) : {};
      return [i.referencia, i.estado, i.agencia, i.agencia_asociado, i.documento_titular, i.nombre_titular, personas.length,
        personas.filter((p) => p.tipo !== 'TITULAR').map((p) => `${p.nombre} (${p.documento}, ${p.tipo})`).join(' | '),
        i.total, s && (MEDIOS_PAGO[s.medio_pago]?.etiqueta ?? s.medio_pago), s?.cus || null, s?.banco || null,
        s?.agencia_pago, s?.recibo, s && (s.archivo ? 'Sí' : 'No'), s?.fecha_pago, s?.valor_pagado, s?.alerta,
        ...camposSoporte.map((c) => campos[c.id]),
        i.motivo, i.revisado_por, i.revisado_en, i.autorizacion_version, i.autorizacion_en, i.autorizacion_imagen ? 'Sí' : 'No', i.creada_en];
    });
    return `﻿${[encabezado, ...filas].map((f) => f.map(celda).join(';')).join('\r\n')}\r\n`;
  }

  return { inscripcionesCsv };
}

module.exports = { crearExportacion, celda };
