// Pruebas unitarias del dominio (sin base de datos) y del enrutador HTTP.
const test = require('node:test');
const assert = require('node:assert');
const { clasificarAcompanante, ocupaCupo } = require('../src/dominio/tiposAcompanante');
const { medioPago } = require('../src/dominio/mediosPago');
const { transicion, cuposRequeridos, exigirCupos, generarReferencia } = require('../src/dominio/inscripcion');
const { validarDocumento, validarNombreCompleto, validarFecha } = require('../src/dominio/valores');
const { validarCampos } = require('../src/dominio/camposFormulario');
const { crearEnrutador } = require('../src/http/enrutador');

const tarifa = { valor_asociado: 100, valor_invitado: 300 };
const base = { documento: '9001', etiqueta: 'Acompañante 1', titular: '1001', coopetrolito: () => undefined };

test('tipos de acompañante: asociado activo, Coopetrolito del titular e invitado', () => {
  assert.deepStrictEqual(clasificarAcompanante({ ...base, registro: { estado: 'ACTIVO' } }, tarifa),
    { tipo: 'ASOCIADO', valor: 100, observacion: null });
  assert.deepStrictEqual(clasificarAcompanante({ ...base, solicitado: 'COOPETROLITO', coopetrolito: () => ({ documento_asociado: '1001' }) }, tarifa),
    { tipo: 'COOPETROLITO', valor: 100, observacion: null });
  assert.throws(() => clasificarAcompanante({ ...base, solicitado: 'COOPETROLITO', coopetrolito: () => ({ documento_asociado: '2002' }) }, tarifa),
    /no figura como Coopetrolito/);
  assert.deepStrictEqual(clasificarAcompanante({ ...base, registro: { estado: 'INACTIVO' } }, tarifa),
    { tipo: 'INVITADO', valor: 300, observacion: 'Asociado inactivo: se liquida como invitado' });
  assert.ok(ocupaCupo('TITULAR') && ocupaCupo('COOPETROLITO') && !ocupaCupo('INVITADO'));
});

test('medios de pago: PSE exige CUS (el banco ya no se pide); agencia exige agencia y el recibo es opcional', () => {
  const puertos = { cusUsado: () => undefined, reciboUsado: () => undefined, existeAgencia: (a) => a === 'BOGOTA' };
  assert.deepStrictEqual(medioPago('PSE').validar({ cus: '12 345' }, puertos),
    { cus: '12345', banco: '', agencia_pago: null, recibo: null });
  assert.deepStrictEqual(medioPago('PSE').validar({ cus: '12345', banco: '<script>' }, puertos).banco, '', 'un banco enviado se ignora');
  assert.throws(() => medioPago('PSE').validar({ cus: 'abc' }, puertos), /CUS/);
  assert.throws(() => medioPago('PSE').validar({ cus: '12345' }, { ...puertos, cusUsado: () => 'EVT26-000001' }), /ya fue registrado/);
  assert.deepStrictEqual(medioPago('AGENCIA').validar({ agenciaPago: 'bogota' }, puertos),
    { cus: '', banco: '', agencia_pago: 'BOGOTA', recibo: null });
  assert.throws(() => medioPago('AGENCIA').validar({ agenciaPago: 'CALI' }, puertos), /agencia/);
  assert.ok(medioPago('PSE').archivoObligatorio && !medioPago('AGENCIA').archivoObligatorio);
  assert.throws(() => medioPago('EFECTIVO'), /cómo realizó el pago/);
  assert.throws(() => medioPago('toString'), /cómo realizó el pago/, 'no acepta propiedades heredadas');
});

test('ciclo de vida: transiciones del revisor, cupos y referencia', () => {
  assert.deepStrictEqual(transicion('APROBAR', 'EN_REVISION'), { hacia: 'CONFIRMADO', motivo: null });
  assert.throws(() => transicion('APROBAR', 'PREINSCRITO'), /No se puede aprobar/);
  assert.throws(() => transicion('RECHAZAR', 'EN_REVISION', '  '), /motivo/);
  assert.throws(() => transicion('BORRAR', 'EN_REVISION'), /Acción no válida/);
  assert.strictEqual(cuposRequeridos([{ tipo: 'TITULAR' }, { tipo: 'INVITADO' }, { tipo: 'ASOCIADO' }]), 2);
  assert.throws(() => exigirCupos({ agencia: 'BOGOTA', requeridos: 3, disponibles: 2 }), /quedan 2 y su inscripción requiere 3/);
  assert.throws(() => exigirCupos({ agencia: 'BOGOTA', requeridos: 1, disponibles: 0 }), /^Error: Lo sentimos, se ha superado el límite de cupos disponibles para el evento de BOGOTA\.$/);
  assert.doesNotThrow(() => exigirCupos({ agencia: 'BOGOTA', requeridos: 2, disponibles: 2 }));
  assert.strictEqual(generarReferencia(42, new Date('2026-10-01T12:00:00Z')), 'EVT26-000042');
});

test('valores: documento, nombre completo, fechas y campos condicionales', () => {
  assert.strictEqual(validarDocumento('1.234.567-8'), '12345678');
  assert.throws(() => validarDocumento(''), /^Error: Ingrese el número de documento\.$/);
  assert.throws(() => validarDocumento('12', { etiqueta: 'Acompañante 1' }), /^Error: Acompañante 1: el número de documento debe tener entre 4 y 15/);
  assert.strictEqual(validarNombreCompleto('  Ana   Pérez '), 'Ana Pérez');
  assert.throws(() => validarNombreCompleto('Ana'), /nombres y apellidos/);
  assert.strictEqual(validarFecha('', 'la fecha', { opcional: true }), null);
  assert.throws(() => validarFecha('2026-02-30', 'la fecha de pago'), /^Error: La fecha de pago no es válida\.$/);
  const definicion = [
    { id: 'vehiculo', etiqueta: 'Vehículo', tipo: 'seleccion', opciones: ['Sí', 'No'], requerido: true },
    { id: 'placa', etiqueta: 'Placa', tipo: 'placa', requerido: true, mostrarSi: { campo: 'vehiculo', valor: 'Sí' } },
  ];
  assert.deepStrictEqual(validarCampos(definicion, { vehiculo: 'No', placa: 'basura' }), { vehiculo: 'No' });
  assert.deepStrictEqual(validarCampos(definicion, { vehiculo: 'Sí', placa: 'abc-12d' }), { vehiculo: 'Sí', placa: 'ABC12D' });
  assert.throws(() => validarCampos(definicion, { vehiculo: 'Sí' }), /Complete el campo "Placa"/);
});

test('enrutador: parámetros con patrón, decodificación diferida y método', () => {
  const buscar = crearEnrutador([
    { metodo: 'GET', ruta: '/inscripciones/:id(\\d+)/revision' },
    { metodo: 'GET', ruta: '/bases/:tipo(asociados|coopetrolitos)/plantilla.csv' },
    { metodo: 'PUT', ruta: '/asociados/:clave' },
  ]);
  assert.deepStrictEqual(buscar('GET', '/inscripciones/12/revision').params, { id: '12' });
  assert.strictEqual(buscar('GET', '/inscripciones/ab/revision'), null);
  assert.deepStrictEqual(buscar('GET', '/bases/asociados/plantilla.csv').params, { tipo: 'asociados' });
  assert.strictEqual(buscar('GET', '/bases/asociados/plantillaXcsv'), null, 'el punto es literal');
  assert.strictEqual(buscar('GET', '/asociados/1001'), null, 'otro método');
  const malCodificada = buscar('PUT', '/asociados/%E0%A4%A');
  assert.strictEqual(malCodificada.crudos.clave, '%E0%A4%A');
  assert.throws(() => malCodificada.params, URIError);
  assert.deepStrictEqual(buscar('PUT', '/asociados/a%20b').params, { clave: 'a b' });
});
