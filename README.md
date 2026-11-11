# Gestión para centro médico

App web en PHP + MySQL para manejar la **agenda de turnos** por profesional y consultorio, la **sala de espera**, los **pacientes** con su obra social, la **historia clínica** (antecedentes y evoluciones con diagnóstico **CIE-10**), las **recetas** con firma del profesional, el **vademécum** con el listado oficial de precios de medicamentos, los **nomencladores** de prácticas, los **cobros** de coseguros y consultas particulares (con pago dividido en varios medios) y la **caja diaria**.

Misma base que el sistema de estética: corre en **IIS** o **XAMPP** en la PC del centro, y también publicada en internet (Railway o cualquier hosting con PHP y MySQL). Desde la red del centro se entra desde cualquier PC, tablet o celular por el navegador.

---

## Requisitos

- PHP 7.4 o superior (8.x recomendado) con las extensiones `pdo_mysql` y `curl` (o `openssl`; esta última sólo para consultar el listado de medicamentos)
- MySQL 5.7+ o MariaDB 10.3+
- Un servidor web: **IIS** o **XAMPP** (Apache)

## Instalación

### Con IIS (sitio propio en el puerto 8030)

1. Tener PHP configurado en IIS (FastCGI) con `pdo_mysql`, como para estética y el bar.
2. Doble clic en **`iis\configurar-iis.bat`** (pide permiso de administrador). El script:
   - crea el grupo de aplicaciones y el sitio **centro_medico** en el puerto **8030**, apuntando a esta carpeta;
   - verifica que el sitio tome PHP (si no le llega desde la configuración general de IIS, le agrega el manejador);
   - da permiso de lectura a IIS sobre la carpeta y de escritura en la carpeta de sesiones de PHP;
   - abre el puerto 8030 en el Firewall sólo para redes privadas y de dominio;
   - si falta `config.local.php`, ofrece copiar el de estética (o el del bar) cambiando la base a `centro_medico`;
   - arranca el sitio y prueba que responda.

   Se puede volver a correr sin problema. Para otro puerto: `configurar-iis.bat -Puerto 8031`.
3. Entrar a `http://localhost:8030/instalar.php`, elegir el nombre del centro y la contraseña del usuario **admin**.
   Dejar tildado "Cargar datos de ejemplo" para probar con profesionales, prestaciones, pacientes, turnos e historias clínicas armados; para arrancar en limpio, destildarlo.
4. **Borrar o renombrar `instalar.php`** una vez instalado.
5. Ingresar en `http://localhost:8030/` con `admin` y la contraseña elegida. Desde otra PC de la red: `http://IP-DE-ESTA-PC:8030/`.

El `web.config` incluido bloquea el acceso desde el navegador a las carpetas `inc`, `sql` e `iis` y define `index.php` como página de inicio.

A mano, sin el script: en el Administrador de IIS, **Agregar sitio web** → nombre `centro_medico`, ruta física `D:\centro_medico`, enlace http puerto 8030; en su grupo de aplicaciones poner **Sin código administrado**; copiar `config.local.example.php` como `config.local.php` con la contraseña de MySQL (puede ser el mismo MySQL de estética y el bar: la base es otra, `centro_medico`).

Si algo no conecta, `http://localhost:8030/diagnostico.php` revisa PHP, la extensión, el puerto y el usuario de MySQL, y dice qué corregir.

### Con XAMPP

1. Copiar la carpeta `centro_medico` dentro de `C:\xampp\htdocs\`.
2. Iniciar **Apache** y **MySQL** en el panel de XAMPP.
3. Copiar `config.local.example.php` como `config.local.php` con la contraseña de MySQL.
4. Abrir `http://localhost/centro_medico/instalar.php` y seguir como en IIS (la app queda en `http://localhost/centro_medico/`).

### Instalación manual de la base (opcional)

En lugar de `instalar.php` se pueden importar los scripts con HeidiSQL, phpMyAdmin o la consola:

```sql
CREATE DATABASE centro_medico CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE centro_medico;
SOURCE sql/database.sql;
SOURCE sql/migraciones/002_cie10_nomencladores_recetas.sql;
SOURCE sql/migraciones/003_cie10.sql;
SOURCE sql/migraciones/004_rnas.sql;
SOURCE sql/migraciones/005_nomencladores_ordenes.sql;
SOURCE sql/datos_ejemplo.sql;   -- opcional
```

Si se instala a mano y se omiten las migraciones, la app las aplica sola la primera vez que se abre.

El usuario inicial queda `admin` / `admin123`: cambiarle la contraseña en **Ajustes → Usuarios**.
Con los datos de ejemplo también quedan `recepcion` / `recepcion123` y `laura` / `laura123` (profesional: Dra. Laura Méndez, clínica médica). Todos los pacientes, DNI, teléfonos y números de afiliado de ejemplo son inventados.

## Subir a GitHub

Doble clic en **`iis\subir-github.bat`**:

- **La primera vez** crea el repositorio local, guarda todo y crea el repositorio **privado** en GitHub (con GitHub CLI `gh`; si no lo tenés, te pide la URL de un repositorio vacío creado a mano en github.com).
- **Las siguientes veces** muestra qué archivos cambiaron, te pide una descripción y los sube. Railway vuelve a desplegar solo con cada subida.

La contraseña de MySQL vive en `config.local.php`, que está en `.gitignore`: nunca se sube (el script además lo verifica). `config.php` no tiene contraseñas: en la nube lee las variables de entorno.

## Publicar en Railway

El repositorio incluye un `Dockerfile` (PHP 8.3 + Apache). La app queda en `https://TU-DOMINIO.up.railway.app/centro_medico/`.

1. En el proyecto de Railway: **New → GitHub Repo** → elegir el repositorio.
2. En el servicio nuevo → **Variables**:

   | Variable | Valor |
   |---|---|
   | `MYSQL_URL` | `${{MySQL.MYSQL_URL}}` (referencia al servicio MySQL; cambiar `MySQL` por el nombre que tenga) |
   | `DB_NAME` | `centro_medico` (base propia, para no mezclar con otras apps si usan el mismo MySQL) |
   | `INSTALL_KEY` | una clave cualquiera, sólo para instalar |

3. **Settings → Networking → Generate Domain**.
4. Abrir `https://TU-DOMINIO.up.railway.app/centro_medico/instalar.php`, poner la `INSTALL_KEY`, el nombre del centro y la contraseña del admin.

`instalar.php` exige la clave y no instala sobre una base con tablas de otro sistema; una vez instalada queda bloqueado; `diagnostico.php` sólo muestra detalles a un administrador logueado; `inc/` y `sql/` no son accesibles desde el navegador. La base tiene datos de salud: usar contraseñas fuertes.

## Usuarios y permisos

| Rol | Puede |
|---|---|
| **Administrador** | Todo lo administrativo: prestaciones y precios, profesionales y horarios, especialidades, consultorios, obras sociales, usuarios, anular cobros, ajustes. **No ve la historia clínica** |
| **Recepción** | Agenda de todos, dar y reprogramar turnos, sala de espera, pacientes y coberturas, cobrar, abrir y cerrar caja, vademécum. **No ve la historia clínica ni las recetas** |
| **Profesional** | Su agenda y la de su especialidad, su sala de espera, estados de sus turnos, sus pacientes, la **historia clínica** (antecedentes y evoluciones), **recetas** con su firma, vademécum y CIE-10 |

Al crear un usuario con rol **Profesional** se elige qué profesional es. Si el director médico también administra, conviene que tenga dos usuarios (uno Profesional y uno Administrador).

## Historia clínica

- La ven y la escriben sólo los usuarios con rol Profesional (confidencialidad, Ley 26.529). Recepción y el administrador ven datos, cobertura, turnos y cobros.
- Es una **historia única**: el profesional que puede ver al paciente (porque lo atendió él o su especialidad) ve todas sus evoluciones, de cualquier especialidad.
- **Antecedentes** (alergias, medicación, enfermedades): se muestran en rojo en cada turno; queda registrado quién los actualizó y cuándo.
- **Evoluciones**: una por turno (motivo, evolución, diagnóstico, indicaciones), o sueltas para resultados de estudios o consultas telefónicas. Quien la escribió puede corregirla durante 24 horas; después queda cerrada, no se modifica ni se borra.
- Se imprime completa (o a PDF) desde **Historia clínica → Imprimir**.
- Los avisos por WhatsApp no llevan datos clínicos.

## Diagnósticos CIE-10

La tabla `cie10` trae los 14.206 códigos de la CIE-10 en español (categorías y subcategorías, más U07.1/U07.2 de COVID-19). En la evolución y en la receta el diagnóstico se elige buscando por palabras o por código; el código queda guardado (`evoluciones.cie10_codigo`, `recetas.cie10_codigo`) y el texto se puede completar a mano. Fuente: paquete npm `cie10` 0.0.2 (MIT); al texto original le faltaban las tildes y se restauraron automáticamente, así que puede quedar alguna palabra sin tilde.

## Recetas

- Cada profesional carga en **Recetas → Mi firma** la imagen de su firma (dibujada o subida), el texto del sello y un **PIN** de 4 a 8 números que se pide en cada firma (5 intentos fallidos bloquean 10 minutos).
- La receta se hace desde el turno, la ficha o la historia clínica: medicamentos buscados en el listado oficial (droga, presentación, marca sugerida opcional, envases y posología), diagnóstico CIE-10 e indicaciones. Se guarda como borrador o se firma con el PIN.
- Firmada, queda numerada y no se modifica: se guarda un hash SHA-256 del contenido y un **código de verificación** (HMAC con la clave `firma_clave` de `configuracion`). Se imprime en A4 (con duplicado opcional) con firma, sello, código y, si en Ajustes se cargó la **dirección pública**, un QR. `verificar.php` (pública, sin login) dice si la receta es auténtica, si fue modificada o si está anulada, mostrando sólo las iniciales del paciente.
- Se anula con motivo y se repite (copia en una receta nueva).
- **Validez**: desde 2025 la receta de medicamentos debe ser electrónica (Ley 27.553, Decreto 345/2024) y emitirse en una plataforma inscripta en el **ReNaPDiS**, con su **CUIR**. Esta receta tiene **firma electrónica**, no firma digital con certificado, y no tiene CUIR: sirve como registro en la historia clínica y como receta en papel donde se admite. Si se emite también en una plataforma registrada, la CUIR se anota en la receta (**Registrar CUIR**).

## Vademécum

Búsqueda por droga o principio activo, marca, laboratorio o troquel en el **listado oficial de precios de medicamentos** del Ministerio de Salud (la API que usa argentina.gob.ar/precios-de-medicamentos: `https://cnpm.msal.gov.ar/api/vademecum`). Cada resultado se guarda o actualiza en `medicamentos` y cada búsqueda queda registrada en `medicamento_busquedas` (término, origen online/local, resultados, nuevos, error, usuario y fecha). Una búsqueda repetida dentro de 7 días usa lo guardado; sin internet se busca en lo guardado. Si la dirección cambia, se puede definir `CNPM_API` en `config.local.php`.

## Instalar como app (PC, celular, tablet)

El sistema es una app web instalable (PWA): `manifest.php`, `sw.js`, `offline.html` e íconos en `assets/img/app-*.png`.

- En Chrome/Edge (PC y Android) aparece **Instalar app** en el menú lateral y en la pantalla de ingreso: un clic y queda como programa, con su ícono.
- En iPhone/iPad el mismo botón explica cómo hacerlo desde Safari (**Compartir → Agregar a inicio**).
- Necesita **HTTPS** (Railway lo tiene). En la red local por `http://IP:puerto` el navegador no deja instalar; en la misma PC sí funciona con `http://localhost`.
- El service worker no guarda páginas ni datos de pacientes: sólo estilos, fuentes e íconos. Sin conexión muestra un aviso.

## Padrón de la SSS

Desde la ficha del paciente, **Padrón SSS** abre la consulta pública del padrón de beneficiarios de la Superintendencia de Servicios de Salud (`https://publico.sssalud.gob.ar/padron`). Esa página tiene reCAPTCHA, así que la app no la consulta sola: copia el CUIL (o el DNI) al portapapeles, abre la página en otra pestaña y recepción registra lo que figura (obra social por código RNAS, parentesco, nota).

- El CUIL se guarda en `pacientes.cuil`; si falta, se calcula con DNI y sexo (20/27, o 23 cuando el dígito da 10) y se muestra para confirmar.
- Cada consulta queda en `padron_consultas` (fecha, usuario, resultado, RNAS, obra social como figura). La última se ve en la ficha.
- Con **Actualizar la cobertura**, si el RNAS corresponde a otra obra social del centro, la ficha pasa a esa (y se borran plan y nº de afiliado). Para eso cada obra social necesita su código RNAS en Ajustes → Obras sociales.

## Nomencladores de prácticas

Misma estructura que **gestion_adm**: `prest_nomenclador`, `prest_nomenclador_capitulo`, `prest_nomenclador_codigo` (código como texto; único por nomenclador), `prest_nomenclador_vigencia` y `prest_nomenclador_valor` (los valores van por vigencia: cada edición tiene fecha de inicio y la anterior termina el día antes). Trae cargados PMO, Bioquímicas, PAMI, OBSBA y NBU (9.750 códigos de `sql/prest_nomenclador_datos_20260917.sql`, con los ids de origen 1, 2, 5, 6 y 7 y la Ñ y los acentos recuperados). Se importan más códigos y valores desde CSV (`código;descripción;valor;capítulo`). Cada obra social dice con qué nomenclador se le factura (o usa el por defecto, el PMO) y cada prestación tiene su código en cada nomenclador (`prestacion_codigo`). Al dar un turno queda guardado el código que corresponde a la cobertura.

## Órdenes de laboratorio y estudios

Desde el turno, la ficha o la historia clínica. Laboratorio usa el nomenclador de Ajustes (Bioquímicas) con botones de determinaciones frecuentes; estudios, el de la obra social del paciente. Prácticas buscadas por nombre o código, o libres; cantidad y observación; diagnóstico presuntivo CIE-10 (obligatorio para firmar), indicaciones / preparación y urgente. Se firman con el mismo PIN que las recetas (número, hash y código de verificación), se imprimen en A4 con la cobertura, el RNAS y el afiliado, y se anulan, repiten o vinculan a una CUIR igual que las recetas. `verificar.php` verifica recetas y órdenes. Tablas `ordenes` y `orden_items`.

## Especialidades, consultorios y obras sociales

- **Especialidades** (Clínica médica, Cardiología, Pediatría…): agrupan prestaciones y profesionales (un profesional puede tener varias). Filtran la agenda, permiten dar un turno a **cualquier profesional libre** de la especialidad y deciden qué agenda y qué pacientes ve cada profesional.
- **Consultorios**: cada franja del horario de un profesional va en un consultorio, y el turno lo toma solo. No se puede dar un turno en un consultorio que a esa hora usa otro profesional. Una prestación puede requerir una sala (ej. ergometría).
- **Obras sociales**: cada una con su código **RNAS** (Registro Nacional de Agentes del Seguro, elegido de la lista de 331 agentes de la Superintendencia de Servicios de Salud que trae la tabla `agentes_seguro`, descargada el 09/05/2025; fuente: paquete `oss_ar`, MIT) y su **coseguro** (lo que paga el paciente en el centro; $ 0 = nada). El turno toma la cobertura del paciente y propone el coseguro, o el precio particular de la prestación si es particular. Cada profesional puede atender todas o sólo algunas: si no atiende la del paciente, el sistema avisa.

## Cómo se usa en el día

El manual completo está dentro de la app: menú **Ayuda** (cada rol ve su parte, con buscador y opción de imprimir) y el botón **?** arriba a la derecha de cada pantalla, que abre la explicación de esa pantalla.

1. **Abrir caja** con el efectivo inicial.
2. En **Inicio** están los turnos del día (con botón **Llegó**), la sala de espera, lo pendiente de cobro y los **recordatorios para mañana**: un botón por turno abre WhatsApp con el mensaje armado (se edita en Ajustes).
3. **Agenda**: una columna por profesional con su consultorio, filtro por especialidad. Clic en un espacio libre para dar un turno a esa hora. Las zonas rayadas son fuera de horario.
   - Borde punteado: reservado sin confirmar. Relleno: confirmado. Ámbar: en la sala de espera. Color pleno: en atención. Gris: atendido. Rayado rojo: no vino.
   - **ST** sobreturno. **$** ya está cobrado. **!** antecedentes (sólo lo ven los profesionales).
4. **Dar un turno**: buscar el paciente (por apellido, DNI, teléfono o nº de afiliado) o cargarlo nuevo, elegir especialidad y prestación; se completan la duración, el consultorio, la cobertura y el importe (coseguro o particular). Abajo se ve el horario del profesional y lo ocupado de ese día.
   - Si el consultorio lo usa otro profesional a esa hora, no deja guardar.
   - Si se superpone con otro turno, queda fuera de horario, el profesional no hace la prestación o no atiende la obra social, avisa y deja guardar confirmando. **Sobreturno** evita el aviso de superposición.
5. **Llegó** → el paciente pasa a la **sala de espera** del profesional, que lo llama con **Atender**, escribe la **evolución** y el turno queda atendido.
6. **Cobrar**: coseguros y consultas particulares (también futuras, para pago adelantado), otros conceptos (certificados, insumos), descuento en monto o porcentaje (`10%`) y **pago dividido**. Calcula el vuelto. Genera un recibo imprimible (no válido como factura). Cobrar no cambia el estado del turno.
7. **Cerrar caja** al final del día contando el efectivo: registra la diferencia.

## Actualizaciones de la base

Cuando una versión nueva necesita cambios en la base, trae un archivo en `sql/migraciones/` (ej. `002_algo.sql`) y sube `DB_VERSION_APP` en `inc/migraciones.php`. Al abrir cualquier página, la app ve que la base está atrasada (`db_version` en la tabla `configuracion`) y aplica sola lo que falta, una sola vez, sin tocar los datos existentes. Conviene hacer un backup antes de copiar una versión nueva.

## Copias de seguridad

La base tiene historias clínicas: backup diario con una tarea programada, y una copia fuera de la PC.

```bat
"C:\Program Files\MySQL\MySQL Server 8.0\bin\mysqldump.exe" -u root -p centro_medico > D:\backups\centro_medico_%date:~-4%%date:~3,2%%date:~0,2%.sql
```

## Estructura

```
config.php              Configuración (lee config.local.php o variables de entorno; sin contraseñas)
config.local.php        Contraseña de MySQL de tu PC (no se sube a GitHub)
Dockerfile              Imagen para Railway (PHP 8.3 + Apache)
web.config / .htaccess  IIS / Apache
iis/                    configurar-iis.bat (sitio de IIS en el puerto 8030) y subir-github.bat
instalar.php            Instalador (borrar después de usar)
diagnostico.php         Revisa la conexión a MySQL y dice qué corregir
index.php               Inicio: turnos del día, sala de espera, pendientes de cobro, recordatorios
agenda.php              Agenda por día (todos los profesionales) o semana (uno)
turno.php               Dar turno, ver, reprogramar, estados, evolución, WhatsApp
pacientes.php           Lista y búsqueda de pacientes
paciente.php            Ficha: datos, cobertura, turnos, cobros (y resumen de HC para profesionales)
paciente_form.php       Alta y edición de datos y cobertura
historia.php            Historia clínica completa e imprimible, antecedentes (sólo profesionales)
evolucion.php           Evolución nueva sin turno o corrección dentro de las 24 horas
cobro.php               Cobro de turnos y otros conceptos, pago dividido
recibo.php              Recibo imprimible y anulación
caja.php                Apertura, ingresos/egresos, cierre e historial
prestaciones.php        Prestaciones por especialidad, coseguros y actualización masiva de precios
prestacion_form.php     Alta/edición de prestación
profesionales.php       Profesionales, horarios y consultorios
profesional_form.php    Datos, matrícula, horario y consultorio por franja, especialidades, prestaciones, obras sociales
ajustes.php             Datos del centro, agenda, mensaje de recordatorio, recetas
recetas.php             Recetas del profesional y de sus pacientes, búsqueda, verificación
ordenes.php             Órdenes de laboratorio y estudios, búsqueda
orden.php               Nueva orden / borrador / firmada (imprimible), anular, repetir, CUIR
receta.php              Nueva receta / borrador / firmada (imprimible), anular, repetir, CUIR
firma.php               Firma, sello y PIN del profesional
verificar.php           Verificación pública de una receta por código o QR (sin login)
medicamentos.php        Vademécum: listado oficial de precios de medicamentos y registro de búsquedas
cie10.php               Consulta de la tabla CIE-10 por capítulo
nomencladores.php       Nomencladores de prácticas, importación CSV, nomenclador por defecto
ayuda.php               Manual de uso por rol (recepción, profesionales, administrador, técnico)
usuarios.php            Usuarios y roles
abm.php                 Especialidades, consultorios, obras sociales, medios de pago
api/                    Búsqueda de pacientes, día del profesional, profesionales libres, CIE-10, prácticas y medicamentos (JSON)
inc/                    Lógica: conexión, permisos, agenda, historia clínica, CIE-10, nomencladores, medicamentos, recetas, caja, cobros
sql/                    Scripts de base de datos; sql/migraciones/ trae las actualizaciones
assets/                 Estilos, scripts y tipografías (todo local, funciona sin internet)
```

## Próximas fases

1. **Liquidación a obras sociales**: listado mensual de prestaciones atendidas por obra social (paciente, DNI, nº de afiliado, prestación, profesional) para facturarles, con exportación a Excel.
2. **Honorarios de profesionales**: porcentaje o monto fijo por prestación y liquidación mensual.
3. **Adjuntar resultados** de laboratorio y estudios (PDF/imagen) a la orden y a la historia clínica.
4. **Receta electrónica con CUIR**: integración con una plataforma inscripta en el ReNaPDiS o con la de cada obra social, cuando ofrezcan API.
5. **Reserva online**: página pública donde el paciente elige especialidad, profesional y horario libre.
6. **Facturación electrónica ARCA** (WSFE) desde el cobro.
