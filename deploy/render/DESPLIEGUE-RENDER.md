# Despliegue en Render + Neon

La aplicación (Laravel en Docker) corre en **Render** y la base de datos PostgreSQL en **Neon**, ambos en su plan
gratuito. Render publica con HTTPS en `https://evento-coopetrol-laravel.onrender.com` (o un subdominio de Coopetrol) sin depender
del firewall de la empresa. La configuración del servicio está en `render.yaml` (Blueprint) y la imagen en `Dockerfile`.

## Lo que hay que saber de los planes gratuitos

| | Render (web service free) | Neon (free) |
|---|---|---|
| Inactividad | Se suspende tras **15 minutos** sin visitas; la primera visita después tarda alrededor de 1 minuto | El cómputo se suspende tras unos minutos sin consultas; la primera consulta después tarda un poco más |
| Disco | **No persistente**: se borra en cada despliegue o reinicio | 0,5 GB de almacenamiento por proyecto |
| Consola | Sin **Shell** ni SSH: los comandos `php artisan evento:*` se ejecutan desde un equipo con la conexión de Neon (paso 5) | Consola SQL en el panel de Neon |
| Respaldos | — | Restauración a un momento anterior dentro de una ventana corta (revise la vigente en su plan) |

Por eso todo el estado vive en Neon: inscripciones, **comprobantes de pago** (`SOPORTES_ALMACEN=base_datos`), sesiones
del panel y caché de los límites de intentos. Durante el periodo de inscripciones considere el plan Starter de Render
(sin suspensión) o mantener el servicio despierto con un monitor externo que visite `/up` cada 10 minutos.

## Requisitos

- Repositorio en GitHub con este código; cuentas en [render.com](https://render.com) y [neon.tech](https://neon.tech).
- En su equipo, para los comandos de operación: **PHP 8.4** (`pdo_pgsql`, `sodium`, `zip`), **Composer** y el
  **cliente de PostgreSQL 17** (`pg_dump`/`pg_restore`, para respaldos). En Windows: instalador de PostgreSQL, componente
  *Command Line Tools*.

## 1. Crear la base en Neon

1. En Neon: **New Project** → nombre `evento-coopetrol`, versión de PostgreSQL **17**, región **AWS US East 1
   (N. Virginia)** (la más cercana a la región *virginia* de Render).
2. Cree la base `evento_coopetrol` (**Databases → New database**) con el rol que Neon creó (o uno nuevo).
3. **Connect** → elija la base y copie dos cadenas de conexión:
   - **Pooled** (casilla *Connection pooling* activada; el servidor termina en `-pooler`): la usa la aplicación.
     `postgresql://usuario:clave@ep-xxxx-pooler.us-east-1.aws.neon.tech/evento_coopetrol?sslmode=require`
   - **Directa** (sin `-pooler`): para migraciones manuales, `pg_dump` y `pg_restore` desde su equipo.
4. Guarde ambas en el gestor de contraseñas. Siempre con `sslmode=require`.

El pooler de Neon es PgBouncer en modo transacción. La aplicación es compatible: el bloqueo que serializa la ocupación de
cupos es `pg_advisory_xact_lock` (de transacción, se libera al terminar). Un bloqueo de sesión (`pg_advisory_lock`) no
funcionaría a través del pooler.

## 2. Generar las claves

En su equipo (en la carpeta del proyecto, con `composer install` hecho). Guarde los valores en el gestor de contraseñas:

```bash
php artisan key:generate --show                    # APP_KEY  (base64:...)
php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"  # RESPALDO_CLAVE (64 hexadecimales)
php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"  # EVENTO_SECRETO, solo en una instalación nueva
```

- **`APP_KEY`**: cifra las sesiones. Render no la puede generar (su `generateValue` no tiene el prefijo `base64:` que
  exige Laravel).
- **`EVENTO_SECRETO`**: con ella se calcula el HMAC de las fechas de expedición. **Si va a migrar los asociados de la
  versión anterior (Node.js) debe ser EXACTAMENTE la `EVENTO_SECRETO` del servidor anterior** (servicio anterior →
  *Environment*); si no, ningún asociado podrá identificarse. En una instalación nueva use una al azar. Si cambia
  después, hay que volver a cargar la base de asociados.
- **`RESPALDO_CLAVE`**: cifra los respaldos de `evento:respaldo`. Sin ella no se pueden descifrar.

## 3. Crear el servicio en Render (Blueprint)

1. En Render: **New → Blueprint** → elija el repositorio y la rama `main`.
2. Render lee `render.yaml` y pide los valores marcados `sync: false`:

   | Variable | Valor |
   |---|---|
   | `APP_KEY` | La del paso 2 |
   | `APP_URL` | `https://evento-coopetrol-laravel.onrender.com` (o su dominio propio) |
   | `DB_URL` | Cadena **pooled** de Neon |
   | `EVENTO_SECRETO` | La del paso 2 (la del servidor anterior si migra datos) |
   | `RESPALDO_CLAVE` | La del paso 2 |
   | `PANEL_REDES` | Vacío, o la IP pública de Coopetrol (p. ej. `181.79.218.42/32`) para abrir el panel solo desde la empresa |

3. **Apply**. Render construye la imagen (5 a 10 minutos la primera vez) y la publica. Las demás variables ya vienen en
   `render.yaml`: `APP_ENV=production`, `APP_DEBUG=false`, `DB_CONNECTION=pgsql`, `DB_SSLMODE=require`,
   `SESSION_DRIVER=database`, `SESSION_SECURE_COOKIE=true`, `SESSION_SAME_SITE=strict`, `SESSION_LIFETIME=480`,
   `CACHE_STORE=database`, `TRUSTED_PROXIES=*`, `LOG_CHANNEL=stderr`, `LOG_SEGURIDAD=php://stderr`,
   `SOPORTES_ALMACEN=base_datos` y `PORT=8080` (el puerto donde escucha Nginx en la imagen).

Al arrancar, el contenedor ejecuta (`deploy/docker/50-evento.sh`): `config:cache`, `route:cache`, `view:cache`,
`migrate --force` (crea o actualiza las tablas en Neon) y `evento:tarifas --solo-recargar`. Si algo falla, el
despliegue no queda activo y el error aparece en **Logs**. Render verifica la salud en `/up`.

Cuando el estado sea *Live*, abra `https://evento-coopetrol-laravel.onrender.com/up` y luego la portada.

Los despliegues siguientes son manuales (`autoDeploy: false`): **Manual Deploy → Deploy latest commit**.

## 4. Ejecutar comandos contra Neon desde su equipo

El plan gratuito no tiene *Shell*, así que los comandos de operación se ejecutan en su equipo apuntando a Neon. Cree en
la carpeta del proyecto un archivo **`.env.production`** (está en `.gitignore` y `.dockerignore`; nunca lo suba al
repositorio):

```dotenv
APP_ENV=production
APP_KEY=base64:...                 # la misma de Render
DB_CONNECTION=pgsql
DB_URL=postgresql://usuario:clave@ep-xxxx.us-east-1.aws.neon.tech/evento_coopetrol?sslmode=require   # DIRECTA
DB_SSLMODE=require
CACHE_STORE=array
EVENTO_SECRETO=...                 # la misma de Render
RESPALDO_CLAVE=...                 # la misma de Render
RESPALDO_DIR=C:/Respaldos/evento   # carpeta local (fuera del proyecto)
# PG_DUMP=C:/Program Files/PostgreSQL/17/bin/pg_dump.exe   # si pg_dump no está en el PATH
```

y agregue `--env=production` a cada comando: `php artisan evento:... --env=production`.

## 5. Primer usuario del panel

```bash
php artisan evento:usuario dreina "Diego Reina" --rol=administrador --env=production   # pide la clave dos veces
php artisan evento:usuario tesoreria "Tesorería" --rol=revisor --env=production
```

(Con un plan de pago, lo mismo desde **Shell** del servicio, sin `--env`.) Ingrese en `https://…/admin`.

## 6. Cargar las bases de asociados y Coopetrolitos

Panel → **Cupos y cargas masivas** → elija el Excel o CSV de asociados → revise la vista previa (registros válidos,
filas omitidas con su motivo, advertencias) → **confirmar**. Luego la de Coopetrolitos. Cada carga reemplaza la base
completa. Plantillas: botón de plantilla en la misma pantalla. Alternativa por consola:

```bash
php artisan evento:cargar-base asociados ruta/asociados.xlsx --env=production              # vista previa
php artisan evento:cargar-base asociados ruta/asociados.xlsx --confirmar --env=production
```

## 7. Tarifas y configuración del evento

- **Tarifas y cupos**: actualice `docs/Evento.xlsx`, ejecute `php artisan evento:tarifas` (genera `data/tarifas.json`
  y lo carga en su base local), haga commit y `git push`, y en Render **Manual Deploy**. Al arrancar se recargan en Neon.
- **`data/config.json`** (fechas de inscripción, `validar_periodo_inscripcion`, enlaces de pago, tamaño máximo del
  soporte, fecha de supresión), `data/formulario_soporte.json`, `data/habeas_data.json` y `data/agencias_evento.json`:
  edítelos, commit, push y **Manual Deploy**.
- Antes de abrir inscripciones: `"validar_periodo_inscripcion": true`.

## 8. Migrar los datos de la versión anterior (Node.js + SQLite)

Una sola vez, antes de abrir el nuevo servicio al público:

1. **Detenga las inscripciones en el servicio anterior** (p. ej. suspéndalo) para que no entren datos durante la migración.
2. Copie a su equipo, desde el disco del servicio anterior (`/var/data`), el archivo **`evento.db`** y la carpeta
   **`soportes`** (por SSH/`scp` del servicio anterior, o desde un respaldo suyo). Si tiene un respaldo cifrado de la
   versión anterior, `php artisan evento:restaurar <carpeta> <destino>` lo descifra: el formato es el mismo (use la
   `RESPALDO_CLAVE` anterior).
3. Verifique que `EVENTO_SECRETO` en Render y en `.env.production` es la del servidor anterior.
4. Vista previa (no escribe nada; muestra conteos y comprobantes encontrados o faltantes):
   ```bash
   php artisan evento:importar-sqlite ruta/evento.db --soportes=ruta/soportes --env=production
   ```
5. Importación (una transacción: todo o nada):
   ```bash
   php artisan evento:importar-sqlite ruta/evento.db --soportes=ruta/soportes --confirmar --env=production
   ```

Qué hace: copia usuarios del panel (sus claves scrypt siguen sirviendo y se convierten al primer ingreso; si un usuario
ya existe en Neon se conserva el actual), asociados (con su HMAC), Coopetrolitos, cargas, cupos ajustados, inscripciones
(conservando **id y referencias**), personas, soportes (conservando id) y auditoría, y avanza los consecutivos para que
las nuevas referencias sigan después. Los comprobantes se guardan en el almacén configurado (la base). Las tarifas salen
de `data/tarifas.json` y las sesiones anteriores se descartan. Solo funciona sobre una base **sin** inscripciones ni
asociados (si cargó datos de prueba, primero `evento:limpiar todo --confirmar`).

6. Pruebe en el portal con un asociado real (documento + fecha de expedición) y revise una inscripción migrada en el
   panel, incluido su comprobante. Luego cambie el DNS (paso 11) y elimine el servicio y el disco anteriores cuando esté
   seguro (después de guardar un respaldo).

## 9. Respaldos

- **Neon**: restauración a un momento anterior (*Restore*/*Branches*) dentro de la ventana de su plan. Úsela ante un
  error reciente.
- **Respaldo propio cifrado** (recomendado a diario durante las inscripciones y antes de cada cambio grande), desde su
  equipo con `pg_dump` 17:
  ```bash
  php artisan evento:respaldo --env=production
  ```
  Crea `RESPALDO_DIR/AAAAMMDD-HHMM` con `evento.dump.enc` (toda la base, comprobantes incluidos) y `datos.tar.gz.enc`
  (`data/*.json`), y borra los respaldos con más de `RETENCION_DIAS` (30) días. Guarde la carpeta en un sitio seguro de
  Coopetrol.
- **Restaurar**:
  ```bash
  php artisan evento:restaurar C:/Respaldos/evento/20261015-1800 C:/Temp/restaurado --env=production
  pg_restore --clean --if-exists --no-owner --no-privileges --dbname="<cadena DIRECTA de Neon>" C:/Temp/restaurado/evento.dump
  ```
  Es más seguro restaurar primero en una base o rama nueva de Neon, revisar y luego apuntar `DB_URL` a ella. Borre los
  archivos descifrados al terminar.
- Exporte además las inscripciones a Excel desde el panel periódicamente.

## 10. Limpieza de datos

Para borrar datos de prueba antes de abrir, o al cumplirse `fecha_supresion_datos` (finalidad cumplida):

```bash
php artisan evento:limpiar todo --env=production                # vista previa: cuántos registros se borrarían
php artisan evento:limpiar todo --confirmar --env=production    # respaldo automático y luego borrado
```

Grupos: `inscripciones` (con soportes y comprobantes; las referencias vuelven a `EVTaa-000001`), `bases` (asociados,
Coopetrolitos y registro de cargas), `auditoria`, `cupos` (cupos ajustados) o `todo`. Se conservan los usuarios del
panel, las tarifas y la configuración. Si el respaldo previo falla, no se borra nada.

## 11. Dominio propio (opcional), p. ej. `https://eventos.coopetrol.coop`

Servicio → **Settings → Custom Domains → Add** `eventos.coopetrol.coop`. Render indica un registro **CNAME** que se crea
en el DNS de `coopetrol.coop` apuntando a `evento-coopetrol-laravel.onrender.com`. El certificado lo emite y renueva Render.
Actualice `APP_URL`.

## 12. Verificar la IP de los clientes (una vez)

Los límites por IP, `PANEL_REDES` y la IP de la autorización de habeas data dependen de leer bien la IP del visitante
(`TRUSTED_PROXIES=*`). Ingrese al panel y busque en **Logs** la línea de seguridad del ingreso: el campo `ip` debe ser su
IP pública (consúltela en https://www.cual-es-mi-ip.net), no una IP interna de Render.

## Si los comprobantes no caben en Neon (Cloudflare R2)

Cada comprobante pesa hasta `soporte_max_mb` (5 MB en `data/config.json`) y cuenta para los 0,5 GB de Neon. Vigile el
tamaño en el panel de Neon o con `SELECT pg_size_pretty(pg_database_size(current_database()));`. Si se va a quedar
corto, guarde los comprobantes en Cloudflare R2 (10 GB gratis), **preferiblemente antes de abrir inscripciones** (los
comprobantes ya guardados en la base no se mueven solos):

1. En su equipo: `composer require league/flysystem-aws-s3-v3 "^3.0"`, commit de `composer.json` y `composer.lock`.
2. En Cloudflare: cree un bucket R2 privado y un token de API con permiso de lectura y escritura sobre él.
3. En Render → *Environment*: `SOPORTES_ALMACEN=s3`, `AWS_ACCESS_KEY_ID`, `AWS_SECRET_ACCESS_KEY`,
   `AWS_DEFAULT_REGION=auto`, `AWS_BUCKET=<bucket>`, `AWS_ENDPOINT=https://<id de cuenta>.r2.cloudflarestorage.com`,
   `AWS_USE_PATH_STYLE_ENDPOINT=true`. Despliegue.

Con un disco, `evento:respaldo` descarga los comprobantes del bucket a `soportes.tar.gz` y `evento:limpiar inscripciones`
también los borra del bucket.

## Solución de problemas

| Síntoma | Causa y solución |
|---|---|
| El despliegue falla con `Unsupported cipher or incorrect key length` o `No application encryption key` | `APP_KEY` vacía o sin el prefijo `base64:`. Genérela con `php artisan key:generate --show` |
| `Falta EVENTO_SECRETO` o `debe tener al menos 32 caracteres` | Defina `EVENTO_SECRETO` en *Environment* |
| `No open ports detected` | Falta `PORT=8080` en *Environment* (la imagen escucha en el 8080) |
| `SQLSTATE[08006]` … SSL o `Endpoint ID is not specified` | Use la cadena de Neon con `sslmode=require`. Con un cliente muy antiguo agregue `&options=endpoint%3D<id del endpoint>` |
| `prepared statement "pdo_stmt_…" does not exist` o fallos de migración a través del pooler | Ejecute las migraciones desde su equipo con la cadena **directa** (`php artisan migrate --force --env=production`); si persiste en la aplicación, use temporalmente la cadena directa en `DB_URL` |
| La primera visita tarda mucho | Normal en el plan gratuito: Render se suspende tras 15 minutos y Neon tras unos minutos sin consultas |
| `419 Page Expired` al enviar un formulario | La sesión expiró o la cookie no llega: revise `APP_URL` (https), `SESSION_SECURE_COOKIE=true` y `TRUSTED_PROXIES=*`; recargue la página |
| El panel responde 403 desde la empresa | `PANEL_REDES` no incluye la IP pública actual; ajústela o déjela vacía |
| `pg_dump: error: aborting because of server version mismatch` | Instale el cliente de PostgreSQL de la versión del servidor (17) o superior, o indique su ruta en `PG_DUMP` |
| `No se encontró pg_dump` | Instale el cliente de PostgreSQL o use la restauración a un momento anterior de Neon |
| `413` al subir un comprobante | El archivo supera los límites (25 MB en PHP/Nginx; el del evento es `soporte_max_mb`) |
| Las fechas de expedición de asociados migrados no validan | `EVENTO_SECRETO` no es la del servidor anterior: corríjala (no hace falta volver a importar) |
| Neon sin espacio | Borre datos que ya no se necesitan (`evento:limpiar`) o pase los comprobantes a R2 |

## Datos personales

Render y Neon alojan los datos en Estados Unidos (región Virginia). Es una **transferencia internacional de datos
personales** (Ley 1581 de 2012, art. 26): el área jurídica debe validarla y, si corresponde, mencionar en la
autorización de tratamiento (`data/habeas_data.json`) que los datos se almacenan con proveedores de nube en el exterior.
Las claves (`APP_KEY`, `EVENTO_SECRETO`, `RESPALDO_CLAVE`) y las cadenas de conexión solo van en *Environment* de Render
y en el gestor de contraseñas, nunca en el repositorio.
