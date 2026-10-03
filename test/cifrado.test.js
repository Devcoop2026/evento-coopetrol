const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const crypto = require('node:crypto');
const { claveRespaldo, cifrarArchivo, descifrarArchivo } = require('../src/infraestructura/cifrado');

test('respaldos: cifra y descifra; rechaza clave equivocada o archivo alterado', () => {
  const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'cifrado-'));
  const clave = claveRespaldo(crypto.randomBytes(32).toString('hex'));
  fs.writeFileSync(path.join(dir, 'datos.txt'), 'cédula 1001 · datos personales');
  cifrarArchivo(path.join(dir, 'datos.txt'), path.join(dir, 'datos.enc'), clave);
  assert.ok(!fs.readFileSync(path.join(dir, 'datos.enc')).includes('1001'), 'el archivo cifrado no contiene el texto');
  descifrarArchivo(path.join(dir, 'datos.enc'), path.join(dir, 'ok.txt'), clave);
  assert.equal(fs.readFileSync(path.join(dir, 'ok.txt'), 'utf8'), 'cédula 1001 · datos personales');
  assert.throws(() => descifrarArchivo(path.join(dir, 'datos.enc'), path.join(dir, 'x'), crypto.randomBytes(32)), /no corresponde/);
  const alterado = fs.readFileSync(path.join(dir, 'datos.enc'));
  alterado[alterado.length - 1] ^= 1;
  fs.writeFileSync(path.join(dir, 'alterado.enc'), alterado);
  assert.throws(() => descifrarArchivo(path.join(dir, 'alterado.enc'), path.join(dir, 'x'), clave), /alterado/);
  assert.throws(() => claveRespaldo('corta'), /64 caracteres/);
  assert.equal(claveRespaldo(''), null);
});
