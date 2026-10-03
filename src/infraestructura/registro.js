// Registro de eventos de seguridad: una línea JSON por evento en la salida estándar (journald en producción).
// Consultar: journalctl -u evento-coopetrol | grep '"tipo":"seguridad"'
// Nunca registra claves, fechas de expedición ni documentos completos (se enmascaran).
const enmascarar = (documento) => {
  const d = String(documento ?? '');
  return d.length <= 3 ? '***' : `${'*'.repeat(d.length - 3)}${d.slice(-3)}`;
};

function crearRegistro({ salida = (linea) => console.log(linea), ahora = () => new Date() } = {}) {
  return (evento, datos = {}) => salida(JSON.stringify({ fecha: ahora().toISOString(), tipo: 'seguridad', evento, ...datos }));
}

module.exports = { crearRegistro, enmascarar };
