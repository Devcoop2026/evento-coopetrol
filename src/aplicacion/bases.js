// Carga de las bases de asociados y Coopetrolitos desde Excel (.xlsx) o CSV, directamente a la base de datos.
// Los datos personales no pasan por archivos del proyecto: se validan en memoria y se guardan en SQLite.
// Cada carga reemplaza la base completa en una transacción y queda registrada (quién, cuándo, cuántos).
const { leerHoja, fechaDesdeExcel } = require('../infraestructura/excel');
const { hmacFecha } = require('../infraestructura/secreto');
const { ErrorValidacion } = require('../dominio/errores');
const { esNumerico, esTexto } = require('../dominio/reglas');

const normalizar = (t) => String(t ?? '').normalize('NFD').replace(/[̀-ͯ]/g, '')
  .toLowerCase().replace(/[._]/g, ' ').replace(/\s+/g, ' ').trim();
const documento = (v) => (typeof v === 'number' && Number.isInteger(v) ? String(v) : String(v ?? '')).replace(/[\s.,-]/g, '').toUpperCase();
const texto = (v) => String(v ?? '').replace(/\s+/g, ' ').trim();
const VALORES_SI = new Set(['si', 's', 'x', '1', 'activo', 'true', 'verdadero']);

function fecha(valor, fecha1904) {
  if (valor == null || valor === '') return null;
  if (typeof valor === 'number') return fechaDesdeExcel(valor, fecha1904);
  const t = String(valor).trim().split(' ')[0];
  let m = t.match(/^(\d{1,2})[/-](\d{1,2})[/-](\d{2}|\d{4})$/);
  if (m) {
    const anio = m[3].length === 2 ? `20${m[3]}` : m[3];
    return validarFecha(`${anio}-${m[2].padStart(2, '0')}-${m[1].padStart(2, '0')}`, valor);
  }
  m = t.match(/^(\d{4})[/-](\d{1,2})[/-](\d{1,2})$/);
  if (m) return validarFecha(`${m[1]}-${m[2].padStart(2, '0')}-${m[3].padStart(2, '0')}`, valor);
  throw new Error(`fecha no reconocida: "${valor}"`);
}
function validarFecha(iso, original) {
  const d = new Date(`${iso}T00:00:00Z`);
  if (Number.isNaN(d.getTime()) || d.toISOString().slice(0, 10) !== iso) throw new Error(`fecha no válida: "${original}"`);
  return iso;
}

const TIPOS = {
  asociados: {
    columnas: {
      documento: ['cedula', 'documento', 'numero de documento', 'identificacion'],
      nombre: ['nombre', 'nombre completo', 'nombres'],
      agencia: ['agencia'],
      asociado: ['asociado', 'estado', 'es asociado'],
      fecha_actualizacion: ['actualizacion datos', 'ultima actualizacion de datos', 'fecha actualizacion', 'fecha de actualizacion',
        'actualizacion de datos'],
      fecha_expedicion: ['fecha expedicion', 'fecha de expedicion', 'fecha expedicion cedula', 'fecha de expedicion de la cedula',
        'fecha de expedicion cedula', 'fecha expedicion documento'],
      fecha_nacimiento: ['fecha nacimiento', 'fecha de nacimiento', 'nacimiento'],
    },
    // Columnas que pueden faltar (archivos con el formato anterior): el valor queda vacío.
    opcionales: ['fecha_nacimiento'],
    plantilla: 'Cedula;Nombre;Agencia;Asociado;actualizacion_datos;fecha_expedicion;fecha_nacimiento\n'
      + '1234567;Nombre de Ejemplo;BOGOTA;SI;15/02/2026;14/03/2008;20/05/1985\n',
  },
  coopetrolitos: {
    columnas: {
      documento: ['documento', 'documento coopetrolito', 'tarjeta de identidad', 'registro civil'],
      nombre: ['nombre', 'nombre coopetrolito', 'nombre completo'],
      documento_asociado: ['cedula asociado', 'documento asociado', 'cedula del asociado', 'cedula padre', 'cedula madre'],
      fecha_nacimiento: ['fecha nacimiento', 'fecha de nacimiento', 'nacimiento'],
    },
    opcionales: ['fecha_nacimiento'],
    plantilla: 'Documento;Nombre;Cedula asociado;fecha_nacimiento\n1100000001;Nombre de Ejemplo;1234567;10/06/2015\n',
  },
};

function crearBases(db, { ahora = () => new Date() } = {}) {
  // Agencias válidas: las de data/agencias_evento.json (incluye eventos compartidos) y los nombres de los eventos.
  const agencias = () => new Set(db.prepare('SELECT agencia FROM agencias_evento UNION SELECT agencia FROM tarifas').all().map((t) => t.agencia));

  // Valida el archivo y devuelve los registros listos para guardar, sin tocar la base.
  function analizar(tipo, buffer) {
    const def = TIPOS[tipo];
    if (!def) throw new ErrorValidacion('Tipo de base no válido.');
    let hoja;
    try {
      hoja = leerHoja(buffer);
    } catch (err) {
      throw new ErrorValidacion(err.message);
    }
    const { filas, fecha1904 } = hoja;
    const iFila = filas.findIndex((f) => f.some((c) => c != null && String(c).trim() !== ''));
    if (iFila < 0) throw new ErrorValidacion('El archivo no tiene datos.');
    const encabezado = filas[iFila].map(normalizar);
    const indice = {};
    const faltantes = [];
    for (const [campo, nombres] of Object.entries(def.columnas)) {
      const i = encabezado.findIndex((h) => nombres.includes(h));
      if (i < 0 && !def.opcionales?.includes(campo)) faltantes.push(nombres[0]);
      indice[campo] = i;
    }
    if (faltantes.length) {
      throw new ErrorValidacion(`Faltan columnas: ${faltantes.join(', ')}. Revise la primera fila del archivo.`);
    }

    const validas = tipo === 'asociados' ? agencias() : null;
    const asociados = tipo === 'coopetrolitos' ? new Set(db.prepare('SELECT documento FROM asociados').all().map((a) => a.documento)) : null;
    const registros = [];
    const errores = [];
    const advertencias = [];
    const vistos = new Set();
    let sinExpedicion = 0;
    let sinAsociado = 0;
    const agenciasDesconocidas = new Map(); // agencia -> filas, para resumirlas aunque la lista de errores se recorte

    filas.slice(iFila + 1).forEach((fila, k) => {
      const n = iFila + k + 2; // número de fila como lo ve el usuario en Excel
      if (!fila.some((c) => c != null && String(c).trim() !== '')) return;
      const v = (campo) => (indice[campo] >= 0 ? fila[indice[campo]] : null);
      const doc = documento(v('documento'));
      if (!doc) return errores.push(`Fila ${n}: sin documento`);
      if (!esNumerico(doc)) return errores.push(`Fila ${n}: el documento "${doc}" solo debe contener números`);
      const nombreFila = texto(v('nombre'));
      if (!nombreFila) return errores.push(`Fila ${n}: sin nombre`);
      if (!esTexto(nombreFila)) return errores.push(`Fila ${n}: el nombre "${nombreFila}" solo debe contener letras y espacios`);
      if (vistos.has(doc)) return errores.push(`Fila ${n}: documento ${doc} repetido (se conserva el primero)`);
      try {
        const nacimiento = fecha(v('fecha_nacimiento'), fecha1904);
        if (nacimiento && (nacimiento < '1900-01-01' || nacimiento > new Date().toISOString().slice(0, 10))) {
          return errores.push(`Fila ${n}: la fecha de nacimiento ${nacimiento} no es válida`);
        }
        if (tipo === 'asociados') {
          const agencia = texto(v('agencia')).toUpperCase();
          if (!validas.has(agencia)) agenciasDesconocidas.set(agencia, (agenciasDesconocidas.get(agencia) || 0) + 1);
          if (!validas.has(agencia)) return errores.push(`Fila ${n}: la agencia "${agencia}" no existe en las tarifas ni en data/agencias_evento.json`);
          const expedicion = fecha(v('fecha_expedicion'), fecha1904);
          if (!expedicion) sinExpedicion++;
          const valorAsociado = typeof v('asociado') === 'boolean' ? (v('asociado') ? 'si' : 'no') : normalizar(v('asociado'));
          registros.push({
            documento: doc,
            nombre: texto(v('nombre')),
            agencia,
            estado: VALORES_SI.has(valorAsociado) ? 'ACTIVO' : 'INACTIVO',
            fecha_actualizacion: fecha(v('fecha_actualizacion'), fecha1904),
            expedicion_hmac: expedicion ? hmacFecha(expedicion) : null,
            fecha_nacimiento: nacimiento,
          });
        } else {
          const padre = documento(v('documento_asociado'));
          if (!padre) return errores.push(`Fila ${n}: sin cédula del asociado`);
          if (!esNumerico(padre)) return errores.push(`Fila ${n}: la cédula del asociado "${padre}" solo debe contener números`);
          if (!asociados.has(padre)) sinAsociado++;
          registros.push({ documento: doc, nombre: texto(v('nombre')), documento_asociado: padre, fecha_nacimiento: nacimiento });
        }
        vistos.add(doc);
      } catch (err) {
        errores.push(`Fila ${n}: ${err.message}`);
      }
    });

    if (sinExpedicion) advertencias.push(`${sinExpedicion} asociado(s) sin fecha de expedición: no podrán identificarse.`);
    if (agenciasDesconocidas.size) {
      const lista = [...agenciasDesconocidas].sort((a, b) => b[1] - a[1]).map(([a, n]) => `${a || '(vacía)'} (${n})`).join(', ');
      advertencias.push(`Agencias no reconocidas: ${lista}. Agréguelas a data/agencias_evento.json o corrija el archivo.`);
    }
    if (sinAsociado) advertencias.push(`${sinAsociado} Coopetrolito(s) con una cédula de asociado que no está en la base de asociados.`);
    if (!registros.length) throw new ErrorValidacion(`El archivo no tiene registros válidos. ${errores.slice(0, 3).join('. ')}`);
    return { registros, errores, advertencias };
  }

  // Reemplaza la base completa (todo o nada) y registra la carga.
  function guardar(tipo, registros, { archivo, usuario }) {
    db.exec('BEGIN IMMEDIATE');
    try {
      if (tipo === 'asociados') {
        db.exec('DELETE FROM asociados');
        const ins = db.prepare(`INSERT INTO asociados (documento, nombre, agencia, estado, fecha_actualizacion, expedicion_hmac,
                                fecha_nacimiento) VALUES (?, ?, ?, ?, ?, ?, ?)`);
        for (const r of registros) {
          ins.run(r.documento, r.nombre, r.agencia, r.estado, r.fecha_actualizacion, r.expedicion_hmac, r.fecha_nacimiento);
        }
      } else {
        db.exec('DELETE FROM coopetrolitos');
        const ins = db.prepare('INSERT INTO coopetrolitos (documento, nombre, documento_asociado, fecha_nacimiento) VALUES (?, ?, ?, ?)');
        for (const r of registros) ins.run(r.documento, r.nombre, r.documento_asociado, r.fecha_nacimiento);
      }
      db.prepare('INSERT INTO cargas_bases (tipo, archivo, registros, usuario, cargada_en) VALUES (?, ?, ?, ?, ?)')
        .run(tipo, String(archivo || '').slice(0, 200), registros.length, usuario, ahora().toISOString());
      db.exec('COMMIT');
    } catch (err) {
      db.exec('ROLLBACK');
      throw err;
    }
  }

  // Analiza y, si `confirmar`, guarda. Sin confirmar solo devuelve la vista previa.
  function cargar(tipo, buffer, { confirmar = false, archivo, usuario } = {}) {
    const { registros, errores, advertencias } = analizar(tipo, buffer);
    if (confirmar) guardar(tipo, registros, { archivo, usuario });
    const resumen = {
      tipo,
      registros: registros.length,
      omitidas: errores.length,
      errores: errores.slice(0, 100),
      advertencias,
      guardado: confirmar,
    };
    if (tipo === 'asociados') {
      resumen.activos = registros.filter((r) => r.estado === 'ACTIVO').length;
      resumen.porAgencia = Object.entries(registros.reduce((t, r) => ({ ...t, [r.agencia]: (t[r.agencia] || 0) + 1 }), {}))
        .sort(([a], [b]) => a.localeCompare(b));
    }
    return resumen;
  }

  function estado() {
    const ultima = db.prepare('SELECT archivo, registros, usuario, cargada_en FROM cargas_bases WHERE tipo = ? ORDER BY id DESC LIMIT 1');
    return {
      asociados: { total: db.prepare('SELECT COUNT(*) AS n FROM asociados').get().n, ultimaCarga: ultima.get('asociados') ?? null },
      coopetrolitos: { total: db.prepare('SELECT COUNT(*) AS n FROM coopetrolitos').get().n, ultimaCarga: ultima.get('coopetrolitos') ?? null },
    };
  }

  const plantilla = (tipo) => {
    if (!TIPOS[tipo]) throw new ErrorValidacion('Tipo de base no válido.');
    return `﻿${TIPOS[tipo].plantilla}`;
  };

  return { analizar, cargar, estado, plantilla, guardar };
}

module.exports = { crearBases, TIPOS };
