// Enrutador declarativo: cada ruta es { metodo, ruta, manejador, ...opciones }.
// En `ruta`, ":nombre" captura un segmento y ":nombre(regex)" lo restringe (p. ej. "/inscripciones/:id(\\d+)").
// Los parámetros se entregan sin decodificar en `crudos` y decodificados (al pedirlos) en `params`, para que una URL
// mal codificada se rechace (400) solo después de validar la sesión.
function compilar(ruta) {
  const nombres = [];
  const patron = ruta.split('/').map((segmento) => {
    const param = /^:(\w+)(?:\((.+)\))?$/.exec(segmento);
    if (!param) return segmento.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
    nombres.push(param[1]);
    return `(${param[2] || '[^/]+'})`;
  }).join('/');
  return { regex: new RegExp(`^${patron}$`), nombres };
}

function crearEnrutador(rutas) {
  const compiladas = rutas.map((r) => ({ ...r, ...compilar(r.ruta) }));
  // Devuelve { ruta, crudos, params } o null.
  return function buscar(metodo, pathname) {
    for (const r of compiladas) {
      if (r.metodo !== metodo) continue;
      const m = r.regex.exec(pathname);
      if (!m) continue;
      const crudos = Object.fromEntries(r.nombres.map((n, i) => [n, m[i + 1]]));
      return {
        ruta: r,
        crudos,
        get params() { return Object.fromEntries(Object.entries(crudos).map(([k, v]) => [k, decodeURIComponent(v)])); },
      };
    }
    return null;
  };
}

module.exports = { crearEnrutador };
