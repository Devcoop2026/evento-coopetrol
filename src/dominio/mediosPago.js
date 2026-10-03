// Medios de pago (estrategias). Cada uno valida sus propios datos y dice si el comprobante es obligatorio.
// Para agregar un medio nuevo basta con añadir una entrada aquí (y su opción en el formulario de public/app.js).
//
// `validar(datos, puertos)` devuelve los datos del soporte { cus, banco, agencia_pago, recibo } o lanza el error.
// (El banco ya no se pide en el formulario; la columna se conserva para los soportes registrados antes.)
// `puertos` son consultas a la base que inyecta el caso de uso:
//   cusUsado(cus) / reciboUsado(agencia, recibo)  -> referencia de otra inscripción vigente que ya lo usó
//   existeAgencia(agencia)                        -> true si la agencia tiene evento
const { ErrorValidacion } = require('./errores');

const MEDIOS_PAGO = {
  // PSE: CUS y comprobante obligatorios.
  PSE: {
    etiqueta: 'PSE',
    archivoObligatorio: true,
    validar({ cus }, { cusUsado }) {
      const codigo = String(cus ?? '').replace(/\s/g, '');
      if (!/^\d{4,20}$/.test(codigo)) throw new ErrorValidacion('Ingrese el número CUS de la transacción PSE (solo números).');
      if (cusUsado(codigo)) throw new ErrorValidacion('Ese número CUS ya fue registrado en otra inscripción.');
      return { cus: codigo, banco: '', agencia_pago: null, recibo: null };
    },
  },
  // Pago directo en agencia o en efectivo: agencia obligatoria; recibo de caja y comprobante opcionales.
  AGENCIA: {
    etiqueta: 'Agencia / efectivo',
    archivoObligatorio: false,
    validar({ agenciaPago, recibo }, { existeAgencia, reciboUsado }) {
      const agencia = String(agenciaPago ?? '').trim().toUpperCase();
      if (!existeAgencia(agencia)) throw new ErrorValidacion('Seleccione la agencia donde realizó el pago.');
      const numero = String(recibo ?? '').replace(/\s/g, '').toUpperCase() || null;
      if (numero && !/^[A-Z0-9-]{3,30}$/.test(numero)) throw new ErrorValidacion('El número de recibo no es válido.');
      if (numero && reciboUsado(agencia, numero)) throw new ErrorValidacion('Ese número de recibo ya fue registrado en otra inscripción.');
      return { cus: '', banco: '', agencia_pago: agencia, recibo: numero };
    },
  },
};

function medioPago(codigo) {
  const medio = Object.hasOwn(MEDIOS_PAGO, codigo) ? MEDIOS_PAGO[codigo] : null;
  if (!medio) throw new ErrorValidacion('Seleccione cómo realizó el pago.');
  return medio;
}

module.exports = { MEDIOS_PAGO, medioPago };
