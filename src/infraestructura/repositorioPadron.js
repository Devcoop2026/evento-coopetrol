// Consultas de lectura sobre las bases cargadas: asociados, Coopetrolitos, el evento, sus tarifas por evento (agencia del
// evento) y qué agencias asisten a cada evento.
function crearRepositorioPadron(db) {
  const q = {
    asociado: db.prepare(`SELECT documento, nombre, agencia, estado, fecha_actualizacion, expedicion_hmac
                          FROM asociados WHERE documento = ?`),
    coopetrolito: db.prepare('SELECT documento, documento_asociado FROM coopetrolitos WHERE documento = ?'),
    tarifa: db.prepare('SELECT agencia, cupos, valor_invitado, valor_asociado FROM tarifas WHERE agencia = ?'),
    tarifas: db.prepare('SELECT agencia, cupos, valor_invitado, valor_asociado FROM tarifas ORDER BY agencia'),
    evento: db.prepare('SELECT nombre, inscripciones, cuenta_contable, concepto FROM evento WHERE id = 1'),
    eventoDe: db.prepare('SELECT evento FROM agencias_evento WHERE agencia = ?'),
    agencias: db.prepare('SELECT agencia, evento FROM agencias_evento ORDER BY agencia'),
  };
  const copia = (fila) => (fila ? { ...fila } : undefined);
  return {
    asociado: (documento) => copia(q.asociado.get(documento)),
    coopetrolito: (documento) => copia(q.coopetrolito.get(documento)),
    tarifa: (agencia) => copia(q.tarifa.get(agencia)),
    // Agencia o punto de atención válido (de la base de asociados o donde se paga), o nombre de un evento.
    existeAgencia: (agencia) => !!(q.eventoDe.get(agencia) || q.tarifa.get(agencia)),
    // Evento al que asiste una agencia (en un evento compartido, el nombre del evento).
    eventoDeAgencia: (agencia) => q.eventoDe.get(agencia)?.evento ?? (q.tarifa.get(agencia) ? agencia : undefined),
    // Agencias y puntos de atención con su evento: [{ agencia, evento }].
    agencias: () => q.agencias.all().map(copia),
    tarifas: () => q.tarifas.all().map(copia),
    evento: () => copia(q.evento.get()),
  };
}

module.exports = { crearRepositorioPadron };
