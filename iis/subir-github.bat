@echo off
rem Sube la app a GitHub (y con eso Railway se vuelve a desplegar solo).
rem La primera vez crea el repositorio privado; las siguientes sube los cambios.
setlocal EnableDelayedExpansion
cd /d "%~dp0.."

where git >nul 2>&1
if errorlevel 1 (
  echo No se encontro git. Instalalo desde https://git-scm.com y volve a correr esto.
  pause & exit /b 1
)

if not exist ".git" (
  echo Creando el repositorio local...
  git init -b main
)

git add -A

rem config.local.php tiene la clave de MySQL: nunca se sube
git ls-files --cached --error-unmatch config.local.php >nul 2>&1
if not errorlevel 1 (
  git rm --cached --quiet config.local.php
  echo Se saco config.local.php de lo que se sube.
)

git diff --cached --quiet
if errorlevel 1 (
  echo.
  echo Archivos a subir:
  git --no-pager diff --cached --name-status
  echo.
  set "MSG="
  set /p "MSG=Descripcion del cambio [Actualizacion]: "
  if "!MSG!"=="" set "MSG=Actualizacion"
  git commit -q -m "!MSG!"
  if errorlevel 1 (
    echo No se pudo hacer el commit. Si dice que falta tu nombre o email, corre:
    echo   git config --global user.name "Tu Nombre"
    echo   git config --global user.email "tu@email.com"
    pause & exit /b 1
  )
) else (
  echo No hay cambios nuevos para guardar.
)

git remote get-url origin >nul 2>&1
if errorlevel 1 (
  where gh >nul 2>&1
  if errorlevel 1 (
    echo.
    echo Crea un repositorio PRIVADO y VACIO en https://github.com/new y pega aca su URL
    set "URL="
    set /p "URL=URL del repositorio (https://github.com/usuario/nombre.git): "
    if "!URL!"=="" ( echo Sin URL no se puede subir. & pause & exit /b 1 )
    git remote add origin "!URL!"
    git push -u origin main
  ) else (
    set "REPO="
    set /p "REPO=Nombre del repositorio nuevo en GitHub [centro_medico]: "
    if "!REPO!"=="" set "REPO=centro_medico"
    gh repo create "!REPO!" --private --source . --remote origin --push
  )
) else (
  git push -u origin main
)

if errorlevel 1 (
  echo.
  echo La subida fallo: revisa el mensaje de arriba.
) else (
  echo.
  echo Listo: subido a GitHub.
  git remote get-url origin
)
echo.
pause
