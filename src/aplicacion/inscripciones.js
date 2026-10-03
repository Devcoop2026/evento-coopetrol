// Casos de uso de la inscripción: preinscripción, consulta, modificación, cancelación, formulario de pago y revisión.
// Las reglas viven en src/dominio (estados y cupos en inscripcion.js, medios de pago en mediosPago.js) y el acceso a
// datos en src/infraestructura (repositorioInscripciones.js, almacenSoportes.js).
//
// El cupo es por agencia del evento y lo ocupan el titular, los acompañantes asociados y los Coopetrolitos; se descuenta
// al enviar el formulario de pago válido. Preinscripción y pago exigen la autorización de habeas data (versión, fecha e IP).
const path = require('node:path');
const { ErrorValidacion } = require('../dominio/errores');
const { normalizarDocumento, esFechaValida, fechaColombia, formatoFecha } = require('../dominio/valores');
const {
  ESTADOS_ACTIVOS, ESTADOS_CUPO, TRANSICIONES, esEditable, exigirEditable, transicion, generarReferencia, cuposRequeridos, exigirCupos,
} = require('../dominio/inscripcion');
const { medioPago } = require('../dominio/mediosPago');
const { validarCampos } = require('../dominio/camposFormulario');
const { crearRepositorioInscripciones } = require('../infraestructura/repositorioInscripciones');
const { crearRepositorioPadron } = require('../infraestructura/repositorioPadron');
const { crearAlmacenSoportes, tipoPorFirma } = require('../infraestructura/almacenSoportes');
const { crearExportacion } = require('./exportacion');

function crearInscripciones(db, simulador, {
  config = {}, camposSoporte = [], habeasData = { version: 'sin-version' }, dirSoportes, ahora = () => new Date(),
  repositorio = crearRepositorioInscripciones(db), padron = crearRepositorioPadron(db), almacen = crearAlmacenSoportes(dirSoportes),
}) {
  const maxMb = config.soporte_max_mb || 5;
  const exportacion = crearExportacion(repositorio, { camposSoporte });

  // Habeas data (Ley 1581 de 2012): sin autorización expresa no se procesa la solicitud.
  function exigirAutorizacion(autorizaDatos) {
    if (autorizaDatos !== true) {
      throw new ErrorValidacion('Debe aceptar la autorización para el tratamiento de datos personales para continuar.');
    }
  }

  function validarPeriodo() {
    // Interruptor en data/config.json para pausar la validación (p. ej. durante pruebas).
    if (config.validar_periodo_inscripcion === false) return;
    const hoy = fechaColombia(ahora());
    const { inscripciones_desde: desde, inscripciones_hasta: hasta } = config;
    if (desde && hoy < desde) throw new ErrorValidacion(`Las inscripciones abren el ${formatoFecha(desde)}.`);
    if (hasta && hoy > hasta) throw new ErrorValidacion(`Las inscripciones cerraron el ${formatoFecha(hasta)}.`);
  }

  // Convierte el resultado de la simulación en la lista de personas a inscribir.
  const personasDe = (sim) => [
    { documento: sim.asociado.documento, nombre: sim.asociado.nombre, tipo: 'TITULAR', valor: sim.titular.valor },
    ...sim.acompanantes.map(({ documento, nombre, tipo, valor }) => ({ documento, nombre, tipo, valor })),
  ];

  // Valida que nadie esté ya inscrito y que haya cupo. `idPropio` excluye la inscripción que se modifica.
  function validarDisponibilidad(sim, personas, idPropio = 0) {
    for (const p of personas) {
      const otra = repositorio.personaActiva(p.documento, idPropio);
      if (!otra) continue;
      if (p.tipo === 'TITULAR' && otra.documento_titular === p.documento) {
        throw new ErrorValidacion(`Ya tiene una preinscripción activa (${otra.referencia}). Consúltela en "Mi inscripción".`,
          { referenciaExistente: otra.referencia });
      }
      throw new ErrorValidacion(p.tipo === 'TITULAR'
        ? 'Usted ya está registrado como acompañante en otra inscripción del evento.'
        : `El documento ${p.documento} ya está registrado en otra inscripción del evento.`);
    }
    const agencia = sim.agenciaEvento;
    exigirCupos({ agencia, requeridos: cuposRequeridos(personas), disponibles: repositorio.cuposDisponibles(agencia, idPropio) });
  }

  // `autorizaImagen` (opcional, true/false): uso de fotografías y videos del evento; no condiciona la inscripción.
  function preinscribir({ autorizaDatos, autorizaImagen, ...datos }, { ip } = {}) {
    exigirAutorizacion(autorizaDatos);
    validarPeriodo();
    const sim = simulador.simular(datos);
    const personas = personasDe(sim);
    const marca = ahora();
    const id = repositorio.transaccion(() => {
      validarDisponibilidad(sim, personas);
      return repositorio.crear({
        titular: sim.asociado,
        agencia: sim.agenciaEvento,
        total: sim.resumen.total,
        personas,
        autorizacion: { version: habeasData.version, ip: ip || null, imagen: autorizaImagen === true },
        marca: marca.toISOString(),
      }, (nuevoId) => generarReferencia(nuevoId, marca));
    });
    return detallePublico(repositorio.porId(id));
  }

  // El asociado accede con documento + fecha de expedición; la referencia es opcional (si llega, debe coincidir).
  function autenticar({ referencia, documento, fechaExpedicion }) {
    simulador.consultarAsociado(documento, fechaExpedicion);
    const doc = normalizarDocumento(documento);
    const ref = String(referencia ?? '').trim().toUpperCase();
    const inscripcion = ref ? repositorio.porReferencia(ref) : repositorio.deTitular(doc);
    if (!inscripcion || inscripcion.documento_titular !== doc) {
      throw new ErrorValidacion(ref
        ? 'No encontramos una inscripción con esa referencia para su documento.'
        : 'No encontramos una inscripción para su documento. Inscríbase en uno de los módulos de inscripción y pago.');
    }
    return inscripcion;
  }

  const consultar = (credenciales) => detallePublico(autenticar(credenciales));

  // Cambia los acompañantes y/o el evento (agencia): recalcula el total con las tarifas vigentes y vuelve a validar cupos.
  function modificar({ acompanantes, agenciaEvento, ...credenciales }) {
    const inscripcion = autenticar(credenciales);
    exigirEditable(inscripcion, 'modificarla');
    validarPeriodo();
    const sim = simulador.simular({ ...credenciales, acompanantes, agenciaEvento: agenciaEvento || inscripcion.agencia });
    const personas = personasDe(sim);
    repositorio.transaccion(() => {
      validarDisponibilidad(sim, personas, inscripcion.id);
      repositorio.guardarPersonas(inscripcion.id, personas);
      repositorio.actualizarLiquidacion(inscripcion.id, { total: sim.resumen.total, agencia: sim.agenciaEvento, marca: ahora().toISOString() });
    });
    return detallePublico(repositorio.porId(inscripcion.id));
  }

  function cancelar(credenciales) {
    const inscripcion = autenticar(credenciales);
    exigirEditable(inscripcion, 'cancelarla');
    repositorio.cambiarEstado(inscripcion.id, 'CANCELADO', 'Cancelada por el asociado', ahora().toISOString());
    return detallePublico(repositorio.porId(inscripcion.id));
  }

  function leerArchivo(archivo) {
    if (!archivo?.contenido) throw new ErrorValidacion('Adjunte el soporte de pago.');
    const bytes = Buffer.from(String(archivo.contenido), 'base64');
    if (!bytes.length) throw new ErrorValidacion('El archivo adjunto está vacío.');
    if (bytes.length > maxMb * 1024 * 1024) throw new ErrorValidacion(`El soporte supera el tamaño máximo de ${maxMb} MB.`);
    const tipo = tipoPorFirma(bytes);
    if (!tipo) throw new ErrorValidacion('El soporte debe ser un archivo PDF, JPG o PNG.');
    return { bytes, tipo, nombreOriginal: path.basename(String(archivo.nombre || 'soporte')).slice(0, 120) };
  }

  // Valida los datos del pago (sin escribir nada). Cada medio de pago (src/dominio/mediosPago.js) valida los suyos.
  // `idPropio` excluye la propia inscripción al buscar CUS o recibos repetidos (0 si aún no existe).
  function validarPago({ medioPago: codigoMedio = 'PSE', cus, banco, agenciaPago, recibo, fechaPago, valorPagado, campos, archivo }, idPropio) {
    const medio = medioPago(codigoMedio);
    const datosMedio = medio.validar({ cus, banco, agenciaPago, recibo }, {
      cusUsado: (codigo) => repositorio.cusUsado(codigo, idPropio),
      reciboUsado: (agencia, numero) => repositorio.reciboUsado(agencia, numero, idPropio),
      existeAgencia: padron.existeAgencia,
    });
    const fecha = String(fechaPago ?? '').trim();
    if (!esFechaValida(fecha)) throw new ErrorValidacion('Ingrese la fecha del pago.');
    if (fecha > fechaColombia(ahora())) throw new ErrorValidacion('La fecha del pago no puede ser futura.');
    const valor = Number(String(valorPagado ?? '').replace(/\D/g, ''));
    if (!valor) throw new ErrorValidacion('Ingrese el valor pagado.');
    const adicionales = validarCampos(camposSoporte, campos);
    // Si el medio no exige comprobante, es opcional (p. ej. foto del recibo de caja).
    const adjunto = medio.archivoObligatorio || archivo?.contenido ? leerArchivo(archivo) : null;
    return { codigoMedio, datosMedio, fecha, valor, adicionales, adjunto };
  }

  // Guarda el comprobante y ejecuta `registrar(nombreArchivo)` en una transacción; si falla, borra el archivo.
  function conComprobante(adjunto, registrar) {
    const nombreArchivo = adjunto ? almacen.guardar(adjunto.bytes, adjunto.tipo.ext) : '';
    try {
      return repositorio.transaccion(() => registrar(nombreArchivo));
    } catch (err) {
      if (nombreArchivo) almacen.borrar(nombreArchivo);
      throw err;
    }
  }

  // Registra el soporte y deja la inscripción EN_REVISION (dentro de la transacción).
  function registrarPago(inscripcionId, total, pago, nombreArchivo, marca) {
    repositorio.insertarSoporte({
      inscripcion_id: inscripcionId,
      medio_pago: pago.codigoMedio,
      ...pago.datosMedio,
      fecha_pago: pago.fecha,
      valor_pagado: pago.valor,
      campos: JSON.stringify(pago.adicionales),
      archivo: nombreArchivo,
      tipo_archivo: pago.adjunto?.tipo.tipo ?? '',
      nombre_original: pago.adjunto?.nombreOriginal ?? null,
      alerta: pago.valor !== total ? `Valor pagado ${pago.valor} distinto del total ${total}` : null,
      autorizacion_version: habeasData.version,
      cargado_en: marca,
    });
    repositorio.cambiarEstado(inscripcionId, 'EN_REVISION', null, marca);
  }

  // Formulario de pago de una inscripción existente (PREINSCRITO o RECHAZADO). El revisor verifica en el panel.
  function cargarSoporte({ autorizaDatos, referencia, documento, fechaExpedicion, ...datosPago }) {
    exigirAutorizacion(autorizaDatos);
    const inscripcion = autenticar({ referencia, documento, fechaExpedicion });
    exigirEditable(inscripcion, 'cargar el soporte');
    const pago = validarPago(datosPago, inscripcion.id);
    conComprobante(pago.adjunto, (nombreArchivo) => {
      // El cupo se ocupa al registrar el pago: si ya no alcanza, no se acepta el formulario.
      exigirCupos({
        agencia: inscripcion.agencia,
        requeridos: cuposRequeridos(repositorio.personas(inscripcion.id)),
        disponibles: repositorio.cuposDisponibles(inscripcion.agencia, inscripcion.id),
        sugerencia: ' Modifique su inscripción o comuníquese con su agencia.',
      });
      registrarPago(inscripcion.id, inscripcion.total, pago, nombreArchivo, ahora().toISOString());
    });
    return detallePublico(repositorio.porId(inscripcion.id));
  }

  // Inscripción y pago en un solo paso (módulo "Inscripción y pago en agencia"): crea la inscripción con el soporte
  // EN_REVISION y ocupa el cupo. Todo o nada: si el pago no es válido o no hay cupo, no queda la inscripción a medias.
  function inscribirConPago({ autorizaDatos, autorizaImagen, documento, fechaExpedicion, agenciaEvento, acompanantes, ...datosPago }, { ip } = {}) {
    exigirAutorizacion(autorizaDatos);
    validarPeriodo();
    const sim = simulador.simular({ documento, fechaExpedicion, agenciaEvento, acompanantes });
    const personas = personasDe(sim);
    const pago = validarPago(datosPago, 0);
    const marca = ahora();
    const id = conComprobante(pago.adjunto, (nombreArchivo) => {
      validarDisponibilidad(sim, personas); // incluye el cupo: se ocupa al registrar el pago
      const nuevoId = repositorio.crear({
        titular: sim.asociado,
        agencia: sim.agenciaEvento,
        total: sim.resumen.total,
        personas,
        autorizacion: { version: habeasData.version, ip: ip || null, imagen: autorizaImagen === true },
        marca: marca.toISOString(),
      }, (idCreado) => generarReferencia(idCreado, marca));
      registrarPago(nuevoId, sim.resumen.total, pago, nombreArchivo, marca.toISOString());
      return nuevoId;
    });
    return detallePublico(repositorio.porId(id));
  }

  function detallePublico(i) {
    const ultimo = repositorio.ultimoSoporte(i.id);
    return {
      referencia: i.referencia,
      estado: i.estado,
      motivo: i.motivo,
      agencia: i.agencia,
      agenciaAsociado: i.agencia_asociado,
      titular: i.nombre_titular,
      total: i.total,
      personas: repositorio.personas(i.id),
      creadaEn: i.creada_en,
      actualizadaEn: i.actualizada_en,
      editable: esEditable(i.estado),
      soporte: ultimo && {
        medioPago: ultimo.medio_pago, cus: ultimo.cus, banco: ultimo.banco, agenciaPago: ultimo.agencia_pago, recibo: ultimo.recibo,
        fechaPago: ultimo.fecha_pago, valorPagado: ultimo.valor_pagado, conArchivo: !!ultimo.archivo, cargadoEn: ultimo.cargado_en,
      },
    };
  }

  // ---- Administración ----
  // Contador de cupos por agencia del evento: solo cuentan quienes ocupan cupo; los invitados se informan aparte.
  const cupos = () => repositorio.cupos().map((c) => ({ ...c, disponibles: Math.max(0, c.cupos - c.ocupados) }));

  // Ajusta el cupo de una agencia desde el panel. null o vacío vuelve al valor del Excel.
  function ajustarCupos(agencia, valor, usuario) {
    const actual = cupos().find((c) => c.agencia === agencia);
    if (!actual) throw new ErrorValidacion('Agencia no encontrada.');
    if (valor === null || valor === undefined || valor === '') {
      repositorio.quitarAjusteCupos(agencia);
    } else {
      const cantidad = Number(valor);
      if (!Number.isInteger(cantidad) || cantidad < 0) throw new ErrorValidacion('El cupo debe ser un número entero mayor o igual a cero.');
      if (cantidad < actual.ocupados) {
        throw new ErrorValidacion(`${agencia} ya tiene ${actual.ocupados} cupos ocupados; no puede fijar un cupo menor.`);
      }
      repositorio.ajustarCupos(agencia, cantidad, usuario, ahora().toISOString());
    }
    return cupos().find((c) => c.agencia === agencia);
  }

  const listar = (filtros) => repositorio.listar(filtros);

  function detalleAdmin(id) {
    const i = repositorio.porId(Number(id));
    if (!i) throw new ErrorValidacion('Inscripción no encontrada.');
    return {
      ...i,
      personas: repositorio.personas(i.id),
      // La ruta interna del archivo no se expone; solo si existe comprobante adjunto.
      soportes: repositorio.soportes(i.id).map(({ archivo, campos, ...s }) => ({ ...s, tiene_archivo: !!archivo, campos: JSON.parse(campos) })),
    };
  }

  function archivoSoporte(idSoporte) {
    const s = repositorio.soporte(Number(idSoporte));
    if (!s) throw new ErrorValidacion('Soporte no encontrado.');
    if (!s.archivo) throw new ErrorValidacion('Este pago se registró sin comprobante adjunto.');
    return { ruta: almacen.ruta(s.archivo), tipo: s.tipo_archivo, nombre: s.nombre_original };
  }

  function revisar(id, { accion, motivo }, usuario) {
    const i = repositorio.porId(Number(id));
    if (!Object.hasOwn(TRANSICIONES, accion)) throw new ErrorValidacion('Acción no válida.');
    if (!i) throw new ErrorValidacion('Inscripción no encontrada.');
    const cambio = transicion(accion, i.estado, motivo);
    repositorio.registrarRevision(i.id, { estado: cambio.hacia, motivo: cambio.motivo, usuario, marca: ahora().toISOString() });
    return detalleAdmin(i.id);
  }

  return {
    preinscribir, inscribirConPago, consultar, modificar, cancelar, cargarSoporte, cupos, ajustarCupos, listar, detalleAdmin, archivoSoporte, revisar,
    exportarCsv: exportacion.inscripcionesCsv,
  };
}

module.exports = { crearInscripciones, ESTADOS_ACTIVOS, ESTADOS_CUPO };
