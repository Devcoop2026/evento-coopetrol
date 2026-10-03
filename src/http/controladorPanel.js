// Rutas del panel de administración (/api/admin/...).
// Opciones de cada ruta:
//   publica: true        no exige sesión (ingreso y salida)
//   soloAdmin: 'acción'  exige el rol ADMINISTRADOR; el REVISOR recibe 403 y queda en el registro de seguridad
//   limiteCuerpo         tamaño máximo del JSON (por defecto LIMITE_JSON)
const fs = require('node:fs');
const { ErrorValidacion } = require('../dominio/errores');
const { LIMITE_JSON } = require('./respuestas');

const LIMITE_BASE = Math.ceil(20 * 1024 * 1024 * 1.4) + LIMITE_JSON; // Excel/CSV de asociados o Coopetrolitos

// Gestión manual: cada entidad se publica como /<recurso> (GET lista, POST crea) y /<recurso>/:clave (PUT, DELETE).
const ENTIDADES = [
  { recurso: 'usuarios', nombre: 'Usuario', listar: (gestion) => gestion.listarUsuarios() },
  { recurso: 'asociados', nombre: 'Asociado', listar: (gestion, p) => gestion.listarAsociados(p) },
  { recurso: 'coopetrolitos', nombre: 'Coopetrolito', listar: (gestion, p) => gestion.listarCoopetrolitos(p) },
];

function rutasPanel({ inscripciones, administracion, bases, gestion, config, registrar, ahora, cookieSesion }) {
  // Avisos para los administradores (p. ej. la fecha de supresión de datos personales ya se cumplió).
  function avisosPanel() {
    const fecha = config.fecha_supresion_datos;
    if (!fecha || ahora().toISOString().slice(0, 10) < fecha) return [];
    return [`Se cumplió la fecha de supresión de datos personales (${fecha.split('-').reverse().join('/')}). `
      + 'Exporte lo necesario y ejecute la limpieza de datos (deploy/limpiar.sh todo).'];
  }

  const crud = ENTIDADES.flatMap(({ recurso, nombre, listar }) => {
    const soloAdmin = `gestionar ${recurso}`;
    return [
      {
        metodo: 'GET', ruta: `/${recurso}`, soloAdmin,
        manejador: ({ url }) => listar(gestion, { q: url.searchParams.get('q'), pagina: url.searchParams.get('pagina') }),
      },
      {
        metodo: 'POST', ruta: `/${recurso}`, soloAdmin, estado: 201,
        manejador: async ({ cuerpo, sesion }) => gestion[`crear${nombre}`](await cuerpo(), sesion.usuario),
      },
      {
        metodo: 'PUT', ruta: `/${recurso}/:clave`, soloAdmin,
        manejador: async ({ params, cuerpo, sesion }) => gestion[`editar${nombre}`](params.clave, await cuerpo(), sesion.usuario),
      },
      {
        metodo: 'DELETE', ruta: `/${recurso}/:clave`, soloAdmin,
        manejador: ({ params, sesion }) => gestion[`eliminar${nombre}`](params.clave, sesion.usuario),
      },
    ];
  });

  return [
    // ---- Sesión ----
    {
      metodo: 'POST', ruta: '/login', publica: true, limiteLogin: true,
      manejador: async ({ cuerpo, ip, req, res }) => {
        const { usuario, clave } = await cuerpo();
        let sesion;
        try {
          sesion = administracion.iniciarSesion(usuario, clave);
        } catch (err) {
          registrar('login_fallido', { usuario: String(usuario || '').slice(0, 40), ip });
          throw err;
        }
        registrar('login_ok', { usuario: sesion.usuario, rol: sesion.rol, ip });
        res.setHeader('Set-Cookie', cookieSesion(req, sesion.token, sesion.maxAge));
        return { usuario: sesion.usuario, nombre: sesion.nombre, rol: sesion.rol };
      },
    },
    {
      metodo: 'POST', ruta: '/logout', publica: true,
      manejador: ({ req, res, sid }) => {
        administracion.cerrarSesion(sid);
        res.setHeader('Set-Cookie', cookieSesion(req, '', 0));
        return { ok: true };
      },
    },
    {
      metodo: 'GET', ruta: '/sesion',
      manejador: ({ sesion }) => ({ ...sesion, avisos: sesion.rol === 'ADMINISTRADOR' ? avisosPanel() : [] }),
    },

    // ---- Cupos e inscripciones ----
    { metodo: 'GET', ruta: '/cupos', manejador: () => inscripciones.cupos() },
    {
      metodo: 'POST', ruta: '/cupos/:agencia', soloAdmin: 'ajustar cupos',
      manejador: async ({ params, cuerpo, sesion }) => inscripciones.ajustarCupos(params.agencia, (await cuerpo()).cupos, sesion.usuario),
    },
    {
      metodo: 'GET', ruta: '/inscripciones',
      manejador: ({ url }) => {
        const p = url.searchParams;
        return inscripciones.listar({ estado: p.get('estado'), agencia: p.get('agencia'), busqueda: p.get('q') });
      },
    },
    { metodo: 'GET', ruta: '/inscripciones/:id(\\d+)', manejador: ({ params }) => inscripciones.detalleAdmin(params.id) },
    {
      metodo: 'POST', ruta: '/inscripciones/:id(\\d+)/revision',
      manejador: async ({ params, cuerpo, sesion, exigirAdmin }) => {
        const revision = await cuerpo();
        if (revision.accion === 'ANULAR') exigirAdmin('anular inscripción'); // el revisor aprueba o rechaza, no anula
        return inscripciones.revisar(params.id, revision, sesion.usuario);
      },
    },
    {
      metodo: 'GET', ruta: '/soportes/:id(\\d+)',
      manejador: ({ params, res }) => {
        const archivo = inscripciones.archivoSoporte(params.id);
        const contenido = fs.readFileSync(archivo.ruta);
        res.writeHead(200, {
          'Content-Type': archivo.tipo,
          'Content-Disposition': `inline; filename="${encodeURIComponent(archivo.nombre || 'soporte')}"`,
          'Cache-Control': 'private, no-store',
          'Cross-Origin-Resource-Policy': 'same-origin',
          // Aislamiento del archivo subido: las imágenes no pueden ejecutar nada. Los PDF se abren en el visor
          // aislado del navegador, que no tiene acceso a la página ni a la sesión.
          ...(archivo.tipo.startsWith('image/')
            ? { 'Content-Security-Policy': "default-src 'none'; img-src 'self'; style-src 'unsafe-inline'; sandbox" } : {}),
        });
        res.end(contenido);
      },
    },
    {
      metodo: 'GET', ruta: '/exportar.csv',
      manejador: ({ res }) => {
        res.writeHead(200, {
          'Content-Type': 'text/csv; charset=utf-8',
          'Content-Disposition': `attachment; filename="inscripciones-${ahora().toISOString().slice(0, 10)}.csv"`,
          'Cache-Control': 'no-store',
        });
        res.end(inscripciones.exportarCsv());
      },
    },

    // ---- Gestión manual y auditoría ----
    ...crud,
    {
      metodo: 'GET', ruta: '/auditoria', soloAdmin: 'ver auditoría',
      manejador: ({ url }) => gestion.auditoria(url.searchParams.get('limite')),
    },

    // ---- Bases de asociados y Coopetrolitos: vista previa (confirmar: false) y reemplazo (confirmar: true) ----
    { metodo: 'GET', ruta: '/bases', soloAdmin: 'cargar bases', manejador: () => bases.estado() },
    {
      metodo: 'GET', ruta: '/bases/:tipo(asociados|coopetrolitos)/plantilla.csv', soloAdmin: 'cargar bases',
      manejador: ({ params, res }) => {
        res.writeHead(200, {
          'Content-Type': 'text/csv; charset=utf-8',
          'Content-Disposition': `attachment; filename="plantilla-${params.tipo}.csv"`,
        });
        res.end(bases.plantilla(params.tipo));
      },
    },
    {
      metodo: 'POST', ruta: '/bases/:tipo(asociados|coopetrolitos)', soloAdmin: 'cargar bases', limiteCuerpo: LIMITE_BASE,
      manejador: async ({ params, cuerpo, sesion, ip }) => {
        const { nombre, contenido, confirmar } = await cuerpo();
        if (!contenido) throw new ErrorValidacion('Adjunte el archivo de la base.');
        const resumen = bases.cargar(params.tipo, Buffer.from(String(contenido), 'base64'), {
          confirmar: confirmar === true, archivo: nombre, usuario: sesion.usuario,
        });
        if (resumen.guardado) registrar('carga_base', { tipo: params.tipo, registros: resumen.registros, usuario: sesion.usuario, ip });
        return resumen;
      },
    },
  ];
}

module.exports = { rutasPanel };
