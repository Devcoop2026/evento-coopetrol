# Despliegue manual en InfinityFree

La aplicacion usa Laravel 12, PHP 8.2 y MySQL. Este procedimiento no requiere Composer ni SSH en el hosting: las dependencias se preparan en el equipo y la base se importa con phpMyAdmin.

## 1. Preparar la cuenta

En el panel de InfinityFree, active PHP 8.2 para el dominio y cree una base MySQL. Anote exactamente el host, nombre de base, usuario y clave que muestra el panel. Compruebe que PHP tiene habilitado `pdo_mysql`, `fileinfo`, `mbstring`, `openssl`, `sodium` y `zip`.

## 2. Preparar el paquete local

Use PHP 8.2 para esta preparacion (Composer tambien esta limitado a resolver dependencias como PHP 8.2 por la configuracion del proyecto):

```bash
composer install --no-dev --optimize-autoloader
php artisan key:generate --show
php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"
```

Guarde los resultados de los dos ultimos comandos como `APP_KEY` y `EVENTO_SECRETO`; no los publique. Configure `.env` localmente con una base MySQL de desarrollo y ejecute las migraciones y los datos iniciales antes de exportar:

```bash
php artisan migrate --force
php artisan db:seed --force
php artisan evento:usuario admin "Administrador" --rol=administrador
```

Cree el usuario con una clave segura cuando el comando la solicite. No ejecute `DatosPruebaSeeder` con datos ficticios en la base que vaya a publicar.

Exporte todas las tablas desde phpMyAdmin local (o con `mysqldump`) a un archivo SQL. En el phpMyAdmin de InfinityFree, seleccione la base creada e importe ese SQL. No use una exportacion de SQLite.

## 3. Subir los archivos fuera del directorio publico

Si el administrador de archivos permite crear carpetas junto a `htdocs`, use una estructura similar a esta:

```text
cuenta/
  evento_app/       # app, bootstrap, config, database, resources, routes, storage, vendor, data
  htdocs/           # solo el contenido de public/
```

Suba el contenido del proyecto (incluido `vendor/`) a `evento_app`, pero no suba `.env`, `.git`, `tests/`, `docs/` ni `node_modules/`. Copie el contenido de `public/` a `htdocs/`. En `htdocs/index.php`, cambie las dos rutas Laravel para apuntar a la carpeta privada:

```php
require __DIR__.'/../evento_app/vendor/autoload.php';
$app = require_once __DIR__.'/../evento_app/bootstrap/app.php';
```

Ajuste `evento_app` si eligio otro nombre. Compruebe tambien que el `.htaccess` de `public/` quedo en `htdocs/`. La carpeta de la aplicacion, `.env` y comprobantes no deben ser accesibles por URL. Si su cuenta no permite guardar el codigo fuera de `htdocs` ni cambiar el directorio publico, no suba la aplicacion completa a una carpeta web: solicite una configuracion de document root segura antes de publicar datos personales.

## 4. Crear `.env` en el servidor

Cree `evento_app/.env` desde `.env.example` y reemplace los valores de ejemplo por los del panel. Como minimo:

```dotenv
APP_ENV=production
APP_DEBUG=false
APP_URL=https://tu-dominio
APP_KEY=base64:CLAVE_GENERADA
APP_TIMEZONE=America/Bogota

DB_CONNECTION=mysql
DB_HOST=HOST_MYSQL_DEL_PANEL
DB_PORT=3306
DB_DATABASE=NOMBRE_COMPLETO_DE_LA_BASE
DB_USERNAME=USUARIO_COMPLETO
DB_PASSWORD=CLAVE_MYSQL

SESSION_DRIVER=file
CACHE_STORE=file
QUEUE_CONNECTION=sync
FILESYSTEM_DISK=local
SOPORTES_ALMACEN=soportes
SOPORTES_DIR=/ruta/privada/evento_app/storage/app/soportes
EVENTO_SECRETO=SECRETO_ALEATORIO_DE_64_HEXADECIMALES
PANEL_REDES=
```

Use los nombres completos que muestra el panel; InfinityFree normalmente los prefija. `APP_KEY` y `EVENTO_SECRETO` son distintos. Mantenga `.env` fuera de `htdocs`, con permisos de lectura solo para la cuenta. Cree `storage/app/soportes` y asegure permisos de escritura para PHP en `storage/` y `bootstrap/cache/`.

El almacén `soportes` usa archivos fuera de la web raiz. No cambie a `base_datos` para recibos grandes: el tipo binario de MySQL puede tener limites menores que el maximo de archivo configurado en el formulario.

## 5. Verificar

Abra el dominio y `/up`. Luego compruebe la portada, una inscripcion de prueba y el ingreso a `/admin`. El administrador se creo antes de exportar la base. Borre los registros de prueba antes de abrir el sitio y cargue las bases reales desde el panel.

Sin consola en el hosting, los cambios de esquema deben prepararse localmente con migraciones, exportarse como SQL e importarse desde phpMyAdmin. Antes de cada actualizacion descargue un respaldo SQL y los archivos de `storage/app/soportes`. Nunca suba `.env` a Git ni comparta el SQL o las copias de soportes: contienen informacion personal.
