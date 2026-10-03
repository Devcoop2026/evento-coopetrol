const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const { execFileSync } = require('node:child_process');
const { crearTarGz } = require('../src/infraestructura/empaquetar');

test('crea un .tar.gz estándar que tar extrae con carpetas, binarios y nombres largos', async () => {
  const base = fs.mkdtempSync(path.join(os.tmpdir(), 'tar-'));
  const largo = `${'carpeta-con-nombre-largo/'.repeat(5)}archivo-${'x'.repeat(60)}.pdf`; // > 100 caracteres
  fs.mkdirSync(path.join(base, 'soportes', path.dirname(largo)), { recursive: true });
  const binario = Buffer.from(Array.from({ length: 1500 }, (_, i) => i % 256));
  fs.writeFileSync(path.join(base, 'soportes', 'a.pdf'), binario);
  fs.writeFileSync(path.join(base, 'soportes', largo), 'contenido');
  fs.writeFileSync(path.join(base, 'config.json'), '{"ñ":"tilde"}');
  const salida = path.join(base, 'paquete.tar.gz');
  await crearTarGz(salida, base, ['soportes', 'config.json']);

  const destino = fs.mkdtempSync(path.join(os.tmpdir(), 'untar-'));
  // tar relativo al cwd: en Windows evita que "C:" se interprete como host remoto.
  execFileSync('tar', ['-xzf', path.relative(destino, salida).split(path.sep).join('/')], { cwd: destino });
  assert.deepEqual(fs.readFileSync(path.join(destino, 'soportes', 'a.pdf')), binario);
  assert.equal(fs.readFileSync(path.join(destino, 'soportes', largo), 'utf8'), 'contenido');
  assert.equal(fs.readFileSync(path.join(destino, 'config.json'), 'utf8'), '{"ñ":"tilde"}');
});
