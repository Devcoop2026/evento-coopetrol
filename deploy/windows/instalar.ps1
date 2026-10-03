#Requires -RunAsAdministrator
<#
.SYNOPSIS
  Instala o actualiza el Evento Coopetrol en Windows Server 2025 con un contenedor Windows.

.DESCRIPTION
  Ejecutar como administrador desde la carpeta del código (la que contiene Dockerfile.windows):
    powershell -ExecutionPolicy Bypass -File .\deploy\windows\instalar.ps1

  Idempotente: la primera vez crea carpetas, claves y la tarea de respaldo; las siguientes veces hace un respaldo,
  reconstruye la imagen y reemplaza el contenedor conservando datos, claves y configuración.

  Estructura en el servidor (por defecto C:\EventoCoopetrol):
    datos\        base de datos, soportes de pago y config.json   -> C:\datos en el contenedor
    respaldos\    respaldos cifrados                              -> C:\respaldos
    importar\     archivos de bases para cargar por consola (solo lectura) -> C:\importar
    evento.env    variables y claves (solo administradores)
    evento.ps1    herramienta de operación (admin, bases, respaldos, limpieza, registros)
#>
param(
  [string]$Raiz = 'C:\EventoCoopetrol',
  [int]$Puerto = 3000,
  [string]$Contenedor = 'evento-coopetrol',
  [string]$Imagen = 'evento-coopetrol',
  [string]$VersionNode = 'latest-v24.x',
  [string]$HoraRespaldo = '02:30',
  # Subdirectorio público si IIS publica la aplicación bajo una ruta (p. ej. /portal-eventos). Se guarda en
  # evento.env: no hace falta repetirlo al actualizar. Use -RutaBase '' para volver a publicarla en la raíz.
  [string]$RutaBase
)
$ErrorActionPreference = 'Stop'
$codigo = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
function Paso($texto) { Write-Host "==> $texto" -ForegroundColor Green }

if (-not (Test-Path (Join-Path $codigo 'Dockerfile.windows'))) { throw "No se encontró Dockerfile.windows en $codigo." }
if (-not (Get-Command docker -ErrorAction SilentlyContinue)) {
  throw 'Docker no está instalado. Vea "Instalar Docker" en deploy\windows\DESPLIEGUE-WINDOWS.md.'
}
$tipo = docker info --format '{{.OSType}}'
if ($LASTEXITCODE -ne 0) { throw 'El servicio de Docker no responde: Start-Service docker' }
if ($tipo -ne 'windows') { throw "Docker está en modo '$tipo'; esta instalación usa contenedores Windows." }

# ---- Carpetas y permisos ----
Paso "Carpetas en $Raiz"
foreach ($d in 'datos', 'datos\soportes', 'respaldos', 'importar') { New-Item -ItemType Directory -Force (Join-Path $Raiz $d) | Out-Null }
# Datos personales: sin herencia; administradores y SYSTEM con control total. El usuario sin privilegios del
# contenedor (ContainerUser) escribe en las carpetas montadas a través del grupo Usuarios autentificados (S-1-5-11).
icacls $Raiz /inheritance:r /grant:r '*S-1-5-18:(OI)(CI)F' '*S-1-5-32-544:(OI)(CI)F' | Out-Null
foreach ($d in 'datos', 'respaldos') { icacls (Join-Path $Raiz $d) /grant '*S-1-5-11:(OI)(CI)M' | Out-Null }
icacls (Join-Path $Raiz 'importar') /grant '*S-1-5-11:(OI)(CI)RX' | Out-Null

# config.json propio del servidor (fechas de inscripción, enlace PSE...). Nunca se sobrescribe.
$config = Join-Path $Raiz 'datos\config.json'
if (-not (Test-Path $config)) {
  Copy-Item (Join-Path $codigo 'data\config.json') $config
  Write-Warning "Se creó $config desde el proyecto. Revise las fechas y active validar_periodo_inscripcion antes de abrir."
}

# ---- Variables y claves (se generan una sola vez) ----
$env_ = Join-Path $Raiz 'evento.env'
function ClaveHex {
  $bytes = New-Object byte[] 32
  [System.Security.Cryptography.RandomNumberGenerator]::Create().GetBytes($bytes)
  -join ($bytes | ForEach-Object { $_.ToString('x2') })
}
if (-not (Test-Path $env_)) {
  Paso 'Generando claves (EVENTO_SECRETO y RESPALDO_CLAVE)'
  @(
    '# Variables del contenedor. Guarde una copia segura de las claves: sin ellas no se pueden validar las fechas de',
    '# expedición (EVENTO_SECRETO) ni descifrar los respaldos (RESPALDO_CLAVE).',
    'TRUST_PROXY=1',
    "EVENTO_SECRETO=$(ClaveHex)",
    "RESPALDO_CLAVE=$(ClaveHex)",
    '# Redes desde las que se permite el panel (CIDR separados por coma). Vacío = sin restricción.',
    'PANEL_REDES=',
    'RETENCION_DIAS=30'
  ) | Set-Content -Encoding ascii $env_
} else {
  Paso "Se conservan las claves de $env_"
}
if ($PSBoundParameters.ContainsKey('RutaBase')) {
  $RutaBase = $RutaBase.Trim().TrimEnd('/')
  if ($RutaBase -and $RutaBase -notmatch '^(/[A-Za-z0-9._-]+)+$') { throw "RutaBase inválida: '$RutaBase' (ej.: /portal-eventos, sin espacios)." }
  Paso "Ruta pública: $(if ($RutaBase) { $RutaBase + '/' } else { 'raíz del sitio' })"
  @(Get-Content $env_ | Where-Object { $_ -notmatch '^RUTA_BASE=' }) + "RUTA_BASE=$RutaBase" | Set-Content -Encoding ascii $env_
}
icacls $env_ /inheritance:r /grant:r '*S-1-5-18:F' '*S-1-5-32-544:F' | Out-Null

# ---- Respaldo previo (si ya está instalado) ----
$existente = docker ps -a --filter "name=^$Contenedor$" --format '{{.Names}}'
if ($existente -and (docker inspect -f '{{.State.Running}}' $Contenedor) -eq 'true') {
  Paso 'Respaldo previo a la actualización'
  docker exec $Contenedor node --disable-warning=ExperimentalWarning scripts/respaldo.js
  if ($LASTEXITCODE -ne 0) { throw 'Falló el respaldo previo; no se actualizó nada.' }
}

# ---- Imagen ----
$version = Get-Date -Format 'yyyyMMdd-HHmm'
Paso "Construyendo la imagen $Imagen`:$version (la primera vez descarga la imagen base de Windows, varios minutos)"
docker build -f (Join-Path $codigo 'Dockerfile.windows') --build-arg "VERSION_NODE=$VersionNode" -t "$Imagen`:$version" -t "$Imagen`:actual" $codigo
if ($LASTEXITCODE -ne 0) { throw 'Falló la construcción de la imagen.' }

# ---- Contenedor ----
if ($existente) { Paso 'Reemplazando el contenedor'; docker rm -f $Contenedor | Out-Null }
# En Windows la red NAT de Docker no permite publicar el puerto solo en 127.0.0.1. Por eso:
#  - el firewall de Windows (que bloquea las entradas por defecto) mantiene el puerto cerrado desde fuera, y
#  - la aplicación solo cree la IP que envía IIS (X-Real-IP) si la conexión viene de la red NAT (PROXY_CONFIABLE):
#    quien llegara directo al puerto no podría falsificar su IP para evadir los límites por intentos.
$subredNat = (docker network inspect nat --format '{{range .IPAM.Config}}{{.Subnet}} {{end}}').Trim() -split '\s+' |
  Where-Object { $_ -match '^\d+\.\d+\.\d+\.\d+/\d+$' } | Select-Object -First 1
if (-not $subredNat) { throw 'No se encontró la red "nat" de Docker. Reinicie el servicio: Restart-Service docker' }
Paso "Iniciando el contenedor en el puerto $Puerto (red NAT $subredNat; IIS publica el sitio)"
docker run -d --name $Contenedor --restart unless-stopped `
  --env-file $env_ `
  -e "PROXY_CONFIABLE=$subredNat" `
  -p "$Puerto`:3000" `
  -v "$(Join-Path $Raiz 'datos'):C:\datos" `
  -v "$(Join-Path $Raiz 'respaldos'):C:\respaldos" `
  -v "$(Join-Path $Raiz 'importar'):C:\importar:ro" `
  --log-driver json-file --log-opt max-size=10m --log-opt max-file=10 `
  "$Imagen`:actual" | Out-Null
if ($LASTEXITCODE -ne 0) { throw 'No se pudo iniciar el contenedor. Revise: docker logs ' + $Contenedor }

$listo = $false
for ($i = 0; $i -lt 30 -and -not $listo; $i++) {
  Start-Sleep -Seconds 2
  try { $listo = (Invoke-WebRequest -UseBasicParsing "http://127.0.0.1:$Puerto/api/cupos" -TimeoutSec 5).StatusCode -eq 200 } catch {}
}
if (-not $listo) {
  docker logs --tail 30 $Contenedor
  throw "La aplicación no responde en http://127.0.0.1:$Puerto. Revise los registros anteriores."
}

# ---- Herramienta de operación y respaldo diario ----
Copy-Item (Join-Path $PSScriptRoot 'evento.ps1') (Join-Path $Raiz 'evento.ps1') -Force
$accion = New-ScheduledTaskAction -Execute 'powershell.exe' `
  -Argument "-NoProfile -ExecutionPolicy Bypass -File `"$(Join-Path $Raiz 'evento.ps1')`" respaldo"
$disparador = New-ScheduledTaskTrigger -Daily -At $HoraRespaldo
$principal = New-ScheduledTaskPrincipal -UserId 'SYSTEM' -RunLevel Highest
Register-ScheduledTask -TaskName 'EventoCoopetrol-Respaldo' -Action $accion -Trigger $disparador -Principal $principal `
  -Description 'Respaldo cifrado diario del Evento Coopetrol (base, soportes y configuración).' -Force | Out-Null

# Limpieza de imágenes antiguas (se conservan la actual y las 3 anteriores para poder volver atrás).
docker images $Imagen --format '{{.Tag}}' | Where-Object { $_ -match '^\d{8}-\d{4}$' } | Sort-Object -Descending |
  Select-Object -Skip 4 | ForEach-Object { docker rmi "$Imagen`:$_" 2>$null | Out-Null }

Paso "Listo: http://127.0.0.1:$Puerto (versión $version). Respaldo diario a las $HoraRespaldo."
# El puerto del contenedor no debe quedar abierto hacia la red (solo IIS debe llegar a él).
$abiertas = Get-NetFirewallRule -Direction Inbound -Action Allow -Enabled True -ErrorAction SilentlyContinue |
  Get-NetFirewallPortFilter -ErrorAction SilentlyContinue | Where-Object { $_.Protocol -eq 'TCP' -and $_.LocalPort -contains "$Puerto" }
if ($abiertas) {
  Write-Warning "Hay reglas del firewall que permiten entradas al puerto $Puerto. Desactívelas: solo IIS (puertos 80 y 443) debe quedar abierto."
}
Write-Host "  Siguiente: publicar con IIS (configurar-iis.ps1) y crear el usuario del panel:"
Write-Host "    & '$Raiz\evento.ps1' admin <usuario> `"<Nombre completo>`""
