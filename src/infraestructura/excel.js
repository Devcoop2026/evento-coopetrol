// Lectura de hojas de cálculo sin dependencias: .xlsx (ZIP + XML) y .csv (separado por ; o ,).
// Devuelve la primera hoja como matriz de filas; las celdas numéricas llegan como número
// (las fechas de Excel son números de serie: usar fechaDesdeExcel).
const zlib = require('node:zlib');

const ZIP_FIRMA = Buffer.from([0x50, 0x4b, 0x03, 0x04]);
const MAX_DESCOMPRIMIDO = 200 * 1024 * 1024; // protección contra archivos ZIP maliciosos

function leerZip(buffer) {
  // Fin del directorio central: firma 0x06054b50 cerca del final del archivo.
  let fin = -1;
  for (let i = buffer.length - 22; i >= Math.max(0, buffer.length - 65557); i--) {
    if (buffer.readUInt32LE(i) === 0x06054b50) { fin = i; break; }
  }
  if (fin < 0) throw new Error('El archivo no es un Excel (.xlsx) válido.');
  const total = buffer.readUInt16LE(fin + 10);
  let pos = buffer.readUInt32LE(fin + 16);
  const archivos = new Map();
  let descomprimido = 0;
  for (let n = 0; n < total; n++) {
    if (buffer.readUInt32LE(pos) !== 0x02014b50) throw new Error('El archivo Excel está dañado.');
    const metodo = buffer.readUInt16LE(pos + 10);
    const comprimido = buffer.readUInt32LE(pos + 20);
    const tamano = buffer.readUInt32LE(pos + 24);
    const largoNombre = buffer.readUInt16LE(pos + 28);
    const largoExtra = buffer.readUInt16LE(pos + 30);
    const largoComentario = buffer.readUInt16LE(pos + 32);
    const local = buffer.readUInt32LE(pos + 42);
    const nombre = buffer.toString('utf8', pos + 46, pos + 46 + largoNombre);
    pos += 46 + largoNombre + largoExtra + largoComentario;
    descomprimido += tamano;
    if (descomprimido > MAX_DESCOMPRIMIDO) throw new Error('El archivo Excel es demasiado grande.');
    archivos.set(nombre, () => {
      const inicio = local + 30 + buffer.readUInt16LE(local + 26) + buffer.readUInt16LE(local + 28);
      const datos = buffer.subarray(inicio, inicio + comprimido);
      if (metodo === 0) return datos;
      if (metodo === 8) return zlib.inflateRawSync(datos, { maxOutputLength: MAX_DESCOMPRIMIDO });
      throw new Error('Compresión de Excel no soportada.');
    });
  }
  return archivos;
}

const ENTIDADES = { amp: '&', lt: '<', gt: '>', quot: '"', apos: "'" };
const decodificar = (t) => t.replace(/&(#x[0-9a-f]+|#\d+|\w+);/gi, (m, e) => {
  if (e[0] === '#') return String.fromCodePoint(e[1].toLowerCase() === 'x' ? parseInt(e.slice(2), 16) : Number(e.slice(1)));
  return ENTIDADES[e] ?? m;
});
const textoDe = (xml) => decodificar([...xml.matchAll(/<t(?:\s[^>]*)?>([\s\S]*?)<\/t>/g)].map((m) => m[1]).join(''));

function columna(ref) {
  let n = 0;
  for (const ch of ref.replace(/\d+/g, '')) n = n * 26 + (ch.charCodeAt(0) - 64);
  return n - 1;
}

function leerXlsx(buffer) {
  const zip = leerZip(buffer);
  const leer = (nombre) => zip.get(nombre)?.().toString('utf8');

  const libro = leer('xl/workbook.xml');
  if (!libro) throw new Error('El archivo no es un Excel (.xlsx) válido.');
  const fecha1904 = /date1904="(1|true)"/.test(libro);
  const idHoja = libro.match(/<sheet\b[^>]*\br:id="([^"]+)"/)?.[1];
  const relaciones = leer('xl/_rels/workbook.xml.rels') || '';
  const destino = [...relaciones.matchAll(/<Relationship\b[^>]*>/g)].map((m) => m[0])
    .find((r) => r.includes(`Id="${idHoja}"`))?.match(/Target="([^"]+)"/)?.[1] || 'worksheets/sheet1.xml';
  const rutaHoja = destino.startsWith('/') ? destino.slice(1) : `xl/${destino.replace(/^\.\//, '')}`;

  const compartidas = [...(leer('xl/sharedStrings.xml') || '').matchAll(/<si>([\s\S]*?)<\/si>/g)].map((m) => textoDe(m[1]));
  const hoja = leer(rutaHoja);
  if (!hoja) throw new Error('No se encontró la primera hoja del Excel.');

  const filas = [];
  for (const [, contenido] of hoja.matchAll(/<row\b[^>]*>([\s\S]*?)<\/row>/g)) {
    const fila = [];
    for (const [, atributos, cuerpo = ''] of contenido.matchAll(/<c\b([^>]*?)(?:\/>|>([\s\S]*?)<\/c>)/g)) {
      const ref = atributos.match(/\br="([A-Z]+\d+)"/)?.[1];
      const tipo = atributos.match(/\bt="(\w+)"/)?.[1];
      const v = cuerpo.match(/<v>([\s\S]*?)<\/v>/)?.[1];
      let valor = null;
      if (tipo === 's') valor = compartidas[Number(v)] ?? '';
      else if (tipo === 'inlineStr') valor = textoDe(cuerpo);
      else if (tipo === 'str' || tipo === 'e') valor = v == null ? null : decodificar(v);
      else if (tipo === 'b') valor = v === '1';
      else if (v != null) valor = Number(v);
      fila[ref ? columna(ref) : fila.length] = valor;
    }
    filas.push(Array.from(fila, (x) => x ?? null));
  }
  return { filas, fecha1904 };
}

function leerCsv(buffer) {
  let texto;
  try {
    texto = new TextDecoder('utf-8', { fatal: true }).decode(buffer);
  } catch {
    texto = new TextDecoder('windows-1252').decode(buffer); // CSV guardado desde Excel en Windows
  }
  texto = texto.replace(/^﻿/, '');
  const primera = texto.split(/\r?\n/, 1)[0];
  const sep = (primera.match(/;/g) || []).length > (primera.match(/,/g) || []).length ? ';' : ',';
  const filas = [];
  let fila = [];
  let campo = '';
  let comillas = false;
  for (let i = 0; i < texto.length; i++) {
    const ch = texto[i];
    if (comillas) {
      if (ch === '"' && texto[i + 1] === '"') { campo += '"'; i++; } else if (ch === '"') comillas = false;
      else campo += ch;
    } else if (ch === '"') comillas = true;
    else if (ch === sep) { fila.push(campo); campo = ''; }
    else if (ch === '\n' || ch === '\r') {
      if (ch === '\r' && texto[i + 1] === '\n') i++;
      fila.push(campo); filas.push(fila); fila = []; campo = '';
    } else campo += ch;
  }
  if (campo || fila.length) { fila.push(campo); filas.push(fila); }
  return { filas, fecha1904: false };
}

// Detecta el formato por contenido (no por extensión).
function leerHoja(buffer) {
  if (!buffer?.length) throw new Error('El archivo está vacío.');
  return buffer.subarray(0, 4).equals(ZIP_FIRMA) ? leerXlsx(buffer) : leerCsv(buffer);
}

// Número de serie de Excel -> 'AAAA-MM-DD'.
function fechaDesdeExcel(serie, fecha1904 = false) {
  const base = fecha1904 ? Date.UTC(1904, 0, 1) : Date.UTC(1899, 11, 30);
  return new Date(base + Math.round(serie) * 86400000).toISOString().slice(0, 10);
}

module.exports = { leerHoja, fechaDesdeExcel };
