# Despliegue (Linux)

Guía para instalar, actualizar y operar el sistema del Evento Fin de Año Coopetrol en servidores Linux con systemd
(probado en Ubuntu 24.04).

## Ambientes

| | Producción | Pruebas |
|---|---|---|
| Servidor | `pqrsf-prod` (198.46.87.167), usuario `coopadmin` | `192.168.252.59` (red interna), usuario `pqrs` |
| Convive con | PQRSF producción (Nginx, PHP, PostgreSQL) | PQRSF QA (Apache en 80/443/8080) |
| Acceso | `https://eventos.coopetrol.coop` (pendiente DNS) · mientras tanto por túnel SSH | `http://192.168.252.59:3100` |
| Instalación | `sudo bash deploy/instalar.sh` | `sudo bash deploy/instalar.sh --pruebas` |
| Datos | Bases reales cargadas desde el panel | **Solo datos ficticios** (HTTP sin cifrado) |

La instalación es aislada: usuario de sistema propio (`evento`), carpetas propias y puerto propio. No modifica PQRSF.

```
Producción:
Internet ──HTTPS 443──► Nginx ──HTTP──► node server.js (127.0.0.1:3000, usuario "evento")
                                           ├─ /opt/evento-coopetrol            código (solo lectura)
                                           │    └─ data/                       configuración (JSON, sin datos personales)
                                           ├─ /var/lib/evento-coopetrol        evento.db y soportes/ (datos personales)
                                           ├─ /var/backups/evento-coopetrol    respaldos diarios
                                           └─ /etc/evento-coopetrol/evento.env variables y EVENTO_SECRETO
Pruebas:
Red interna ──HTTP 3100──► node server.js (0.0.0.0:3100, DATOS_PRUEBA=1)
```

## Requisitos

| Componente | Versión | Notas |
|---|---|---|
| Node.js | 22.13 o superior (24 LTS recomendado) | Sin dependencias npm. |
| Nginx + certbot | Nginx 1.18+ | Solo producción. |
| rsync, curl, tar | cualquiera | Usados por los scripts. |
| DNS | Registro **A** `eventos` → `198.46.87.167` en GoDaddy (`coopetrol.coop`) | Pendiente de crear. |
| Acceso | SSH con llave + `sudo` (pide contraseña) | Los pasos con `sudo` los ejecuta el administrador del servidor. |

Recursos: 1 vCPU, 1 GB RAM y 10 GB de disco son suficientes. SQLite con un solo proceso atiende el volumen del evento;
**no** ejecute varias instancias detrás de un balanceador.

> **Recomendación al pegar comandos:** ejecute **una línea a la vez**. Si pega varias líneas mientras un comando pide una
> contraseña (`sudo` o la del panel), las líneas siguientes se toman como respuesta.

## 1. Preparar y subir el paquete

Desde el equipo de desarrollo (Windows). El paquete se genera con `git archive`, así que **solo incluye lo que está en el
commit**: nunca viajan archivos locales con datos personales.

```powershell
# Cargar la llave SSH en el agente de Windows (pide la frase de la llave una vez por sesión)
ssh-add $HOME\.ssh\pqrsf_prod_ed25519      # producción
ssh-add $HOME\.ssh\coopetrol_pqrs          # pruebas

# Generar el paquete desde el último commit y subirlo a la carpeta personal del servidor
git archive --format=tar.gz -o evento-release.tgz HEAD
scp evento-release.tgz pqrsf-prod:           # o: scp evento-release.tgz pqrs@192.168.252.59:
```

En el servidor, descomprimir en la carpeta personal (no requiere `sudo`):
```bash
rm -rf ~/evento-coopetrol-src && mkdir ~/evento-coopetrol-src && tar -xzf ~/evento-release.tgz -C ~/evento-coopetrol-src && rm ~/evento-release.tgz
```

## 2. Instalación inicial

```bash
cd ~/evento-coopetrol-src && sudo bash deploy/instalar.sh              # producción
cd ~/evento-coopetrol-src && sudo bash deploy/instalar.sh --pruebas    # pruebas
```

El instalador (idempotente):
- Crea el usuario `evento`, las carpetas y copia el código a `/opt/evento-coopetrol`.
- Crea `/etc/evento-coopetrol/evento.env` y **genera `EVENTO_SECRETO`** (clave que protege las fechas de expedición).
- Instala y arranca los servicios `evento-coopetrol` y `evento-coopetrol-respaldo.timer` (respaldo diario 2:30 a. m.).
- Verifica que la aplicación responda.
- Con `--pruebas`: puerto 3100 en la red interna, sin proxy y con datos ficticios (`DATOS_PRUEBA=1`).

**Guarde la clave secreta** en el gestor de contraseñas de TI (si se pierde, hay que volver a cargar la base de asociados):
```bash
sudo grep EVENTO_SECRETO /etc/evento-coopetrol/evento.env
```

**Solo pruebas — firewall.** El servidor de pruebas tiene `ufw` activo; abra el puerto 3100 únicamente para redes internas:
```bash
sudo ufw allow from 172.16.0.0/12 to any port 3100 proto tcp comment 'evento-coopetrol pruebas'
sudo ufw allow from 192.168.0.0/16 to any port 3100 proto tcp comment 'evento-coopetrol pruebas'
```

## 3. Publicar con HTTPS (solo producción)

Requiere que el DNS ya resuelva (`nslookup eventos.coopetrol.coop` → `198.46.87.167`).

```bash
cd ~/evento-coopetrol-src && sudo bash deploy/configurar_nginx.sh eventos.coopetrol.coop tecnologia@coopetrol.coop
```

El script:
1. Verifica que el dominio apunte al servidor (si no, se detiene sin cambiar nada).
2. Publica un sitio HTTP temporal y obtiene el certificado de Let's Encrypt (renovación automática).
3. Activa el sitio HTTPS definitivo (`/etc/nginx/sites-available/evento-coopetrol`).

Antes de cada recarga valida con `nginx -t`; **si falla, revierte solo los cambios de este sitio** y los demás sitios
(PQRSF) no se tocan. No activa HTTP/2: en Nginx 1.24 se activaría también para los demás sitios del puerto 443.
La plantilla incluye un bloque opcional para restringir el panel a la red interna.

**Acceso antes de publicar (túnel SSH):** desde el equipo de trabajo, deje abierta una terminal con
```powershell
ssh -L 3100:127.0.0.1:3000 pqrsf-prod
```
y abra `http://localhost:3100/` y `http://localhost:3100/admin.html`. Solo funciona en ese equipo y el tráfico va cifrado.

## 4. Usuarios del panel

El primer usuario se crea por consola; los demás, desde el panel (pestaña **Usuarios del panel**).

```bash
sudo bash /opt/evento-coopetrol/deploy/admin.sh dreina "Diego Reina"                  # administrador
sudo bash /opt/evento-coopetrol/deploy/admin.sh ana.lopez "Ana López" --rol revisor  # revisor
```

**Roles** (pestaña *Usuarios del panel*; el servidor rechaza con 403 lo que el rol no permite):

| Acción | Revisor | Administrador |
|---|---|---|
| Ver inscripciones, cupos y soportes; exportar a Excel | ✅ | ✅ |
| Aprobar o rechazar pagos | ✅ | ✅ |
| Anular inscripciones, ajustar cupos, cargar bases, gestionar asociados, Coopetrolitos y usuarios, ver auditoría | — | ✅ |

El primer usuario (por consola) es administrador. Cree **revisores** para quienes solo verifican pagos (mínimo privilegio).
Siempre queda al menos un administrador; nadie puede cambiar su propio rol.

- El primer argumento es el **usuario** (minúsculas, sin espacios); el texto entre comillas es el **nombre** que se muestra.
- La **contraseña** se escribe cuando la pide (dos veces, mínimo 10 caracteres, no se ve al escribir).
- Primero pide la contraseña de `sudo` del servidor y luego la nueva del panel.
- Para cambiar la contraseña, ejecute el mismo comando con el mismo usuario (o edítelo desde el panel).
- Cada servidor tiene sus propios usuarios. Cree **uno por persona** para que quede registro de quién aprueba cada pago.

## 5. Bases de asociados y Coopetrolitos

Los datos personales **no** se guardan en el repositorio ni en archivos del proyecto: van directo a la base de datos
del servidor. En pruebas use solo datos ficticios.

**Carga masiva (panel → Cupos y cargas masivas):**
1. Descargue la plantilla o use el Excel de Coopetrol con las mismas columnas:
   - Asociados: Cedula, Nombre, Agencia, Asociado, Ultima actualizacion de datos, Fecha expedicion.
   - Coopetrolitos: Documento, Nombre, Cedula asociado.
2. Arrastre el archivo (.xlsx o .csv, hasta 20 MB). Se muestra la vista previa: registros válidos, filas omitidas con
   su motivo (cédula repetida, agencia inexistente, fecha inválida) y advertencias.
3. Confirme para **reemplazar la base completa** (una sola transacción, queda registrada con usuario y fecha).

Cargue primero **asociados** y luego **Coopetrolitos**.

**Gestión manual (panel → Asociados / Coopetrolitos):** crear, editar y eliminar registros individuales, con búsqueda.
- La fecha de expedición es de solo escritura: al editar se deja vacía para conservarla o se escribe una nueva.
- No se puede eliminar un asociado o Coopetrolito con inscripción activa (márquelo inactivo), ni un asociado con
  Coopetrolitos vinculados.
- Cada cambio queda en el **registro de cambios** (pestaña Usuarios del panel).

**Carga por consola** (automatizaciones):
```bash
sudo bash /opt/evento-coopetrol/deploy/cargar_base.sh asociados /tmp/asociados.xlsx              # vista previa
sudo bash /opt/evento-coopetrol/deploy/cargar_base.sh asociados /tmp/asociados.xlsx --confirmar  # reemplaza
shred -u /tmp/asociados.xlsx
```

Cada servidor protege las fechas de expedición con su propia `EVENTO_SECRETO`: las bases se cargan en cada servidor;
no copie `evento.db` entre ambientes.

## 6. Configuración

| Archivo | Contenido | Cómo se actualiza |
|---|---|---|
| `/etc/evento-coopetrol/evento.env` | `EVENTO_SECRETO`, `RESPALDO_CLAVE`, `PANEL_REDES`, puerto, rutas, `TRUST_PROXY`, `DATOS_PRUEBA` | Manual; luego `sudo systemctl restart evento-coopetrol` |
| `/opt/evento-coopetrol/data/config.json` | Fechas de inscripción, **`validar_periodo_inscripcion`**, enlace PSE, tamaño del soporte | Propio de cada servidor (`actualizar.sh` no lo toca); editar y reiniciar |
| `data/formulario_soporte.json` | Campos del soporte (vehículo/placa, discapacidad, contacto…) | Viene con el código: `actualizar.sh` |
| `data/habeas_data.json` | Texto de autorización de datos (versión) | Viene con el código: `actualizar.sh` |
| `data/tarifas.json` | Tarifas y cupos del Excel | Viene con el código: `actualizar.sh` |

Editar la configuración del servidor:
```bash
sudo nano /opt/evento-coopetrol/data/config.json
sudo systemctl restart evento-coopetrol
```

> Hoy `validar_periodo_inscripcion` está en `false` para poder probar. **Póngalo en `true` antes de abrir inscripciones.**

**Restricción del panel por red.** Con `PANEL_REDES` (CIDR separados por coma) el panel (`/admin.html` y `/api/admin/`)
solo responde desde esas redes; la parte pública sigue abierta. Ejemplo: `PANEL_REDES=10.0.0.0/8,192.168.0.0/16`.
Reinicie el servicio tras cambiarlo.

**Supresión de datos personales (habeas data).** `fecha_supresion_datos` en `config.json` (por defecto 2027-01-31) es la fecha
en que se cumple la finalidad. Desde ese día los administradores ven un aviso en el panel y el servicio lo registra al arrancar:
exporte lo necesario y ejecute `sudo bash /opt/evento-coopetrol/deploy/limpiar.sh todo --confirmar`.

## 7. Actualizar a una versión nueva

1. En el equipo de desarrollo: commit, `git archive` y `scp` (sección 1).
2. En el servidor, descomprimir en `~/evento-coopetrol-src` y ejecutar:
   ```bash
   cd ~/evento-coopetrol-src && sudo bash deploy/actualizar.sh
   ```
3. Recargar el navegador con `Ctrl + F5` (la aplicación se actualiza sola en los navegadores de los asociados).

`actualizar.sh` hace un respaldo previo, reemplaza el código, actualiza `tarifas.json`, `formulario_soporte.json` y
`habeas_data.json` (la versión anterior queda como `*.anterior`) y reinicia el servicio. Conserva `config.json`,
la base de datos, los soportes y la configuración del servidor.

## 8. Limpiar registros

Para borrar datos de prueba (por ejemplo, antes de abrir inscripciones). Siempre muestra primero cuánto borraría y hace
un respaldo antes de borrar; durante la limpieza detiene la aplicación unos segundos.

```bash
sudo bash /opt/evento-coopetrol/deploy/limpiar.sh inscripciones                # vista previa
sudo bash /opt/evento-coopetrol/deploy/limpiar.sh inscripciones --confirmar    # borra
```

| Opción | Qué borra |
|---|---|
| `inscripciones` | Preinscripciones, personas inscritas y soportes (incluidos los archivos). Las referencias vuelven a `EVT26-000001`. |
| `bases` | Asociados, Coopetrolitos e historial de cargas. |
| `auditoria` | Registro de cambios del panel. |
| `cupos` | Cupos ajustados a mano (vuelven al valor del Excel). |
| `todo` | Todo lo anterior. |

Siempre se conservan los usuarios del panel, las tarifas y la configuración. En el equipo local:
`npm run limpiar -- inscripciones` (detenga antes el servidor local).

## 9. Operación

| Tarea | Comando |
|---|---|
| Estado del servicio | `systemctl status evento-coopetrol` |
| Ver registros | `journalctl -u evento-coopetrol -f` |
| Reiniciar | `sudo systemctl restart evento-coopetrol` |
| Respaldo manual | `sudo systemctl start evento-coopetrol-respaldo` |
| Ver respaldos | `sudo ls -l /var/backups/evento-coopetrol` |
| Verificar la API | `curl -s http://127.0.0.1:3000/api/cupos` (pruebas: puerto 3100) |

### Respaldos

Cada día a las 2:30 a. m. (y antes de cada actualización o limpieza) se crea
`/var/backups/evento-coopetrol/AAAAMMDD-HHMM/` con `evento.db` (copia consistente), `soportes.tar.gz` y `datos.tar.gz`.
Se conservan 30 días (`RETENCION_DIAS`). Contienen **datos personales**: cópielos a un almacenamiento externo cifrado o
con acceso restringido, según la política de Coopetrol.

Con `RESPALDO_CLAVE` (la genera `instalar.sh`) los archivos se guardan **cifrados con AES-256-GCM** (`.enc`) y los originales
se borran. **Guarde `RESPALDO_CLAVE` en el gestor de secretos y fuera del servidor: sin ella no se puede restaurar.**

**Restaurar:**
```bash
# 1. Descifrar en una carpeta temporal (como el usuario del servicio, con sus variables)
sudo -u evento bash -c 'set -a; . /etc/evento-coopetrol/evento.env; set +a; cd /opt/evento-coopetrol && \
  node scripts/restaurar.js /var/backups/evento-coopetrol/FECHA /var/lib/evento-coopetrol/restaurado'
# 2. Reemplazar la base y los soportes
sudo systemctl stop evento-coopetrol
sudo install -o evento -g evento -m 600 /var/lib/evento-coopetrol/restaurado/evento.db /var/lib/evento-coopetrol/evento.db
sudo tar -xzf /var/lib/evento-coopetrol/restaurado/soportes.tar.gz -C /var/lib/evento-coopetrol/
sudo chown -R evento:evento /var/lib/evento-coopetrol
sudo systemctl start evento-coopetrol
# 3. Borrar la carpeta descifrada (contiene datos personales)
sudo rm -rf /var/lib/evento-coopetrol/restaurado
```

**Registro de seguridad.** Ingresos al panel (correctos, fallidos, bloqueados), fallos de identificación (documento enmascarado),
IP bloqueadas, permisos denegados, accesos fuera de `PANEL_REDES` y cargas de bases:
```bash
journalctl -u evento-coopetrol | grep '"tipo":"seguridad"'
```

## 10. Solución de problemas

| Síntoma | Causa y solución |
|---|---|
| `Permission denied (publickey)` al conectar por SSH | La llave tiene frase: cárguela con `ssh-add` (sección 1). |
| La página no carga desde otro equipo (pruebas) | Firewall `ufw`: agregue las reglas del puerto 3100 (sección 2). |
| "Uso: … configurar_nginx.sh dominio" | Falta el dominio o tiene mayúsculas/texto de ejemplo. |
| "El dominio no apunta a este servidor" | Falta o no ha propagado el registro DNS A. |
| El panel dice "Usuario o clave incorrectos" | Reinicie la contraseña con `admin.sh` (sección 4). Tras varios intentos, espere 15 min o reinicie el servicio. |
| "Las inscripciones abren el …" | `validar_periodo_inscripcion: true` y aún no inicia el periodo (sección 6). |
| "Ya tiene una preinscripción activa" | Regla de negocio: use "Ver mi inscripción y pagar" o cancele la existente. |
| El botón "Pagar por PSE" muestra Not Found | Revise `enlace_pago_pse` en `config.json` (enlace oficial: `https://www.avalpaycenter.com/wps/portal/portal-de-pagos/web/pagos-aval`). |
| Cambios de formulario o habeas data no se ven | Ejecute `actualizar.sh` y recargue con `Ctrl + F5`. |

## Seguridad aplicada

- Producción: Node escucha solo en `127.0.0.1`; Nginx termina TLS, agrega HSTS y limita la tasa de la API.
- El servicio corre como usuario sin privilegios, con el sistema de archivos en solo lectura salvo sus carpetas de datos.
- Fechas de expedición almacenadas como HMAC (`EVENTO_SECRETO`); las bases se cargan por el panel y no existen como archivos.
- IP real del cliente (habeas data, límite de intentos) tomada de `X-Real-IP` solo con `TRUST_PROXY=1`.
- Cookie de sesión del panel `HttpOnly`, `SameSite=Strict` y `Secure` detrás de HTTPS.
- Tokens de sesión guardados como hash; límites por IP en identificación, ingreso al panel y API (429).
- Content-Security-Policy estricta y aislamiento (`sandbox`) de los soportes de imagen; mensajes de identidad genéricos.
- Soportes fuera de la carpeta pública con nombre aleatorio; solo los ven administradores autenticados.
- Registro de cambios de toda gestión manual; `NODE_ENV=production` impide arrancar sin `EVENTO_SECRETO`.

## Antes de abrir inscripciones (producción)

- [ ] Registro DNS `eventos` creado y sitio publicado con HTTPS (`configurar_nginx.sh`).
- [ ] `validar_periodo_inscripcion` en `true` y fechas correctas en `config.json`.
- [ ] Texto de habeas data (incluidos datos sensibles de discapacidad) aprobado por jurídica.
- [ ] Bases reales de asociados (con fecha de expedición) y Coopetrolitos cargadas desde el panel.
- [ ] Copias de `EVENTO_SECRETO` y `RESPALDO_CLAVE` guardadas en el gestor de secretos.
- [ ] `PANEL_REDES` configurado con las redes de Coopetrol (o VPN).
- [ ] Usuarios con el rol adecuado (revisores para quienes solo verifican pagos).
- [ ] `fecha_supresion_datos` definida con jurídica en `config.json`.
- [ ] Node.js 24 LTS instalado en el servidor.
- [ ] Usuarios del panel creados (uno por persona); restricción del panel por IP evaluada.
- [ ] Registros de prueba limpiados: `limpiar.sh inscripciones --confirmar`.
- [ ] Respaldo ejecutado manualmente una vez y restauración probada.
- [ ] Flujo completo probado en el dominio público: identificación → simulación → preinscripción → pago PSE → soporte → aprobación.
