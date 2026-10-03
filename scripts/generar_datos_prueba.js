// Genera bases FICTICIAS para pruebas: 20 asociados y 20 Coopetrolitos, en el formato de la carga masiva del panel.
// Uso: node scripts/generar_datos_prueba.js [carpeta]   (por defecto docs/prueba)
// Cárguelas en el panel (Cupos y cargas masivas): primero asociados, luego Coopetrolitos. Nunca en producción con datos reales.
const fs = require('node:fs');
const path = require('node:path');

const destino = process.argv[2] || path.join(__dirname, '..', 'docs', 'prueba');
const hoy = new Date();
const fecha = (d) => `${String(d.getDate()).padStart(2, '0')}/${String(d.getMonth() + 1).padStart(2, '0')}/${d.getFullYear()}`;
const haceMeses = (m) => fecha(new Date(hoy.getFullYear(), hoy.getMonth() - m, Math.min(hoy.getDate(), 28)));

// [cédula, nombre, agencia, asociado, meses desde la actualización (null = sin dato), fecha de expedición, fecha de nacimiento]
const ASOCIADOS = [
  ['77000001', 'Andrea Paola Rincón Mejía', 'BOGOTA', 'SI', 2, '14/03/2008', '12/04/1980'],
  ['77000002', 'Carlos Andrés Peña Suárez', 'BOGOTA', 'SI', 5, '22/07/2010', '03/09/1975'],
  ['77000003', 'Luz Marina Ocampo Díaz', 'BOGOTA NORTE', 'SI', 1, '09/01/2012', '25/01/1988'],
  ['77000004', 'Jorge Eliécer Bautista Ruiz', 'MEDELLIN', 'SI', 8, '30/11/2005', '17/06/1969'],
  ['77000005', 'Diana Marcela Castaño Vélez', 'MEDELLIN', 'SI', 3, '18/05/2009', '08/11/1984'],
  ['77000006', 'Héctor Fabio Lozano Muñoz', 'CALI', 'SI', 6, '02/08/2011', '30/03/1979'],
  ['77000007', 'Paola Andrea Quintero Ríos', 'CALI', 'SI', 10, '25/02/2003', '14/07/1990'],
  ['77000008', 'Ricardo José Salcedo Pérez', 'CARTAGENA', 'SI', 4, '11/10/2007', '21/02/1972'],
  ['77000009', 'Mónica Patricia Herrera Gil', 'PTO. MAMONAL', 'SI', 7, '06/06/2006', '05/10/1983'],
  ['77000010', 'Fernando Antonio Duarte León', 'BUCARAMANGA', 'SI', 2, '19/09/2013', '19/12/1986'],
  ['77000011', 'Sandra Milena Torres Cano', 'BARRANCABERMEJA', 'SI', 9, '28/04/2004', '27/05/1977'],
  ['77000012', 'Óscar Iván Moreno Plata', 'CUCUTA', 'SI', 1, '15/12/2014', '09/08/1992'],
  ['77000013', 'Claudia Lorena Vargas Niño', 'NEIVA', 'SI', 11, '03/03/2002', '02/03/1970'],
  ['77000014', 'Julián David Arango Zapata', 'MANIZALES', 'SI', 5, '21/07/2015', '16/09/1989'],
  ['77000015', 'Natalia Andrea Guzmán Rojas', 'VILLAVICENCIO', 'SI', 3, '08/08/2016', '23/11/1991'],
  ['77000016', 'Edwin Alberto Cárdenas Mora', 'ORITO', 'SI', 6, '17/01/2009', '11/01/1982'],
  ['77000017', 'Yolanda Esther Pacheco Ruiz', 'PASTO', 'SI', 4, '12/05/2001', '28/06/1968'],
  // Casos para probar validaciones:
  ['77000018', 'Mauricio Javier Ospina Lara', 'BOGOTA', 'NO', 2, '27/10/2006', '06/04/1985'], // inactivo: no puede inscribirse; como acompañante paga tarifa de invitado
  ['77000019', 'Gloria Inés Restrepo Sáenz', 'BOGOTA', 'SI', 15, '04/02/2000', '13/10/1965'], // datos desactualizados (> 12 meses)
  ['77000020', 'Wilson Enrique Mejía Barón', 'CALI', 'SI', null, '13/09/2011', '20/08/1981'], // sin fecha de actualización
];

// [documento, nombre, cédula del asociado, fecha de nacimiento]
const COOPETROLITOS = [
  ['1100770001', 'Sofía Rincón Peña', '77000001', '14/02/2016'],
  ['1100770002', 'Samuel Rincón Peña', '77000001', '03/07/2019'],
  ['1100770003', 'Valentina Peña Ocampo', '77000002', '22/09/2014'],
  ['1100770004', 'Mateo Bautista Castaño', '77000004', '10/05/2013'],
  ['1100770005', 'Isabella Bautista Castaño', '77000004', '18/12/2017'],
  ['1100770006', 'Emiliano Castaño Vélez', '77000005', '07/03/2015'],
  ['1100770007', 'Martina Lozano Quintero', '77000006', '25/06/2018'],
  ['1100770008', 'Jerónimo Lozano Quintero', '77000006', '01/11/2020'],
  ['1100770009', 'Salomé Quintero Ríos', '77000007', '12/08/2012'],
  ['1100770010', 'Tomás Salcedo Herrera', '77000008', '29/04/2016'],
  ['1100770011', 'Antonella Herrera Gil', '77000009', '16/01/2014'],
  ['1100770012', 'Santiago Duarte Torres', '77000010', '04/10/2013'],
  ['1100770013', 'Mariana Duarte Torres', '77000010', '21/05/2019'],
  ['1100770014', 'Gabriel Moreno Vargas', '77000012', '09/09/2015'],
  ['1100770015', 'Luciana Arango Guzmán', '77000014', '27/02/2017'],
  ['1100770016', 'Daniel Arango Guzmán', '77000014', '13/06/2021'],
  ['1100770017', 'Victoria Cárdenas Pacheco', '77000016', '05/12/2012'],
  ['1100770018', 'Nicolás Cárdenas Pacheco', '77000016', '19/03/2018'],
  ['1100770019', 'Juliana Ospina Restrepo', '77000018', '08/07/2016'], // hijo de un asociado inactivo
  ['1100770020', 'Agustín Mejía Barón', '77000020', '30/10/2014'],
];

const csv = (filas) => `﻿${filas.map((f) => f.join(';')).join('\r\n')}\r\n`; // BOM: Excel abre las tildes bien
fs.mkdirSync(destino, { recursive: true });
fs.writeFileSync(path.join(destino, 'asociados_prueba.csv'), csv([
  ['Cedula', 'Nombre', 'Agencia', 'Asociado', 'actualizacion_datos', 'fecha_expedicion', 'fecha_nacimiento'],
  ...ASOCIADOS.map(([doc, nombre, agencia, activo, meses, expedicion, nacimiento]) => [
    doc, nombre, agencia, activo, meses === null ? '' : haceMeses(meses), expedicion, nacimiento]),
]));
fs.writeFileSync(path.join(destino, 'coopetrolitos_prueba.csv'), csv([
  ['Documento', 'Nombre', 'Cedula asociado', 'fecha_nacimiento'],
  ...COOPETROLITOS,
]));
console.log(`Generados en ${destino}: asociados_prueba.csv (${ASOCIADOS.length}) y coopetrolitos_prueba.csv (${COOPETROLITOS.length}).`);
