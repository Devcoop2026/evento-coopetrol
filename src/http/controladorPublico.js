// Rutas públicas (/api/...): datos del evento, cupos e inscripción del asociado.
// `identidad: true` marca las rutas que validan documento + fecha de expedición: sus fallos cuentan por IP.
const { ErrorValidacion } = require('../dominio/errores');
const { LIMITE_JSON } = require('./respuestas');

function rutasPublicas({ simulador, inscripciones, padron, config, camposSoporte, habeasData, maxAcompanantes }) {
  const limiteSoporte = Math.ceil((config.soporte_max_mb || 5) * 1024 * 1024 * 1.4) + LIMITE_JSON; // base64 ≈ +33 %

  return [
    {
      metodo: 'GET',
      ruta: '/api/evento',
      manejador: () => ({
        evento: padron.evento(),
        tarifas: padron.tarifas(),
        // Agencias y puntos de atención (donde se paga; en eventos compartidos aparecen por separado).
        agencias: padron.agencias(),
        maxAcompanantes,
        camposSoporte,
        habeasData,
        enlacePago: config.enlace_pago_pse,
        enlaceCanales: config.enlace_canales_pago,
        soporteMaxMb: config.soporte_max_mb || 5,
        inscripcionesDesde: config.inscripciones_desde,
        inscripcionesHasta: config.inscripciones_hasta,
      }),
    },
    // Contador público de cupos disponibles por evento (agencia).
    {
      metodo: 'GET',
      ruta: '/api/cupos',
      manejador: () => inscripciones.cupos().map(({ agencia, cupos, disponibles }) => ({ agencia, cupos, disponibles })),
    },
    {
      metodo: 'POST',
      ruta: '/api/identificar',
      identidad: true,
      manejador: async ({ cuerpo }) => {
        const datos = await cuerpo();
        if (datos.autorizaDatos !== true) {
          throw new ErrorValidacion('Debe aceptar la autorización para el tratamiento de datos personales para continuar.');
        }
        const { asociado, tarifa } = simulador.consultarAsociado(datos.documento, datos.fechaExpedicion);
        return {
          nombre: asociado.nombre, agencia: asociado.agencia, agenciaEvento: tarifa.agencia, fechaActualizacion: asociado.fecha_actualizacion, tarifa,
        };
      },
    },
    { metodo: 'POST', ruta: '/api/simular', identidad: true, manejador: async ({ cuerpo }) => simulador.simular(await cuerpo()) },
    {
      metodo: 'POST',
      ruta: '/api/inscripciones',
      identidad: true,
      manejador: async ({ cuerpo, ip }) => inscripciones.preinscribir(await cuerpo(), { ip }),
    },
    { metodo: 'POST', ruta: '/api/inscripciones/consultar', identidad: true, manejador: async ({ cuerpo }) => inscripciones.consultar(await cuerpo()) },
    { metodo: 'POST', ruta: '/api/inscripciones/modificar', identidad: true, manejador: async ({ cuerpo }) => inscripciones.modificar(await cuerpo()) },
    { metodo: 'POST', ruta: '/api/inscripciones/cancelar', identidad: true, manejador: async ({ cuerpo }) => inscripciones.cancelar(await cuerpo()) },
    // Inscripción y pago en un solo paso (módulo de pago en agencia).
    {
      metodo: 'POST',
      ruta: '/api/inscripciones/con-pago',
      identidad: true,
      limiteCuerpo: limiteSoporte,
      manejador: async ({ cuerpo, ip }) => inscripciones.inscribirConPago(await cuerpo(), { ip }),
    },
    {
      metodo: 'POST',
      ruta: '/api/inscripciones/soporte',
      identidad: true,
      limiteCuerpo: limiteSoporte,
      manejador: async ({ cuerpo }) => inscripciones.cargarSoporte(await cuerpo()),
    },
  ];
}

module.exports = { rutasPublicas };
