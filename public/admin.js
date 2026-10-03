// Panel de administración: cupos, revisión de pagos, bases, gestión de registros y auditoría.
import {
  $, pesos, fechaCorta, fechaHora, ETIQUETA_TIPO, el, mensaje, solicitar, leerBase64,
} from './comun.js';
import { confirmar, avisar } from './dialogo.js';
import { notificar } from './notificacion.js';
import './validacion.js';

const ESTADOS = {
  PREINSCRITO: ['Preinscrito', 'pendiente'], EN_REVISION: ['En revisión', 'revision'], RECHAZADO: ['Rechazado', 'rechazado'],
  CONFIRMADO: ['Confirmado', 'confirmado'], CANCELADO: ['Cancelado', 'inactivo'], ANULADO: ['Anulado', 'inactivo'],
};
const PERMITIDAS = { APROBAR: ['EN_REVISION'], RECHAZAR: ['EN_REVISION'], ANULAR: ['PREINSCRITO', 'EN_REVISION', 'RECHAZADO', 'CONFIRMADO'] };

let actual = null;
let etiquetas = {}; // id del campo adicional -> etiqueta (data/formulario_soporte.json)

let sesionActual = null;
let agenciasEvento = [];

// Si la sesión venció (401), vuelve a la pantalla de ingreso.
async function pedir(url, cuerpo, metodo) {
  try {
    return await solicitar(url, cuerpo, metodo);
  } catch (err) {
    if (err.estado === 401 && !url.endsWith('/login')) mostrarLogin();
    throw err;
  }
}

const chipEstado = (estado) => {
  const [texto, clase] = ESTADOS[estado] || [estado, 'inactivo'];
  return el('span', { className: `estado estado--${clase}`, textContent: texto });
};

// ---- Sesión ----
function mostrarLogin() {
  $('#panel').hidden = true;
  $('#usuario').hidden = true;
  $('#btn-salir').hidden = true;
  $('#form-login').hidden = false;
  $('#l-usuario').focus();
}

const esAdministrador = () => sesionActual?.rol === 'ADMINISTRADOR';

function mostrarPanel(sesion) {
  sesionActual = sesion;
  // El rol define qué se muestra; el servidor igual rechaza (403) lo que el rol no permite.
  document.body.dataset.rol = sesion.rol || 'REVISOR';
  $('#avisos-panel').replaceChildren(...(sesion.avisos || []).map((a) => el('p', { className: 'aviso aviso--rechazado', textContent: a })));
  $('#form-login').hidden = true;
  $('#usuario').textContent = `${sesion.nombre} · ${sesion.rol === 'ADMINISTRADOR' ? 'Administrador' : 'Revisor'}`;
  $('#usuario').hidden = false;
  $('#btn-salir').hidden = false;
  $('#panel').hidden = false;
  cargarTodo();
}

$('#form-login').addEventListener('submit', async (e) => {
  e.preventDefault();
  const msg = $('#msg-login');
  $('#btn-login').disabled = true;
  try {
    const sesion = await pedir('api/admin/login', { usuario: $('#l-usuario').value.trim(), clave: $('#l-clave').value });
    $('#l-clave').value = '';
    mensaje(msg, '');
    mostrarPanel(sesion);
  } catch (err) {
    mensaje(msg, err.message, 'error');
  } finally {
    $('#btn-login').disabled = false;
  }
});

$('#btn-salir').addEventListener('click', async () => {
  await pedir('api/admin/logout', {}).catch(() => {});
  mostrarLogin();
});

// ---- Cupos y listado ----
async function cargarCupos() {
  const cupos = await pedir('api/admin/cupos');
  const suma = (k) => cupos.reduce((t, c) => t + c[k], 0);
  $('#kpis').replaceChildren(...[
    ['Cupos totales', suma('cupos')], ['Ocupados', suma('ocupados')], ['Confirmados', suma('confirmados')], ['Disponibles', suma('disponibles')],
  ].map(([t, v]) => el('div', { className: 'kpi' }, el('span', { className: 'kpi__valor', textContent: v.toLocaleString('es-CO') }), el('span', { className: 'kpi__label', textContent: t }))));
  // No se redibuja una fila mientras el usuario está editando su cupo.
  const editando = document.activeElement?.closest?.('#tabla-cupos tr')?.dataset.agencia;
  const filas = cupos.map((c) => {
    const previa = editando === c.agencia ? $('#tabla-cupos').querySelector(`tr[data-agencia="${CSS.escape(c.agencia)}"]`) : null;
    if (previa) return previa;
    const input = el('input', {
      className: 'campo campo--cupo', type: 'number', min: String(c.ocupados), step: '1', inputMode: 'numeric',
      value: c.cupos_ajustados ?? '', placeholder: String(c.cupos_excel), ariaLabel: `Cupo vigente de ${c.agencia}`,
    });
    const guardar = el('button', { type: 'button', className: 'boton boton--linea boton--mini', textContent: 'Guardar' });
    guardar.addEventListener('click', () => ajustarCupo(c.agencia, input.value.trim(), guardar));
    input.addEventListener('keydown', (e) => { if (e.key === 'Enter') guardar.click(); });
    const vigente = esAdministrador()
      ? el('div', { className: 'cupo-editor' }, input, guardar, c.cupos_ajustados != null ? el('span', { className: 'sub', textContent: 'Ajustado' }) : null)
      : el('span', { textContent: `${c.cupos}${c.cupos_ajustados != null ? ' (ajustado)' : ''}` });
    const tr = el('tr', {},
      el('td', { textContent: c.agencia }),
      el('td', { className: 'num', textContent: c.cupos_excel }),
      el('td', {}, vigente),
      el('td', { className: 'num', textContent: c.ocupados }),
      el('td', { className: 'num', textContent: c.confirmados }),
      el('td', { className: `num${c.disponibles <= 0 ? ' agotado' : ''}`, textContent: c.disponibles }),
      el('td', { className: 'num', textContent: c.pendientes }),
      el('td', { className: 'num', textContent: c.invitados }));
    tr.dataset.agencia = c.agencia;
    return tr;
  });
  $('#tabla-cupos').replaceChildren(...filas);
  const select = $('#f-agencia');
  if (select.options.length === 1) select.append(...cupos.map((c) => new Option(c.agencia, c.agencia)));
}

async function cargarListado() {
  const params = new URLSearchParams({ estado: $('#f-estado').value, agencia: $('#f-agencia').value, q: $('#f-q').value.trim() });
  for (const [k, v] of [...params]) if (!v) params.delete(k);
  const filas = await pedir(`api/admin/inscripciones?${params}`);
  $('#tabla-inscripciones').replaceChildren(...filas.map((f) => {
    const tr = el('tr', { tabIndex: 0 },
      el('td', {}, el('span', { className: 'referencia', textContent: f.referencia })),
      el('td', {}, f.nombre_titular, el('span', { className: 'sub', textContent: `Doc. ${f.documento_titular}` })),
      el('td', { textContent: f.agencia }),
      el('td', { className: 'num', textContent: f.personas }),
      el('td', { className: 'num', textContent: pesos.format(f.total) }),
      el('td', {}, chipEstado(f.estado), f.alerta ? el('span', { className: 'sub alerta', textContent: '⚠ Valor no coincide' }) : null),
      el('td', { textContent: fechaHora(f.actualizada_en) }));
    tr.dataset.referencia = f.referencia;
    const abrir = () => abrirDetalle(f.id);
    tr.addEventListener('click', abrir);
    tr.addEventListener('keydown', (e) => { if (e.key === 'Enter') abrir(); });
    return tr;
  }));
  $('#conteo').textContent = filas.length ? `${filas.length} inscripción(es)${filas.length === 1000 ? ' (se muestran las 1.000 más recientes)' : ''}.` : 'No hay inscripciones con estos filtros.';
}

async function ajustarCupo(agencia, valor, boton) {
  const msg = $('#msg-cupos');
  boton.disabled = true;
  try {
    await pedir(`api/admin/cupos/${encodeURIComponent(agencia)}`, { cupos: valor === '' ? null : Number(valor) });
    document.activeElement?.blur();
    mensaje(msg, '');
    notificar({ titulo: 'Cupo actualizado', mensaje: `${agencia}: ${valor === '' ? 'vuelve al valor del Excel' : `${Number(valor).toLocaleString('es-CO')} cupos`}.` });
    await cargarCupos();
  } catch (err) {
    mensaje(msg, err.message, 'error');
  } finally {
    boton.disabled = false;
  }
}

// Refresco automático del contador (solo con la pestaña visible y el detalle cerrado).
setInterval(() => {
  if (!document.hidden && !$('#panel').hidden && !$('#detalle').open) cargarTodo();
}, 30000);

// ---- Bases de asociados y Coopetrolitos ----

async function cargarEstadoBases() {
  const estado = await pedir('api/admin/bases');
  for (const tarjeta of document.querySelectorAll('[data-base]')) {
    const e = estado[tarjeta.dataset.base];
    const u = e.ultimaCarga;
    tarjeta.querySelector('[data-estado]').textContent = `${e.total.toLocaleString('es-CO')} registros` +
      (u ? ` · última carga: ${fechaHora(u.cargada_en)} por ${u.usuario} (${u.archivo || 'sin nombre'})` : ' · sin cargas desde el panel');
  }
}

function vistaPrevia(tarjeta, resumen, archivo, contenido) {
  const vista = tarjeta.querySelector('[data-vista]');
  const etiqueta = tarjeta.dataset.base === 'asociados' ? 'asociados' : 'Coopetrolitos';
  const datos = [
    ['Archivo', archivo.name], ['Registros válidos', resumen.registros.toLocaleString('es-CO')],
    ...(resumen.activos != null ? [['Asociados activos', resumen.activos.toLocaleString('es-CO')]] : []),
    ['Filas omitidas', String(resumen.omitidas)],
  ];
  const hijos = [el('div', { className: 'ficha ficha--simple' }, ...datos.map(([k, v]) => dato(k, v)))];
  for (const a of resumen.advertencias) hijos.push(el('p', { className: 'aviso aviso--pendiente', textContent: a }));
  if (resumen.errores.length) {
    hijos.push(el('details', { className: 'base__errores' },
      el('summary', { textContent: `Ver filas omitidas (${resumen.omitidas}${resumen.omitidas > resumen.errores.length ? `, se muestran ${resumen.errores.length}` : ''})` }),
      el('ul', {}, ...resumen.errores.map((e) => el('li', { textContent: e })))));
  }
  if (resumen.porAgencia?.length) {
    hijos.push(el('details', { className: 'base__errores' }, el('summary', { textContent: 'Ver asociados por agencia' }),
      el('ul', {}, ...resumen.porAgencia.map(([a, n]) => el('li', { textContent: `${a}: ${n.toLocaleString('es-CO')}` })))));
  }
  const reemplazar = el('button', { type: 'button', className: 'boton boton--primario', textContent: `Reemplazar base de ${etiqueta}` });
  const descartar = el('button', { type: 'button', className: 'boton boton--linea', textContent: 'Descartar' });
  hijos.push(el('div', { className: 'acciones acciones--fila' }, reemplazar, descartar));
  vista.replaceChildren(...hijos);
  vista.hidden = false;

  const limpiar = () => { vista.hidden = true; vista.replaceChildren(); tarjeta.querySelector('input[type=file]').value = ''; };
  descartar.addEventListener('click', limpiar);
  reemplazar.addEventListener('click', async () => {
    const ok = await confirmar({
      titulo: `¿Reemplazar la base de ${etiqueta}?`,
      mensaje: `Los registros actuales se reemplazarán por los ${resumen.registros.toLocaleString('es-CO')} del archivo. Las inscripciones existentes no se modifican.`,
      detalles: datos,
      aceptar: 'Sí, reemplazar',
      cancelar: 'Volver',
      tono: 'aviso',
    });
    if (!ok) return;
    const msg = tarjeta.querySelector('[data-mensaje]');
    reemplazar.disabled = true;
    mensaje(msg, 'Guardando…');
    try {
      const r = await pedir(`api/admin/bases/${tarjeta.dataset.base}`, { nombre: archivo.name, contenido, confirmar: true });
      limpiar();
      mensaje(msg, '');
      notificar({ titulo: `Base de ${etiqueta} actualizada`, mensaje: `${r.registros.toLocaleString('es-CO')} registros cargados.` });
      cargarEstadoBases();
    } catch (err) {
      mensaje(msg, err.message, 'error');
      notificar({ titulo: 'No se pudo reemplazar la base', mensaje: err.message, tono: 'error' });
    } finally {
      reemplazar.disabled = false;
    }
  });
}

async function analizarBase(tarjeta, archivo) {
  const msg = tarjeta.querySelector('[data-mensaje]');
  tarjeta.querySelector('[data-vista]').hidden = true;
  if (archivo.size > 20 * 1024 * 1024) return mensaje(msg, 'El archivo supera 20 MB.', 'error');
  mensaje(msg, 'Validando archivo…');
  try {
    const contenido = await leerBase64(archivo);
    const resumen = await pedir(`api/admin/bases/${tarjeta.dataset.base}`, { nombre: archivo.name, contenido, confirmar: false });
    mensaje(msg, 'Revise la vista previa y confirme para reemplazar la base.');
    vistaPrevia(tarjeta, resumen, archivo, contenido);
  } catch (err) {
    tarjeta.querySelector('input[type=file]').value = '';
    mensaje(msg, err.message, 'error');
  }
}

for (const tarjeta of document.querySelectorAll('[data-base]')) {
  const input = tarjeta.querySelector('input[type=file]');
  const zona = tarjeta.querySelector('[data-zona]');
  input.addEventListener('change', () => { if (input.files[0]) analizarBase(tarjeta, input.files[0]); });
  for (const evt of ['dragenter', 'dragover']) zona.addEventListener(evt, (e) => { e.preventDefault(); zona.classList.add('zona-archivo--arrastre'); });
  for (const evt of ['dragleave', 'drop']) {
    zona.addEventListener(evt, (e) => {
      if (evt === 'dragleave' && zona.contains(e.relatedTarget)) return;
      zona.classList.remove('zona-archivo--arrastre');
    });
  }
  zona.addEventListener('drop', (e) => {
    e.preventDefault();
    if (e.dataTransfer.files[0]) analizarBase(tarjeta, e.dataTransfer.files[0]);
  });
}
for (const evt of ['dragover', 'drop']) window.addEventListener(evt, (e) => { if (!e.target.closest?.('[data-zona]')) e.preventDefault(); });

// El estado de las bases solo lo consulta el administrador (para el revisor el servidor respondería 403).
const cargarTodo = () => Promise.all([cargarCupos(), cargarListado(), esAdministrador() ? cargarEstadoBases() : null]).catch(() => {});
$('#form-filtros').addEventListener('submit', (e) => { e.preventDefault(); cargarListado().catch(() => {}); });
for (const id of ['#f-estado', '#f-agencia']) $(id).addEventListener('change', () => cargarListado().catch(() => {}));

// ---- Detalle y revisión ----
function dato(etiqueta, valor) {
  return el('div', {}, el('span', { className: 'ficha__label', textContent: etiqueta }), el('strong', { textContent: valor ?? '—' }));
}

function mostrarDetalle(d) {
  actual = d;
  $('#d-referencia').textContent = d.referencia;
  $('#d-estado').replaceWith(Object.assign(chipEstado(d.estado), { id: 'd-estado' }));
  $('#d-ficha').replaceChildren(
    dato('Titular', d.nombre_titular), dato('Documento', d.documento_titular), dato('Evento (agencia)', d.agencia),
    dato('Agencia del asociado', d.agencia_asociado),
    dato('Total', pesos.format(d.total)), dato('Creada', fechaHora(d.creada_en)),
    dato('Revisado por', d.revisado_por ? `${d.revisado_por} · ${fechaHora(d.revisado_en)}` : null),
    dato('Motivo', d.motivo),
    dato('Autorización de datos', d.autorizacion_en ? `v${d.autorizacion_version} · ${fechaHora(d.autorizacion_en)}` : null),
    dato('Uso de imagen', d.autorizacion_imagen ? 'Autorizado' : 'No autorizado'),
  );
  const alerta = d.soportes[0]?.alerta;
  $('#d-alerta').hidden = !alerta;
  $('#d-alerta').textContent = alerta ? `Atención: ${alerta.replace(/(\d+)/g, (n) => pesos.format(Number(n)))}` : '';
  $('#d-personas').replaceChildren(...d.personas.map((p) => el('tr', {},
    el('td', { textContent: p.nombre }), el('td', { textContent: p.documento }),
    el('td', {}, el('span', { className: `chip chip--${p.tipo.toLowerCase()}`, textContent: ETIQUETA_TIPO[p.tipo] || p.tipo })),
    el('td', { className: 'num', textContent: pesos.format(p.valor) }))));

  $('#d-soportes').replaceChildren(...(d.soportes.length ? d.soportes.map((s, i) => {
    const url = `api/admin/soportes/${s.id}`;
    const enAgencia = s.medio_pago === 'AGENCIA';
    const vista = !s.tiene_archivo ? null : s.tipo_archivo === 'application/pdf'
      ? el('iframe', { src: url, title: 'Soporte PDF', className: 'soporte__vista' })
      : el('img', { src: url, alt: 'Soporte de pago', className: 'soporte__vista' });
    const extras = Object.entries(s.campos || {}).map(([k, v]) => dato(etiquetas[k] || k, v));
    const datosMedio = enAgencia
      ? [dato('Medio de pago', 'En agencia / efectivo'), dato('Agencia de pago', s.agencia_pago), dato('Recibo de caja', s.recibo || 'No indicado')]
      : [dato('Medio de pago', 'PSE'), dato('CUS', s.cus), s.banco ? dato('Banco', s.banco) : null];
    return el('div', { className: `soporte${i ? ' soporte--anterior' : ''}` },
      enAgencia && i === 0 ? el('p', { className: 'aviso aviso--pendiente',
        textContent: 'Pago registrado en agencia: verifique con caja o tesorería de la agencia antes de aprobar.' }) : null,
      el('div', { className: 'ficha ficha--simple' },
        ...datosMedio, dato('Fecha pago', fechaCorta(s.fecha_pago)),
        dato('Valor pagado', pesos.format(s.valor_pagado)), dato('Cargado', fechaHora(s.cargado_en)), ...extras),
      i === 0 ? vista : null,
      s.tiene_archivo
        ? el('a', { href: url, target: '_blank', rel: 'noopener', className: 'enlace', textContent: `Abrir ${s.nombre_original || 'soporte'} en otra pestaña` })
        : el('p', { className: 'nota', textContent: 'Sin comprobante adjunto.' }),
      i === 1 ? el('p', { className: 'nota', textContent: 'Soportes anteriores (rechazados):' }) : null);
  }) : [el('p', { className: 'nota', textContent: 'Aún no se ha cargado soporte de pago.' })]));

  for (const boton of document.querySelectorAll('[data-accion]')) {
    boton.hidden = !PERMITIDAS[boton.dataset.accion].includes(d.estado) || (boton.dataset.accion === 'ANULAR' && !esAdministrador());
  }
  $('#d-revision').hidden = ![...document.querySelectorAll('[data-accion]')].some((b) => !b.hidden);
  $('#d-motivo').value = '';
  mensaje($('#msg-revision'), '');
}

async function abrirDetalle(id) {
  try {
    mostrarDetalle(await pedir(`api/admin/inscripciones/${id}`));
    $('#detalle').showModal();
  } catch (err) {
    avisar({ titulo: 'No fue posible abrir la inscripción', mensaje: err.message, tono: 'aviso' });
  }
}

$('#btn-cerrar').addEventListener('click', () => $('#detalle').close());
$('#detalle').addEventListener('click', (e) => { if (e.target === $('#detalle')) $('#detalle').close(); });

const RESULTADO_REVISION = {
  APROBAR: { titulo: 'Pago aprobado', mensaje: 'La inscripción quedó confirmada.', tono: 'exito' },
  RECHAZAR: { titulo: 'Soporte rechazado', mensaje: 'El asociado verá el motivo y podrá cargar un nuevo soporte.', tono: 'aviso' },
  ANULAR: { titulo: 'Inscripción anulada', mensaje: 'Sus cupos quedaron liberados.', tono: 'info' },
};

function resaltarFila(referencia) {
  const fila = [...document.querySelectorAll('#tabla-inscripciones tr')].find((tr) => tr.dataset.referencia === referencia);
  if (!fila) return;
  fila.classList.remove('fila--resaltada');
  void fila.offsetWidth; // reinicia la animación
  fila.classList.add('fila--resaltada');
  fila.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
}

for (const boton of document.querySelectorAll('[data-accion]')) {
  boton.addEventListener('click', async () => {
    const accion = boton.dataset.accion;
    const textos = {
      APROBAR: { titulo: '¿Aprobar el pago?', mensaje: 'Confirma que el pago fue verificado. La inscripción quedará confirmada.', aceptar: 'Sí, aprobar', tono: 'normal' },
      RECHAZAR: { titulo: '¿Rechazar el soporte?', mensaje: 'El asociado verá el motivo y podrá cargar un nuevo soporte.', aceptar: 'Sí, rechazar', tono: 'aviso' },
      ANULAR: { titulo: '¿Anular la inscripción?', mensaje: 'Se liberarán sus cupos. Esta acción no se puede deshacer.', aceptar: 'Sí, anular', tono: 'peligro' },
    }[accion];
    const motivo = $('#d-motivo').value.trim();
    const detalles = [['Referencia', actual.referencia], ['Titular', actual.nombre_titular], ['Total', pesos.format(actual.total)]];
    if (motivo) detalles.push(['Motivo', motivo]);
    if (!await confirmar({ ...textos, detalles, cancelar: 'Volver' })) return;
    boton.disabled = true;
    try {
      const r = await pedir(`api/admin/inscripciones/${actual.id}/revision`, { accion, motivo: $('#d-motivo').value });
      // Confirmada la acción: se cierra el detalle y se vuelve al listado de inscripciones actualizado.
      $('#detalle').close();
      mostrarVista('inscripciones');
      notificar({ ...RESULTADO_REVISION[accion], mensaje: `${r.referencia} · ${r.nombre_titular}. ${RESULTADO_REVISION[accion].mensaje}` });
      await cargarTodo();
      resaltarFila(r.referencia);
    } catch (err) {
      mensaje($('#msg-revision'), err.message, 'error'); // el detalle sigue abierto para corregir (p. ej. el motivo)
    } finally {
      boton.disabled = false;
    }
  });
}

pedir('api/evento').then((e) => {
  etiquetas = Object.fromEntries((e.camposSoporte || []).map((c) => [c.id, c.etiqueta]));
  // Agencias y puntos de atención de los asociados (en eventos compartidos, por separado).
  agenciasEvento = e.agencias?.length ? e.agencias.map((a) => a.agencia) : (e.tarifas || []).map((t) => t.agencia);
}).catch(() => {});

// ============ Pestañas del panel ============
function mostrarVista(vista) {
  for (const tab of document.querySelectorAll('.pestanas-admin [data-vista]')) tab.setAttribute('aria-selected', tab.dataset.vista === vista);
  for (const panel of document.querySelectorAll('#panel [data-panel]')) panel.hidden = panel.dataset.panel !== vista;
  if (ENTIDADES[vista]) cargarEntidad(vista);
  if (vista === 'usuarios') cargarAuditoria();
}
for (const tab of document.querySelectorAll('.pestanas-admin [data-vista]')) tab.addEventListener('click', () => mostrarVista(tab.dataset.vista));

// ============ Gestión manual: asociados, Coopetrolitos y usuarios ============
const ENTIDADES = {
  asociados: {
    ruta: 'api/admin/asociados', clave: 'documento', singular: 'asociado',
    columnas: [
      ['documento', 'Documento'], ['nombre', 'Nombre'], ['agencia', 'Agencia'],
      ['estado', 'Estado', (v) => el('span', { className: `estado estado--${v === 'ACTIVO' ? 'confirmado' : 'inactivo'}`, textContent: v === 'ACTIVO' ? 'Activo' : 'Inactivo' })],
      ['fecha_actualizacion', 'Datos actualizados', fechaCorta],
      ['fecha_nacimiento', 'Nacimiento', fechaCorta],
      ['expedicion_registrada', 'Fecha expedición', (v) => (v ? 'Registrada' : el('span', { className: 'alerta', textContent: 'Falta' }))],
    ],
    campos: () => [
      { id: 'documento', etiqueta: 'Número de documento', tipo: 'text', requerido: true, soloCrear: true, validar: 'numerico', max: 15 },
      { id: 'nombre', etiqueta: 'Nombres y apellidos', tipo: 'text', requerido: true, validar: 'texto', max: 100 },
      { id: 'agencia', etiqueta: 'Agencia', tipo: 'select', opciones: agenciasEvento.map((a) => [a, a]), requerido: true },
      { id: 'estado', etiqueta: 'Estado', tipo: 'select', opciones: [['ACTIVO', 'Activo'], ['INACTIVO', 'Inactivo']], requerido: true },
      { id: 'fechaActualizacion', desde: 'fecha_actualizacion', etiqueta: 'Última actualización de datos', tipo: 'date' },
      { id: 'fechaNacimiento', desde: 'fecha_nacimiento', etiqueta: 'Fecha de nacimiento', tipo: 'date' },
      { id: 'fechaExpedicion', etiqueta: 'Fecha de expedición del documento', tipo: 'date', requeridoAlCrear: true,
        ayudaEditar: 'Por seguridad no se muestra. Déjela vacía para conservar la registrada o escriba una nueva para reemplazarla.' },
    ],
  },
  coopetrolitos: {
    ruta: 'api/admin/coopetrolitos', clave: 'documento', singular: 'Coopetrolito',
    columnas: [['documento', 'Documento'], ['nombre', 'Nombre'], ['fecha_nacimiento', 'Nacimiento', fechaCorta],
      ['documento_asociado', 'Cédula asociado'], ['nombre_asociado', 'Asociado']],
    campos: () => [
      { id: 'documento', etiqueta: 'Número de documento (TI o registro civil)', tipo: 'text', requerido: true, soloCrear: true, validar: 'numerico', max: 15 },
      { id: 'nombre', etiqueta: 'Nombres y apellidos', tipo: 'text', requerido: true, validar: 'texto', max: 100 },
      { id: 'documentoAsociado', desde: 'documento_asociado', etiqueta: 'Cédula del asociado (padre, madre o acudiente)', tipo: 'text', requerido: true, validar: 'numerico', max: 15 },
      { id: 'fechaNacimiento', desde: 'fecha_nacimiento', etiqueta: 'Fecha de nacimiento', tipo: 'date' },
    ],
  },
  usuarios: {
    ruta: 'api/admin/usuarios', clave: 'usuario', singular: 'usuario', sinPaginas: true,
    columnas: [['usuario', 'Usuario'], ['nombre', 'Nombre'],
      ['rol', 'Rol', (v) => el('span', { className: `estado estado--${v === 'ADMINISTRADOR' ? 'confirmado' : 'revision'}`, textContent: v === 'ADMINISTRADOR' ? 'Administrador' : 'Revisor' })],
      ['actualizado_en', 'Último cambio', fechaHora]],
    campos: () => [
      { id: 'usuario', etiqueta: 'Usuario (3 a 40 letras, números, . _ -)', tipo: 'text', requerido: true, soloCrear: true, autocomplete: 'off', validar: 'usuario', max: 40 },
      { id: 'nombre', etiqueta: 'Nombre completo', tipo: 'text', requerido: true, validar: 'texto', max: 100 },
      { id: 'rol', etiqueta: 'Rol', tipo: 'select', requerido: true,
        opciones: [['REVISOR', 'Revisor: consulta, aprueba o rechaza pagos y exporta'], ['ADMINISTRADOR', 'Administrador: todo el panel']] },
      { id: 'clave', etiqueta: 'Contraseña (mínimo 10 caracteres)', tipo: 'password', requeridoAlCrear: true, autocomplete: 'new-password',
        ayudaEditar: 'Déjela vacía para no cambiarla. Si la cambia, se cierran las sesiones abiertas de ese usuario.' },
      { id: 'clave2', etiqueta: 'Repita la contraseña', tipo: 'password', requeridoAlCrear: true, autocomplete: 'new-password', noEnviar: true },
    ],
  },
};
const paginaActual = {};
const busquedaActual = {};

async function cargarEntidad(tipo, pagina = paginaActual[tipo] || 1) {
  const e = ENTIDADES[tipo];
  const msg = document.querySelector(`[data-mensaje-crud="${tipo}"]`);
  const params = new URLSearchParams({ pagina });
  if (busquedaActual[tipo]) params.set('q', busquedaActual[tipo]);
  try {
    let datos = await pedir(`${e.ruta}?${params}`);
    if (Array.isArray(datos)) { // usuarios: sin paginación, filtro en el navegador
      const q = (busquedaActual[tipo] || '').toLowerCase();
      const filas = datos.filter((u) => !q || `${u.usuario} ${u.nombre}`.toLowerCase().includes(q));
      datos = { filas, total: filas.length, pagina: 1, paginas: 1 };
    }
    paginaActual[tipo] = datos.pagina;
    document.querySelector(`[data-cabecera="${tipo}"]`).replaceChildren(el('tr', {},
      ...e.columnas.map(([, t]) => el('th', { textContent: t })), el('th', { className: 'num', textContent: 'Acciones' })));
    document.querySelector(`[data-filas="${tipo}"]`).replaceChildren(...(datos.filas.length ? datos.filas.map((f) => {
      const editar = el('button', { type: 'button', className: 'boton boton--linea boton--mini', textContent: 'Editar' });
      const eliminar = el('button', { type: 'button', className: 'boton boton--peligro boton--mini', textContent: 'Eliminar' });
      editar.addEventListener('click', () => abrirFormulario(tipo, f));
      eliminar.addEventListener('click', () => eliminarRegistro(tipo, f));
      const propio = tipo === 'usuarios' && f.usuario === sesionActual?.usuario;
      return el('tr', {},
        ...e.columnas.map(([k, , fmt]) => el('td', {}, fmt ? fmt(f[k]) : (f[k] ?? '—'))),
        el('td', { className: 'num' }, el('div', { className: 'crud__acciones' }, editar, propio ? el('span', { className: 'sub', textContent: '(usted)' }) : eliminar)));
    }) : [el('tr', {}, el('td', { colSpan: e.columnas.length + 1, className: 'nota', textContent: 'No hay registros.' }))]));
    document.querySelector(`[data-conteo="${tipo}"]`).textContent = `${datos.total.toLocaleString('es-CO')} registro(s)`;
    const paginas = document.querySelector(`[data-paginas="${tipo}"]`);
    paginas.replaceChildren();
    if (datos.paginas > 1) {
      const ir = (p, texto, deshabilitado) => {
        const b = el('button', { type: 'button', className: 'boton boton--linea boton--mini', textContent: texto, disabled: deshabilitado });
        b.addEventListener('click', () => cargarEntidad(tipo, p));
        return b;
      };
      paginas.append(ir(datos.pagina - 1, '‹ Anterior', datos.pagina <= 1),
        el('span', { className: 'nota', textContent: ` Página ${datos.pagina} de ${datos.paginas} ` }),
        ir(datos.pagina + 1, 'Siguiente ›', datos.pagina >= datos.paginas));
    }
  } catch (err) {
    mensaje(msg, err.message, 'error');
  }
}

for (const form of document.querySelectorAll('[data-buscar]')) {
  form.addEventListener('submit', (ev) => {
    ev.preventDefault();
    busquedaActual[form.dataset.buscar] = form.querySelector('input').value.trim();
    cargarEntidad(form.dataset.buscar, 1);
  });
}
for (const b of document.querySelectorAll('[data-nuevo]')) b.addEventListener('click', () => abrirFormulario(b.dataset.nuevo));

let formularioActual = null;
function abrirFormulario(tipo, registro = null) {
  const e = ENTIDADES[tipo];
  formularioActual = { tipo, registro };
  $('#dlg-titulo').textContent = registro ? `Editar ${e.singular}` : `Nuevo ${e.singular}`;
  mensaje($('#dlg-mensaje'), '');
  $('#dlg-campos').replaceChildren(...e.campos().map((c) => {
    const valor = registro ? (registro[c.desde || c.id] ?? '')
      : ({ estado: 'ACTIVO', rol: 'REVISOR' }[c.id] ?? '');
    let control;
    if (c.tipo === 'select') {
      control = el('select', { className: 'campo' }, ...(c.id === 'agencia' ? [new Option('Seleccione…', '')] : []),
        ...c.opciones.map(([v, t]) => new Option(t, v)));
      control.value = valor;
    } else {
      control = el('input', { className: 'campo', type: c.tipo, value: c.tipo === 'password' ? '' : valor });
      if (c.inputMode) control.inputMode = c.inputMode;
      if (c.validar) control.dataset.validar = c.validar;
      if (c.max) control.maxLength = c.max;
      if (c.autocomplete) control.autocomplete = c.autocomplete;
    }
    Object.assign(control, { id: `dlg-${c.id}`, required: !!(c.requerido || (c.requeridoAlCrear && !registro)) });
    control.dataset.campo = c.id;
    if (c.soloCrear && registro) { control.readOnly = true; control.classList.add('campo--solo-lectura'); }
    const ayuda = registro ? c.ayudaEditar : null;
    return el('div', { className: 'campo-adicional' },
      el('label', { className: 'campo__label', htmlFor: `dlg-${c.id}`, textContent: `${c.etiqueta}${control.required ? ' *' : ''}` }),
      control, ayuda ? el('small', { className: 'campo__ayuda', textContent: ayuda }) : null);
  }));
  $('#dlg-registro').showModal();
  $('#dlg-campos').querySelector('input:not([readonly]), select')?.focus();
}

$('#dlg-cancelar').addEventListener('click', () => $('#dlg-registro').close());
$('#form-registro').addEventListener('submit', async (ev) => {
  ev.preventDefault();
  const { tipo, registro } = formularioActual;
  const e = ENTIDADES[tipo];
  const msg = $('#dlg-mensaje');
  const controles = [...$('#dlg-campos').querySelectorAll('[data-campo]')];
  for (const c of controles) c.toggleAttribute('aria-invalid', c.required && !c.value.trim());
  const vacio = controles.find((c) => c.hasAttribute('aria-invalid'));
  if (vacio) { vacio.focus(); return mensaje(msg, 'Complete los campos obligatorios (*).', 'error'); }
  const valores = Object.fromEntries(controles.map((c) => [c.dataset.campo, c.value.trim()]));
  if ('clave2' in valores && valores.clave !== valores.clave2) {
    $('#dlg-clave2').setAttribute('aria-invalid', '');
    return mensaje(msg, 'Las contraseñas no coinciden.', 'error');
  }
  const cuerpo = Object.fromEntries(e.campos().filter((c) => !c.noEnviar).map((c) => [c.id, valores[c.id]]));
  $('#dlg-guardar').disabled = true;
  mensaje(msg, 'Guardando…');
  try {
    if (registro) await pedir(`${e.ruta}/${encodeURIComponent(registro[e.clave])}`, cuerpo, 'PUT');
    else await pedir(e.ruta, cuerpo, 'POST');
    $('#dlg-registro').close();
    mensaje(document.querySelector(`[data-mensaje-crud="${tipo}"]`), '');
    notificar({
      titulo: `${e.singular[0].toUpperCase()}${e.singular.slice(1)} ${registro ? 'actualizado' : 'creado'}`,
      mensaje: `Registro ${registro?.[e.clave] ?? cuerpo[e.clave] ?? ''} guardado correctamente.`,
    });
    cargarEntidad(tipo);
    if (tipo === 'usuarios') cargarAuditoria();
  } catch (err) {
    mensaje(msg, err.message, 'error');
  } finally {
    $('#dlg-guardar').disabled = false;
  }
});

async function eliminarRegistro(tipo, registro) {
  const e = ENTIDADES[tipo];
  const clave = registro[e.clave];
  const ok = await confirmar({
    titulo: `¿Eliminar ${e.singular}?`,
    mensaje: tipo === 'asociados'
      ? 'Si el asociado tiene una inscripción activa o Coopetrolitos vinculados no se podrá eliminar; en ese caso márquelo como inactivo.'
      : 'Esta acción no se puede deshacer.',
    detalles: e.columnas.slice(0, 2).map(([k, t]) => [t, String(registro[k] ?? '—')]),
    aceptar: 'Sí, eliminar', cancelar: 'Volver', tono: 'peligro',
  });
  if (!ok) return;
  const msg = document.querySelector(`[data-mensaje-crud="${tipo}"]`);
  try {
    await pedir(`${e.ruta}/${encodeURIComponent(clave)}`, undefined, 'DELETE');
    mensaje(msg, '');
    notificar({ titulo: `${e.singular[0].toUpperCase()}${e.singular.slice(1)} eliminado`, mensaje: `Registro ${clave} eliminado.` });
    cargarEntidad(tipo);
    if (tipo === 'usuarios') cargarAuditoria();
  } catch (err) {
    mensaje(msg, '');
    notificar({ titulo: `No se pudo eliminar ${e.singular}`, mensaje: err.message, tono: 'error', duracion: 8000 });
  }
}

const ACCIONES = { CREAR: 'Creó', EDITAR: 'Editó', ELIMINAR: 'Eliminó' };
async function cargarAuditoria() {
  try {
    const filas = await pedir('api/admin/auditoria');
    $('#tabla-auditoria').replaceChildren(...(filas.length ? filas.map((a) => el('tr', {},
      el('td', { textContent: fechaHora(a.fecha) }), el('td', { textContent: a.usuario }),
      el('td', { textContent: `${ACCIONES[a.accion] || a.accion} ${a.entidad}` }), el('td', { textContent: a.clave }),
      el('td', { textContent: a.detalle || '' })))
      : [el('tr', {}, el('td', { colSpan: 5, className: 'nota', textContent: 'Sin cambios registrados.' }))]));
  } catch {}
}
pedir('api/admin/sesion').then(mostrarPanel).catch(mostrarLogin);
