// Limitador de intentos por clave (IP, documento…) con ventana deslizante.
// Memoria acotada: elimina claves vencidas periódicamente y, si se supera `maxClaves`, descarta las más antiguas.
function crearLimitador({ maximo, ventanaMs, maxClaves = 50000, ahora = () => Date.now() }) {
  const registros = new Map(); // clave -> marcas de tiempo (ms) dentro de la ventana

  const vigentes = (clave) => {
    const limite = ahora() - ventanaMs;
    const marcas = (registros.get(clave) || []).filter((t) => t > limite);
    if (marcas.length) registros.set(clave, marcas); else registros.delete(clave);
    return marcas;
  };

  function purgar() {
    for (const clave of registros.keys()) vigentes(clave);
    // Map conserva el orden de inserción: las primeras claves son las más antiguas.
    for (const clave of registros.keys()) {
      if (registros.size <= maxClaves) break;
      registros.delete(clave);
    }
  }

  return {
    // ¿La clave ya alcanzó el máximo? Devuelve los segundos que faltan para liberarse (0 si no está bloqueada).
    bloqueada(clave) {
      const marcas = vigentes(clave);
      return marcas.length >= maximo ? Math.ceil((marcas[0] + ventanaMs - ahora()) / 1000) : 0;
    },
    registrar(clave) {
      const marcas = vigentes(clave);
      marcas.push(ahora());
      registros.delete(clave); // reinsertar al final mantiene el orden por actividad reciente
      registros.set(clave, marcas);
      if (registros.size > maxClaves) purgar();
    },
    reiniciar: (clave) => registros.delete(clave),
    purgar,
    get tamano() { return registros.size; },
  };
}

module.exports = { crearLimitador };
