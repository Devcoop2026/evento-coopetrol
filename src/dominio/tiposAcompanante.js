// Tipos de acompañante (estrategias). Se evalúan en orden y gana el primero que aplica; INVITADO aplica siempre.
// Para agregar un tipo nuevo basta con añadir una entrada aquí: `aplica` decide, `validar` exige sus condiciones,
// `valor` toma la tarifa y `ocupaCupo` indica si cuenta en el cupo de la agencia.
//
// Contexto que recibe cada función:
//   registro      asociado con ese documento en la base (o undefined)
//   solicitado    tipo que eligió el usuario en el formulario (p. ej. 'COOPETROLITO')
//   coopetrolito  () => registro en la base de Coopetrolitos (consulta perezosa)
//   titular       documento del asociado titular
//   documento, etiqueta ("Acompañante 2")
const { ErrorValidacion } = require('./errores');

const TIPOS = [
  {
    tipo: 'ASOCIADO', // asociado ACTIVO: se detecta por documento
    ocupaCupo: true,
    aplica: ({ registro }) => registro?.estado === 'ACTIVO',
    valor: (tarifa) => tarifa.valor_asociado,
  },
  {
    tipo: 'COOPETROLITO', // hijo del titular, validado contra la base de Coopetrolitos
    ocupaCupo: true,
    aplica: ({ solicitado }) => solicitado === 'COOPETROLITO',
    validar: ({ coopetrolito, titular, documento, etiqueta }) => {
      const hijo = coopetrolito();
      if (!hijo || hijo.documento_asociado !== titular) {
        throw new ErrorValidacion(`${etiqueta}: el documento ${documento} no figura como Coopetrolito vinculado a su cuenta de asociado.`);
      }
    },
    valor: (tarifa) => tarifa.valor_asociado,
  },
  {
    tipo: 'INVITADO', // no asociado o asociado inactivo
    ocupaCupo: false,
    aplica: () => true,
    valor: (tarifa) => tarifa.valor_invitado,
    observacion: ({ registro }) => (registro ? 'Asociado inactivo: se liquida como invitado' : null),
  },
];

// Devuelve { tipo, valor, observacion } del acompañante según la tarifa del evento.
function clasificarAcompanante(contexto, tarifa) {
  const estrategia = TIPOS.find((t) => t.aplica(contexto));
  estrategia.validar?.(contexto);
  return { tipo: estrategia.tipo, valor: estrategia.valor(tarifa), observacion: estrategia.observacion?.(contexto) ?? null };
}

// Tipos de persona que no ocupan cupo (el titular siempre lo ocupa).
const TIPOS_SIN_CUPO = TIPOS.filter((t) => !t.ocupaCupo).map((t) => t.tipo);
const ocupaCupo = (tipo) => !TIPOS_SIN_CUPO.includes(tipo);

module.exports = { TIPOS, clasificarAcompanante, TIPOS_SIN_CUPO, ocupaCupo };
