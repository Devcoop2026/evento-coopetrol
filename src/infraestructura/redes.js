// Restricción por red (IPv4 en notación CIDR), p. ej. PANEL_REDES="10.0.0.0/8,192.168.0.0/16,127.0.0.1/32".
// También se usa para PROXY_CONFIABLE (desde qué red se acepta la cabecera X-Real-IP del proxy).
function aNumero(ip) {
  const partes = String(ip).split('.');
  if (partes.length !== 4 || partes.some((p) => !/^\d{1,3}$/.test(p) || Number(p) > 255)) return null;
  return partes.reduce((n, p) => n * 256 + Number(p), 0);
}

// Devuelve una función ip -> boolean, o null si no hay restricción configurada.
function crearFiltroRedes(texto, variable = 'PANEL_REDES') {
  const redes = String(texto || '').split(',').map((r) => r.trim()).filter(Boolean).map((r) => {
    const [base, bits = '32'] = r.split('/');
    const numero = aNumero(base);
    const prefijo = Number(bits);
    if (numero == null || !Number.isInteger(prefijo) || prefijo < 0 || prefijo > 32) throw new Error(`Red inválida en ${variable}: ${r}`);
    return { red: numero - (numero % 2 ** (32 - prefijo)), prefijo };
  });
  if (!redes.length) return null;
  return (ip) => {
    const limpia = String(ip || '').replace(/^::ffff:/, '') === '::1' ? '127.0.0.1' : String(ip || '').replace(/^::ffff:/, '');
    const n = aNumero(limpia);
    return n != null && redes.some(({ red, prefijo }) => n - (n % 2 ** (32 - prefijo)) === red);
  };
}

module.exports = { crearFiltroRedes };
