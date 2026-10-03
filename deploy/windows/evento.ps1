<#
.SYNOPSIS
  Operación del Evento Coopetrol en el contenedor Windows. Ejecutar como administrador:
    & C:\EventoCoopetrol\evento.ps1 <comando> [argumentos]

  Comandos:
    estado                                   estado del contenedor y de la aplicación
    registros [-Lineas 200]                  registro de la aplicación (docker logs)
    seguridad [-Lineas 2000]                 solo eventos de seguridad (inicios de sesión, bloqueos, permisos)
    admin <usuario> "<Nombre>" [-Rol revisor] crear usuario del panel o cambiar su clave (la pide por consola)
    base <asociados|coopetrolitos> <archivo> [-Confirmar]
                                             cargar una base desde C:\EventoCoopetrol\importar (sin -Confirmar: vista previa)
    limpiar <inscripciones|bases|auditoria|cupos|todo> [-Confirmar]
                                             borrar registros (sin -Confirmar: vista previa; siempre respalda antes)
    respaldo                                 respaldo cifrado ahora (también corre a diario por tarea programada)
    restaurar <carpeta> [-Confirmar]         restaurar un respaldo de C:\EventoCoopetrol\respaldos (p. ej. 20261015-0230)
    reiniciar                                reiniciar el contenedor
#>
param(
  [Parameter(Position = 0)][string]$Comando = 'estado',
  [Parameter(Position = 1)][string]$Arg1,
  [Parameter(Position = 2)][string]$Arg2,
  [string]$Rol,
  [switch]$Confirmar,
  [int]$Lineas = 0,
  [string]$Raiz = 'C:\EventoCoopetrol',
  [string]$Contenedor = 'evento-coopetrol'
)
$ErrorActionPreference = 'Stop'
$nodo = @('node', '--disable-warning=ExperimentalWarning')

function EnContenedor {
  param([string[]]$Argumentos, [switch]$Interactivo)
  $opciones = if ($Interactivo) { @('exec', '-it') } else { @('exec') }
  & docker @opciones $Contenedor @nodo @Argumentos
  if ($LASTEXITCODE -ne 0) { throw "El comando terminó con error ($LASTEXITCODE)." }
}
function ExigirCorriendo {
  if ((docker inspect -f '{{.State.Running}}' $Contenedor 2>$null) -ne 'true') {
    throw "El contenedor $Contenedor no está corriendo. Inícielo con: docker start $Contenedor"
  }
}

switch ($Comando) {
  'estado' {
    docker ps -a --filter "name=^$Contenedor$" --format 'table {{.Names}}\t{{.Status}}\t{{.Image}}\t{{.Ports}}'
    $puerto = @(docker port $Contenedor 3000 2>$null)[0] -replace '.*:', '' # p. ej. "0.0.0.0:3000" -> 3000
    if ($puerto) {
      try {
        $r = Invoke-WebRequest -UseBasicParsing "http://127.0.0.1:$puerto/api/cupos" -TimeoutSec 5
        Write-Host "Aplicación: responde ($($r.StatusCode)) en http://127.0.0.1:$puerto" -ForegroundColor Green
      } catch { Write-Warning "La aplicación no responde: $($_.Exception.Message)" }
    }
    Get-ScheduledTask -TaskName 'EventoCoopetrol-Respaldo' -ErrorAction SilentlyContinue |
      Get-ScheduledTaskInfo | Select-Object LastRunTime, LastTaskResult, NextRunTime | Format-List
  }
  'registros' {
    docker logs --tail ($(if ($Lineas) { $Lineas } else { 200 })) $Contenedor
  }
  'seguridad' {
    docker logs --tail ($(if ($Lineas) { $Lineas } else { 2000 })) $Contenedor 2>&1 | Where-Object { "$_" -match '"tipo":"seguridad"' }
  }
  'admin' {
    ExigirCorriendo
    if (-not $Arg1) { throw 'Uso: evento.ps1 admin <usuario> "<Nombre completo>" [-Rol revisor]' }
    $argumentos = @('scripts/crear_admin.js', $Arg1)
    if ($Arg2) { $argumentos += $Arg2 }
    if ($Rol) { $argumentos += @('--rol', $Rol) }
    EnContenedor -Interactivo $argumentos
  }
  'base' {
    ExigirCorriendo
    if ($Arg1 -notin 'asociados', 'coopetrolitos' -or -not $Arg2) {
      throw 'Uso: evento.ps1 base <asociados|coopetrolitos> <archivo en C:\EventoCoopetrol\importar> [-Confirmar]'
    }
    $nombre = Split-Path $Arg2 -Leaf
    if (-not (Test-Path (Join-Path $Raiz "importar\$nombre"))) {
      throw "Copie el archivo a $Raiz\importar\ (no se encontró $nombre). Después bórrelo: contiene datos personales."
    }
    $argumentos = @('scripts/cargar_base.js', $Arg1, "C:\importar\$nombre")
    if ($Confirmar) { $argumentos += '--confirmar' }
    EnContenedor $argumentos
  }
  'limpiar' {
    ExigirCorriendo
    if ($Arg1 -notin 'inscripciones', 'bases', 'auditoria', 'cupos', 'todo') {
      throw 'Uso: evento.ps1 limpiar <inscripciones|bases|auditoria|cupos|todo> [-Confirmar]'
    }
    $argumentos = @('scripts/limpiar.js', $Arg1)
    if ($Confirmar) { $argumentos += '--confirmar' }
    EnContenedor $argumentos
  }
  'respaldo' {
    ExigirCorriendo
    EnContenedor @('scripts/respaldo.js')
  }
  'restaurar' {
    ExigirCorriendo
    $origen = Join-Path $Raiz "respaldos\$Arg1"
    if (-not $Arg1 -or -not (Test-Path $origen)) {
      Write-Host 'Respaldos disponibles:'; Get-ChildItem (Join-Path $Raiz 'respaldos') -Directory | Where-Object Name -match '^\d{8}-\d{4}$' |
        Sort-Object Name -Descending | Select-Object -First 15 | ForEach-Object { "  $($_.Name)" }
      throw 'Uso: evento.ps1 restaurar <carpeta> [-Confirmar]'
    }
    if (-not $Confirmar) {
      Write-Host "Se restaurará $origen (base de datos, soportes y config.json). Los datos actuales se guardan antes en un respaldo."
      Write-Host 'Vista previa: no se cambió nada. Agregue -Confirmar para restaurar.'
      return
    }
    $temporal = "restaurado-$Arg1"
    $destino = Join-Path $Raiz "respaldos\$temporal"
    if (Get-ChildItem $origen -Filter '*.enc') {
      EnContenedor @('scripts/restaurar.js', "C:\respaldos\$Arg1", "C:\respaldos\$temporal")
    } else {
      Copy-Item $origen $destino -Recurse -Force # respaldo sin cifrar
    }
    EnContenedor @('scripts/respaldo.js') # copia de seguridad del estado actual
    docker stop $Contenedor | Out-Null
    try {
      $datos = Join-Path $Raiz 'datos'
      Copy-Item (Join-Path $destino 'evento.db') (Join-Path $datos 'evento.db') -Force
      Remove-Item (Join-Path $datos 'evento.db-wal'), (Join-Path $datos 'evento.db-shm') -ErrorAction SilentlyContinue
      if (Test-Path (Join-Path $destino 'soportes.tar.gz')) {
        & "$env:SystemRoot\System32\tar.exe" -xzf (Join-Path $destino 'soportes.tar.gz') -C $datos
        if ($LASTEXITCODE -ne 0) { throw 'No se pudieron extraer los soportes.' }
      }
      if (Test-Path (Join-Path $destino 'config-servidor.json')) {
        Copy-Item (Join-Path $destino 'config-servidor.json') (Join-Path $datos 'config.json') -Force
      }
    } finally {
      docker start $Contenedor | Out-Null
      Remove-Item $destino -Recurse -Force # contiene datos personales descifrados
    }
    Write-Host "Restaurado $Arg1. Verifique el panel." -ForegroundColor Green
  }
  'reiniciar' { docker restart $Contenedor }
  default { Get-Help $PSCommandPath; exit 1 }
}
