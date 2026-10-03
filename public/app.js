// Página del asociado. Dos módulos de inscripción:
//   PSE      simular -> preinscribirse -> pagar en línea -> registrar el CUS (desde la confirmación o "Consulte su inscripción")
//   AGENCIA  simular -> registrar el pago hecho en la agencia en el mismo paso (crea la inscripción en revisión)
// y "Consulte su inscripción" para ver el estado, registrar el pago, modificar o cancelar.
import {
  $, pesos, fechaCorta, ETIQUETA_TIPO, mensaje, solicitar as pedir, leerBase64,
} from './comun.js';
import { confirmar } from './dialogo.js';
import './validacion.js';

const ESTADOS = {
  PREINSCRITO: { texto: 'Preinscrito · pendiente de pago', clase: 'pendiente' },
  EN_REVISION: { texto: 'Soporte en revisión', clase: 'revision' },
  RECHAZADO: { texto: 'Soporte rechazado', clase: 'rechazado' },
  CONFIRMADO: { texto: 'Inscripción confirmada', clase: 'confirmado' },
  CANCELADO: { texto: 'Cancelada', clase: 'inactivo' },
  ANULADO: { texto: 'Anulada', clase: 'inactivo' },
};

const form = $('#formulario');
let evento = { maxAcompanantes: 5, camposSoporte: [], soporteMaxMb: 5 };
let identidad = null;    // { documento, fechaExpedicion } validados en el paso 1
let simulacion = null;   // último resultado de /api/simular
let consulta = null;     // { referencia, documento, fechaExpedicion } de "Mi inscripción"
let inscripcion = null;  // última inscripción consultada
let cupos = {};          // contador de cupos por agencia: { AGENCIA: { cupos, disponibles } }
let agenciaPropia = null;
let modulo = null;        // módulo elegido en la portada: 'PSE' | 'AGENCIA'
let soporteEn = 'consulta'; // dónde está el formulario de pago: 'consulta' o 'inscripcion' (módulo agencia)

// Deshabilita el botón mientras corre la acción y muestra el error en `msg`.
async function conBoton(boton, msg, textoEspera, accion) {
  boton.disabled = true;
  mensaje(msg, textoEspera);
  try {
    await accion();
  } catch (err) {
    mensaje(msg, err.message, 'error');
  } finally {
    boton.disabled = false;
  }
}

// El documento se recuerda solo durante la sesión del navegador (sessionStorage): en equipos compartidos
// (p. ej. en las agencias) no queda guardado al cerrar el navegador. Se borra lo que guardaban versiones anteriores.
try { localStorage.removeItem('ultima-inscripcion'); } catch {}
const recordar = (datos) => { try { sessionStorage.setItem('ultima-inscripcion', JSON.stringify(datos)); } catch {} };
const recordado = () => { try { return JSON.parse(sessionStorage.getItem('ultima-inscripcion')) || {}; } catch { return {}; } };

// Datos que acompañan al botón "Pagar por PSE" (valor y referencia a registrar en el pago).
function prepararPago(referencia, total) {
  for (const el of document.querySelectorAll('[data-pse-total]')) el.textContent = pesos.format(total);
  for (const el of document.querySelectorAll('[data-pse-referencia]')) el.textContent = referencia;
}

for (const boton of document.querySelectorAll('[data-copiar-referencia]')) {
  boton.addEventListener('click', async () => {
    const referencia = boton.closest('.pago-pse').querySelector('[data-pse-referencia]').textContent;
    try {
      await navigator.clipboard.writeText(referencia);
      boton.textContent = '¡Copiada!';
    } catch {
      boton.textContent = referencia;
    }
    setTimeout(() => { boton.textContent = 'Copiar referencia'; }, 2000);
  });
}

// Al corregir un campo marcado como inválido, se quita la marca.
document.addEventListener('input', (e) => { if (e.target.matches?.('.campo')) e.target.removeAttribute('aria-invalid'); });

// ---- Módulos de la portada y vistas ----
const MODULOS = {
  PSE: {
    titulo: 'Inscripción y pago por PSE',
    desc: 'Valide su identidad, simule el valor e inscríbase. Luego pague en línea por PSE y registre el número CUS de su transacción.',
  },
  AGENCIA: {
    titulo: 'Inscripción y pago en agencia',
    desc: 'Valide su identidad, simule el valor y registre el pago que realizó en la agencia. Su inscripción se crea al enviar el pago.',
  },
};

function mostrarVista(id) {
  for (const vista of ['#vista-inicio', '#vista-inscribir', '#vista-mi']) $(vista).hidden = vista !== id;
  for (const boton of document.querySelectorAll('[data-modulo]')) {
    boton.setAttribute('aria-pressed', String(id === '#vista-inscribir' && boton.dataset.modulo === modulo));
  }
  $('#btn-ir-consulta').classList.toggle('enlace-consulta--activo', id === '#vista-mi');
}

function elegirModulo(codigo) {
  if (!MODULOS[codigo]) return;
  modulo = codigo;
  document.body.dataset.modulo = codigo; // muestra los textos [data-solo-modulo] del módulo
  $('#titulo-modulo').textContent = MODULOS[codigo].titulo;
  $('#desc-modulo').textContent = MODULOS[codigo].desc;
  $('#btn-preinscribir').hidden = codigo === 'AGENCIA';
  ocultarResultados();
  mostrarVista('#vista-inscribir');
  $('#vista-inscribir').scrollIntoView({ behavior: 'smooth', block: 'start' });
}
for (const boton of document.querySelectorAll('[data-modulo]')) boton.addEventListener('click', () => elegirModulo(boton.dataset.modulo));

function irAConsulta() {
  ubicarSoporte('consulta');
  mostrarVista('#vista-mi');
  $('#vista-mi').scrollIntoView({ behavior: 'smooth', block: 'start' });
}
$('#btn-ir-consulta').addEventListener('click', irAConsulta);

// ---- Lista de acompañantes (reutilizada en inscripción y modificación) ----
function crearListaAcompanantes(contenedor, botonAgregar, alVaciar) {
  const renumerar = () => {
    [...contenedor.children].forEach((fila, i) => { fila.querySelector('.acompanante__num').textContent = i + 1; });
    botonAgregar.hidden = contenedor.children.length >= evento.maxAcompanantes;
  };
  const agregar = ({ documento = '', nombre = '', tipo = 'NO_ASOCIADO' } = {}, enfocar = true) => {
    if (contenedor.children.length >= evento.maxAcompanantes) return;
    const fila = $('#tpl-acompanante').content.firstElementChild.cloneNode(true);
    fila.querySelector('[name="acomp-documento"]').value = documento;
    fila.querySelector('[name="acomp-nombre"]').value = nombre;
    fila.querySelector('[name="acomp-tipo"]').value = tipo === 'COOPETROLITO' ? 'COOPETROLITO' : 'NO_ASOCIADO';
    fila.querySelector('.boton-icono').addEventListener('click', () => {
      fila.remove();
      renumerar();
      if (!contenedor.children.length) alVaciar?.();
    });
    contenedor.append(fila);
    renumerar();
    if (enfocar) fila.querySelector('input').focus();
  };
  botonAgregar.addEventListener('click', () => agregar());
  return {
    agregar,
    vaciar: () => { contenedor.replaceChildren(); renumerar(); },
    get cantidad() { return contenedor.children.length; },
    // Devuelve los acompañantes o lanza un error si falta algún documento o nombre.
    leer() {
      const datos = [...contenedor.children].map((fila) => {
        const doc = fila.querySelector('[name="acomp-documento"]');
        const nombre = fila.querySelector('[name="acomp-nombre"]');
        doc.toggleAttribute('aria-invalid', !/^\d{4,15}$/.test(doc.value.replace(/[\s.,-]/g, '')));
        nombre.toggleAttribute('aria-invalid', nombre.value.trim().split(/\s+/).filter((p) => p.length >= 2).length < 2);
        return { documento: doc.value.trim(), nombre: nombre.value.trim(), tipo: fila.querySelector('[name="acomp-tipo"]').value };
      });
      const vacio = contenedor.querySelector('[aria-invalid]');
      if (vacio) {
        vacio.focus();
        throw new Error('Cada acompañante debe tener número de documento (solo números) y nombres y apellidos completos.');
      }
      return datos;
    },
  };
}

// ============ Vista 1: simular y preinscribirse ============
function bloquearPasos(bloquear) {
  for (const id of ['#paso-evento', '#paso-modalidad', '#paso-calcular']) $(id).toggleAttribute('data-bloqueado', bloquear);
}

function ocultarResultados() {
  $('#resultado').hidden = true;
  $('#confirmacion').hidden = true;
  if (soporteEn === 'inscripcion') $('#form-soporte').hidden = true;
  simulacion = null;
}

async function validarAsociado() {
  const documento = $('#documento').value.trim();
  const fechaExpedicion = $('#expedicion').value;
  const msg = $('#msg-asociado');
  ocultarResultados();
  $('#documento').toggleAttribute('aria-invalid', !documento);
  $('#expedicion').toggleAttribute('aria-invalid', !fechaExpedicion);
  if (!documento || !fechaExpedicion) return mensaje(msg, 'Ingrese su documento y la fecha de expedición.', 'error');
  if (!$('#acepta-datos').checked) {
    $('#acepta-datos').focus();
    return mensaje(msg, 'Debe aceptar la autorización para el tratamiento de datos personales.', 'error');
  }

  await conBoton($('#btn-validar'), msg, 'Validando…', async () => {
    try {
      const datos = await pedir('api/identificar', { documento, fechaExpedicion, autorizaDatos: true });
      identidad = { documento, fechaExpedicion };
      $('#f-nombre').textContent = datos.nombre;
      $('#f-agencia').textContent = datos.agencia;
      $('#f-actualizacion').textContent = fechaCorta(datos.fechaActualizacion);
      agenciaPropia = datos.agenciaEvento || datos.agencia; // en un evento compartido, el nombre del evento
      $('#agencia-evento').value = agenciaPropia;
      mostrarEvento();
      $('#ficha-asociado').hidden = false;
      mensaje(msg, 'Asociado validado correctamente.', 'ok');
      bloquearPasos(false);
    } catch (err) {
      identidad = null;
      $('#ficha-asociado').hidden = true;
      bloquearPasos(true);
      throw err;
    }
  });
}

$('#btn-validar').addEventListener('click', validarAsociado);
for (const id of ['#documento', '#expedicion', '#acepta-datos']) {
  $(id).addEventListener('keydown', (e) => { if (e.key === 'Enter') { e.preventDefault(); validarAsociado(); } });
  $(id).addEventListener(id === '#acepta-datos' ? 'change' : 'input', () => {
    if (!identidad) return;
    identidad = null;
    $('#ficha-asociado').hidden = true;
    ocultarResultados();
    mensaje($('#msg-asociado'), '');
    bloquearPasos(true);
  });
}

const listaInscripcion = crearListaAcompanantes($('#lista-acompanantes'), $('#btn-agregar'), () => {
  form.modalidad.value = 'SOLO';
  actualizarModalidad();
});

function actualizarModalidad() {
  const acompanado = form.modalidad.value === 'ACOMPAÑADO';
  $('#bloque-acompanantes').hidden = !acompanado;
  if (acompanado && !listaInscripcion.cantidad) listaInscripcion.agregar();
  ocultarResultados();
}
for (const radio of form.modalidad) radio.addEventListener('change', actualizarModalidad);
$('#lista-acompanantes').addEventListener('input', ocultarResultados);

function fila(nombre, chip, sub, valor) {
  const tr = document.createElement('tr');
  const celda = (contenido, clase) => {
    const td = document.createElement('td');
    if (clase) td.className = clase;
    td.append(...contenido);
    tr.append(td);
  };
  const subEl = Object.assign(document.createElement('span'), { className: 'sub', textContent: sub || '' });
  const chipEl = Object.assign(document.createElement('span'), { className: `chip chip--${chip.toLowerCase()}`, textContent: ETIQUETA_TIPO[chip] || chip });
  celda([nombre, subEl]);
  celda([chipEl]);
  celda([pesos.format(valor)], 'num');
  return tr;
}

const DESCRIPCION_TIPO = {
  ASOCIADO: 'Asociado: tarifa preferencial',
  COOPETROLITO: 'Coopetrolito: tarifa de asociado',
  INVITADO: 'No asociado: tarifa invitado',
};
const subPersona = (p) => (p.tipo === 'TITULAR' ? 'Asociado titular'
  : `Doc. ${p.documento} · ${p.observacion || DESCRIPCION_TIPO[p.tipo] || ''}`);

function mostrarResultado(r) {
  $('#detalle').replaceChildren(
    fila(r.asociado.nombre, 'TITULAR', `Asociado · Evento ${r.agenciaEvento}`, r.titular.valor),
    ...r.acompanantes.map((a) => fila(a.nombre, a.tipo, subPersona(a), a.valor)),
  );
  $('#total').textContent = pesos.format(r.resumen.total);
  const { personas, acompanantesAsociados, coopetrolitos, invitados } = r.resumen;
  $('#nota-resultado').textContent = r.modalidad === 'SOLO'
    ? 'Ingreso individual del asociado.'
    : `${personas} personas: titular, ${acompanantesAsociados} asociado(s), ${coopetrolitos} Coopetrolito(s) y ${invitados} no asociado(s).`;
  mensaje($('#msg-preinscripcion'), '');
  $('#btn-ver-existente').hidden = true;
  $('#resultado').hidden = false;
  // Módulo agencia: el pago se registra en el mismo paso, justo debajo del resumen.
  if (modulo === 'AGENCIA') {
    ubicarSoporte('inscripcion');
    $('#s-total').textContent = pesos.format(r.resumen.total);
    $('#s-valor').value = r.resumen.total;
    mensaje($('#msg-soporte'), '');
    $('#form-soporte').hidden = false;
  }
  $('#resultado').scrollIntoView({ behavior: 'smooth', block: 'start' });
}

// ---- Evento (agencia) al que asiste y contador de cupos ----
function llenarAgencias(select) {
  select.replaceChildren(...(evento.tarifas || []).map((t) => new Option(t.agencia, t.agencia)));
}

function textoCupos(agencia) {
  const c = cupos[agencia];
  return c ? `${c.disponibles.toLocaleString('es-CO')} de ${c.cupos.toLocaleString('es-CO')}` : '—';
}

function mostrarEvento() {
  const agencia = $('#agencia-evento').value;
  const t = (evento.tarifas || []).find((x) => x.agencia === agencia);
  if (!t) return;
  $('#f-valor-asociado').textContent = pesos.format(t.valor_asociado);
  $('#f-valor-invitado').textContent = pesos.format(t.valor_invitado);
  $('#f-cupos').textContent = textoCupos(agencia);
  $('#f-cupos').classList.toggle('agotado', cupos[agencia]?.disponibles === 0);
  $('#aviso-sin-cupos').hidden = cupos[agencia]?.disponibles !== 0;
}

$('#agencia-evento').addEventListener('change', () => { ocultarResultados(); mostrarEvento(); });

async function actualizarCupos() {
  try {
    const lista = await pedir('api/cupos');
    cupos = Object.fromEntries(lista.map((c) => [c.agencia, c]));
    mostrarEvento();
    for (const celda of document.querySelectorAll('[data-disponibles]')) {
      const c = cupos[celda.dataset.disponibles];
      celda.textContent = c ? c.disponibles.toLocaleString('es-CO') : '—';
      celda.classList.toggle('agotado', c?.disponibles === 0);
    }
  } catch {}
}
setInterval(() => { if (!document.hidden) actualizarCupos(); }, 30000);
document.addEventListener('visibilitychange', () => { if (!document.hidden) actualizarCupos(); });

const acompanantesFormulario = () => (form.modalidad.value === 'ACOMPAÑADO' ? listaInscripcion.leer() : []);

form.addEventListener('submit', async (e) => {
  e.preventDefault();
  const msg = $('#msg-simulacion');
  if (!identidad) return mensaje(msg, 'Primero valide su documento de asociado.', 'error');
  await conBoton($('#btn-simular'), msg, 'Calculando…', async () => {
    const acompanantes = acompanantesFormulario();
    const agenciaEvento = $('#agencia-evento').value;
    simulacion = { acompanantes, agenciaEvento, resultado: await pedir('api/simular', { ...identidad, agenciaEvento, acompanantes }) };
    mensaje(msg, '');
    mostrarResultado(simulacion.resultado);
  });
});

$('#btn-preinscribir').addEventListener('click', async () => {
  if (!identidad || !simulacion) return;
  const r = simulacion.resultado;
  const asociados = 1 + r.resumen.acompanantesAsociados + r.resumen.coopetrolitos;
  const confirmado = await confirmar({
    titulo: 'Confirmar preinscripción',
    mensaje: 'Se registrará su preinscripción con el valor calculado. El cupo se asigna cuando registre el pago, según disponibilidad.',
    detalles: [
      ['Evento', r.agenciaEvento],
      ['Cupos que ocupará al pagar', `${asociados} (los no asociados no ocupan cupo)`],
      ['Personas', String(r.resumen.personas)],
      ['Total a pagar', pesos.format(r.resumen.total)],
    ],
    aceptar: 'Sí, preinscribirme',
  });
  if (!confirmado) return;
  conBoton($('#btn-preinscribir'), $('#msg-preinscripcion'), 'Registrando preinscripción…', async () => {
    let ins;
    try {
      ins = await pedir('api/inscripciones', {
        ...identidad, agenciaEvento: simulacion.agenciaEvento, acompanantes: simulacion.acompanantes, autorizaDatos: true,
        autorizaImagen: $('#acepta-imagen').checked,
      });
    } catch (err) {
      // Ya tiene una inscripción activa: se ofrece ir a ella (pagar, modificar o cancelar).
      const existente = err.datos?.referenciaExistente;
      if (existente) {
        consulta = { referencia: existente, ...identidad };
        $('#btn-ver-existente').hidden = false;
      }
      throw err;
    }
    mostrarConfirmacion(ins);
  });
});

// Confirmación tras inscribirse (los textos dependen del módulo: [data-solo-modulo]).
function mostrarConfirmacion(ins) {
  actualizarCupos();
  consulta = { referencia: ins.referencia, ...identidad };
  recordar({ documento: identidad.documento });
  $('#c-referencia').textContent = ins.referencia;
  $('#c-total').textContent = pesos.format(ins.total);
  $('#c-personas').textContent = ins.personas.length;
  prepararPago(ins.referencia, ins.total);
  $('#resultado').hidden = true;
  if (soporteEn === 'inscripcion') $('#form-soporte').hidden = true;
  $('#confirmacion').hidden = false;
  limpiarFormularioInscripcion();
  $('#confirmacion').scrollIntoView({ behavior: 'smooth', block: 'start' });
}

// Tras una inscripción exitosa se borran los datos del formulario (documento, fecha de expedición, autorizaciones,
// acompañantes): en equipos compartidos, como los de las agencias, no quedan a la vista de la siguiente persona.
// La confirmación sigue visible y "Ya pagué" / "Ver mi inscripción" usan la referencia guardada en `consulta`.
function limpiarFormularioInscripcion() {
  form.reset();
  listaInscripcion.vaciar();
  $('#bloque-acompanantes').hidden = true;
  for (const campo of form.querySelectorAll('[aria-invalid]')) campo.removeAttribute('aria-invalid');
  for (const id of ['#msg-asociado', '#msg-simulacion', '#msg-preinscripcion']) mensaje($(id), '');
  $('#ficha-asociado').hidden = true;
  identidad = null;
  simulacion = null;
  agenciaPropia = null;
  bloquearPasos(true);
}

function irAMiInscripcion() {
  ubicarSoporte('consulta');
  mostrarVista('#vista-mi');
  $('#q-documento').value = consulta.documento;
  $('#q-expedicion').value = consulta.fechaExpedicion;
  consultarInscripcion();
  window.scrollTo({ top: 0, behavior: 'smooth' });
}
$('#btn-ver-existente').addEventListener('click', irAMiInscripcion);

$('#btn-ir-soporte').addEventListener('click', irAMiInscripcion);
$('#btn-ver-inscripcion').addEventListener('click', irAMiInscripcion);

// ============ Vista 2: mi inscripción ============
function mostrarInscripcion(ins) {
  inscripcion = ins;
  const estado = ESTADOS[ins.estado] || { texto: ins.estado, clase: 'inactivo' };
  $('#e-referencia').textContent = ins.referencia;
  $('#e-estado').textContent = estado.texto;
  $('#e-estado').className = `estado estado--${estado.clase}`;
  $('#e-titular').textContent = ins.titular;
  $('#e-agencia').textContent = ins.agencia;
  if (ins.agenciaAsociado && ins.agenciaAsociado !== ins.agencia) $('#e-agencia').textContent += ` (asociado de ${ins.agenciaAsociado})`;
  $('#e-total').textContent = pesos.format(ins.total);
  const s = ins.soporte;
  $('#e-soporte').textContent = !s ? '—' : s.medioPago === 'AGENCIA'
    ? `Pago en agencia ${s.agenciaPago}${s.recibo ? ` · recibo ${s.recibo}` : ''} · ${fechaCorta(s.cargadoEn)}`
    : `PSE · CUS ${s.cus} · ${fechaCorta(s.cargadoEn)}`;
  $('#e-personas').replaceChildren(...ins.personas.map((p) => fila(p.nombre, p.tipo, subPersona(p), p.valor)));

  const aviso = {
    RECHAZADO: `Su soporte fue rechazado: ${ins.motivo || 'sin motivo'}. Corrija la información y cárguelo de nuevo.`,
    EN_REVISION: 'Recibimos su soporte. El área encargada lo verificará y actualizará el estado de su inscripción.',
    CONFIRMADO: '¡Su inscripción está confirmada! Lo esperamos en el evento.',
    ANULADO: `La inscripción fue anulada${ins.motivo ? `: ${ins.motivo}` : ''}. Comuníquese con su agencia si tiene dudas.`,
    CANCELADO: 'Usted canceló esta preinscripción. Puede inscribirse de nuevo si hay cupos.',
    PREINSCRITO: 'Preinscripción registrada. Su cupo se asigna cuando registre el pago, según disponibilidad.',
  }[ins.estado];
  $('#e-mensaje').hidden = !aviso;
  $('#e-mensaje').textContent = aviso || '';
  $('#e-mensaje').className = `aviso aviso--${estado.clase}`;

  $('#e-acciones').hidden = !ins.editable;
  $('#form-modificar').hidden = true;
  $('#form-soporte').hidden = !ins.editable;
  $('#s-total').textContent = pesos.format(ins.total);
  prepararPago(ins.referencia, ins.total);
  if (ins.editable && !$('#s-valor').value) $('#s-valor').value = ins.total;
  mensaje($('#msg-estado'), '');
  $('#estado-inscripcion').hidden = false;
}

async function consultarInscripcion() {
  const datos = {
    documento: $('#q-documento').value.trim(),
    fechaExpedicion: $('#q-expedicion').value,
  };
  const msg = $('#msg-consulta');
  if (!datos.documento || !datos.fechaExpedicion) {
    return mensaje(msg, 'Ingrese su número de documento y la fecha de expedición.', 'error');
  }
  await conBoton($('#btn-consultar'), msg, 'Consultando…', async () => {
    try {
      const ins = await pedir('api/inscripciones/consultar', datos);
      consulta = { ...datos, referencia: ins.referencia };
      recordar({ documento: datos.documento });
      mensaje(msg, '');
      mostrarInscripcion(ins);
    } catch (err) {
      for (const id of ['#estado-inscripcion', '#form-modificar', '#form-soporte']) $(id).hidden = true;
      throw err;
    }
  });
}

$('#form-consulta').addEventListener('submit', (e) => { e.preventDefault(); consultarInscripcion(); });

$('#btn-cancelar').addEventListener('click', async () => {
  const confirmado = await confirmar({
    titulo: '¿Cancelar su preinscripción?',
    mensaje: 'Se liberarán sus cupos y otra persona podrá tomarlos. Podrá inscribirse de nuevo si aún hay cupos disponibles.',
    detalles: [['Referencia', inscripcion.referencia], ['Evento', inscripcion.agencia]],
    aceptar: 'Sí, cancelar',
    cancelar: 'No, conservarla',
    tono: 'peligro',
  });
  if (!confirmado) return;
  conBoton($('#btn-cancelar'), $('#msg-estado'), 'Cancelando…', async () => {
    mostrarInscripcion(await pedir('api/inscripciones/cancelar', consulta));
    actualizarCupos();
  });
});

// Modificar acompañantes
const listaModificar = crearListaAcompanantes($('#lista-modificar'), $('#btn-agregar-mod'));
$('#btn-modificar').addEventListener('click', () => {
  $('#m-agencia').value = inscripcion.agencia;
  listaModificar.vaciar();
  for (const p of inscripcion.personas.filter((x) => x.tipo !== 'TITULAR')) listaModificar.agregar(p, false);
  mensaje($('#msg-modificar'), '');
  $('#form-modificar').hidden = false;
  $('#form-modificar').scrollIntoView({ behavior: 'smooth', block: 'start' });
});
$('#btn-cerrar-mod').addEventListener('click', () => { $('#form-modificar').hidden = true; });
$('#form-modificar').addEventListener('submit', (e) => {
  e.preventDefault();
  conBoton($('#btn-guardar-mod'), $('#msg-modificar'), 'Guardando…', async () => {
    const anterior = inscripcion.total;
    const ins = await pedir('api/inscripciones/modificar', {
      ...consulta, agenciaEvento: $('#m-agencia').value, acompanantes: listaModificar.leer(),
    });
    actualizarCupos();
    $('#s-valor').value = ins.total;
    mostrarInscripcion(ins);
    mensaje($('#msg-estado'), anterior === ins.total
      ? 'Cambios guardados.'
      : `Cambios guardados. Nuevo total: ${pesos.format(ins.total)} (antes ${pesos.format(anterior)}).`, 'ok');
  });
});

// El mismo formulario de pago se usa en dos lugares:
//   'consulta'     en "Consulte su inscripción": elige el medio (PSE o agencia) y registra el pago de una inscripción existente
//   'inscripcion'  en el módulo agencia, debajo del resumen: solo pago en agencia; crea la inscripción con el pago
// (la autorización de datos ya se aceptó en el paso 1 del módulo).
function ubicarSoporte(destino) {
  const formulario = $('#form-soporte');
  const enInscripcion = destino === 'inscripcion';
  if (soporteEn !== destino) {
    if (enInscripcion) $('#resultado').after(formulario);
    else $('#form-modificar').after(formulario);
    formulario.hidden = true;
    soporteEn = destino;
  }
  $('#s-medios').hidden = enInscripcion;
  $('#s-aviso-agencia').hidden = enInscripcion;
  $('#s-habeas').hidden = enInscripcion;
  $('#acepta-datos-soporte').required = !enInscripcion;
  formulario.querySelector(`[name="medio-pago"][value="${enInscripcion ? 'AGENCIA' : 'PSE'}"]`).checked = true;
  actualizarMedioPago();
  $('#s-titulo').textContent = enInscripcion ? 'Registre el pago realizado en la agencia' : 'Registrar el pago';
  $('#s-ayuda-texto').textContent = enInscripcion
    ? 'Indique la agencia y la fecha en que pagó. El número de recibo de caja y la foto del comprobante son opcionales.'
    : 'Puede pagar por PSE o directamente en cualquier agencia de Coopetrol (efectivo u otro medio) indicando su referencia.';
  $('#btn-enviar-soporte').textContent = enInscripcion ? 'Inscribirme y registrar el pago' : 'Enviar soporte';
}

// Soporte de pago: medio PSE (CUS y comprobante obligatorios) o AGENCIA (agencia obligatoria; recibo y comprobante opcionales)
const medioPago = () => $('#form-soporte').querySelector('[name="medio-pago"]:checked')?.value || 'PSE';
function actualizarMedioPago() {
  const medio = medioPago();
  for (const bloque of document.querySelectorAll('#form-soporte [data-medio]')) {
    const activo = bloque.dataset.medio === medio;
    bloque.hidden = !activo;
    for (const el of bloque.querySelectorAll('input, select')) {
      if (el.id === 's-recibo') continue;
      el.required = activo;
      if (!activo) el.removeAttribute('aria-invalid');
    }
  }
  const comprobanteObligatorio = medio === 'PSE';
  $('#s-archivo').required = comprobanteObligatorio;
  $('#s-archivo-requerido').textContent = comprobanteObligatorio ? '*' : '(opcional: foto del recibo)';
  if (!comprobanteObligatorio) $('#s-archivo').removeAttribute('aria-invalid');
}
for (const radio of document.querySelectorAll('#form-soporte [name="medio-pago"]')) radio.addEventListener('change', actualizarMedioPago);

// Campos del soporte configurables en data/formulario_soporte.json. Los que tienen `mostrarSi`
// aparecen (y se exigen) solo cuando la otra respuesta coincide.
function actualizarCondicionales(campos) {
  for (const c of campos.filter((x) => x.mostrarSi)) {
    const control = $(`#ad-${c.mostrarSi.campo}`);
    const visible = control?.value === c.mostrarSi.valor;
    const bloque = $(`#ad-${c.id}`).closest('.campo-adicional');
    bloque.hidden = !visible;
    $(`#ad-${c.id}`).required = visible && !!c.requerido;
    if (!visible) { $(`#ad-${c.id}`).value = ''; $(`#ad-${c.id}`).removeAttribute('aria-invalid'); }
  }
}

function construirCamposAdicionales(campos) {
  const tipos = { texto: 'text', numero: 'text', fecha: 'date', correo: 'email', telefono: 'tel', placa: 'text' };
  $('#s-adicionales').replaceChildren(...campos.map((c) => {
    const div = document.createElement('div');
    // Posición fija: pregunta Sí/No a la izquierda, su campo dependiente a la derecha; textos largos a todo el ancho.
    const tieneDependientes = campos.some((x) => x.mostrarSi?.campo === c.id);
    const ancho = !c.mostrarSi && c.tipo === 'texto' && (c.max || 200) >= 300;
    div.className = `campo-adicional${tieneDependientes ? ' campo-adicional--pregunta' : ''}${c.mostrarSi ? ' campo-adicional--dependiente' : ''}${ancho ? ' campo-adicional--ancho' : ''}`;
    const label = Object.assign(document.createElement('label'), {
      className: 'campo__label', htmlFor: `ad-${c.id}`, textContent: `${c.etiqueta}${c.requerido ? ' *' : ''}`,
    });
    let input;
    if (c.tipo === 'seleccion') {
      input = document.createElement('select');
      input.append(new Option('Seleccione…', ''), ...(c.opciones || []).map((o) => new Option(o, o)));
    } else {
      input = Object.assign(document.createElement('input'), { type: tipos[c.tipo] || 'text' });
      if (c.tipo === 'numero') input.inputMode = 'decimal';
      if (c.tipo === 'placa') { input.maxLength = 7; input.autocomplete = 'off'; input.placeholder = 'ABC123'; input.dataset.validar = 'alfanumerico'; }
      if (c.tipo === 'telefono') { input.maxLength = 15; input.dataset.validar = 'numerico'; }
      if (c.max) input.maxLength = c.max;
    }
    Object.assign(input, { id: `ad-${c.id}`, className: `campo${c.tipo === 'placa' ? ' campo--mayus' : ''}`, required: !!c.requerido });
    input.dataset.campo = c.id;
    div.append(label, input);
    if (c.ayuda) div.append(Object.assign(document.createElement('small'), { className: 'campo__ayuda', textContent: c.ayuda }));
    return div;
  }));
  for (const c of campos.filter((x) => x.mostrarSi)) {
    $(`#ad-${c.mostrarSi.campo}`)?.addEventListener('change', () => actualizarCondicionales(campos));
  }
  actualizarCondicionales(campos);
}

// ---- Zona de carga del comprobante (arrastrar y soltar o seleccionar) ----
const TIPOS_SOPORTE = { 'application/pdf': 'PDF', 'image/jpeg': 'JPG', 'image/png': 'PNG' };
const inputArchivo = $('#s-archivo');
const zonaArchivo = $('#zona-archivo');
let urlMiniatura = null;

const formatoPeso = (bytes) => (bytes < 1024 * 1024 ? `${Math.max(1, Math.round(bytes / 1024))} KB` : `${(bytes / 1024 / 1024).toFixed(1)} MB`);

function limpiarArchivo() {
  inputArchivo.value = '';
  if (urlMiniatura) URL.revokeObjectURL(urlMiniatura);
  urlMiniatura = null;
  $('#zona-vacia').hidden = false;
  $('#zona-elegido').hidden = true;
  zonaArchivo.classList.remove('zona-archivo--lista');
}

// Valida tipo y tamaño apenas se elige el archivo; si no sirve, lo descarta y explica por qué.
function mostrarArchivo() {
  const archivo = inputArchivo.files[0];
  const msg = $('#msg-archivo');
  mensaje(msg, '');
  inputArchivo.removeAttribute('aria-invalid');
  if (!archivo) return limpiarArchivo();
  const tipo = TIPOS_SOPORTE[archivo.type];
  if (!tipo) {
    limpiarArchivo();
    return mensaje(msg, 'El comprobante debe ser un archivo PDF, JPG o PNG.', 'error');
  }
  if (archivo.size > evento.soporteMaxMb * 1024 * 1024) {
    limpiarArchivo();
    return mensaje(msg, `El archivo pesa ${formatoPeso(archivo.size)}; el máximo es ${evento.soporteMaxMb} MB.`, 'error');
  }
  if (urlMiniatura) URL.revokeObjectURL(urlMiniatura);
  urlMiniatura = tipo === 'PDF' ? null : URL.createObjectURL(archivo);
  $('#archivo-miniatura').hidden = !urlMiniatura;
  if (urlMiniatura) $('#archivo-miniatura').src = urlMiniatura;
  $('#archivo-tipo').hidden = !!urlMiniatura;
  $('#archivo-tipo').textContent = tipo;
  $('#archivo-nombre').textContent = archivo.name;
  $('#archivo-peso').textContent = `${tipo} · ${formatoPeso(archivo.size)}`;
  $('#zona-vacia').hidden = true;
  $('#zona-elegido').hidden = false;
  zonaArchivo.classList.add('zona-archivo--lista');
}

inputArchivo.addEventListener('change', mostrarArchivo);
$('#archivo-quitar').addEventListener('click', () => { limpiarArchivo(); mensaje($('#msg-archivo'), ''); });
for (const evt of ['dragenter', 'dragover']) {
  zonaArchivo.addEventListener(evt, (e) => { e.preventDefault(); zonaArchivo.classList.add('zona-archivo--arrastre'); });
}
for (const evt of ['dragleave', 'drop']) {
  zonaArchivo.addEventListener(evt, (e) => {
    if (evt === 'dragleave' && zonaArchivo.contains(e.relatedTarget)) return;
    zonaArchivo.classList.remove('zona-archivo--arrastre');
  });
}
zonaArchivo.addEventListener('drop', (e) => {
  e.preventDefault();
  if (!e.dataTransfer.files.length) return;
  const seleccion = new DataTransfer();
  seleccion.items.add(e.dataTransfer.files[0]);
  inputArchivo.files = seleccion.files;
  mostrarArchivo();
});
// Evita que el navegador abra el archivo si se suelta fuera de la zona.
for (const evt of ['dragover', 'drop']) window.addEventListener(evt, (e) => { if (!zonaArchivo.contains(e.target)) e.preventDefault(); });

// Datos del pago tal como están en el formulario (el comprobante se lee aparte).
async function datosDelPago() {
  const medio = medioPago();
  const archivo = $('#s-archivo').files[0];
  return {
    medioPago: medio,
    ...(medio === 'PSE'
      ? { cus: $('#s-cus').value }
      : { agenciaPago: $('#s-agencia-pago').value, recibo: $('#s-recibo').value }),
    fechaPago: $('#s-fecha').value,
    valorPagado: $('#s-valor').value,
    campos: Object.fromEntries([...$('#s-adicionales').querySelectorAll('[data-campo]')]
      .filter((el) => !el.closest('.campo-adicional').hidden).map((el) => [el.dataset.campo, el.value])),
    archivo: archivo ? { nombre: archivo.name, contenido: await leerBase64(archivo) } : undefined,
    autorizaDatos: true,
  };
}

function limpiarFormularioPago() {
  $('#form-soporte').reset();
  limpiarArchivo();
  ubicarSoporte(soporteEn); // restablece el medio de pago y los textos del lugar actual
  actualizarCondicionales(evento.camposSoporte || []);
  mensaje($('#msg-soporte'), '');
}

$('#form-soporte').addEventListener('submit', async (e) => {
  e.preventDefault();
  const msg = $('#msg-soporte');
  const requeridos = [...$('#form-soporte').querySelectorAll('[required]')].filter((el) => !el.closest('[hidden]'));
  for (const el of requeridos) el.toggleAttribute('aria-invalid', !el.value.trim());
  const vacio = requeridos.find((el) => !el.value.trim());
  if (vacio) { vacio.focus(); return mensaje(msg, 'Complete los campos obligatorios (*).', 'error'); }
  const enInscripcion = soporteEn === 'inscripcion';
  if (!enInscripcion && !$('#acepta-datos-soporte').checked) {
    $('#acepta-datos-soporte').focus();
    return mensaje(msg, 'Debe aceptar la autorización para el tratamiento de datos personales.', 'error');
  }
  const archivo = $('#s-archivo').files[0];
  if (archivo && archivo.size > evento.soporteMaxMb * 1024 * 1024) return mensaje(msg, `El archivo supera ${evento.soporteMaxMb} MB.`, 'error');

  // Módulo agencia: inscripción y pago en un solo envío.
  if (enInscripcion) {
    if (!identidad || !simulacion) return mensaje(msg, 'Primero valide su documento y simule el valor a pagar.', 'error');
    const r = simulacion.resultado;
    const valor = Number($('#s-valor').value || 0);
    const confirmado = await confirmar({
      titulo: 'Confirmar inscripción y pago',
      mensaje: 'Se creará su inscripción con el pago en agencia registrado. El área encargada lo verificará con la agencia.',
      detalles: [
        ['Evento', r.agenciaEvento],
        ['Personas', String(r.resumen.personas)],
        ['Total a pagar', pesos.format(r.resumen.total)],
        ['Pagó en la agencia', $('#s-agencia-pago').value],
        ['Valor pagado', pesos.format(valor)],
      ],
      aceptar: 'Sí, inscribirme',
    });
    if (!confirmado) return;
    return conBoton($('#btn-enviar-soporte'), msg, 'Registrando inscripción y pago…', async () => {
      let ins;
      try {
        ins = await pedir('api/inscripciones/con-pago', {
          ...identidad, agenciaEvento: simulacion.agenciaEvento, acompanantes: simulacion.acompanantes, ...(await datosDelPago()),
          autorizaImagen: $('#acepta-imagen').checked,
        });
      } catch (err) {
        // Ya tiene una inscripción activa: se ofrece ir a ella.
        const existente = err.datos?.referenciaExistente;
        if (existente) {
          consulta = { referencia: existente, ...identidad };
          $('#btn-ver-existente').hidden = false;
        }
        throw err;
      }
      limpiarFormularioPago();
      mostrarConfirmacion(ins);
    });
  }

  conBoton($('#btn-enviar-soporte'), msg, 'Enviando soporte…', async () => {
    const ins = await pedir('api/inscripciones/soporte', { ...consulta, ...(await datosDelPago()) });
    limpiarFormularioPago();
    mostrarInscripcion(ins);
    mensaje($('#msg-estado'), 'Soporte enviado correctamente.', 'ok');
    $('#estado-inscripcion').scrollIntoView({ behavior: 'smooth', block: 'start' });
  });
});

// Texto de la autorización de habeas data (configurable en data/habeas_data.json).
function mostrarHabeasData(h) {
  if (!h) return;
  for (const cuerpo of document.querySelectorAll('[data-habeas-texto]')) {
    const parrafos = [...(h.texto || []), h.declaracion_acompanantes, ...(h.imagen?.texto || [])].filter(Boolean)
      .map((t) => Object.assign(document.createElement('p'), { textContent: t }));
    if (h.enlace_politica) {
      const enlace = Object.assign(document.createElement('a'), {
        href: h.enlace_politica, target: '_blank', rel: 'noopener', textContent: 'Consulte la Política de Tratamiento de Datos Personales',
      });
      const p = document.createElement('p');
      p.append(enlace);
      parrafos.push(p);
    }
    const version = Object.assign(document.createElement('p'), { className: 'nota', textContent: `Versión ${h.version}` });
    cuerpo.replaceChildren(...parrafos, version);
  }
  if (h.texto_casilla) for (const el of document.querySelectorAll('[data-habeas-casilla]')) el.textContent = h.texto_casilla;
  if (h.imagen?.texto_casilla) {
    for (const el of document.querySelectorAll('[data-imagen-casilla]')) el.textContent = h.imagen.texto_casilla;
    $('#casilla-imagen').hidden = false;
  }
  if (h.titulo) for (const el of document.querySelectorAll('.habeas__titulo')) el.textContent = `${h.titulo} (Ley 1581 de 2012)`;
}

// ---- Carga inicial ----
// 'EVENTO FIN DE AÑO COOPETROL' -> 'Evento fin de año Coopetrol'
function oracion(texto) {
  const t = texto.trim().toLowerCase().replace(/coopetrol/g, 'Coopetrol');
  return t.charAt(0).toUpperCase() + t.slice(1);
}

pedir('api/evento').then((datos) => {
  evento = datos;
  for (const el of document.querySelectorAll('.max-acomp')) el.textContent = datos.maxAcompanantes;
  if (datos.evento?.nombre) $('#nombre-evento').textContent = oracion(datos.evento.nombre);
  if (datos.evento?.inscripciones) $('#inscripciones').textContent = oracion(datos.evento.inscripciones.replace(/^INC?RIPCIONES/i, 'inscripciones'));
  if (datos.enlacePago) for (const id of ['#c-pse', '#s-pse']) $(id).href = datos.enlacePago;
  if (datos.enlaceCanales) for (const a of document.querySelectorAll('.enlace-canales')) a.href = datos.enlaceCanales;
  $('#s-max').textContent = datos.soporteMaxMb;
  $('#s-fecha').max = new Date().toLocaleDateString('en-CA');
  construirCamposAdicionales(datos.camposSoporte || []);
  llenarAgencias($('#agencia-evento'));
  llenarAgencias($('#m-agencia'));
  const agenciasPago = datos.agencias?.length ? datos.agencias.map((a) => a.agencia) : (datos.tarifas || []).map((t) => t.agencia);
  $('#s-agencia-pago').append(...agenciasPago.map((a) => new Option(a, a)));
  actualizarMedioPago();
  if (agenciaPropia) $('#agencia-evento').value = agenciaPropia;
  mostrarHabeasData(datos.habeasData);
  $('#tabla-tarifas').replaceChildren(...datos.tarifas.map((t) => {
    const tr = document.createElement('tr');
    tr.append(Object.assign(document.createElement('td'), { textContent: t.agencia }));
    tr.append(Object.assign(document.createElement('td'), { textContent: t.cupos, className: 'num' }));
    const disponibles = Object.assign(document.createElement('td'), { textContent: '—', className: 'num' });
    disponibles.dataset.disponibles = t.agencia;
    tr.append(disponibles);
    for (const valor of [t.valor_invitado, t.valor_asociado]) {
      tr.append(Object.assign(document.createElement('td'), { textContent: pesos.format(valor), className: 'num' }));
    }
    return tr;
  }));
  actualizarCupos();
}).catch(() => {});

const previo = recordado();
if (previo.documento) $('#q-documento').value = previo.documento;

// Enlaces directos a un módulo (p. ej. para compartir desde una agencia): #pse, #agencia o #consulta.
const ENLACES = { '#pse': () => elegirModulo('PSE'), '#agencia': () => elegirModulo('AGENCIA'), '#consulta': irAConsulta };
(ENLACES[location.hash.toLowerCase()] || (() => mostrarVista('#vista-inicio')))();

if ('serviceWorker' in navigator) navigator.serviceWorker.register('sw.js').catch(() => {});
