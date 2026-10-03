// Persistencia de inscripciones, personas inscritas, soportes de pago y cupos (SQLite).
// Los casos de uso (src/aplicacion/inscripciones.js) no escriben SQL: usan estas funciones.
const crypto = require('node:crypto');
const { ESTADOS_ACTIVOS, ESTADOS_CUPO } = require('../dominio/inscripcion');
const { TIPOS_SIN_CUPO } = require('../dominio/tiposAcompanante');

const lista = (valores) => `(${valores.map((e) => `'${e}'`).join(', ')})`;
const EN_ACTIVOS = lista(ESTADOS_ACTIVOS);
const EN_CUPO = lista(ESTADOS_CUPO);
const OCUPA_CUPO = `p.tipo NOT IN ${lista(TIPOS_SIN_CUPO)}`;

const copia = (fila) => (fila ? { ...fila } : undefined);

function crearRepositorioInscripciones(db) {
  const q = {
    porReferencia: db.prepare('SELECT * FROM inscripciones WHERE referencia = ?'),
    // Inscripción vigente del titular (o, si no hay, la más reciente): un asociado tiene a lo sumo una activa.
    deTitular: db.prepare(`SELECT * FROM inscripciones WHERE documento_titular = ?
                           ORDER BY CASE WHEN estado IN ${EN_ACTIVOS} THEN 0 ELSE 1 END, id DESC LIMIT 1`),
    porId: db.prepare('SELECT * FROM inscripciones WHERE id = ?'),
    todas: db.prepare('SELECT * FROM inscripciones ORDER BY id'),
    personas: db.prepare('SELECT documento, nombre, tipo, valor FROM inscripcion_personas WHERE inscripcion_id = ? ORDER BY rowid'),
    soportes: db.prepare('SELECT * FROM soportes WHERE inscripcion_id = ? ORDER BY id DESC'),
    soporte: db.prepare('SELECT archivo, tipo_archivo, nombre_original FROM soportes WHERE id = ?'),
    personaActiva: db.prepare(`SELECT i.id, i.referencia, i.documento_titular FROM inscripcion_personas p
                               JOIN inscripciones i ON i.id = p.inscripcion_id
                               WHERE p.documento = ? AND i.estado IN ${EN_ACTIVOS} AND i.id <> ?`),
    ocupados: db.prepare(`SELECT COUNT(*) AS n FROM inscripcion_personas p JOIN inscripciones i ON i.id = p.inscripcion_id
                          WHERE i.agencia = ? AND i.estado IN ${EN_CUPO} AND i.id <> ? AND ${OCUPA_CUPO}`),
    cuposAgencia: db.prepare(`SELECT COALESCE(a.cupos, t.cupos) AS cupos FROM tarifas t
                              LEFT JOIN cupos_ajustados a ON a.agencia = t.agencia WHERE t.agencia = ?`),
    cusUsado: db.prepare(`SELECT i.referencia FROM soportes s JOIN inscripciones i ON i.id = s.inscripcion_id
                          WHERE s.medio_pago = 'PSE' AND s.cus = ? AND i.id <> ? AND i.estado IN ${EN_ACTIVOS}`),
    reciboUsado: db.prepare(`SELECT i.referencia FROM soportes s JOIN inscripciones i ON i.id = s.inscripcion_id
                            WHERE s.medio_pago = 'AGENCIA' AND s.agencia_pago = ? AND s.recibo = ? AND i.id <> ? AND i.estado IN ${EN_ACTIVOS}`),
    insertar: db.prepare(`INSERT INTO inscripciones (referencia, documento_titular, nombre_titular, agencia, agencia_asociado, total, estado,
                            creada_en, actualizada_en) VALUES (?, ?, ?, ?, ?, ?, 'PREINSCRITO', ?, ?)`),
    referencia: db.prepare(`UPDATE inscripciones SET referencia = ?, autorizacion_version = ?, autorizacion_en = ?, autorizacion_ip = ?,
                            autorizacion_imagen = ?
                            WHERE id = ?`),
    insertarPersona: db.prepare('INSERT INTO inscripcion_personas (inscripcion_id, documento, nombre, tipo, valor) VALUES (?, ?, ?, ?, ?)'),
    borrarPersonas: db.prepare('DELETE FROM inscripcion_personas WHERE inscripcion_id = ?'),
    liquidacion: db.prepare('UPDATE inscripciones SET total = ?, agencia = ?, actualizada_en = ? WHERE id = ?'),
    estado: db.prepare('UPDATE inscripciones SET estado = ?, motivo = ?, actualizada_en = ? WHERE id = ?'),
    revision: db.prepare('UPDATE inscripciones SET estado = ?, motivo = ?, revisado_por = ?, revisado_en = ?, actualizada_en = ? WHERE id = ?'),
    insertarSoporte: db.prepare(`INSERT INTO soportes (inscripcion_id, medio_pago, cus, banco, agencia_pago, recibo, fecha_pago, valor_pagado,
                                 campos, archivo, tipo_archivo, nombre_original, alerta, autorizacion_version, cargado_en)
                                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)`),
    cupos: db.prepare(`SELECT t.agencia, t.cupos AS cupos_excel, a.cupos AS cupos_ajustados,
                         COALESCE(a.cupos, t.cupos) AS cupos,
                         COUNT(CASE WHEN i.estado IN ${EN_CUPO} AND ${OCUPA_CUPO} THEN 1 END) AS ocupados,
                         COUNT(CASE WHEN i.estado IN ('PREINSCRITO', 'RECHAZADO') AND ${OCUPA_CUPO} THEN 1 END) AS pendientes,
                         COUNT(CASE WHEN i.estado = 'CONFIRMADO' AND ${OCUPA_CUPO} THEN 1 END) AS confirmados,
                         COUNT(CASE WHEN i.estado IN ${EN_CUPO} AND NOT ${OCUPA_CUPO} THEN 1 END) AS invitados
                       FROM tarifas t
                       LEFT JOIN cupos_ajustados a ON a.agencia = t.agencia
                       LEFT JOIN inscripciones i ON i.agencia = t.agencia
                       LEFT JOIN inscripcion_personas p ON p.inscripcion_id = i.id
                       GROUP BY t.agencia ORDER BY t.agencia`),
    quitarAjuste: db.prepare('DELETE FROM cupos_ajustados WHERE agencia = ?'),
    ajustar: db.prepare(`INSERT INTO cupos_ajustados (agencia, cupos, actualizado_por, actualizado_en) VALUES (?, ?, ?, ?)
                         ON CONFLICT(agencia) DO UPDATE SET cupos = excluded.cupos, actualizado_por = excluded.actualizado_por,
                         actualizado_en = excluded.actualizado_en`),
  };

  // Ejecuta `fn` en una transacción exclusiva (cupos y duplicados se validan y escriben sin carreras).
  function transaccion(fn) {
    db.exec('BEGIN IMMEDIATE');
    try {
      const resultado = fn();
      db.exec('COMMIT');
      return resultado;
    } catch (err) {
      db.exec('ROLLBACK');
      throw err;
    }
  }

  function guardarPersonas(id, personas) {
    q.borrarPersonas.run(id);
    for (const p of personas) q.insertarPersona.run(id, p.documento, p.nombre, p.tipo, p.valor);
  }

  // Crea la inscripción y devuelve su id. `referencia(id)` calcula la referencia definitiva a partir del id.
  function crear({ titular, agencia, total, personas, autorizacion, marca }, referencia) {
    const temporal = `TMP-${crypto.randomUUID()}`;
    const id = Number(q.insertar.run(temporal, titular.documento, titular.nombre, agencia, titular.agencia, total, marca, marca).lastInsertRowid);
    q.referencia.run(referencia(id), autorizacion.version, marca, autorizacion.ip, autorizacion.imagen ? 1 : 0, id);
    guardarPersonas(id, personas);
    return id;
  }

  function listar({ estado, agencia, busqueda } = {}) {
    const filtros = [];
    const valores = [];
    if (estado) { filtros.push('i.estado = ?'); valores.push(estado); }
    if (agencia) { filtros.push('i.agencia = ?'); valores.push(agencia); }
    if (busqueda) {
      filtros.push('(i.referencia LIKE ? OR i.documento_titular LIKE ? OR i.nombre_titular LIKE ?)');
      const patron = `%${String(busqueda).trim()}%`;
      valores.push(patron, patron, patron);
    }
    return db.prepare(`SELECT i.id, i.referencia, i.documento_titular, i.nombre_titular, i.agencia, i.total, i.estado,
                         i.creada_en, i.actualizada_en,
                         (SELECT COUNT(*) FROM inscripcion_personas p WHERE p.inscripcion_id = i.id) AS personas,
                         (SELECT alerta FROM soportes s WHERE s.inscripcion_id = i.id ORDER BY s.id DESC LIMIT 1) AS alerta
                       FROM inscripciones i ${filtros.length ? `WHERE ${filtros.join(' AND ')}` : ''}
                       ORDER BY i.id DESC LIMIT 1000`).all(...valores).map(copia);
  }

  return {
    transaccion,
    crear,
    guardarPersonas,
    listar,
    porId: (id) => copia(q.porId.get(id)),
    porReferencia: (referencia) => copia(q.porReferencia.get(referencia)),
    deTitular: (documento) => copia(q.deTitular.get(documento)),
    todas: () => q.todas.all().map(copia),
    personas: (id) => q.personas.all(id).map(copia),
    soportes: (id) => q.soportes.all(id).map(copia),
    ultimoSoporte: (id) => copia(q.soportes.get(id)),
    soporte: (idSoporte) => copia(q.soporte.get(idSoporte)),
    // Otra inscripción vigente (distinta de `idExcluido`) en la que ya figura el documento.
    personaActiva: (documento, idExcluido = 0) => copia(q.personaActiva.get(documento, idExcluido)),
    // Cupos libres de la agencia sin contar la inscripción `idExcluido`.
    cuposDisponibles: (agencia, idExcluido = 0) => q.cuposAgencia.get(agencia).cupos - q.ocupados.get(agencia, idExcluido).n,
    cusUsado: (cus, idExcluido) => q.cusUsado.get(cus, idExcluido)?.referencia,
    reciboUsado: (agencia, recibo, idExcluido) => q.reciboUsado.get(agencia, recibo, idExcluido)?.referencia,
    actualizarLiquidacion: (id, { total, agencia, marca }) => q.liquidacion.run(total, agencia, marca, id),
    cambiarEstado: (id, estado, motivo, marca) => q.estado.run(estado, motivo, marca, id),
    registrarRevision: (id, { estado, motivo, usuario, marca }) => q.revision.run(estado, motivo, usuario, marca, marca, id),
    insertarSoporte: (s) => q.insertarSoporte.run(s.inscripcion_id, s.medio_pago, s.cus, s.banco, s.agencia_pago, s.recibo, s.fecha_pago,
      s.valor_pagado, s.campos, s.archivo, s.tipo_archivo, s.nombre_original, s.alerta, s.autorizacion_version, s.cargado_en),
    cupos: () => q.cupos.all().map(copia),
    quitarAjusteCupos: (agencia) => q.quitarAjuste.run(agencia),
    ajustarCupos: (agencia, cupos, usuario, marca) => q.ajustar.run(agencia, cupos, usuario, marca),
  };
}

module.exports = { crearRepositorioInscripciones };
