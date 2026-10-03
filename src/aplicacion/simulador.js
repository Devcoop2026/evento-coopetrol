// Caso de uso: identificar al asociado y liquidar el valor de ingreso al evento.
//
// - El titular debe ser asociado ACTIVO con datos actualizados (src/dominio/asociado.js) y paga el valor de asociado.
// - Segunda validación de identidad: fecha de expedición del documento (comparada contra su HMAC).
// - Los valores se toman del evento de la agencia al que asistirá (por defecto, la agencia del asociado).
// - Cada acompañante se clasifica con las estrategias de src/dominio/tiposAcompanante.js (asociado, Coopetrolito o invitado).
// - Cada acompañante: número de documento (solo dígitos) y nombres y apellidos completos.
const { ErrorValidacion, errorIdentidad } = require('../dominio/errores');
const { normalizarDocumento, validarDocumento, validarNombreCompleto } = require('../dominio/valores');
const { MESES_VIGENCIA_DATOS, exigirActivo, exigirDatosActualizados } = require('../dominio/asociado');
const { clasificarAcompanante } = require('../dominio/tiposAcompanante');
const { crearRepositorioPadron } = require('../infraestructura/repositorioPadron');
const { coincideFecha } = require('../infraestructura/secreto');
const { crearLimitador } = require('../infraestructura/limitador');

const MAX_ACOMPANANTES = Number(process.env.MAX_ACOMPANANTES || 5);

// Tras INTENTOS_MAXIMOS fallos en BLOQUEO_MINUTOS, el documento queda bloqueado ese tiempo. El servidor además limita
// los fallos por IP, para frenar la prueba de fechas sobre muchos documentos.
const INTENTOS_MAXIMOS = 10;
const BLOQUEO_MINUTOS = 15;

// Mismo mensaje si el documento no existe, no tiene fecha registrada o la fecha no coincide: no revela quién es asociado.
const ERROR_IDENTIDAD = 'El documento y la fecha de expedición no coinciden con un asociado de Coopetrol. '
  + 'Si cree que es un error, comuníquese con su agencia.';

// `hoy` es inyectable para poder probar la vigencia de la actualización de datos.
function crearSimulador(db, { hoy = () => new Date(), padron = crearRepositorioPadron(db) } = {}) {
  const intentos = crearLimitador({ maximo: INTENTOS_MAXIMOS, ventanaMs: BLOQUEO_MINUTOS * 60000, ahora: () => hoy().getTime() });

  function consultarAsociado(documento, fechaExpedicion) {
    const doc = validarDocumento(documento, { minimo: 1, maximo: 20 });
    const fecha = String(fechaExpedicion ?? '').trim();
    if (!/^\d{4}-\d{2}-\d{2}$/.test(fecha)) throw new ErrorValidacion('Ingrese la fecha de expedición del documento.');
    const espera = intentos.bloqueada(doc);
    if (espera) throw errorIdentidad(`Demasiados intentos fallidos. Intente de nuevo en ${Math.ceil(espera / 60)} minuto(s).`);
    const asociado = padron.asociado(doc);
    if (!asociado || !coincideFecha(fecha, asociado.expedicion_hmac)) {
      intentos.registrar(doc);
      throw errorIdentidad(ERROR_IDENTIDAD);
    }
    intentos.reiniciar(doc);
    exigirActivo(asociado);
    exigirDatosActualizados(asociado.fecha_actualizacion, hoy());
    // En un evento compartido (p. ej. CARTAGENA y MAMONAL) la tarifa y los cupos son los del evento.
    const tarifa = padron.tarifa(padron.eventoDeAgencia(asociado.agencia));
    if (!tarifa) throw new ErrorValidacion(`La agencia ${asociado.agencia} no tiene tarifa configurada para el evento.`);
    const { expedicion_hmac: _omitido, ...publico } = asociado;
    return { asociado: publico, tarifa };
  }

  function simular({ documento, fechaExpedicion, agenciaEvento, acompanantes = [] }) {
    const { asociado, tarifa: tarifaPropia } = consultarAsociado(documento, fechaExpedicion);
    const agencia = String(agenciaEvento || '').trim().toUpperCase() || tarifaPropia.agencia;
    const tarifa = agencia === tarifaPropia.agencia ? tarifaPropia : padron.tarifa(agencia);
    if (!tarifa) throw new ErrorValidacion(`No existe un evento para la agencia ${agencia}.`);

    if (!Array.isArray(acompanantes)) throw new ErrorValidacion('Formato de acompañantes inválido.');
    if (acompanantes.length > MAX_ACOMPANANTES) throw new ErrorValidacion(`Máximo ${MAX_ACOMPANANTES} acompañantes por asociado.`);

    const vistos = new Set([asociado.documento]);
    const detalleAcompanantes = acompanantes.map((a, i) => {
      const etiqueta = `Acompañante ${i + 1}`;
      const doc = validarDocumento(a?.documento, { etiqueta });
      if (doc === asociado.documento) throw new ErrorValidacion(`${etiqueta}: no puede ser el mismo asociado titular.`);
      if (vistos.has(doc)) throw new ErrorValidacion(`${etiqueta}: el documento ${doc} está repetido.`);
      vistos.add(doc);
      // Se usa el nombre digitado: no se exponen nombres de la base por número de documento.
      const nombre = validarNombreCompleto(a?.nombre, { etiqueta });
      const clase = clasificarAcompanante({
        documento: doc,
        etiqueta,
        solicitado: a?.tipo,
        titular: asociado.documento,
        registro: padron.asociado(doc),
        coopetrolito: () => padron.coopetrolito(doc),
      }, tarifa);
      return { documento: doc, nombre, ...clase };
    });

    const contar = (tipo) => detalleAcompanantes.filter((a) => a.tipo === tipo).length;
    return {
      asociado,
      agenciaEvento: tarifa.agencia,
      tarifa,
      modalidad: detalleAcompanantes.length ? 'ACOMPAÑADO' : 'SOLO',
      titular: { valor: tarifa.valor_asociado },
      acompanantes: detalleAcompanantes,
      resumen: {
        personas: 1 + detalleAcompanantes.length,
        acompanantesAsociados: contar('ASOCIADO'),
        coopetrolitos: contar('COOPETROLITO'),
        invitados: contar('INVITADO'),
        total: tarifa.valor_asociado + detalleAcompanantes.reduce((suma, a) => suma + a.valor, 0),
      },
    };
  }

  return { consultarAsociado, simular };
}

// ErrorValidacion y normalizarDocumento se reexportan por compatibilidad: su lugar es src/dominio.
module.exports = { crearSimulador, ErrorValidacion, normalizarDocumento, MAX_ACOMPANANTES, MESES_VIGENCIA_DATOS };
