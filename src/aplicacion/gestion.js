// Gestión manual (CRUD) desde el panel: usuarios del panel, asociados y Coopetrolitos.
// Complementa la carga masiva (src/aplicacion/bases.js) con las mismas validaciones. Cada cambio queda en la tabla auditoria.
// La fecha de expedición es de solo escritura: se guarda como HMAC y nunca se devuelve.
const { ErrorValidacion } = require('../dominio/errores');
const { validarDocumento, validarNombreCompleto: validarNombre, validarFecha } = require('../dominio/valores');
const { hmacFecha } = require('../infraestructura/secreto');
const { ESTADOS_ACTIVOS } = require('../dominio/inscripcion');
const { ROLES } = require('./admin');

const EN_ACTIVOS = `(${ESTADOS_ACTIVOS.map((e) => `'${e}'`).join(', ')})`;
const POR_PAGINA = 50;

const texto = (v) => String(v ?? '').replace(/\s+/g, ' ').trim();

// Fecha de nacimiento opcional: real, no futura y posterior a 1900.
function validarNacimiento(valor) {
  const f = validarFecha(valor, 'la fecha de nacimiento', { opcional: true });
  if (f && (f < '1900-01-01' || f > new Date().toISOString().slice(0, 10))) throw new ErrorValidacion('La fecha de nacimiento no es válida.');
  return f;
}

function crearGestion(db, administracion, { ahora = () => new Date() } = {}) {
  const auditar = (usuario, accion, entidad, clave, detalle = null) => db.prepare(
    'INSERT INTO auditoria (usuario, accion, entidad, clave, detalle, fecha) VALUES (?, ?, ?, ?, ?, ?)',
  ).run(usuario, accion, entidad, String(clave), detalle, ahora().toISOString());

  const enInscripcionActiva = db.prepare(`SELECT i.referencia FROM inscripcion_personas p JOIN inscripciones i ON i.id = p.inscripcion_id
                                          WHERE p.documento = ? AND i.estado IN ${EN_ACTIVOS} LIMIT 1`);

  function paginar(sqlBase, filtros, valores, pagina) {
    const donde = filtros.length ? ` WHERE ${filtros.join(' AND ')}` : '';
    const total = db.prepare(`SELECT COUNT(*) AS n FROM (${sqlBase}${donde})`).get(...valores).n;
    const p = Math.max(1, Number(pagina) || 1);
    const filas = db.prepare(`${sqlBase}${donde} ORDER BY nombre LIMIT ${POR_PAGINA} OFFSET ${(p - 1) * POR_PAGINA}`).all(...valores);
    return { filas: filas.map((f) => ({ ...f })), total, pagina: p, paginas: Math.max(1, Math.ceil(total / POR_PAGINA)) };
  }
  const buscar = (q, columnas) => {
    const t = texto(q);
    if (!t) return [[], []];
    return [[`(${columnas.map((c) => `${c} LIKE ?`).join(' OR ')})`], columnas.map(() => `%${t}%`)];
  };

  // ---------- Asociados ----------
  const agencias = () => new Set(db.prepare('SELECT agencia FROM agencias_evento UNION SELECT agencia FROM tarifas').all().map((t) => t.agencia));

  function datosAsociado(d, { parcial = false } = {}) {
    const agencia = texto(d.agencia).toUpperCase();
    if (!agencias().has(agencia)) throw new ErrorValidacion(`La agencia "${agencia}" no existe en las tarifas del evento.`);
    const estado = d.estado === 'INACTIVO' ? 'INACTIVO' : 'ACTIVO';
    const expedicion = validarFecha(d.fechaExpedicion, 'la fecha de expedición', { opcional: parcial });
    return {
      nombre: validarNombre(d.nombre),
      agencia,
      estado,
      fecha_actualizacion: validarFecha(d.fechaActualizacion, 'la fecha de actualización de datos', { opcional: true }),
      fecha_nacimiento: validarNacimiento(d.fechaNacimiento),
      expedicion_hmac: expedicion ? hmacFecha(expedicion) : undefined,
    };
  }

  function listarAsociados({ q, pagina } = {}) {
    const [filtros, valores] = buscar(q, ['documento', 'nombre', 'agencia']);
    return paginar(`SELECT documento, nombre, agencia, estado, fecha_actualizacion, fecha_nacimiento,
                              CASE WHEN expedicion_hmac IS NULL THEN 0 ELSE 1 END AS expedicion_registrada
                       FROM asociados`, filtros, valores, pagina);
  }

  function crearAsociado(d, usuario) {
    const documento = validarDocumento(d.documento);
    if (db.prepare('SELECT 1 FROM asociados WHERE documento = ?').get(documento)) {
      throw new ErrorValidacion(`Ya existe un asociado con el documento ${documento}.`);
    }
    const a = datosAsociado(d);
    db.prepare(`INSERT INTO asociados (documento, nombre, agencia, estado, fecha_actualizacion, expedicion_hmac, fecha_nacimiento)
                VALUES (?, ?, ?, ?, ?, ?, ?)`)
      .run(documento, a.nombre, a.agencia, a.estado, a.fecha_actualizacion, a.expedicion_hmac, a.fecha_nacimiento);
    auditar(usuario, 'CREAR', 'asociado', documento);
    return { documento };
  }

  // La fecha de expedición solo cambia si se envía; vacía conserva la registrada.
  function editarAsociado(documento, d, usuario) {
    const actual = db.prepare('SELECT expedicion_hmac FROM asociados WHERE documento = ?').get(documento);
    if (!actual) throw new ErrorValidacion('Asociado no encontrado.');
    const a = datosAsociado(d, { parcial: true });
    db.prepare(`UPDATE asociados SET nombre = ?, agencia = ?, estado = ?, fecha_actualizacion = ?, expedicion_hmac = ?, fecha_nacimiento = ?
                WHERE documento = ?`)
      .run(a.nombre, a.agencia, a.estado, a.fecha_actualizacion, a.expedicion_hmac ?? actual.expedicion_hmac, a.fecha_nacimiento, documento);
    auditar(usuario, 'EDITAR', 'asociado', documento, a.expedicion_hmac ? 'incluye fecha de expedición' : null);
    return { documento };
  }

  function eliminarAsociado(documento, usuario) {
    if (!db.prepare('SELECT 1 FROM asociados WHERE documento = ?').get(documento)) throw new ErrorValidacion('Asociado no encontrado.');
    const ins = enInscripcionActiva.get(documento);
    if (ins) throw new ErrorValidacion(`El asociado está en la inscripción activa ${ins.referencia}. Márquelo como inactivo o anule la inscripción.`);
    const hijos = db.prepare('SELECT COUNT(*) AS n FROM coopetrolitos WHERE documento_asociado = ?').get(documento).n;
    if (hijos) throw new ErrorValidacion(`El asociado tiene ${hijos} Coopetrolito(s) vinculado(s). Elimínelos o reasígnelos primero.`);
    db.prepare('DELETE FROM asociados WHERE documento = ?').run(documento);
    auditar(usuario, 'ELIMINAR', 'asociado', documento);
    return { documento };
  }

  // ---------- Coopetrolitos ----------
  function datosCoopetrolito(d) {
    const padre = validarDocumento(d.documentoAsociado, { etiqueta: 'Cédula del asociado' });
    if (!db.prepare('SELECT 1 FROM asociados WHERE documento = ?').get(padre)) {
      throw new ErrorValidacion(`No existe un asociado con la cédula ${padre}.`);
    }
    return { nombre: validarNombre(d.nombre), documento_asociado: padre, fecha_nacimiento: validarNacimiento(d.fechaNacimiento) };
  }

  function listarCoopetrolitos({ q, pagina } = {}) {
    const [filtros, valores] = buscar(q, ['c.documento', 'c.nombre', 'c.documento_asociado', 'a.nombre']);
    return paginar(`SELECT c.documento, c.nombre AS nombre, c.documento_asociado, c.fecha_nacimiento, a.nombre AS nombre_asociado
                    FROM coopetrolitos c LEFT JOIN asociados a ON a.documento = c.documento_asociado`, filtros, valores, pagina);
  }

  function crearCoopetrolito(d, usuario) {
    const documento = validarDocumento(d.documento);
    if (db.prepare('SELECT 1 FROM coopetrolitos WHERE documento = ?').get(documento)) {
      throw new ErrorValidacion(`Ya existe un Coopetrolito con el documento ${documento}.`);
    }
    const c = datosCoopetrolito(d);
    db.prepare('INSERT INTO coopetrolitos (documento, nombre, documento_asociado, fecha_nacimiento) VALUES (?, ?, ?, ?)')
      .run(documento, c.nombre, c.documento_asociado, c.fecha_nacimiento);
    auditar(usuario, 'CREAR', 'coopetrolito', documento);
    return { documento };
  }

  function editarCoopetrolito(documento, d, usuario) {
    if (!db.prepare('SELECT 1 FROM coopetrolitos WHERE documento = ?').get(documento)) throw new ErrorValidacion('Coopetrolito no encontrado.');
    const c = datosCoopetrolito(d);
    db.prepare('UPDATE coopetrolitos SET nombre = ?, documento_asociado = ?, fecha_nacimiento = ? WHERE documento = ?')
      .run(c.nombre, c.documento_asociado, c.fecha_nacimiento, documento);
    auditar(usuario, 'EDITAR', 'coopetrolito', documento);
    return { documento };
  }

  function eliminarCoopetrolito(documento, usuario) {
    if (!db.prepare('SELECT 1 FROM coopetrolitos WHERE documento = ?').get(documento)) throw new ErrorValidacion('Coopetrolito no encontrado.');
    const ins = enInscripcionActiva.get(documento);
    if (ins) throw new ErrorValidacion(`El Coopetrolito está en la inscripción activa ${ins.referencia}. Anule o modifique la inscripción primero.`);
    db.prepare('DELETE FROM coopetrolitos WHERE documento = ?').run(documento);
    auditar(usuario, 'ELIMINAR', 'coopetrolito', documento);
    return { documento };
  }

  // ---------- Usuarios del panel ----------
  const listarUsuarios = () => db.prepare(`SELECT a.usuario, a.nombre, a.rol,
      (SELECT MAX(fecha) FROM auditoria WHERE entidad = 'usuario' AND clave = a.usuario) AS actualizado_en
    FROM administradores a ORDER BY a.usuario`).all().map((u) => ({ ...u }));

  const validarRol = (rol) => {
    if (!ROLES.includes(rol)) throw new ErrorValidacion('Seleccione el rol del usuario.');
    return rol;
  };
  const administradores = () => db.prepare("SELECT COUNT(*) AS n FROM administradores WHERE rol = 'ADMINISTRADOR'").get().n;

  function crearUsuario({ usuario, nombre, clave, rol }, autor) {
    const id = String(usuario || '').trim().toLowerCase();
    if (db.prepare('SELECT 1 FROM administradores WHERE usuario = ?').get(id)) throw new ErrorValidacion(`El usuario "${id}" ya existe.`);
    administracion.crearUsuario(id, texto(nombre) || id, clave, { rol: validarRol(rol) });
    auditar(autor, 'CREAR', 'usuario', id, `rol ${rol}`);
    return { usuario: id };
  }

  // Cambiar la clave cierra las sesiones abiertas de ese usuario.
  function editarUsuario(usuario, { nombre, clave, rol }, autor) {
    const actual = db.prepare('SELECT nombre, rol FROM administradores WHERE usuario = ?').get(usuario);
    if (!actual) throw new ErrorValidacion('Usuario no encontrado.');
    const nuevoRol = rol ? validarRol(rol) : actual.rol;
    if (nuevoRol !== actual.rol) {
      if (usuario === autor) throw new ErrorValidacion('No puede cambiar su propio rol.');
      if (actual.rol === 'ADMINISTRADOR' && administradores() <= 1) throw new ErrorValidacion('Debe quedar al menos un administrador.');
      db.prepare('UPDATE administradores SET rol = ? WHERE usuario = ?').run(nuevoRol, usuario);
      db.prepare('DELETE FROM sesiones WHERE usuario = ?').run(usuario); // el nuevo rol aplica desde el próximo ingreso
    }
    if (clave) {
      administracion.crearUsuario(usuario, texto(nombre) || actual.nombre, clave);
      if (usuario !== autor) db.prepare('DELETE FROM sesiones WHERE usuario = ?').run(usuario);
    } else {
      const n = texto(nombre);
      if (!n) throw new ErrorValidacion('Ingrese el nombre.');
      db.prepare('UPDATE administradores SET nombre = ? WHERE usuario = ?').run(n, usuario);
    }
    const detalle = [clave ? 'cambio de clave' : null, nuevoRol !== actual.rol ? `rol ${actual.rol} → ${nuevoRol}` : null].filter(Boolean).join('; ');
    auditar(autor, 'EDITAR', 'usuario', usuario, detalle || null);
    return { usuario };
  }

  function eliminarUsuario(usuario, autor) {
    if (usuario === autor) throw new ErrorValidacion('No puede eliminar su propio usuario.');
    if (!db.prepare('SELECT 1 FROM administradores WHERE usuario = ?').get(usuario)) throw new ErrorValidacion('Usuario no encontrado.');
    const rolEliminado = db.prepare('SELECT rol FROM administradores WHERE usuario = ?').get(usuario).rol;
    if (rolEliminado === 'ADMINISTRADOR' && administradores() <= 1) throw new ErrorValidacion('Debe quedar al menos un administrador.');
    db.prepare('DELETE FROM sesiones WHERE usuario = ?').run(usuario);
    db.prepare('DELETE FROM administradores WHERE usuario = ?').run(usuario);
    auditar(autor, 'ELIMINAR', 'usuario', usuario);
    return { usuario };
  }

  const auditoria = (limite = 100) => db.prepare('SELECT usuario, accion, entidad, clave, detalle, fecha FROM auditoria ORDER BY id DESC LIMIT ?')
    .all(Math.min(500, Number(limite) || 100)).map((a) => ({ ...a }));

  return {
    listarAsociados, crearAsociado, editarAsociado, eliminarAsociado,
    listarCoopetrolitos, crearCoopetrolito, editarCoopetrolito, eliminarCoopetrolito,
    listarUsuarios, crearUsuario, editarUsuario, eliminarUsuario,
    auditoria,
  };
}

module.exports = { crearGestion };
