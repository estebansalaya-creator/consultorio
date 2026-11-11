<#
  Crea (o actualiza) el sitio de IIS "centro_medico" en el puerto 8030, apuntando a la carpeta de la app.

  Uso: doble clic en configurar-iis.bat (pide permiso de administrador).
  Se puede volver a correr sin problema: lo que ya está hecho lo deja como está.

  Qué hace:
   1. Crea el grupo de aplicaciones "centro_medico" (sin código administrado, como corresponde a PHP).
   2. Crea el sitio "centro_medico" con el enlace http en el puerto 8030 (o le agrega ese puerto si ya existía).
   3. Verifica que PHP esté registrado en IIS (el mismo que usan las otras apps); si el manejador no llega al sitio, lo agrega.
   4. Da permiso de lectura sobre la carpeta a IIS, y de escritura en la carpeta de sesiones de PHP.
   5. Abre el puerto 8030 en el Firewall de Windows sólo para redes privadas y de dominio.
   6. Si falta config.local.php, ofrece copiarlo de estética o del bar cambiando la base a "centro_medico".
   7. Arranca el sitio y prueba que responda.
#>
param(
    [string]  $Sitio       = 'centro_medico',
    [int]     $Puerto      = 8030,
    [string]  $Ruta        = (Split-Path -Parent $PSScriptRoot),
    [string[]]$ConfigOtras = @('D:\estetica\config.local.php', 'D:\bar\config.local.php')
)

$ErrorActionPreference = 'Stop'

function Paso($t)  { Write-Host ''; Write-Host "== $t" -ForegroundColor Cyan }
function Ok($t)    { Write-Host "   OK   $t" -ForegroundColor Green }
function Aviso($t) { Write-Host "   OJO  $t" -ForegroundColor Yellow }
function Falla($t) {
    Write-Host ''
    Write-Host "   ERROR  $t" -ForegroundColor Red
    Write-Host ''
    exit 1
}

# ---------- Requisitos ----------
$esAdmin = ([Security.Principal.WindowsPrincipal][Security.Principal.WindowsIdentity]::GetCurrent()).IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)
if (-not $esAdmin) {
    Falla 'Hay que ejecutarlo como administrador: usá configurar-iis.bat (lo pide solo).'
}

$appcmd = Join-Path $env:windir 'system32\inetsrv\appcmd.exe'
if (-not (Test-Path $appcmd)) {
    Falla 'No se encontró IIS en esta PC (falta appcmd.exe). Activalo en "Activar o desactivar las características de Windows".'
}

$Ruta = (Resolve-Path $Ruta).Path.TrimEnd('\')
if (-not (Test-Path (Join-Path $Ruta 'index.php'))) {
    Falla "La carpeta $Ruta no parece la app (no tiene index.php)."
}

# Ejecuta appcmd y corta si falla
function AppCmd {
    $ErrorActionPreference = 'Continue'
    $salida = & $appcmd @args 2>&1
    if ($LASTEXITCODE -ne 0) {
        throw ("appcmd " + ($args -join ' ') + "`n" + ($salida -join "`n"))
    }
    return $salida
}

# Ejecuta appcmd con /xml y devuelve el XML, o $null si no existe lo pedido
function AppCmdXml {
    $ErrorActionPreference = 'Continue'
    $salida = & $appcmd @args '/xml' 2>$null
    if ($LASTEXITCODE -ne 0 -or -not $salida) { return $null }
    try { return [xml]($salida -join "`n") } catch { return $null }
}

Write-Host ''
Write-Host "Sitio de IIS '$Sitio' en el puerto $Puerto -> $Ruta" -ForegroundColor White

try {
    # ---------- 1. Puerto libre ----------
    Paso "Puerto $Puerto"
    $sitios = AppCmdXml list site
    $otro = $null
    if ($sitios) {
        foreach ($s in $sitios.appcmd.SITE) {
            if ($s.'SITE.NAME' -ne $Sitio -and $s.bindings -match ":$($Puerto):") { $otro = $s.'SITE.NAME' }
        }
    }
    if ($otro) {
        Falla "El puerto $Puerto ya lo usa el sitio de IIS '$otro'. Elegí otro puerto: configurar-iis.bat -Puerto 8031"
    }
    $escucha = $null
    try { $escucha = Get-NetTCPConnection -LocalPort $Puerto -State Listen -ErrorAction SilentlyContinue | Select-Object -First 1 } catch { }
    if ($escucha -and $escucha.OwningProcess -ne 4) {
        $proc = (Get-Process -Id $escucha.OwningProcess -ErrorAction SilentlyContinue).ProcessName
        Falla "El puerto $Puerto lo está usando otro programa ($proc). Cerralo o elegí otro puerto: configurar-iis.bat -Puerto 8031"
    }
    Ok 'Libre'

    # ---------- 2. Grupo de aplicaciones ----------
    Paso "Grupo de aplicaciones '$Sitio'"
    if (AppCmdXml list apppool $Sitio) {
        Ok 'Ya existía'
    } else {
        AppCmd add apppool "/name:$Sitio" '/managedRuntimeVersion:' '/managedPipelineMode:Integrated' | Out-Null
        Ok 'Creado (sin código administrado, canalización integrada)'
    }

    # ---------- 3. Sitio ----------
    Paso "Sitio '$Sitio'"
    $site = AppCmdXml list site $Sitio
    if (-not $site) {
        AppCmd add site "/name:$Sitio" "/bindings:http/*:$($Puerto):" "/physicalPath:$Ruta" | Out-Null
        Ok "Creado en http://*:$Puerto"
    } else {
        if ($site.appcmd.SITE.bindings -notmatch ":$($Puerto):") {
            AppCmd set site "/site.name:$Sitio" "/+bindings.[protocol='http',bindingInformation='*:$($Puerto):']" | Out-Null
            Ok "Ya existía: se le agregó el puerto $Puerto"
        } else {
            Ok "Ya existía con el puerto $Puerto"
        }
        AppCmd set vdir "$Sitio/" "/physicalPath:$Ruta" | Out-Null
    }
    AppCmd set app "$Sitio/" "/applicationPool:$Sitio" | Out-Null
    Ok "Carpeta $Ruta, grupo de aplicaciones '$Sitio'"

    # ---------- 4. PHP ----------
    Paso 'PHP'
    $fcgi = AppCmdXml list config '/section:system.webServer/fastCgi'
    $apps = @()
    if ($fcgi) { $apps = @($fcgi.SelectNodes('//application')) }
    if (-not $apps.Count) {
        Falla 'PHP no está registrado en IIS (no hay aplicaciones FastCGI). Configuralo igual que para el bar o estética (PHP Manager o el instalador de PHP para IIS) y volvé a correr esto.'
    }
    $app = $apps | Where-Object { $_.fullPath -match 'php-cgi\.exe$' } | Select-Object -First 1
    if (-not $app) { $app = $apps[0] }
    $phpCgi = $app.fullPath
    $procesador = if ($app.arguments) { "$($app.fullPath)|$($app.arguments)" } else { $app.fullPath }

    $handlers = AppCmdXml list config "$Sitio/" '/section:system.webServer/handlers'
    $phpHandler = $null
    if ($handlers) { $phpHandler = $handlers.SelectSingleNode("//add[@path='*.php']") }
    if ($phpHandler) {
        Ok "Lo toma de la configuración general de IIS ($($phpHandler.scriptProcessor))"
    } else {
        AppCmd set config $Sitio '/section:system.webServer/handlers' "/+[@start,name='PHP_FastCGI_$Sitio',path='*.php',verb='GET,HEAD,POST',modules='FastCgiModule',scriptProcessor='$procesador',resourceType='Either']" '/commit:apphost' | Out-Null
        Ok "Manejador agregado al sitio: $procesador"
    }

    # ---------- 5. Permisos ----------
    Paso 'Permisos de la carpeta'
    $cuentaPool = "IIS AppPool\$Sitio"
    & icacls $Ruta /grant "$($cuentaPool):(OI)(CI)RX" /T /C /Q | Out-Null
    if ($LASTEXITCODE -ne 0) { Aviso "icacls no pudo dar permiso a $cuentaPool sobre $Ruta" }
    & icacls $Ruta /grant 'IUSR:(OI)(CI)RX' /T /C /Q | Out-Null
    Ok "Lectura para $cuentaPool e IUSR"

    # Carpeta de sesiones de PHP: sin permiso de escritura no se puede iniciar sesión
    $phpExe = Join-Path (Split-Path $phpCgi) 'php.exe'
    if (Test-Path $phpExe) {
        $ErrorActionPreference = 'Continue'
        $sesiones = (& $phpExe -r "echo ini_get('session.save_path');" 2>$null)
        $ErrorActionPreference = 'Stop'
        $sesiones = "$sesiones".Trim()
        if ($sesiones -match ';') { $sesiones = $sesiones.Split(';')[-1] }
        if ($sesiones -and (Test-Path $sesiones)) {
            & icacls $sesiones /grant "$($cuentaPool):(OI)(CI)M" /C /Q | Out-Null
            Ok "Escritura en la carpeta de sesiones de PHP ($sesiones)"
        } else {
            Ok 'PHP guarda las sesiones en la carpeta temporal de Windows (no hace falta tocar nada)'
        }
    } else {
        Aviso "No encontré php.exe junto a $phpCgi para revisar la carpeta de sesiones. Si no podés iniciar sesión, dale permiso de escritura a '$cuentaPool' en la carpeta de session.save_path del php.ini."
    }

    # ---------- 6. Firewall ----------
    Paso 'Firewall de Windows'
    $regla = "IIS $Sitio (puerto $Puerto)"
    if (Get-NetFirewallRule -DisplayName $regla -ErrorAction SilentlyContinue) {
        Ok "La regla '$regla' ya existía"
    } else {
        New-NetFirewallRule -DisplayName $regla -Direction Inbound -Protocol TCP -LocalPort $Puerto -Action Allow -Profile Domain, Private | Out-Null
        Ok "Puerto $Puerto abierto para redes privadas y de dominio (no para redes públicas)"
    }

    # ---------- 7. config.local.php ----------
    Paso 'Conexión a MySQL (config.local.php)'
    $configLocal = Join-Path $Ruta 'config.local.php'
    $configOtra = $ConfigOtras | Where-Object { Test-Path $_ } | Select-Object -First 1
    if (Test-Path $configLocal) {
        Ok 'Ya existe'
    } elseif ($configOtra) {
        $r = Read-Host "   No hay config.local.php. ¿Copio el de $configOtra usando la base 'centro_medico'? (S/N)"
        if ($r -match '^[sS]') {
            $txt = [IO.File]::ReadAllText($configOtra)
            $txt = [regex]::Replace($txt, "define\(\s*'DB_NAME'\s*,\s*'[^']*'\s*\)", "define('DB_NAME', 'centro_medico')")
            if ($txt -notmatch "define\('DB_NAME', 'centro_medico'\)") {
                $txt = $txt.TrimEnd() + "`r`ndefine('DB_NAME', 'centro_medico');`r`n"
            }
            [IO.File]::WriteAllText($configLocal, $txt, (New-Object Text.UTF8Encoding($false)))
            Ok 'Creado con los mismos datos de MySQL y la base centro_medico'
        } else {
            Aviso 'Copiá config.local.example.php como config.local.php y completá la contraseña de MySQL.'
        }
    } else {
        Aviso 'Falta config.local.php: copiá config.local.example.php como config.local.php y completá la contraseña de MySQL.'
    }

    # ---------- 8. Arrancar y probar ----------
    Paso 'Arranque'
    try { AppCmd start apppool "/apppool.name:$Sitio" | Out-Null } catch { }
    try { AppCmd recycle apppool "/apppool.name:$Sitio" | Out-Null } catch { }
    try { AppCmd start site "/site.name:$Sitio" | Out-Null } catch { }
    $estado = (AppCmdXml list site $Sitio).appcmd.SITE.state
    if ($estado -ne 'Started') {
        Falla "El sitio quedó en estado '$estado'. Abrí el Administrador de IIS y fijate el error al iniciarlo."
    }
    Ok 'Sitio iniciado'

    $prueba = "http://localhost:$Puerto/diagnostico.php"
    try {
        $resp = Invoke-WebRequest -Uri $prueba -UseBasicParsing -TimeoutSec 30
        if ($resp.Content -match 'Todo bien') {
            Ok 'PHP responde y la app se conecta a MySQL'
        } elseif ($resp.Content -match 'instalar\.php') {
            Ok 'PHP responde y MySQL conecta. Falta crear la base: abrí instalar.php (abajo).'
        } else {
            Aviso "PHP responde pero el diagnóstico marca algo para corregir: abrí $prueba"
        }
    } catch {
        $cod = $null
        if ($_.Exception.Response) { $cod = [int]$_.Exception.Response.StatusCode }
        if ($cod) {
            Aviso "El sitio respondió con error $cod. Abrí $prueba en el navegador de esta PC para ver el detalle."
        } else {
            Aviso "No respondió en $prueba ($($_.Exception.Message))."
        }
    }
} catch {
    Falla $_.Exception.Message
}

# ---------- Resumen ----------
Write-Host ''
Write-Host 'Listo.' -ForegroundColor Green
Write-Host "   En esta PC:           http://localhost:$Puerto/"
$ips = @()
try {
    $ips = Get-NetIPAddress -AddressFamily IPv4 -ErrorAction SilentlyContinue |
        Where-Object { $_.IPAddress -notlike '127.*' -and $_.IPAddress -notlike '169.254.*' } |
        Select-Object -ExpandProperty IPAddress
} catch { }
foreach ($ip in $ips) {
    Write-Host "   Desde la red local:   http://$($ip):$Puerto/"
}
if (Test-Path (Join-Path $Ruta 'instalar.php')) {
    Write-Host "   Primera vez:          http://localhost:$Puerto/instalar.php  (después borrá o renombrá instalar.php)"
}
Write-Host ''
