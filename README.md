# Evento Fin de Año Coopetrol · Simulación, inscripción y soporte de pago

Portal para que el asociado simule el valor de ingreso al evento de fin de año, se inscriba (preinscripción por PSE o
inscripción con pago en agencia) y registre el soporte de pago. Incluye un panel para que el área encargada revise los
pagos, ajuste cupos, cargue las bases de asociados y Coopetrolitos y exporte a Excel.

- **Tecnología**: PHP 8.2+, Laravel 12, Livewire 4 y MySQL. Sin Node.js
  ni compilación de recursos: `public/styles.css` y los módulos de `public/js/` se sirven tal cual.
- **Estructura del código y cómo extenderlo**: [ARQUITECTURA.md](ARQUITECTURA.md).
- **Despliegue manual en InfinityFree**: [docs/DEPLOY-INFINITYFREE.md](docs/DEPLOY-INFINITYFREE.md).

## Enlaces

En desarrollo local, con el servidor iniciado en el puerto `8080`:

| Área                    | Enlace                              |
| ----------------------- | ----------------------------------- |
| Página del evento       | http://127.0.0.1:8080/              |
| Ingreso al panel        | http://127.0.0.1:8080/admin/ingreso |
| Panel de administración | http://127.0.0.1:8080/admin         |
| Estado del servicio     | http://127.0.0.1:8080/up            |

En producción, reemplace `https://tu-dominio` por el dominio asignado por InfinityFree:

| Área                    | Enlace                             |
| ----------------------- | ---------------------------------- |
| Página del evento       | `https://tu-dominio/`              |
| Ingreso al panel        | `https://tu-dominio/admin/ingreso` |
| Panel de administración | `https://tu-dominio/admin`         |
| Estado del servicio     | `https://tu-dominio/up`            |

Enlaces directos a cada módulo de la página del evento (p. ej. para compartir desde las agencias):

| Módulo                        | Enlace local                    | Enlace de producción           |
| ----------------------------- | ------------------------------- | ------------------------------ |
| Inscripción y pago por PSE    | http://127.0.0.1:8080/#pse      | `https://tu-dominio/#pse`      |
| Inscripción y pago en agencia | http://127.0.0.1:8080/#agencia  | `https://tu-dominio/#agencia`  |
| Consulte su inscripción       | http://127.0.0.1:8080/#consulta | `https://tu-dominio/#consulta` |

Después de crear el sitio en InfinityFree, reemplace `tu-dominio` por la dirección real y configure el mismo valor en
`APP_URL`. El panel solo es accesible desde las redes de `PANEL_REDES`, si se definió.

## Ejecutar en desarrollo

Requisitos: **PHP 8.2+** con las extensiones `pdo_mysql`, `sodium`, `zip` (y `intl`, recomendada), **Composer** y
**MySQL 8+**.

```bash
composer install
cp .env.example .env                 # complete DB_DATABASE, DB_USERNAME y DB_PASSWORD
php artisan key:generate
php artisan migrate
php artisan db:seed                  # tarifas y cupos (data/tarifas.json)
php artisan db:seed --class=DatosPruebaSeeder   # asociados y Coopetrolitos FICTICIOS (data/*.seed.json)
php artisan evento:usuario admin "Nombre Apellido" --rol=administrador   # pide la clave (mínimo 10 caracteres)
php artisan serve --host=127.0.0.1 --port=8080  # http://127.0.0.1:8080
```

Para crear el administrador automáticamente durante el seeding, defina `ADMIN_CLAVE` solo en el entorno local o en el
panel del hosting y ejecute `php artisan db:seed`. Opcionalmente puede definir `ADMIN_USUARIO` y `ADMIN_NOMBRE`.
El seeder no modifica la clave si el usuario ya existe.

`composer setup` hace los primeros pasos (instalar, `.env`, clave, migraciones y tarifas) y `composer dev` arranca el
servidor de desarrollo.

### Rutas

| Ruta                                 | Descripción                                                                                                                         |
| ------------------------------------ | ----------------------------------------------------------------------------------------------------------------------------------- |
| `/`                                  | Portal del asociado. Enlaces directos a cada módulo (p. ej. para compartir desde las agencias): `/#pse`, `/#agencia` y `/#consulta` |
| `/admin`                             | Panel de administración (requiere sesión; `PANEL_REDES` lo restringe a redes internas)                                              |
| `/admin/ingreso`                     | Ingreso al panel                                                                                                                    |
| `/admin/soportes/{id}`               | Comprobante de pago de un soporte (solo con sesión del panel; servido aislado con CSP `sandbox`)                                    |
| `/admin/exportar.xlsx`               | Exportación de inscripciones en formato Excel                                                                                       |
| `/admin/bases/{tipo}/plantilla.xlsx` | Plantilla Excel de la base de `asociados` o `coopetrolitos` (solo administrador)                                                    |
| `/up`                                | Verificación de salud del servicio                                                                                                  |

La interfaz es Livewire: no hay una API JSON pública. Las acciones de los componentes pasan por la ruta de
actualización de Livewire, con protección CSRF y el límite general de solicitudes por IP.

### Pruebas

```bash
php artisan test
```

Usan SQLite en memoria, por lo que no requieren vaciar ni conectarse a la base de desarrollo.

### Comandos de consola

| Comando                                                                       | Uso                                                                                                                                                                                                                   |
| ----------------------------------------------------------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `evento:usuario {usuario} {nombre?} {--rol=}`                                 | Crea un usuario del panel o cambia su clave. Rol `ADMINISTRADOR` (por defecto al crear) o `REVISOR`; al actualizar sin `--rol` conserva el actual. La clave se pide dos veces, o se toma de la variable `ADMIN_CLAVE` |
| `evento:cargar-base {asociados\|coopetrolitos} {archivo} {--confirmar}`       | Carga masiva desde Excel o CSV. Sin `--confirmar` solo muestra la vista previa                                                                                                                                        |
| `evento:tarifas {archivo?} {--solo-recargar}`                                 | Genera `data/tarifas.json` desde `docs/Evento.xlsx` y lo carga en la base. `--solo-recargar` solo vuelve a cargar el JSON                                                                                             |
| `evento:respaldo`                                                             | Respaldo de configuración y comprobantes. En InfinityFree, descargue la base MySQL desde phpMyAdmin y copie `storage/app/soportes`                                                                                    |
| `evento:restaurar {origen} {destino}`                                         | Descifra respaldos generados por la aplicación; la restauración de MySQL se hace importando el SQL desde phpMyAdmin                                                                                                   |
| `evento:limpiar {inscripciones\|bases\|auditoria\|cupos\|todo} {--confirmar}` | Borra datos (con respaldo previo). Conserva usuarios del panel, tarifas y configuración. Al limpiar inscripciones las referencias vuelven a empezar en `EVTaa-000001`                                                 |
| `evento:generar-datos-prueba {carpeta?}`                                      | Genera `asociados_prueba.csv` y `coopetrolitos_prueba.csv` ficticios (por defecto en `docs/prueba`)                                                                                                                   |
| `evento:importar-sqlite {archivo} {--soportes=} {--confirmar}`                | Migración única de los datos de la versión anterior (Node.js + SQLite). Ver la guía de despliegue                                                                                                                     |

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

| Estado        | Significado                                      | ¿Ocupa cupo? | Asociado puede                           |
| ------------- | ------------------------------------------------ | ------------ | ---------------------------------------- |
| `PREINSCRITO` | Preinscrito, pendiente de pago (solo módulo PSE) | No           | Modificar, cancelar, registrar el pago   |
| `EN_REVISION` | Pago registrado, por verificar                   | Sí           | Consultar                                |
| `RECHAZADO`   | Soporte no válido (con motivo)                   | No           | Modificar, cancelar, registrar otro pago |
| `CONFIRMADO`  | Pago verificado                                  | Sí           | Consultar                                |
| `CANCELADO`   | Cancelada por el asociado                        | No           | Inscribirse de nuevo                     |
| `ANULADO`     | Anulada por un administrador                     | No           | Inscribirse de nuevo                     |

No hay plazo de pago: la preinscripción no vence. El cupo se ocupa al registrar un pago válido y se libera si el
pago se rechaza, o al cancelar o anular.

## Reglas de negocio

**Identificación**

- El titular debe ser asociado **activo**, con **datos actualizados en los últimos 12 meses** (`MESES_VIGENCIA_DATOS`).
- Segunda validación: **fecha de expedición del documento**. Tras 10 intentos fallidos en 15 minutos el documento se
  bloquea 15 minutos. El mensaje de error es el mismo si el documento no existe o la fecha no coincide.
- Se exige aceptar la **autorización de tratamiento de datos (habeas data)**.

**Valores**

- Se toman del **evento de la agencia a la que asistirá** (por defecto, la agencia del asociado).
- Cada acompañante requiere **documento y nombre completo** (obligatorios) y un tipo: **No asociado** o **Coopetrolito**.
- Titular y acompañantes asociados activos pagan el _valor asumido por asociado_ de ese evento. Un acompañante asociado se
  detecta automáticamente por su documento, sin importar el tipo elegido.
- **Coopetrolito** (hijo del asociado): paga el _valor asumido por asociado_. Se valida contra la base de Coopetrolitos y debe
  estar vinculado al documento del asociado titular.
- Acompañantes no asociados (o asociados inactivos) pagan el _valor evento invitado_ de ese evento.
- El valor queda fijado al preinscribirse y se recalcula si el asociado modifica la inscripción.

**Cupos**

- El cupo es por evento (agencia) y lo ocupan el titular, los acompañantes asociados y los **Coopetrolitos**. Los no asociados no ocupan cupo.
- **La preinscripción no ocupa cupo:** el cupo se descuenta al enviar el formulario de pago válido (estado _En revisión_) y se
  mantiene si se confirma; se libera si el pago se rechaza, la inscripción se anula o el asociado cancela. Al enviar el pago se
  verifica que haya cupo, así el contador nunca supera el límite (las operaciones que ocupan cupos se ejecutan de una en una
  con un bloqueo transaccional compatible con MySQL).
- Cupo inicial: columna _CANT. POR AGENCIA_ del Excel. El administrador puede **ajustarlo desde el panel**
  (no por debajo de los ocupados). Dejarlo vacío vuelve al valor del Excel.
- Contador actualizable: el portal y el panel refrescan los cupos disponibles cada 30 segundos.
- Una persona (titular o acompañante) no puede estar en dos inscripciones activas. Máximo 5 acompañantes (`MAX_ACOMPANANTES`).
- Tras una inscripción exitosa el formulario se limpia (equipos compartidos en agencias).
- Las inscripciones solo se reciben entre `inscripciones_desde` e `inscripciones_hasta` (`data/config.json`), salvo que
  `validar_periodo_inscripcion` sea `false`.

**Soporte de pago**

- PSE: CUS de la transacción (solo números), fecha de pago (no futura), valor pagado y comprobante PDF/JPG/PNG obligatorio
  (máximo `soporte_max_mb`; se verifica el contenido real del archivo, no la extensión).
- Agencia o efectivo: agencia donde pagó, fecha y valor; recibo de caja y foto del comprobante opcionales.
- Un mismo CUS no puede usarse en dos inscripciones. Si el valor pagado no coincide con el total, se marca una alerta para el revisor.
- Campos adicionales configurables en `data/formulario_soporte.json` (sin programar).

**Validación por tipo de campo** (en el navegador mientras se escribe y en el servidor; ver `app/Dominio/Compartido/Reglas.php`
y `public/js/validacion.js`):

| Tipo         | Permite                                                                             | Campos                                                                                             |
| ------------ | ----------------------------------------------------------------------------------- | -------------------------------------------------------------------------------------------------- |
| Numérico     | Solo dígitos                                                                        | Documentos (titular, acompañantes, Coopetrolitos, cédula del asociado), CUS, valor pagado, celular |
| Texto        | Letras (con tildes y ñ), espacios, apóstrofo, punto y guion; debe empezar por letra | Nombres y apellidos                                                                                |
| Alfanumérico | Letras y números en mayúsculas                                                      | Placa, recibo de caja                                                                              |
| Usuario      | Minúsculas, números, punto, guion y guion bajo                                      | Usuario del panel                                                                                  |

La carga masiva omite (con el motivo) las filas cuyo documento no es numérico o cuyo nombre tiene números o símbolos.

## Habeas data (Ley 1581 de 2012)

- Autorización obligatoria (casilla) al identificarse y al cargar el soporte; el servidor rechaza solicitudes sin ella.
- Se registra la **versión del texto, la fecha/hora y la IP** de aceptación en cada inscripción (visible en el panel y en la exportación).
- Texto, versión, enlace a la política y declaración sobre los datos de acompañantes: `data/habeas_data.json`.
- **Uso de imagen**: autorización aparte y **opcional** (las fotos son datos sensibles: no puede condicionar la inscripción);
  texto en `imagen` de `data/habeas_data.json`. Se guarda por inscripción y aparece en el panel y en la exportación.
  **El texto es un borrador y debe validarlo el área jurídica / oficial de protección de datos**; al cambiarlo, suba la `version`.
- Para acompañantes no se muestran nombres de la base de datos (se usa el nombre digitado).
- La ubicación y las condiciones del proveedor de hosting de producción deben ser revisadas por el área jurídica antes de
  publicar datos personales.

## Configuración

| Archivo                        | Contenido                                                                                                                    |
| ------------------------------ | ---------------------------------------------------------------------------------------------------------------------------- |
| `data/config.json`             | Fechas de inscripción, `validar_periodo_inscripcion`, enlaces de pago, tamaño máximo del soporte, `fecha_supresion_datos`    |
| `data/formulario_soporte.json` | Campos adicionales del formulario de soporte                                                                                 |
| `data/habeas_data.json`        | Texto de autorización de tratamiento de datos y de uso de imagen                                                             |
| `data/tarifas.json`            | Tarifas y cupos (generado desde el Excel con `evento:tarifas`)                                                               |
| `data/agencias_evento.json`    | Eventos compartidos por varias agencias (p. ej. CARTAGENA y PTO. MAMONAL → "CARTAGENA Y MAMONAL"): comparten tarifas y cupos |

Campos adicionales: `{ "id", "etiqueta", "tipo", "requerido", "opciones"?, "max"?, "ayuda"?, "mostrarSi"?: { "campo", "valor" } }`
con `tipo` = `texto`, `numero`, `numerico`, `fecha`, `correo`, `telefono`, `placa` o `seleccion` (requiere `opciones`).
Los JSON se leen en cada solicitud y `data/tarifas.json` se recarga en la base cuando cambia; en InfinityFree se actualizan
subiendo los archivos y, si es necesario, ejecutando la recarga desde un equipo con acceso a la base.

Variables de entorno principales (ver `.env.example` y `config/evento.php`):

| Variable                                                             | Uso                                                                                     |
| -------------------------------------------------------------------- | --------------------------------------------------------------------------------------- |
| `DB_CONNECTION`, `DB_HOST`/`DB_DATABASE`/`DB_USERNAME`/`DB_PASSWORD` | Conexión MySQL del panel de InfinityFree                                                |
| `EVENTO_SECRETO`                                                     | Clave HMAC de las fechas de expedición (32 caracteres o más; obligatoria en producción) |
| `SOPORTES_ALMACEN`                                                   | `soportes` para archivos fuera de la carpeta pública o `base_datos`                     |
| `PANEL_REDES`                                                        | Panel solo desde estas redes (CIDR separados por coma)                                  |
| `MAX_ACOMPANANTES`, `MESES_VIGENCIA_DATOS`                           | Reglas del evento (5 y 12 por defecto)                                                  |
| `LIMITE_LOGIN_IP`, `LIMITE_IDENTIDAD_IP`, `LIMITE_SOLICITUDES_IP`    | Límites por IP (10, 20 y 600)                                                           |
| `RESPALDO_CLAVE`, `RESPALDO_DIR`, `RETENCION_DIAS`                   | Respaldos de archivos y configuración                                                   |
| `SESSION_*`, `CACHE_STORE`, `LOG_SEGURIDAD`                          | Sesiones, caché y registro de seguridad; en InfinityFree se recomienda usar `file`      |
| `EVENTO_DATOS`, `EVENTO_CONFIG`                                      | Carpeta de los JSON y un `config.json` alterno (ambientes de prueba)                    |

## Datos personales: bases de asociados y Coopetrolitos

Los datos personales **no se guardan en el repositorio ni en archivos del proyecto**. Se cargan directamente a la base de
datos:

- **Panel de administración** (recomendado): _Cupos y cargas masivas_ → elegir el Excel o CSV → revisar la vista previa
  (registros válidos, filas omitidas con motivo, advertencias) → confirmar. Cada carga reemplaza la base completa en una
  transacción y queda registrada con usuario y fecha.
- **Consola**: `php artisan evento:cargar-base asociados archivo.xlsx` (vista previa) y agregar `--confirmar` para guardar.

Columnas (primera fila; no importan mayúsculas, tildes, puntos ni guiones bajos). Plantillas descargables desde el panel;
archivos ficticios para probar en `docs/prueba/` (`evento:generar-datos-prueba`):

| Base          | Columnas                                                                                                        |
| ------------- | --------------------------------------------------------------------------------------------------------------- |
| Asociados     | Cedula, Nombre, Agencia, Asociado, Ultima actualizacion de datos, Fecha expedicion, Fecha nacimiento (opcional) |
| Coopetrolitos | Documento, Nombre, Cedula asociado, Fecha nacimiento (opcional)                                                 |

- **Asociado**: SI / S / X / 1 / ACTIVO = asociado; otro valor = no asociado.
- **Fechas**: fecha de Excel, DD/MM/AAAA, DD-MM-AAAA o AAAA-MM-DD.
- **Agencia**: debe coincidir con el nombre del Excel de tarifas (o estar en `data/agencias_evento.json`).
- **Fecha de expedición**: se guarda como HMAC-SHA256 con `EVENTO_SECRETO`, nunca en texto plano. Si la clave cambia,
  hay que volver a cargar la base de asociados.
- Cargue primero asociados y luego Coopetrolitos.

**Tarifas** (sin datos personales): modifique `docs/Evento.xlsx` y ejecute `php artisan evento:tarifas`; luego publique el
cambio de `data/tarifas.json` y recargue la información en MySQL.

**Datos de prueba**: `php artisan db:seed --class=DatosPruebaSeeder` carga los asociados y Coopetrolitos ficticios de
`data/*.seed.json` (ver la tabla al final). Nunca se cargan en producción.

## Seguridad

- **Panel**: sesiones de Laravel guardadas en la base (tabla `sessions`), cookie `HttpOnly`, `SameSite=Strict`, `Secure` en
  producción y duración de 8 horas (`SESSION_LIFETIME=480`). Claves con el hash de Laravel (bcrypt); las claves scrypt
  importadas de la versión anterior se convierten al primer ingreso. Cambiar la clave o el rol cierra las sesiones abiertas
  del usuario. 10 intentos de ingreso cada 15 minutos por IP.
- **Roles**: **Administrador** (todo) y **Revisor** (consultar, aprobar o rechazar pagos y exportar), con la regla
  `Gate::define('administrar')`; lo que el rol no permite responde 403. `PANEL_REDES` restringe el panel a redes internas.
- **Límites por IP** (`RateLimiter` de Laravel sobre la caché en la base; respuesta 429 con `Retry-After`): 20 fallos de
  identificación (documento + fecha) cada 15 minutos y 600 solicitudes a los componentes cada 5 minutos; además, 10 fallos
  por documento bloquean ese documento 15 minutos.
- Mensaje de identidad genérico: no revela si un documento es de un asociado ni si tiene fecha de expedición registrada.
- Registro de seguridad en formato JSON (`LOG_SEGURIDAD`; en InfinityFree use un archivo fuera de `htdocs`), sin claves, fechas ni
  documentos completos.
- **Cabeceras**: Content-Security-Policy estricta con _nonce_ por solicitud (Livewire usa la compilación `csp_safe` de Alpine,
  sin `eval`), `nosniff`, `X-Frame-Options`, `Referrer-Policy`, `Permissions-Policy` y `Cross-Origin-Opener-Policy`.
- **Comprobantes**: se identifican por su firma de bytes, se guardan con nombre aleatorio en la base de datos (tabla
  `soportes_archivos`; o en un disco privado/S3 con `SOPORTES_ALMACEN`) y solo se sirven a usuarios del panel, aislados con
  CSP `sandbox`.
- La exportación CSV neutraliza fórmulas para evitar inyección al abrirla en Excel.
- Respaldos cifrados con AES-256-GCM (`RESPALDO_CLAVE`); restauración con `evento:restaurar`.
- `fecha_supresion_datos` (config.json): aviso a los administradores cuando se cumple la finalidad del tratamiento
  (borrado con `evento:limpiar`).
- En producción, publique detrás de HTTPS con `TRUSTED_PROXIES` configurado para tomar la IP real del cliente.

## Documentos de prueba (`DatosPruebaSeeder`, data/\*.seed.json)

| Documento   | Fecha expedición        | Agencia         | Caso                                                     |
| ----------- | ----------------------- | --------------- | -------------------------------------------------------- |
| 1001 / 1002 | 14/03/2008 · 22/07/2010 | BOGOTA          | Válidos (1001 tiene los Coopetrolitos 1100001 y 1100002) |
| 1003        | 09/01/2012              | BOGOTA NORTE    | Válido                                                   |
| 2001 / 2002 | 30/11/2005 · 18/05/2009 | ORITO           | Válidos (2001 tiene el Coopetrolito 1100003)             |
| 3001        | 02/08/2011              | CALI            | Válido                                                   |
| 4001        | 25/02/2003              | BARRANCABERMEJA | Datos vencidos                                           |
| 5001        | 11/10/2007              | BARRANQUILLA    | Válido                                                   |
| 6001        | 06/06/2014              | MEDELLIN        | Sin fecha de actualización                               |
| 9001        | 19/09/2001              | BOGOTA          | No asociado                                              |

## Identidad visual

Paleta de coopetrol.coop: verde `#009935`, `#00723A` / `#007749`, texto `#2C3A33`, acento `#FFB81C`; tipografía **Poppins**.
