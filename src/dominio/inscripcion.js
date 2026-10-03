// Ciclo de vida de una inscripción.
//
// Estados:
//   PREINSCRITO  pendiente de pago                          -> se puede modificar, cancelar o cargar soporte
//   EN_REVISION  formulario de pago enviado, por verificar  -> ocupa cupo
//   RECHAZADO    soporte no válido (con motivo)            -> se puede modificar, cancelar o cargar otro soporte
//   CONFIRMADO   pago verificado                           -> ocupa cupo
//   CANCELADO    cancelada por el asociado
//   ANULADO      anulada por un administrador
// No hay plazo de pago. La preinscripción NO ocupa cupo: se descuenta al enviar el formulario de pago válido y se
// libera si el pago se rechaza, se anula o se cancela.
const { ErrorValidacion } = require('./errores');
const { ocupaCupo } = require('./tiposAcompanante');

const ESTADOS_ACTIVOS = ['PREINSCRITO', 'EN_REVISION', 'RECHAZADO', 'CONFIRMADO']; // inscripción vigente
const ESTADOS_CUPO = ['EN_REVISION', 'CONFIRMADO']; // ocupan cupo (pago registrado)
const ESTADOS_EDITABLES = ['PREINSCRITO', 'RECHAZADO'];

// Acciones del revisor en el panel.
const TRANSICIONES = {
  APROBAR: { desde: ['EN_REVISION'], hacia: 'CONFIRMADO', motivo: false },
  RECHAZAR: { desde: ['EN_REVISION'], hacia: 'RECHAZADO', motivo: true },
  ANULAR: { desde: ESTADOS_ACTIVOS, hacia: 'ANULADO', motivo: true },
};

const esEditable = (estado) => ESTADOS_EDITABLES.includes(estado);

function exigirEditable(inscripcion, accion) {
  if (!esEditable(inscripcion.estado)) {
    throw new ErrorValidacion(`No es posible ${accion}: la inscripción está ${inscripcion.estado.replace('_', ' ').toLowerCase()}.`);
  }
}

// Valida la acción del revisor y devuelve { hacia, motivo }.
function transicion(accion, estadoActual, motivo) {
  const regla = Object.hasOwn(TRANSICIONES, accion) ? TRANSICIONES[accion] : null;
  if (!regla) throw new ErrorValidacion('Acción no válida.');
  if (!regla.desde.includes(estadoActual)) throw new ErrorValidacion(`No se puede ${accion.toLowerCase()} una inscripción en estado ${estadoActual}.`);
  const texto = String(motivo ?? '').trim().slice(0, 500);
  if (regla.motivo && !texto) throw new ErrorValidacion('Indique el motivo.');
  return { hacia: regla.hacia, motivo: texto || null };
}

// Referencia visible para el asociado: EVT26-000001.
const generarReferencia = (id, fecha) => `EVT${String(fecha.getFullYear()).slice(2)}-${String(id).padStart(6, '0')}`;

// Cupos que requiere un grupo de personas (los invitados no ocupan cupo).
const cuposRequeridos = (personas) => personas.filter((p) => ocupaCupo(p.tipo)).length;

// Lanza el mensaje de cupos agotados si `requeridos` supera `disponibles`. `sugerencia` se añade al final.
function exigirCupos({ agencia, requeridos, disponibles, sugerencia = '' }) {
  if (requeridos <= disponibles) return;
  throw new ErrorValidacion(disponibles > 0
    ? `Lo sentimos, se ha superado el límite de cupos disponibles: en el evento de ${agencia} quedan ${disponibles} y su inscripción requiere ${requeridos}.${sugerencia}`
    : `Lo sentimos, se ha superado el límite de cupos disponibles para el evento de ${agencia}.`);
}

module.exports = {
  ESTADOS_ACTIVOS, ESTADOS_CUPO, ESTADOS_EDITABLES, TRANSICIONES,
  esEditable, exigirEditable, transicion, generarReferencia, cuposRequeridos, exigirCupos,
};
