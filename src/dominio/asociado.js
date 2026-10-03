// Reglas del asociado titular.
// - Debe ser asociado ACTIVO.
// - Debe haber actualizado sus datos en los últimos MESES_VIGENCIA_DATOS meses.
const { ErrorValidacion } = require('./errores');
const { aISO, formatoFecha } = require('./valores');

const MESES_VIGENCIA_DATOS = Number(process.env.MESES_VIGENCIA_DATOS || 12);

// Fecha mínima aceptada de actualización de datos (AAAA-MM-DD): hoy menos MESES_VIGENCIA_DATOS meses.
const fechaLimiteActualizacion = (hoy) => aISO(new Date(hoy.getFullYear(), hoy.getMonth() - MESES_VIGENCIA_DATOS, hoy.getDate()));

function exigirActivo(asociado) {
  if (asociado.estado !== 'ACTIVO') throw new ErrorValidacion('El documento no figura como asociado activo de Coopetrol. Comuníquese con su agencia.');
}

function exigirDatosActualizados(fecha, hoy) {
  if (!fecha) throw new ErrorValidacion('No registra actualización de datos. Actualice sus datos en su agencia para continuar.');
  if (fecha < fechaLimiteActualizacion(hoy)) {
    throw new ErrorValidacion(`Sus datos fueron actualizados por última vez el ${formatoFecha(fecha)}. `
      + `Debe actualizarlos (vigencia máxima de ${MESES_VIGENCIA_DATOS} meses) para continuar.`);
  }
}

module.exports = { MESES_VIGENCIA_DATOS, exigirActivo, exigirDatosActualizados };
