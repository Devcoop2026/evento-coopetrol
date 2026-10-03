// Errores del dominio. El servidor HTTP los traduce a 400 con el mensaje (y `datos`, si los hay) para el usuario.

// Regla de negocio incumplida. `datos` viaja al cliente junto al mensaje (p. ej. la referencia de una inscripción existente).
class ErrorValidacion extends Error {
  constructor(mensaje, datos) {
    super(mensaje);
    this.datos = datos;
  }
}

// Error de identidad (documento/fecha de expedición): el servidor lo cuenta como intento fallido de la IP.
function errorIdentidad(mensaje) {
  return Object.assign(new ErrorValidacion(mensaje), { identidad: true });
}

module.exports = { ErrorValidacion, errorIdentidad };
