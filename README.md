# Evento Fin de Año Coopetrol · Simulador, preinscripción y soporte de pago

PWA para que el asociado simule el valor de ingreso al evento, separe cupo (preinscripción), pague por PSE en los
canales de Coopetrol y cargue el soporte de pago. Incluye un panel para que el área encargada revise los soportes,
ajuste cupos y exporte a Excel.

Estructura del código y cómo extenderlo: [ARQUITECTURA.md](ARQUITECTURA.md). Despliegue y operación: [DESPLIEGUE.md](DESPLIEGUE.md) (Linux) , [deploy/windows/DESPLIEGUE-WINDOWS.md](deploy/windows/DESPLIEGUE-WINDOWS.md) (Windows Server 2025 con contenedor Windows e IIS) y [deploy/render/DESPLIEGUE-RENDER.md](deploy/render/DESPLIEGUE-RENDER.md) (Render).

## Ejecutar

Requisito: **Node.js 22.13 o superior** (SQLite nativo, `node:sqlite`). No hay dependencias que instalar.

```bash
npm start                                   # http://localhost:3000  (puerto: variable PORT)
npm run admin -- tesoreria "Nombre Apellido" # crea/actualiza un usuario del panel (pide la clave)
npm test                                    # pruebas de reglas de negocio
npm run dev                                 # arranca con datos ficticios de prueba
npm run limpiar -- inscripciones            # vista previa de limpieza (agregar --confirmar); ver scripts/limpiar.js
```

- Asociados: `http://localhost:3000/`
- Panel de administración: `http://localhost:3000/admin.html`

## Flujo

La portada ofrece dos módulos y una consulta:

```
Inscripción y pago por PSE
  Identificación ─► Evento ─► Simulación ─► Preinscripción ─► Pago PSE ─► Registro del pago (CUS) ─► Revisión ─► Confirmado
  (documento + fecha                          (referencia,      (canales     (desde la confirmación o
   de expedición + habeas data)                sin cupo)         Coopetrol)   "Consulte su inscripción")

Inscripción y pago en agencia (un solo paso)
  Identificación ─► Evento ─► Simulación ─► Registro del pago en agencia ─► Revisión ─► Confirmado
                                            (crea la inscripción EN_REVISION y ocupa el cupo; todo o nada)

¿Ya se inscribió? Consulte su inscripción: estado, registrar el pago (PSE o agencia), modificar o cancelar.
```

Enlaces directos a cada módulo (p. ej. para compartir desde las agencias): `/#pse`, `/#agencia` y `/#consulta`.

| Estado | Significado | ¿Ocupa cupo? | Asociado puede |
|---|---|---|---|
| `PREINSCRITO` | Preinscrito, pendiente de pago (solo módulo PSE) | No | Modificar, cancelar, registrar el pago |
| `EN_REVISION` | Pago registrado, por verificar | Sí | Consultar |
| `RECHAZADO` | Soporte no válido (con motivo) | No | Modificar, cancelar, registrar otro pago |
| `CONFIRMADO` | Pago verificado | Sí | Consultar |
| `CANCELADO` | Cancelada por el asociado | No | Inscribirse de nuevo |
| `ANULADO` | Anulada por un administrador | No | Inscribirse de nuevo |

No hay plazo de pago: la preinscripción no vence. El cupo se ocupa al registrar un pago válido y se libera si el
pago se rechaza, o al cancelar o anular.

## Reglas de negocio

**Identificación**
- El titular debe ser asociado **activo**, con **datos actualizados en los últimos 12 meses** (`MESES_VIGENCIA_DATOS`).
- Segunda validación: **fecha de expedición del documento**. Tras 5 intentos fallidos el documento se bloquea 15 minutos.
  El mensaje de error es el mismo si el documento no existe o la fecha no coincide.
- Se exige aceptar la **autorización de tratamiento de datos (habeas data)**.

**Valores**
- Se toman del **evento de la agencia a la que asistirá** (por defecto, la agencia del asociado).
- Cada acompañante requiere **documento y nombre completo** (obligatorios) y un tipo: **No asociado** o **Coopetrolito**.
- Titular y acompañantes asociados activos pagan el *valor asumido por asociado* de ese evento. Un acompañante asociado se
  detecta automáticamente por su documento, sin importar el tipo elegido.
- **Coopetrolito** (hijo del asociado): paga el *valor asumido por asociado*. Se valida contra la base de Coopetrolitos y debe
  estar vinculado al documento del asociado titular.
- Acompañantes no asociados (o asociados inactivos) pagan el *valor evento invitado* de ese evento.
- El valor queda fijado al preinscribirse y se recalcula si el asociado modifica la inscripción.

**Cupos**
- El cupo es por evento (agencia) y lo ocupan el titular, los acompañantes asociados y los **Coopetrolitos**. Los no asociados no ocupan cupo.
- **La preinscripción no ocupa cupo:** el cupo se descuenta al enviar el formulario de pago válido (estado *En revisión*) y se
  mantiene si se confirma; se libera si el pago se rechaza, la inscripción se anula o el asociado cancela. Al enviar el pago se
  verifica que haya cupo, así el contador nunca supera el límite.
- Cupo inicial: columna *CANT. POR AGENCIA* del Excel. El administrador puede **ajustarlo desde el panel**
  (no por debajo de los ocupados). Dejarlo vacío vuelve al valor del Excel.
- Contador actualizable: la página pública y el panel refrescan los cupos disponibles cada 30 segundos.
- Una persona (titular o acompañante) no puede estar en dos inscripciones activas. Máximo 5 acompañantes (`MAX_ACOMPANANTES`).
- Tras una inscripción exitosa el formulario se limpia (equipos compartidos en agencias).
- Las inscripciones solo se reciben entre `inscripciones_desde` e `inscripciones_hasta` (`data/config.json`).

**Soporte de pago**
- PSE: CUS de la transacción (solo números), fecha de pago (no futura), valor pagado y comprobante PDF/JPG/PNG obligatorio
  (máximo `soporte_max_mb`, se verifica el contenido real del archivo).
- Agencia o efectivo: agencia donde pagó, fecha y valor; recibo de caja y foto del comprobante opcionales.
- Un mismo CUS no puede usarse en dos inscripciones. Si el valor pagado no coincide con el total, se marca una alerta para el revisor.
- Campos adicionales configurables en `data/formulario_soporte.json` (sin programar).

**Validación por tipo de campo** (en el navegador mientras se escribe y en el servidor; ver `src/dominio/reglas.js` y `public/validacion.js`):

| Tipo | Permite | Campos |
|---|---|---|
| Numérico | Solo dígitos | Documentos (titular, acompañantes, Coopetrolitos, cédula del asociado), CUS, valor pagado, celular |
| Texto | Letras (con tildes y ñ), espacios, apóstrofo, punto y guion; debe empezar por letra | Nombres y apellidos |
| Alfanumérico | Letras y números en mayúsculas | Placa, recibo de caja |
| Usuario | Minúsculas, números, punto, guion y guion bajo | Usuario del panel |

La carga masiva omite (con el motivo) las filas cuyo documento no es numérico o cuyo nombre tiene números o símbolos.

## Habeas data (Ley 1581 de 2012)

- Autorización obligatoria (casilla) al identificarse y al cargar el soporte; el servidor rechaza solicitudes sin ella.
- Se registra la **versión del texto, la fecha/hora y la IP** de aceptación en cada inscripción (visible en el panel y en la exportación).
- Texto, versión, enlace a la política y declaración sobre los datos de acompañantes: `data/habeas_data.json`.
- **Uso de imagen**: autorización aparte y **opcional** (las fotos son datos sensibles: no puede condicionar la inscripción);
  texto en `imagen` de `data/habeas_data.json`. Se guarda por inscripción y aparece en el panel y en la exportación.
  **El texto es un borrador y debe validarlo el área jurídica / oficial de protección de datos**; al cambiarlo, suba la `version`.
- Para acompañantes no se muestran nombres de la base de datos (se usa el nombre digitado).

## Configuración

| Archivo | Contenido |
|---|---|
| `data/config.json` | Fechas de inscripción, enlace de pago PSE, tamaño máximo del soporte |
| `data/formulario_soporte.json` | Campos adicionales del formulario de soporte |
| `data/habeas_data.json` | Texto de autorización de tratamiento de datos |
| `data/tarifas.json` | Tarifas y cupos (generado desde el Excel) |
| `data/agencias_evento.json` | Eventos compartidos por varias agencias (p. ej. CARTAGENA y MAMONAL → "CARTAGENA Y MAMONAL"): comparten tarifas y cupos |

Campos adicionales: `{ "id", "etiqueta", "tipo", "requerido", "opciones"?, "max"? }` con
`tipo` = `texto`, `numero`, `fecha`, `correo`, `telefono` o `seleccion` (requiere `opciones`). Reinicie el servidor tras editarlos.

Variables de entorno: `PORT`, `HOST`, `TRUST_PROXY` (1 detrás de Nginx), `NODE_ENV`, `EVENTO_SECRETO` (clave HMAC de las
fechas de expedición; obligatoria en producción), `DATOS_PRUEBA` (1 = carga los asociados ficticios), `EVENTO_DB`,
`SOPORTES_DIR`, `EVENTO_CONFIG`, `RESPALDO_DIR`, `RETENCION_DIAS`, `MAX_ACOMPANANTES`, `MESES_VIGENCIA_DATOS`.

## Datos personales: bases de asociados y Coopetrolitos

Los datos personales **no se guardan en el repositorio ni en archivos del proyecto**. Se cargan directamente a la base de
datos del servidor:

- **Panel de administración** (recomendado): *Bases de asociados y Coopetrolitos* → arrastrar el Excel o CSV → revisar la
  vista previa (registros válidos, filas omitidas con motivo, advertencias) → confirmar. Cada carga reemplaza la base
  completa en una transacción y queda registrada con usuario y fecha.
- **Consola** (cargas masivas): `npm run base -- asociados archivo.xlsx` (vista previa) y agregar `--confirmar` para guardar.
  En producción: `deploy/cargar_base.sh`.

Columnas (primera fila; no importan mayúsculas ni tildes). Plantillas con datos ficticios en `docs/` o descargables desde el panel:

| Base | Columnas |
|---|---|
| Asociados | Cedula, Nombre, Agencia, Asociado, Ultima actualizacion de datos, Fecha expedicion |
| Coopetrolitos | Documento, Nombre, Cedula asociado |

- **Asociado**: SI / S / X / 1 / ACTIVO = asociado; otro valor = no asociado.
- **Fechas**: fecha de Excel, DD/MM/AAAA, DD-MM-AAAA o AAAA-MM-DD.
- **Agencia**: debe coincidir con el nombre del Excel de tarifas.
- **Fecha de expedición**: se guarda como HMAC-SHA256 con `EVENTO_SECRETO`, nunca en texto plano. Si la clave cambia,
  hay que volver a cargar la base de asociados.
- Cargue primero asociados y luego Coopetrolitos.

**Tarifas** (sin datos personales): modifique `docs/Evento.xlsx` y ejecute `npm run tarifas` (Python + openpyxl);
el servidor recarga `data/tarifas.json` sin reiniciar.

**Datos de prueba**: `npm run dev` arranca con los asociados y Coopetrolitos ficticios de `data/*.seed.json`
(ver la tabla al final). Con `npm start` no se cargan.

## Producción y respaldos

Despliegue en Linux (Nginx + systemd, respaldo diario): ver [DESPLIEGUE.md](DESPLIEGUE.md) y la carpeta `deploy/`.
Respaldo manual: `npm run respaldo` (copia la base, los soportes y `data/*.json`).

## Seguridad

- Panel: claves con hash scrypt; sesión en cookie `HttpOnly`/`SameSite=Strict` (8 horas) y en la base solo el **hash** del token;
  10 intentos de ingreso cada 15 minutos por IP.
- Límites por IP (respuesta 429 con `Retry-After`): 20 fallos de identificación (documento + fecha) cada 15 minutos y 600
  solicitudes a la API cada 5 minutos; además, 10 fallos por documento bloquean ese documento 15 minutos.
- Mensaje de identidad genérico: no revela si un documento es de un asociado ni si tiene fecha de expedición registrada.
- Roles del panel: **Administrador** (todo) y **Revisor** (consultar, aprobar o rechazar pagos y exportar); el servidor responde 403
  a lo que el rol no permite. `PANEL_REDES` restringe el panel a redes internas.
- Registro de seguridad en formato JSON (`journalctl ... | grep '"tipo":"seguridad"'`), sin claves, fechas ni documentos completos.
- Respaldos cifrados con AES-256-GCM (`RESPALDO_CLAVE`); restauración con `node scripts/restaurar.js`.
- `fecha_supresion_datos` (config.json): aviso a los administradores cuando se cumple la finalidad del tratamiento.
- El documento del asociado se recuerda solo durante la sesión del navegador (`sessionStorage`).
- Cabeceras: Content-Security-Policy estricta (sin scripts ni estilos en línea), `nosniff`, `X-Frame-Options`,
  `Permissions-Policy` y `Cross-Origin-Opener-Policy`. Los soportes de imagen se sirven con CSP `sandbox`.
- Solo se aceptan cuerpos JSON (415 en otro caso); URL mal codificadas responden 400.
- Los soportes se guardan con nombre aleatorio fuera de la carpeta pública y solo se sirven a administradores.
- La exportación CSV neutraliza fórmulas para evitar inyección al abrirla en Excel.
- En producción, publique detrás de HTTPS (la cookie se marca `Secure` automáticamente si el proxy envía `X-Forwarded-Proto: https`).

## API

| Método | Ruta | Descripción |
|---|---|---|
| GET | `/api/evento` | Evento, tarifas, campos del soporte, habeas data, configuración |
| GET | `/api/cupos` | Contador público de cupos por evento |
| POST | `/api/identificar` | `{ documento, fechaExpedicion, autorizaDatos }` |
| POST | `/api/simular` | `{ documento, fechaExpedicion, agenciaEvento, acompanantes }` |
| POST | `/api/inscripciones` | Preinscribir (mismos datos + `autorizaDatos`) |
| POST | `/api/inscripciones/con-pago` | Inscribirse y registrar el pago en un solo paso (módulo agencia): datos de la simulación + `medioPago: AGENCIA`, `agenciaPago`, `recibo`, `fechaPago`, `valorPagado`, `campos`, `archivo` (opcional) |
| POST | `/api/inscripciones/consultar` · `/modificar` · `/cancelar` · `/soporte` | Requieren `{ referencia, documento, fechaExpedicion }` |
| POST | `/api/admin/login` · `/logout` | Sesión del panel |
| GET | `/api/admin/cupos` · `/inscripciones` · `/inscripciones/:id` · `/soportes/:id` · `/exportar.csv` | Panel |
| POST | `/api/admin/cupos/:agencia` · `/inscripciones/:id/revision` | Ajustar cupo · `{ accion: APROBAR\|RECHAZAR\|ANULAR, motivo }` |

## Documentos de prueba (`npm run dev`, data/*.seed.json)

| Documento | Fecha expedición | Agencia | Caso |
|---|---|---|---|
| 1001 / 1002 | 14/03/2008 · 22/07/2010 | BOGOTA | Válidos |
| 2001 / 2002 | 30/11/2005 · 18/05/2009 | ORITO | Válidos |
| 3001 | 02/08/2011 | CALI | Válido |
| 4001 | 25/02/2003 | BARRANCABERMEJA | Datos vencidos |
| 6001 | 06/06/2014 | MEDELLIN | Sin fecha de actualización |
| 9001 | 19/09/2001 | BOGOTA | No asociado |

## Identidad visual

Paleta de coopetrol.coop: verde `#009935`, `#00723A` / `#007749`, texto `#2C3A33`, acento `#FFB81C`; tipografía **Poppins**.
# simulador-eventos
#   e v e n t o - c o o p e t r o l  
 #   e v e n t o - c o o p e t r o l  
 