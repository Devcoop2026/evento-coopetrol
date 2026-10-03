# Arquitectura

Aplicación Node.js (≥ 22.13) **sin dependencias**: `node:http`, `node:sqlite` y `node:test`. El código del servidor
está organizado en capas; las dependencias solo apuntan hacia adentro (http → aplicación → dominio).

```
server.js                    Punto de entrada: lee el entorno, abre la base y escucha en HOST:PORT
src/
  dominio/                   Reglas de negocio puras (sin base de datos, sin HTTP)
    errores.js               ErrorValidacion (→ 422) y errorIdentidad (cuenta como intento fallido por IP)
    valores.js               Documento, nombre completo, fechas (validación y formato)
    reglas.js                Tipos de campo: numérico, texto, alfanumérico (los mismos que public/validacion.js)
    asociado.js              Titular activo y con datos actualizados (MESES_VIGENCIA_DATOS)
    tiposAcompanante.js      Estrategias: ASOCIADO, COOPETROLITO, INVITADO (tarifa y si ocupa cupo)
    mediosPago.js            Estrategias: PSE, AGENCIA (validación y si el comprobante es obligatorio)
    inscripcion.js           Estados, transiciones del revisor, cupos requeridos, referencia EVTaa-nnnnnn
    camposFormulario.js      Campos adicionales del pago (data/formulario_soporte.json, condicionales mostrarSi)
  aplicacion/                Casos de uso: orquestan dominio e infraestructura
    simulador.js             Identificar al asociado (2.º factor: fecha de expedición) y liquidar
    inscripciones.js         Preinscribir, inscribir con pago (agencia), consultar, modificar, cancelar, registrar el pago, revisar
    exportacion.js           CSV de inscripciones (protegido contra inyección de fórmulas)
    gestion.js               CRUD del panel (asociados, Coopetrolitos, usuarios) y auditoría
    bases.js                 Carga masiva de Excel/CSV con vista previa
    admin.js                 Usuarios del panel, contraseñas (scrypt), sesiones y roles
  infraestructura/           Detalles técnicos
    db.js                    Apertura de SQLite, tarifas, datos de prueba, configuración (data/*.json)
    migraciones.js           Migraciones versionadas (PRAGMA user_version)
    repositorioInscripciones.js  SQL de inscripciones, personas, soportes y cupos
    repositorioPadron.js     SQL de lectura: asociados, Coopetrolitos, evento y tarifas
    almacenSoportes.js       Comprobantes en disco (nombres aleatorios, tipo por firma de bytes)
    secreto.js · cifrado.js  HMAC de la fecha de expedición · AES-256-GCM de los respaldos
    limitador.js · redes.js · registro.js · excel.js
  http/                      Adaptador HTTP
    servidor.js              Composición, cabeceras de seguridad, límites por IP, sesión y roles
    enrutador.js             Tabla de rutas declarativa ("/inscripciones/:id(\\d+)")
    controladorPublico.js    Rutas /api/... del asociado
    controladorPanel.js      Rutas /api/admin/... del panel
    respuestas.js            JSON, errores → códigos HTTP, CSP
public/                      Interfaz (PWA) en módulos ES, sin scripts ni estilos en línea
  comun.js                   Utilidades compartidas: $, el, solicitar (fetch JSON), formatos, leerBase64
  app.js · admin.js          Página del asociado · panel (módulos de entrada)
  dialogo.js · validacion.js Modales propios (sin alert/confirm) · validación por tipo mientras se escribe
  notificacion.js            Notificaciones emergentes del resultado de una acción (éxito, aviso, error)
  sw.js                      Service worker (subir CACHE al cambiar archivos de la interfaz)
scripts/  deploy/  test/
```

## Flujo de una solicitud

1. `http/servidor.js` aplica las cabeceras de seguridad, la restricción del panel por red (`PANEL_REDES`) y el
   límite general por IP.
2. El enrutador busca la ruta. En el panel se valida la sesión **antes** de responder 404 o de decodificar la URL;
   `soloAdmin` exige el rol ADMINISTRADOR. En la parte pública, las rutas con `identidad: true` cuentan los fallos de
   documento + fecha por IP.
3. El controlador lee el JSON (solo `application/json`, con límite de tamaño) y llama al caso de uso.
4. El caso de uso valida con el dominio y persiste con los repositorios. Las operaciones que tocan cupos corren en
   `repositorio.transaccion()` (`BEGIN IMMEDIATE`), así dos pagos simultáneos no superan el cupo.
5. Los errores se traducen en `http/respuestas.js`: `ErrorValidacion` → 422, sesión → 401, rol/red → 403,
   límites → 429, URL mal codificada → 400.

## Cómo extender

| Cambio | Dónde |
|---|---|
| Nuevo tipo de acompañante | Una entrada en `TIPOS` de `dominio/tiposAcompanante.js` (`aplica`, `validar`, `valor`, `ocupaCupo`) y su opción en `public/app.js` |
| Nuevo medio de pago | Una entrada en `MEDIOS_PAGO` de `dominio/mediosPago.js` y su opción en el formulario (`public/index.html`, `app.js`) |
| Nuevo campo del formulario de pago | Solo `data/formulario_soporte.json` (tipos: texto, numerico, telefono, correo, fecha, seleccion, placa; `mostrarSi`) |
| Nueva columna o tabla | Un paso al final de `MIGRACIONES` (`infraestructura/migraciones.js`), idempotente, con la versión siguiente |
| Nueva ruta | Una entrada en `controladorPublico.js` o `controladorPanel.js` |
| Nueva regla de un campo | `dominio/reglas.js` y la misma en `public/validacion.js` |

## Decisiones

- **Sin dependencias**: menos superficie de ataque y de mantenimiento; actualizar Node basta.
- **SQLite**: un solo archivo, respaldos en caliente con `VACUUM INTO`, suficiente para el volumen del evento.
  Las migraciones son hacia adelante; una base de una versión posterior hace que la aplicación no arranque.
- **Datos personales**: la fecha de expedición se guarda como HMAC (`EVENTO_SECRETO`); los respaldos se cifran
  (`RESPALDO_CLAVE`); las bases se cargan desde el panel, nunca desde el repositorio.
- **El cupo se ocupa al pagar**: la preinscripción no bloquea cupos; el formulario de pago válido sí
  (`ESTADOS_CUPO` en `dominio/inscripcion.js`).

## Pruebas

`npm test` (≈ 100 pruebas, `node:test`):
- `dominio.test.js`: reglas puras y enrutador.
- `migraciones.test.js`: base nueva, base anterior a las migraciones versionadas y base de una versión posterior.
- `simulador`, `inscripciones`, `gestion`, `bases`, `cifrado`: casos de uso sobre SQLite en memoria.
- `http.test.js`: servidor real (cabeceras, límites, sesión, roles, red del panel).
