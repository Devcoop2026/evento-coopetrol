#Requires -RunAsAdministrator
<#
.SYNOPSIS
  Publica el Evento Coopetrol en IIS como proxy inverso HTTPS hacia el contenedor.

.DESCRIPTION
  Dos formas:
    - Bajo una ruta de un sitio existente (p. ej. https://sistemas.coopetrol.coop/portal-eventos/):
        configurar-iis.ps1 -Dominio sistemas.coopetrol.coop -Ruta /portal-eventos
      Crea una aplicación de IIS dentro del sitio que ya atiende ese dominio, sin tocar su configuración. Si ningún
      sitio atiende el dominio, crea uno. Ejecute antes instalar.ps1 -RutaBase /portal-eventos.
      Si el firewall de la empresa publica HTTPS por otro puerto (p. ej. 8199): agregue -PuertoHttps 8199
      (https://sistemas.coopetrol.coop:8199/portal-eventos/).
    - Como sitio propio en la raíz (p. ej. https://eventos.coopetrol.coop/):
        configurar-iis.ps1 -Dominio eventos.coopetrol.coop

  Requisitos: URL Rewrite 2.1 y Application Request Routing (ARR) 3.0, y el certificado del dominio en
  Cert:\LocalMachine\My (si hay varios, indique -Huella). Sin certificado todavía: agregue -SinCertificado (solo HTTP),
  obtenga el certificado (p. ej. Let's Encrypt con win-acme) y ejecute de nuevo sin -SinCertificado.
  Se puede ejecutar de nuevo sin problema.
#>
param(
  [Parameter(Mandatory)][string]$Dominio,
  [string]$Ruta = '',
  [int]$Puerto = 3000,
  [string]$Huella,
  [string]$Sitio,
  [string]$Carpeta = 'C:\inetpub\evento-coopetrol',
  [string]$GrupoAplicaciones = 'EventoCoopetrol',
  # Solo HTTP, sin certificado (pruebas internas o paso previo a obtener el certificado de Let's Encrypt).
  [switch]$SinCertificado,
  # Puerto HTTPS de IIS (443 por defecto). Use el que el firewall de la empresa publique hacia este servidor, p. ej. 8199.
  [int]$PuertoHttps = 443
)
$ErrorActionPreference = 'Stop'
function Paso($texto) { Write-Host "==> $texto" -ForegroundColor Green }
$appcmd = "$env:windir\System32\inetsrv\appcmd.exe"

$Ruta = $Ruta.Trim().TrimEnd('/')
if ($Ruta -and $Ruta -notmatch '^(/[A-Za-z0-9._-]+)+$') { throw "Ruta inválida: '$Ruta' (ej.: /portal-eventos, sin espacios)." }

# ---- Requisitos ----
if (-not (Get-WindowsFeature Web-Server).Installed) {
  Paso 'Instalando IIS'
  Install-WindowsFeature Web-Server -IncludeManagementTools | Out-Null
}
Import-Module WebAdministration
$modulos = (& $appcmd list modules) -join "`n"
$faltan = @()
if ($modulos -notmatch 'RewriteModule') { $faltan += 'URL Rewrite 2.1: https://www.iis.net/downloads/microsoft/url-rewrite' }
if ($modulos -notmatch 'ApplicationRequestRouting') { $faltan += 'Application Request Routing 3.0: https://www.iis.net/downloads/microsoft/application-request-routing' }
if ($faltan) { throw "Instale primero (y vuelva a ejecutar este script):`n  $($faltan -join "`n  ")" }

# ---- ARR como proxy y variables que puede fijar URL Rewrite ----
Paso 'Habilitando el proxy de ARR'
& $appcmd set config -section:system.webServer/proxy /enabled:"True" /preserveHostHeader:"True" `
  /reverseRewriteHostInResponseHeaders:"False" /timeout:"00:02:00" /commit:apphost | Out-Null
foreach ($variable in 'HTTP_X_REAL_IP', 'HTTP_X_FORWARDED_PROTO') {
  $existe = & $appcmd list config -section:system.webServer/rewrite/allowedServerVariables /commit:apphost
  if (($existe -join "`n") -notmatch $variable) {
    & $appcmd set config -section:system.webServer/rewrite/allowedServerVariables /+"[name='$variable']" /commit:apphost | Out-Null
  }
}

# ---- Sitio que atiende el dominio (existente o nuevo) ----
function SitioDelDominio {
  Get-Website | Where-Object {
    $_.bindings.Collection | Where-Object { $_.protocol -in 'http', 'https' -and ($_.bindingInformation -split ':')[-1] -eq $Dominio }
  } | Select-Object -First 1
}
$existente = if ($Sitio) { Get-Website -Name $Sitio } else { SitioDelDominio }

if ($existente -and $Ruta) {
  $Sitio = $existente.Name
  Paso "Se usa el sitio existente '$Sitio' ($Dominio); no se modifica su configuración"
} else {
  if (-not $Sitio) { $Sitio = if ($Ruta) { $Dominio } else { 'EventoCoopetrol' } }
  # Carpeta de la raíz del sitio: la del evento si se publica en la raíz; una vacía si va bajo una ruta.
  $raizSitio = if ($Ruta) { "C:\inetpub\$($Sitio -replace '[^A-Za-z0-9.-]', '-')" } else { $Carpeta }
  New-Item -ItemType Directory -Force $raizSitio | Out-Null
  $grupoSitio = if ($Ruta) { $Sitio } else { $GrupoAplicaciones }
  if (-not (Test-Path "IIS:\AppPools\$grupoSitio")) { New-WebAppPool -Name $grupoSitio | Out-Null }
  Set-ItemProperty "IIS:\AppPools\$grupoSitio" -Name managedRuntimeVersion -Value ''
  if (-not (Get-Website -Name $Sitio)) {
    Paso "Creando el sitio $Sitio"
    New-Website -Name $Sitio -PhysicalPath $raizSitio -HostHeader $Dominio -Port 80 -ApplicationPool $grupoSitio | Out-Null
  }
  Start-Website -Name $Sitio -ErrorAction SilentlyContinue
}

# ---- HTTPS: enlace 443 con el certificado (salvo -SinCertificado) ----
$enlaceHttps = Get-WebBinding -Name $Sitio -Protocol https -Port $PuertoHttps -HostHeader $Dominio | Select-Object -First 1
# Un enlace 443 sin certificado asociado (p. ej. de una ejecución interrumpida) no cuenta como HTTPS configurado.
$conCertificado = $enlaceHttps -and $enlaceHttps.certificateHash
if ($SinCertificado) {
  if (-not $conCertificado) { Write-Warning "Sitio solo por HTTP. Obtenga el certificado y ejecute de nuevo este script sin -SinCertificado." }
} elseif ($conCertificado -and -not $Huella) {
  Paso 'El sitio ya tiene HTTPS: se conserva su certificado'
} else {
  if (-not $Huella) {
    $dominioPadre = '*.' + ($Dominio -replace '^[^.]+\.', '')
    $certs = @(Get-ChildItem Cert:\LocalMachine\My, Cert:\LocalMachine\WebHosting -ErrorAction SilentlyContinue | Where-Object {
        $_.NotAfter -gt (Get-Date) -and $_.HasPrivateKey -and
        ($_.DnsNameList.Unicode -contains $Dominio -or $_.DnsNameList.Unicode -contains $dominioPadre)
      } | Sort-Object NotAfter -Descending)
    if (-not $certs) {
      throw "No hay un certificado vigente para $Dominio en este servidor. Impórtelo, use -Huella, o publique primero con -SinCertificado (ver la guía: certificado de Let's Encrypt)."
    }
    $Huella = $certs[0].Thumbprint
  }
  $cert = Get-ChildItem Cert:\LocalMachine\My, Cert:\LocalMachine\WebHosting -ErrorAction SilentlyContinue | Where-Object Thumbprint -eq $Huella | Select-Object -First 1
  if (-not $cert) { throw "No se encontró el certificado con huella $Huella." }
  Paso "Certificado: $($cert.Subject) (vence $($cert.NotAfter.ToString('yyyy-MM-dd')))"
  if (-not $enlaceHttps) {
    New-WebBinding -Name $Sitio -Protocol https -Port $PuertoHttps -HostHeader $Dominio -SslFlags 1 # SNI: convive con otros sitios
  }
  # Almacén del certificado: 'My' o 'WebHosting' (de "Microsoft.PowerShell.Security\Certificate::LocalMachine\My").
  $almacen = ($cert.PSParentPath -split '\\')[-1]
  $enlace = Get-WebBinding -Name $Sitio -Protocol https -Port $PuertoHttps -HostHeader $Dominio | Select-Object -First 1
  $enlace.AddSslCertificate($Huella, $almacen)
  Paso "HTTPS activado en el puerto $PuertoHttps con el certificado $Huella"
  if ($PuertoHttps -ne 443) {
    # IIS solo abre 80 y 443 en el firewall de Windows: se abre también el puerto HTTPS elegido.
    $regla = "EventoCoopetrol HTTPS $PuertoHttps"
    if (-not (Get-NetFirewallRule -DisplayName $regla -ErrorAction SilentlyContinue)) {
      New-NetFirewallRule -DisplayName $regla -Direction Inbound -Protocol TCP -LocalPort $PuertoHttps -Action Allow | Out-Null
      Paso "Firewall de Windows: abierto el puerto TCP $PuertoHttps"
    }
  }
}

# ---- Evento: aplicación bajo la ruta, o raíz del sitio ----
New-Item -ItemType Directory -Force $Carpeta | Out-Null
$config = (Get-Content -Raw (Join-Path $PSScriptRoot 'web.config')) -replace 'PUERTO_EVENTO', $Puerto
if ($PuertoHttps -ne 443) { $config = $config.Replace('https://{HTTP_HOST}{REQUEST_URI}', "https://{SERVER_NAME}:$PuertoHttps{REQUEST_URI}") }
if ($SinCertificado) {
  # Solo HTTP (pruebas o mientras se obtiene el certificado): sin redirección a HTTPS ni HSTS.
  $config = $config -replace '(?s)\s*<!-- Todo por HTTPS\. -->.*?</rule>', '' -replace '(?m)^.*Strict-Transport-Security.*\r?\n', '' `
    -replace '(?m)^.*si el sitio padre ya define HSTS.*\r?\n', '' -replace 'name="HTTP_X_FORWARDED_PROTO" value="https"', 'name="HTTP_X_FORWARDED_PROTO" value="http"'
}
Set-Content -Encoding utf8 -Value $config (Join-Path $Carpeta 'web.config')
if ($Ruta) {
  # Grupo de aplicaciones propio: no comparte proceso con las demás aplicaciones del sitio.
  if (-not (Test-Path "IIS:\AppPools\$GrupoAplicaciones")) { New-WebAppPool -Name $GrupoAplicaciones | Out-Null }
  Set-ItemProperty "IIS:\AppPools\$GrupoAplicaciones" -Name managedRuntimeVersion -Value ''
  $nombre = $Ruta.TrimStart('/')
  if (Get-WebApplication -Site $Sitio -Name $nombre) {
    Set-ItemProperty "IIS:\Sites\$Sitio\$nombre" -Name physicalPath -Value $Carpeta
    Set-ItemProperty "IIS:\Sites\$Sitio\$nombre" -Name applicationPool -Value $GrupoAplicaciones
  } else {
    Paso "Creando la aplicación $Ruta en el sitio $Sitio"
    New-WebApplication -Site $Sitio -Name $nombre -PhysicalPath $Carpeta -ApplicationPool $GrupoAplicaciones | Out-Null
  }
  $ubicacion = "$Sitio$Ruta"
} else {
  Set-ItemProperty "IIS:\Sites\$Sitio" -Name physicalPath -Value $Carpeta
  $ubicacion = $Sitio
}
# Los errores de la aplicación (p. ej. 422 con el mensaje para el usuario) pasan tal cual, sin la página de error de
# IIS. Se fija en applicationHost.config solo para esta ubicación (la sección httpErrors no se delega a web.config).
& $appcmd set config $ubicacion -section:system.webServer/httpErrors /existingResponse:"PassThrough" /commit:apphost | Out-Null

# ---- Verificación ----
$url = if ($SinCertificado) { "http://$Dominio$Ruta/" } elseif ($PuertoHttps -ne 443) { "https://$Dominio`:$PuertoHttps$Ruta/" } else { "https://$Dominio$Ruta/" }
Start-Sleep -Seconds 2
try {
  $r = Invoke-WebRequest -UseBasicParsing "$($url)api/cupos" -TimeoutSec 15
  Paso "Publicado: $url (respuesta $($r.StatusCode)). Panel: $($url)admin.html"
} catch {
  Write-Warning "No se pudo verificar $url desde este servidor: $($_.Exception.Message)"
  Write-Warning "Revise el DNS (o el archivo hosts), el firewall (puerto $PuertoHttps) y que el contenedor esté corriendo."
}
if ($Ruta) {
  $rutaBase = (docker exec evento-coopetrol node -e "process.stdout.write(process.env.RUTA_BASE||'')" 2>$null)
  if ($rutaBase -ne $Ruta) {
    Write-Warning "El contenedor tiene RUTA_BASE='$rutaBase'. Ejecute instalar.ps1 -RutaBase $Ruta para que el inicio de sesión del panel funcione bajo $Ruta."
  }
}
