@echo off
rem Crea el sitio de IIS "centro_medico" en el puerto 8030. Ver configurar-iis.ps1.
rem Otro puerto:  configurar-iis.bat -Puerto 8031

fltmc >nul 2>&1
if errorlevel 1 (
  echo Pidiendo permiso de administrador...
  if "%~1"=="" (
    powershell -NoProfile -Command "Start-Process -FilePath '%~f0' -Verb RunAs"
  ) else (
    powershell -NoProfile -Command "Start-Process -FilePath '%~f0' -ArgumentList '%*' -Verb RunAs"
  )
  exit /b
)

powershell -NoProfile -ExecutionPolicy Bypass -File "%~dp0configurar-iis.ps1" %*
echo.
pause
