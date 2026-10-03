// Usuarios administradores del panel de revisión y sus sesiones.
const crypto = require('node:crypto');
const { ErrorValidacion } = require('../dominio/errores');

const HORAS_SESION = 8;
// ADMINISTRADOR: todo el panel. REVISOR: ver inscripciones y soportes, aprobar o rechazar pagos y exportar.
const ROLES = ['ADMINISTRADOR', 'REVISOR'];

const derivar = (clave, salt) => crypto.scryptSync(String(clave), salt, 64);
// En la base solo se guarda el hash del token de sesión: si se filtra evento.db, las sesiones no se pueden reutilizar.
const hashToken = (token) => crypto.createHash('sha256').update(String(token)).digest('hex');

function crearAdministracion(db, { ahora = () => new Date() } = {}) {
  // Sesiones de versiones anteriores (token en texto plano, distinto de 64 caracteres hex): se descartan.
  db.prepare("DELETE FROM sesiones WHERE length(token) <> 64 OR token GLOB '*[^0-9a-f]*'").run();

  // Crea el usuario o cambia su clave. `rol` solo aplica al crear (o si se indica); si no, conserva el actual.
  function crearUsuario(usuario, nombre, clave, { rol } = {}) {
    if (!/^[a-z0-9._-]{3,40}$/i.test(usuario || '')) throw new ErrorValidacion('Usuario inválido (3 a 40 letras, números, . _ -).');
    if (String(clave || '').length < 10) throw new ErrorValidacion('La clave debe tener al menos 10 caracteres.');
    if (rol != null && !ROLES.includes(rol)) throw new ErrorValidacion('Rol inválido.');
    const salt = crypto.randomBytes(16).toString('hex');
    db.prepare(`INSERT INTO administradores (usuario, nombre, salt, hash, rol) VALUES (?, ?, ?, ?, ?)
                ON CONFLICT(usuario) DO UPDATE SET nombre = excluded.nombre, salt = excluded.salt, hash = excluded.hash,
                  rol = COALESCE(?, administradores.rol)`)
      .run(usuario.toLowerCase(), nombre || usuario, salt, derivar(clave, salt).toString('hex'), rol ?? 'ADMINISTRADOR', rol ?? null);
  }

  function iniciarSesion(usuario, clave) {
    const admin = db.prepare('SELECT * FROM administradores WHERE usuario = ?').get(String(usuario || '').toLowerCase());
    // Se deriva la clave aunque el usuario no exista para no revelar usuarios por tiempo de respuesta.
    const esperado = admin ? Buffer.from(admin.hash, 'hex') : crypto.randomBytes(64);
    const recibido = derivar(clave || '', admin?.salt || 'sin-usuario');
    if (!admin || !crypto.timingSafeEqual(esperado, recibido)) throw new ErrorValidacion('Usuario o clave incorrectos.');
    const token = crypto.randomBytes(32).toString('base64url');
    const expira = new Date(ahora().getTime() + HORAS_SESION * 3600000).toISOString();
    db.prepare('DELETE FROM sesiones WHERE expira_en < ?').run(ahora().toISOString());
    db.prepare('INSERT INTO sesiones (token, usuario, expira_en) VALUES (?, ?, ?)').run(hashToken(token), admin.usuario, expira);
    return { token, usuario: admin.usuario, nombre: admin.nombre, rol: admin.rol, maxAge: HORAS_SESION * 3600 };
  }

  function validarSesion(token) {
    if (!token) return null;
    const sesion = db.prepare(`SELECT s.usuario, a.nombre, a.rol FROM sesiones s JOIN administradores a ON a.usuario = s.usuario
                               WHERE s.token = ? AND s.expira_en > ?`).get(hashToken(token), ahora().toISOString());
    return sesion ? { ...sesion } : null;
  }

  const cerrarSesion = (token) => db.prepare('DELETE FROM sesiones WHERE token = ?').run(hashToken(token || ''));

  return { crearUsuario, iniciarSesion, validarSesion, cerrarSesion };
}

module.exports = { crearAdministracion, ROLES };
