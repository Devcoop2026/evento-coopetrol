# Despliegue en Windows Server 2025 (contenedor Windows + IIS)

La aplicación corre en un **contenedor Windows** (Nano Server 2025 + Node.js 24 LTS) y se publica con **IIS** como
proxy inverso HTTPS. El puerto del contenedor (3000) queda cerrado hacia la red por el firewall de Windows, y la
aplicación solo acepta la IP del cliente que envía IIS (`X-Real-IP`) cuando la conexión viene de la red NAT de Docker
(`PROXY_CONFIABLE`, lo configura `instalar.ps1`): aunque alguien llegara directo al puerto, no podría falsificar su IP.

```
Internet ──443──► IIS (certificado, HSTS, URL Rewrite + ARR) ──► 127.0.0.1:3000 ──► contenedor evento-coopetrol
                                                                                   C:\datos     ◄─ C:\EventoCoopetrol\datos
                                                                                   C:\respaldos ◄─ C:\EventoCoopetrol\respaldos
```

| Carpeta del servidor | Contenido | ¿Datos personales? |
|---|---|---|
| `C:\EventoCoopetrol\datos` | `evento.db` (base), `soportes\` (comprobantes), `config.json` del servidor | Sí |
| `C:\EventoCoopetrol\respaldos` | Respaldos diarios cifrados (AES-256-GCM), 30 días | Sí (cifrados) |
| `C:\EventoCoopetrol\importar` | Archivos de bases para cargar por consola (bórrelos después) | Sí |
| `C:\EventoCoopetrol\evento.env` | Claves (`EVENTO_SECRETO`, `RESPALDO_CLAVE`) y variables | Claves |
| `C:\EventoCoopetrol\evento.ps1` | Herramienta de operación | No |

La imagen solo contiene código y los JSON de configuración versionados (tarifas, formulario, habeas data).

## 1. Requisitos

- Windows Server 2025 Standard, con acceso a internet (descarga de la imagen base y de Node.js).
- Cuenta de administrador. Todos los comandos se ejecutan en **PowerShell como administrador**.
- El certificado del dominio (p. ej. `eventos.coopetrol.coop`) en formato `.pfx`, y el registro DNS apuntando al servidor.
- Puertos 80 y 443 abiertos hacia el servidor. El 3000 **no** debe abrirse.

## 2. Instalar Docker (una sola vez)

Docker Engine para contenedores Windows, con el script oficial de Microsoft (instala la característica
*Containers* y el servicio `docker`; puede pedir reiniciar):

```powershell
Invoke-WebRequest -UseBasicParsing https://raw.githubusercontent.com/microsoft/Windows-Containers/Main/helpful_tools/Install-DockerCE/install-docker-ce.ps1 -OutFile install-docker-ce.ps1
.\install-docker-ce.ps1
# Tras reiniciar, si lo pide, ejecútelo de nuevo. Verifique:
docker version
docker info --format '{{.OSType}}'   # debe decir: windows
```

El servicio `docker` arranca con el servidor y el contenedor se reinicia solo (`--restart unless-stopped`).

## 3. Copiar el código al servidor

En el equipo de desarrollo, desde la carpeta del proyecto:

```powershell
git archive --format=zip -o evento-coopetrol.zip HEAD
```

Copie `evento-coopetrol.zip` al servidor (escritorio remoto o carpeta compartida) y descomprímalo, p. ej. en
`C:\Fuentes\evento-coopetrol` (`Expand-Archive evento-coopetrol.zip C:\Fuentes\evento-coopetrol`).

## 4. Instalar

```powershell
cd C:\Fuentes\evento-coopetrol
powershell -ExecutionPolicy Bypass -File .\deploy\windows\instalar.ps1
```

El script:
1. Crea `C:\EventoCoopetrol` con permisos restringidos (administradores y SYSTEM; el usuario sin privilegios del
   contenedor solo puede escribir en `datos` y `respaldos`).
2. Genera **una sola vez** `evento.env` con `EVENTO_SECRETO` y `RESPALDO_CLAVE` aleatorias.
   **Guarde una copia segura de ese archivo** (gestor de contraseñas o bóveda): sin `EVENTO_SECRETO` no se validan
   las fechas de expedición cargadas y sin `RESPALDO_CLAVE` no se pueden descifrar los respaldos.
3. Copia `config.json` a `datos\` si no existe (después nunca lo sobrescribe).
4. Construye la imagen (la primera vez descarga ~1-2 GB de imágenes base de Microsoft; tarda varios minutos).
5. Inicia el contenedor en el puerto 3000 (red NAT de Docker) y verifica que responda en `http://127.0.0.1:3000`.
   En Windows no se puede publicar el puerto solo en 127.0.0.1: el firewall lo mantiene cerrado hacia la red. El script
   avisa si alguna regla del firewall lo abre.
6. Programa el respaldo diario (tarea **EventoCoopetrol-Respaldo**, 02:30, como SYSTEM).

Opciones: `-Puerto 3001` (si el 3000 está ocupado), `-Raiz D:\EventoCoopetrol`, `-HoraRespaldo 03:00`,
`-VersionNode v24.21.0` (fijar la versión de Node; por defecto la última 24 LTS).

## 5. Publicar con IIS (HTTPS)

Hay dos formas. Ambas requieren **URL Rewrite 2.1** y **Application Request Routing 3.0** instalados desde iis.net
(el script indica los enlaces si faltan).

### a) Bajo una ruta de un sitio existente (p. ej. `https://sistemas.coopetrol.coop/portal-eventos/`)

Para cuando el dominio ya existe en IIS con su certificado (otros sistemas en `sistemas.coopetrol.coop`). Se crea una
**aplicación** `portal-eventos` dentro de ese sitio, con su propio grupo de aplicaciones; la configuración del sitio y
de sus otras aplicaciones no se modifica.

```powershell
cd "$HOME\Documents\evento"
# 1. Indicar al contenedor la ruta pública (se guarda en evento.env; no hace falta repetirlo al actualizar).
powershell -ExecutionPolicy Bypass -File .\deploy\windows\instalar.ps1 -RutaBase /portal-eventos
# 2. Crear la aplicación en el sitio que atiende sistemas.coopetrol.coop.
powershell -ExecutionPolicy Bypass -File .\deploy\windows\configurar-iis.ps1 -Dominio sistemas.coopetrol.coop -Ruta /portal-eventos
```

Sitio: `https://sistemas.coopetrol.coop/portal-eventos/` · Panel: `https://sistemas.coopetrol.coop/portal-eventos/admin.html`.
La dirección sin barra final redirige a la que la tiene. Si ningún sitio de IIS atiende ese dominio por HTTPS, el
script lo crea (necesita el certificado, ver b.1). Si el sitio tiene otro nombre o hay varios, indíquelo con `-Sitio`.

### b) Como sitio propio en la raíz (p. ej. `https://eventos.coopetrol.coop/`)

1. Importe el certificado en **Equipo local › Personal**:
   ```powershell
   Import-PfxCertificate -FilePath C:\ruta\certificado.pfx -CertStoreLocation Cert:\LocalMachine\My -Password (Read-Host -AsSecureString 'Clave del pfx')
   ```
2. Configure el sitio:
   ```powershell
   powershell -ExecutionPolicy Bypass -File .\deploy\windows\configurar-iis.ps1 -Dominio eventos.coopetrol.coop
   ```

### c) Sin certificado todavía: Let's Encrypt (gratis, se renueva solo)

Requisito: el firewall de la empresa reenvía los puertos 80 y 443 de la IP pública hacia este servidor (NAT), de modo
que `http://<dominio>/` llegue a IIS desde internet.

```powershell
# 1. Sitio solo por HTTP (agregue -Ruta /portal-eventos si va bajo una ruta).
powershell -ExecutionPolicy Bypass -File .\deploy\windows\configurar-iis.ps1 -Dominio sistemas.coopetrol.coop -Ruta /portal-eventos -SinCertificado
# 2. Descargar win-acme y pedir el certificado para el sitio (lo instala en IIS y programa la renovación).
$version = Invoke-RestMethod https://api.github.com/repos/win-acme/win-acme/releases/latest
$zip = $version.assets | Where-Object name -match 'x64\.trimmed\.zip$' | Select-Object -First 1
Invoke-WebRequest $zip.browser_download_url -OutFile $HOME\Downloads\win-acme.zip
Expand-Archive $HOME\Downloads\win-acme.zip C:\win-acme -Force
C:\win-acme\wacs.exe --source iis --host sistemas.coopetrol.coop --accepttos --emailaddress <correo-del-area-de-tecnologia>
# 3. Activar HTTPS en el evento (redirección a HTTPS y HSTS), ya con el certificado instalado.
powershell -ExecutionPolicy Bypass -File .\deploy\windows\configurar-iis.ps1 -Dominio sistemas.coopetrol.coop -Ruta /portal-eventos
```

Si `wacs.exe` falla en la validación, el puerto 80 no llega desde internet: revise el reenvío en el firewall. También
puede ejecutar `C:\win-acme\wacs.exe` sin argumentos y seguir el menú (*N: Create certificate (default settings)*).

En todos los casos (con certificado) el proxy agrega redirección a HTTPS, HSTS, límite de 30 MB y la cabecera `X-Real-IP` con la IP
real del cliente (límites por IP, registro de seguridad y `PANEL_REDES`), y deja pasar los mensajes de error de la
aplicación. Si el puerto no es 3000, agregue `-Puerto`. Enlaces directos a los módulos: `#pse`, `#agencia`, `#consulta`
al final de la dirección del sitio.

## 6. Primer usuario del panel

```powershell
& C:\EventoCoopetrol\evento.ps1 admin dreina "Diego Reina"                 # ADMINISTRADOR (pide la clave)
& C:\EventoCoopetrol\evento.ps1 admin tesoreria "Tesorería" -Rol revisor   # REVISOR
```

El mismo comando sobre un usuario existente cambia su clave (conserva el rol).

## 7. Bases de asociados y Coopetrolitos

Lo recomendado es cargarlas desde el panel (**Cupos y cargas masivas**). Por consola:

```powershell
Copy-Item .\asociados.xlsx C:\EventoCoopetrol\importar\
& C:\EventoCoopetrol\evento.ps1 base asociados asociados.xlsx              # vista previa
& C:\EventoCoopetrol\evento.ps1 base asociados asociados.xlsx -Confirmar   # reemplaza la base
Remove-Item C:\EventoCoopetrol\importar\asociados.xlsx                      # contiene datos personales
```

## 8. Configuración

| Qué | Dónde | Cómo aplicar |
|---|---|---|
| Fechas de inscripción, enlace PSE, `validar_periodo_inscripcion`, `fecha_supresion_datos` | `C:\EventoCoopetrol\datos\config.json` | `evento.ps1 reiniciar` |
| `PANEL_REDES` (redes permitidas al panel, p. ej. `10.0.0.0/8,192.168.0.0/16`), `RETENCION_DIAS` | `C:\EventoCoopetrol\evento.env` | volver a ejecutar `instalar.ps1` |
| Tarifas y cupos, eventos compartidos entre agencias (`agencias_evento.json`), formulario de pago, texto de habeas data | `data\*.json` del código | publicar una versión nueva (sección 9) |

Antes de abrir inscripciones: `"validar_periodo_inscripcion": true` en `config.json`.

## 9. Actualizar a una versión nueva

Copie y descomprima el nuevo `evento-coopetrol.zip` (sección 3) y ejecute de nuevo:

```powershell
cd C:\Fuentes\evento-coopetrol
powershell -ExecutionPolicy Bypass -File .\deploy\windows\instalar.ps1
```

Hace un respaldo, construye la imagen nueva y reemplaza el contenedor; conserva datos, claves y `config.json`. Las
migraciones de la base corren solas al arrancar.

Para volver a la versión anterior: ejecute `instalar.ps1` desde el zip de esa versión. Si la versión nueva ya migró
la base, restaure además el respaldo que `instalar.ps1` hizo justo antes de actualizar (sección 11): una base de una
versión posterior no arranca con código anterior.

## 10. Operación diaria

```powershell
& C:\EventoCoopetrol\evento.ps1 estado        # contenedor, respuesta de la aplicación y último respaldo
& C:\EventoCoopetrol\evento.ps1 registros     # registro de la aplicación
& C:\EventoCoopetrol\evento.ps1 seguridad     # ingresos al panel, bloqueos por IP, permisos denegados
& C:\EventoCoopetrol\evento.ps1 reiniciar
```

El registro del contenedor rota en 10 archivos de 10 MB. Si se requiere conservar los eventos de seguridad más tiempo,
expórtelos periódicamente: `evento.ps1 seguridad -Lineas 100000 > seguridad-AAAAMM.log`.

## 11. Respaldos y restauración

- Automático: todos los días a las 02:30 (tarea programada). Manual: `evento.ps1 respaldo`.
- Cada respaldo (`C:\EventoCoopetrol\respaldos\AAAAMMDD-HHMM\`) contiene, cifrados con `RESPALDO_CLAVE`: la base
  (`evento.db.enc`), los comprobantes (`soportes.tar.gz.enc`), los JSON de la versión (`datos.tar.gz.enc`) y el
  `config.json` del servidor (`config-servidor.json.enc`). Se conservan 30 días (`RETENCION_DIAS`).
- **Copie los respaldos fuera del servidor** (unidad de red, copia de seguridad corporativa).

Restaurar (respalda antes el estado actual, detiene el contenedor, reemplaza base, soportes y config.json, y lo inicia):

```powershell
& C:\EventoCoopetrol\evento.ps1 restaurar                          # lista los respaldos disponibles
& C:\EventoCoopetrol\evento.ps1 restaurar 20261015-0230            # vista previa
& C:\EventoCoopetrol\evento.ps1 restaurar 20261015-0230 -Confirmar
```

En otro servidor: instale (secciones 2-4), reemplace `evento.env` por la copia guardada (mismas claves), vuelva a
ejecutar `instalar.ps1`, copie la carpeta del respaldo a `C:\EventoCoopetrol\respaldos\` y restaure.

## 12. Limpiar registros (p. ej. tras las pruebas)

```powershell
& C:\EventoCoopetrol\evento.ps1 limpiar todo               # vista previa
& C:\EventoCoopetrol\evento.ps1 limpiar todo -Confirmar    # respalda y borra
```

Opciones: `inscripciones`, `bases`, `auditoria`, `cupos`, `todo`. Se conservan usuarios del panel, tarifas y
configuración.

## 13. Problemas frecuentes

| Síntoma | Causa probable | Solución |
|---|---|---|
| `instalar.ps1`: *Docker está en modo 'linux'* | Docker Desktop u otro motor en modo Linux | Use Docker Engine para Windows (sección 2) |
| La compilación falla al descargar Node | Sin salida a internet o proxy corporativo | Permita `nodejs.org` y `mcr.microsoft.com`; con proxy, configure `HTTP(S)_PROXY` del servicio docker |
| *The container operating system does not match the host* | Imagen base de otra versión de Windows | `instalar.ps1` usa `ltsc2025`; en Windows Server 2022 compile con `--build-arg VERSION_WINDOWS=ltsc2022` |
| `SQLITE_CANTOPEN` / *EPERM* en el registro | Permisos de `datos` o `respaldos` | Vuelva a ejecutar `instalar.ps1` (reaplica permisos) |
| 502.3 en el navegador | El contenedor no está corriendo | `evento.ps1 estado`, `evento.ps1 registros` |
| 404.13 al cargar una base grande | Límite de IIS | Verifique `maxAllowedContentLength` en `C:\inetpub\evento-coopetrol\web.config` |
| Todos los intentos fallidos parecen venir de la misma IP (`evento.ps1 seguridad`) | Falta `X-Real-IP`, o IIS llega al contenedor desde fuera de la red NAT | Vuelva a ejecutar `configurar-iis.ps1`; si persiste, revise `docker network inspect nat` y `PROXY_CONFIABLE` |
| *Windows does not support host IP addresses in NAT settings* | Versión anterior de `instalar.ps1` | Actualice el código (`git pull`) y vuelva a ejecutarlo |
| El panel responde 403 desde la red interna | `PANEL_REDES` no incluye su red | Ajuste `evento.env` y ejecute `instalar.ps1` |

## Lista de verificación antes de abrir

- [ ] `docker info` muestra `OSType: windows`; `evento.ps1 estado` responde 200.
- [ ] `https://<dominio>[/ruta]/` abre con certificado válido; `http://` redirige a `https://`; el panel permite ingresar.
- [ ] Copia segura de `C:\EventoCoopetrol\evento.env` fuera del servidor.
- [ ] Usuarios del panel creados; bases de asociados y Coopetrolitos cargadas.
- [ ] `config.json`: fechas correctas, `validar_periodo_inscripcion: true`, `fecha_supresion_datos` definida con jurídica.
- [ ] `PANEL_REDES` con las redes de Coopetrol (si el panel no debe verse desde internet).
- [ ] Tarea **EventoCoopetrol-Respaldo** con resultado 0 tras la primera noche; respaldos copiados fuera del servidor.
- [ ] Puerto 3000 cerrado desde fuera: desde otro equipo, `Test-NetConnection <servidor> -Port 3000` debe fallar.
