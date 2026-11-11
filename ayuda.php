<?php
/*
 * Manual de uso. Cada rol ve sólo las secciones que le corresponden
 * (el filtro es del lado del servidor). El botón "?" de cada pantalla abre el tema que corresponde.
 */
require __DIR__ . '/inc/bootstrap.php';
requiere('ayuda');

$rol = usuario()['rol'];
$ver = [
    'recepcion'   => in_array($rol, ['admin', 'recepcion'], true),
    'profesional' => true,
    'admin'       => $rol === 'admin',
    'tecnico'     => $rol === 'admin',
    'recetas'     => $rol === 'profesional',
];

layout_header('Ayuda');
?>
<div class="ayuda">
  <aside class="ayuda-indice" aria-label="Índice">
    <input type="search" id="ayuda-buscar" placeholder="Buscar en la ayuda" autocomplete="off">
    <p class="apagado" id="ayuda-sin" hidden>No hay temas con esas palabras.</p>
    <details id="ayuda-nav" open>
    <summary>Índice</summary>
    <nav>
      <?php if ($ver['recepcion']): ?>
        <strong>Recepción</strong>
        <a href="#dia">Empezar el día</a>
        <a href="#agenda">La agenda</a>
        <a href="#dar-turno">Dar un turno</a>
        <a href="#sobreturno">Sobreturnos</a>
        <a href="#cualquiera">Turno a cualquier profesional libre</a>
        <a href="#llegada">Llegada y sala de espera</a>
        <a href="#estados">Estados del turno</a>
        <a href="#reprogramar">Reprogramar o cancelar</a>
        <a href="#recordatorios">Recordatorios por WhatsApp</a>
        <a href="#aviso-profesional">Avisar al profesional</a>
        <a href="#pacientes">Pacientes</a>
        <a href="#padron">Padrón de la SSS</a>
        <a href="#coberturas">Obras sociales y coseguros</a>
        <a href="#cobrar">Cobrar</a>
        <a href="#cobro-sin-turno">Cobro sin turno</a>
        <a href="#caja">Caja: movimientos y cierre</a>
        <a href="#celular">Usar desde otra PC o el celular</a>
        <a href="#dudas">Dudas frecuentes</a>
      <?php endif; ?>
      <strong>Profesionales</strong>
      <a href="#mi-agenda">Mi agenda</a>
      <a href="#sala-espera">Sala de espera</a>
      <a href="#atender">Atender un turno</a>
      <a href="#evolucion">Escribir la evolución</a>
      <a href="#historia">Historia clínica y antecedentes</a>
      <a href="#confidencialidad">Quién ve la historia clínica</a>
      <?php if ($ver['recetas']): ?>
        <a href="#firma">Mi firma para recetas</a>
        <a href="#recetas">Hacer una receta</a>
        <a href="#receta-imprimir">Imprimir, anular o repetir</a>
        <a href="#receta-validez">Validez de la receta</a>
      <?php endif; ?>
      <?php if ($ver['recetas']): ?>
        <a href="#ordenes">Órdenes de laboratorio y estudios</a>
        <a href="#orden-imprimir">Imprimir y anular una orden</a>
      <?php endif; ?>
      <a href="#vademecum">Vademécum</a>
      <a href="#cie10">Diagnósticos CIE-10</a>
      <?php if ($ver['admin']): ?>
        <strong>Administrador</strong>
        <a href="#prestaciones">Prestaciones</a>
        <a href="#precios">Actualizar precios</a>
        <a href="#especialidades">Especialidades</a>
        <a href="#profesionales">Profesionales y horarios</a>
        <a href="#consultorios">Consultorios</a>
        <a href="#obras-sociales">Obras sociales</a>
        <a href="#nomencladores">Nomencladores de prácticas</a>
        <a href="#anular">Anular un cobro</a>
        <a href="#caja-admin">Control de cajas</a>
        <a href="#ajustes">Ajustes</a>
        <a href="#usuarios">Usuarios y permisos</a>
      <?php endif; ?>
      <?php if ($ver['tecnico']): ?>
        <strong>Técnico</strong>
        <a href="#tec-estructura">Cómo está armada</a>
        <a href="#tec-iis">Servidor local (IIS)</a>
        <a href="#tec-red">Acceso desde la red</a>
        <a href="#tec-railway">GitHub y Railway</a>
        <a href="#tec-actualizar">Actualizar la app</a>
        <a href="#tec-recetas">Recetas y listado de medicamentos</a>
        <a href="#tec-backup">Copias de seguridad</a>
        <a href="#tec-problemas">Problemas comunes</a>
      <?php endif; ?>
    </nav>
    </details>
    <button class="btn btn-chico no-print mt" type="button" onclick="window.print()">Imprimir el manual</button>
  </aside>

  <div class="ayuda-contenido">

<?php if ($ver['recepcion']): ?>
    <section class="ayuda-seccion">
      <h2>Recepción</h2>

      <article class="tema card" id="dia">
        <h3>Empezar el día</h3>
        <ol>
          <li>Entrá a <b>Caja</b>, contá el efectivo del cajón y escribilo en <b>Efectivo inicial</b>. El sistema sugiere lo que se contó al cerrar la caja anterior. Tocá <b>Abrir caja</b>.</li>
          <li>En <b>Inicio</b> tenés el resumen del día:
            <ul>
              <li><b>Turnos de hoy</b>, en orden de hora, con la cobertura y el estado. Cuando llega un paciente, tocá <b>Llegó</b> en su renglón.</li>
              <li><b>Sala de espera</b>: quiénes llegaron y hace cuánto esperan.</li>
              <li><b>Pendientes de cobro</b>: coseguros y consultas particulares que falta cobrar, con su botón <b>Cobrar</b>.</li>
              <li><b>Recordatorios para mañana</b>: un botón de WhatsApp por cada turno (ver <a href="#recordatorios">Recordatorios</a>).</li>
            </ul>
          </li>
        </ol>
        <p class="apagado">Arriba a la derecha de cada pantalla está el estado de la caja (abierta o cerrada). El número junto a <b>Caja</b> en el menú son los turnos con algo para cobrar que todavía no se cobraron.</p>
      </article>

      <article class="tema card" id="agenda">
        <h3>La agenda</h3>
        <p>Cada columna es un profesional y cada fila, un horario. Se muestran los profesionales que atienden ese día o que tienen algún turno. Arriba de cada columna está su horario y el consultorio donde atiende.</p>
        <ul>
          <li><b>Cambiar de día</b>: con <b>‹</b> y <b>›</b>, con <b>Hoy</b> o eligiendo la fecha en el calendario.</li>
          <li><b>Filtrar por especialidad</b>: quedan sólo las columnas de los profesionales de esa especialidad.</li>
          <li><b>Ver la semana de alguien</b>: tocá su nombre arriba de la columna, o usá <b>Semana</b>. Para volver a un día, tocá el nombre del día.</li>
          <li><b>Zonas rayadas</b>: el profesional no atiende en ese horario. <b>Línea roja</b>: la hora actual.</li>
        </ul>
        <p><b>Cómo se ve cada turno</b>:</p>
        <p class="leyenda" style="margin-top:0">
          <?= badge_turno('reservado') ?> borde punteado, todavía no confirmó
          <?= badge_turno('confirmado') ?> relleno suave
          <?= badge_turno('presente') ?> llegó, está en la sala de espera
          <?= badge_turno('en_atencion') ?> color pleno, está en el consultorio
          <?= badge_turno('atendido') ?> gris
          <?= badge_turno('ausente') ?> rayado rojo
        </p>
        <p>Los cancelados no aparecen. <span class="t-marca m-st">ST</span> es un sobreturno; <span class="t-marca m-pago">$</span> ya está cobrado. Cada turno muestra el paciente, la prestación y la cobertura. Si dos turnos se superponen se dibujan uno al lado del otro. Pasando el mouse por un turno se ve el detalle; tocándolo se abre.</p>
        <p><b>Dar un turno desde la agenda</b>: hacé clic en un espacio libre de la columna del profesional. Se abre el formulario con ese profesional, ese día y esa hora ya cargados.</p>
        <details><summary>No encuentro a un profesional en la agenda</summary>
          <p>Puede que no atienda ese día: abajo de la agenda está el enlace <b>Ver también a los que no atienden este día</b>. También revisá que no haya un filtro de especialidad puesto, y que el profesional esté activo (en <b>Profesionales</b>).</p>
        </details>
      </article>

      <article class="tema card" id="dar-turno">
        <h3>Dar un turno</h3>
        <p>Desde <b>Nuevo turno</b> (arriba en la agenda), con un clic en un espacio libre de la agenda, o desde la ficha del paciente con <b>Dar turno</b>.</p>
        <ol>
          <li><b>Paciente</b>: escribí parte del apellido, nombre, DNI, teléfono o número de afiliado y elegilo de la lista (con las flechas y <kbd>Enter</kbd> o con el mouse).
            Si es nuevo, tocá <b>Es nuevo: cargar sus datos</b> y completá nombre, apellido, DNI, teléfono y número de afiliado. Si escribiste el DNI en el buscador, ya queda cargado. El resto de la ficha se completa después.</li>
          <li><b>Especialidad y prestación</b>: al elegir la especialidad, la lista de prestaciones y de profesionales se reduce a los de esa especialidad. Al elegir la prestación se completan solos la duración y, si la prestación necesita una sala (ej. ergometría), el consultorio.</li>
          <li><b>Profesional</b>: los que no hacen esa prestación aparecen con <i>(no la hace)</i>, y los que no atienden la obra social del paciente, con <i>(no atiende la cobertura)</i>. También podés dejar <b>Cualquier profesional libre</b>.</li>
          <li><b>Consultorio</b>: dejá <b>Según el horario del profesional</b> y el turno toma el consultorio donde atiende a esa hora. Elegí otro sólo si ese día atiende en otro lado.</li>
          <li><b>Fecha, hora y duración</b>: la duración viene de la prestación o, si no fija una, de la duración de turno del profesional. Abajo aparece a qué hora termina y un recuadro azul con su horario y consultorio ese día y lo que ya tiene ocupado.</li>
          <li><b>Cobertura</b>: viene la obra social del paciente. Si se atiende como particular, elegí <b>Particular</b>. El importe se completa solo: el <b>coseguro</b> de la obra social o el <b>precio particular</b> de la prestación (se puede cambiar).</li>
          <li><b>Aclaraciones</b> es para avisos administrativos ("trae estudios", "viene con acompañante"). Tildá <b>Ya está confirmado</b> si corresponde y tocá <b>Agendar turno</b>.</li>
        </ol>
        <p><b>Dos prestaciones seguidas</b> (ej. consulta y electrocardiograma): <b>Agendar y sumar otro</b> guarda el turno y abre otro para el mismo paciente, empezando a la hora en que termina el primero.</p>
        <p><b>Controles al guardar</b>:</p>
        <ul>
          <li>Si el <b>consultorio</b> a esa hora lo está usando <b>otro profesional</b>, <b>no deja guardar</b>: cambiá el consultorio o el horario.</li>
          <li>Si se <b>superpone</b> con otro turno del profesional, queda <b>fuera de su horario</b>, el profesional <b>no hace</b> la prestación, <b>no atiende la obra social</b> o el paciente ya tiene otro turno a esa hora, aparece un aviso amarillo. Leelo y, si está bien así, tildá <b>Entendido, guardar igual</b> y volvé a guardar.</li>
          <li>Si al cargar un paciente nuevo ya hay uno con ese DNI (o con el mismo nombre y apellido, si no pusiste DNI), avisa para no duplicarlo: buscalo.</li>
        </ul>
      </article>

      <article class="tema card" id="sobreturno">
        <h3>Sobreturnos</h3>
        <p>Cuando el profesional acepta atender a alguien en un horario que ya tiene ocupado, tildá <b>Sobreturno</b> al dar el turno: no aparece el aviso de superposición y en la agenda queda marcado con <span class="t-marca m-st">ST</span>, dibujado al lado del otro turno. El aviso al profesional por WhatsApp también dice que es un sobreturno.</p>
      </article>

      <article class="tema card" id="cualquiera">
        <h3>Turno a cualquier profesional libre</h3>
        <p>Sirve cuando al paciente le da lo mismo quién lo atienda (por ejemplo, clínica médica).</p>
        <ol>
          <li>Elegí la <b>especialidad</b> (o directamente la prestación: la especialidad se completa sola).</li>
          <li>En <b>Profesional</b> elegí <b>Cualquier profesional libre</b>, que aparece con el nombre de la especialidad.</li>
          <li>Elegí fecha y hora. El recuadro azul muestra quién está libre a esa hora y a quién se le va a asignar, o por qué no hay nadie ("no atiende en ese horario", "tiene otro turno", "no atiende esa obra social").</li>
          <li>Tocá <b>Agendar turno</b>.</li>
        </ol>
        <p>Se asigna al primero, en el orden de la agenda, que hace esa prestación, atiende la cobertura del paciente, atiende a esa hora y no tiene otro turno. En el turno queda indicado <i>(asignado automáticamente)</i>.</p>
      </article>

      <article class="tema card" id="llegada">
        <h3>Llegada y sala de espera</h3>
        <p>Cuando el paciente llega, tocá <b>Llegó</b> en su renglón de <b>Inicio</b> (o <b>Llegó: a la sala de espera</b> dentro del turno). El sistema anota la hora de llegada y el turno queda <?= badge_turno('presente') ?>.</p>
        <ul>
          <li>El profesional lo ve en su <b>Sala de espera</b> con el tiempo que lleva esperando, y lo llama con <b>Atender</b>.</li>
          <li>Si tiene coseguro o es particular, es buen momento para <b>cobrar</b>: el turno sigue en la sala de espera aunque esté cobrado.</li>
          <li>En <b>Inicio</b>, la tarjeta <b>Sala de espera</b> muestra a todos los que esperan, con quién y hace cuánto (en rojo si pasan los 30 minutos). La pantalla se actualiza sola cada minuto.</li>
        </ul>
      </article>

      <article class="tema card" id="estados">
        <h3>Estados del turno</h3>
        <p>Abrí el turno desde la agenda o desde Inicio. Los botones cambian según el estado:</p>
        <div class="tabla-scroll">
        <table class="tabla">
          <thead><tr><th>Botón</th><th>Cuándo</th><th>Queda</th></tr></thead>
          <tr><td><b>Confirmar</b></td><td>El paciente confirmó que viene</td><td><?= badge_turno('confirmado') ?></td></tr>
          <tr><td><b>Llegó: a la sala de espera</b></td><td>Llegó al centro</td><td><?= badge_turno('presente') ?></td></tr>
          <tr><td><b>Pasa a atención</b></td><td>Entró al consultorio (lo suele marcar el profesional)</td><td><?= badge_turno('en_atencion') ?></td></tr>
          <tr><td><b>Marcar atendido</b></td><td>Terminó la consulta</td><td><?= badge_turno('atendido') ?></td></tr>
          <tr><td><b>No vino</b></td><td>No se presentó ni avisó</td><td><?= badge_turno('ausente') ?> (el horario queda libre)</td></tr>
          <tr><td><b>Cancelar turno</b></td><td>Avisó que no viene</td><td>Desaparece de la agenda; se puede anotar el motivo</td></tr>
        </table>
        </div>
        <p class="mt">Si te equivocaste, <b>Volver a confirmado</b> deshace los estados "en espera", "en atención", "atendido" y "no vino" (y borra la hora de llegada).</p>
        <p>En el mismo turno están los datos del paciente y su cobertura, y los botones <b>Cobrar</b>, <b>Recordatorio por WhatsApp</b>, <b>Avisar a…</b> y <b>Darle otro turno</b>.</p>
      </article>

      <article class="tema card" id="reprogramar">
        <h3>Reprogramar o cancelar</h3>
        <ul>
          <li><b>Reprogramar</b>: en el turno, <b>Reprogramar o editar</b>. Cambiá día, hora, profesional, prestación, cobertura o duración y tocá <b>Guardar cambios</b>. Valen los mismos controles que al darlo. Si el consultorio estaba en "según el horario", se actualiza solo al nuevo día.</li>
          <li><b>Cancelar</b>: <b>Cancelar turno</b>, escribí el motivo si querés y confirmá. El horario queda libre.</li>
        </ul>
        <p class="apagado">Un turno ya cobrado no se puede cancelar ni marcar como "no vino": primero hay que anular el cobro (lo hace un administrador). Tampoco se le cambia el importe.</p>
      </article>

      <article class="tema card" id="recordatorios">
        <h3>Recordatorios por WhatsApp</h3>
        <p>El botón <b>WhatsApp</b> abre WhatsApp (Web o la aplicación) con el mensaje ya escrito para ese paciente: profesional, especialidad, día, hora y dirección, y que traiga DNI y credencial. Revisalo y envialo. El sistema anota que el recordatorio se mandó.</p>
        <ul>
          <li>En <b>Inicio → Recordatorios para mañana</b> están todos los turnos del día siguiente: andá tocando uno por uno. Los ya enviados dicen <i>Enviado</i>.</li>
          <li>En cada turno, <b>Recordatorio por WhatsApp</b> (o <b>Volver a enviar recordatorio</b>).</li>
        </ul>
        <p>Para que funcione, el teléfono del paciente tiene que tener el código de área, sin 0 ni 15: <code>11 5555-1234</code>, <code>351 555-1234</code>. El texto del mensaje se cambia en <b>Ajustes</b>.</p>
      </article>

      <article class="tema card" id="aviso-profesional">
        <h3>Avisar al profesional por WhatsApp</h3>
        <p>Cuando das un turno, lo reprogramás o lo cancelás, arriba de la pantalla aparece un cartel verde con el botón <b>Avisar a … por WhatsApp</b>. Abre WhatsApp con el mensaje ya escrito para el profesional: día y hora, paciente y edad, prestación, cobertura, consultorio y aclaraciones. No incluye nada de la historia clínica.</p>
        <ul>
          <li><b>Reprogramar</b>: el mensaje dice qué cambió y, si cambió el horario, cómo era antes y cómo quedó.</li>
          <li><b>Si el turno pasa a otro profesional</b> aparecen dos botones: uno para avisarle al que lo tenía y otro para el que lo recibe.</li>
          <li><b>Cancelar</b>: avisa que el horario quedó libre, con el motivo si lo escribiste.</li>
          <li>Si cerraste el cartel, en el turno está el botón <b>Avisar a …</b> para mandarle los datos cuando quieras.</li>
        </ul>
        <p>No avisa turnos que ya pasaron. Para que funcione, el profesional tiene que tener el teléfono cargado en <b>Profesionales</b>.</p>
      </article>

      <article class="tema card" id="pacientes">
        <h3>Pacientes</h3>
        <p>En <b>Pacientes</b> se busca por apellido, nombre, DNI, teléfono, email o número de afiliado, y se puede filtrar por cobertura. Los pacientes también se crean solos al dar un turno a alguien nuevo.</p>
        <p>La <b>ficha</b> de cada paciente tiene:</p>
        <ul>
          <li><b>Datos</b>: DNI, fecha de nacimiento y edad, sexo, teléfono (con enlace para escribirle por WhatsApp), email, dirección y <b>notas administrativas</b>.</li>
          <li><b>Cobertura</b>: obra social o prepaga, plan y número de afiliado (o Particular).</li>
          <li><b>Turnos anteriores</b>, <b>Próximos turnos</b> y <b>Cobros</b>; si tiene algo sin cobrar, el aviso con <b>Cobrar ahora</b>.</li>
        </ul>
        <p>Recepción no ve la historia clínica (antecedentes, evoluciones, diagnósticos): es confidencial y la ven sólo los profesionales. Por eso las alergias o enfermedades que cuente el paciente no se anotan en las notas: se las dice al profesional.</p>
        <p class="apagado">Al eliminar un paciente que tiene turnos, cobros o historia clínica, no se borra: queda archivado y deja de aparecer en las búsquedas. No deja cargar dos pacientes con el mismo DNI.</p>
      </article>

      <article class="tema card" id="padron">
        <h3>Padrón de la SSS</h3>
        <p>Para saber qué obra social nacional tiene un paciente, en su ficha tocá <b>Padrón SSS</b>. Se usa la consulta pública de la Superintendencia de Servicios de Salud, que tiene un control antirrobots: por eso la búsqueda se hace en su página y el resultado se anota acá.</p>
        <ol>
          <li>Tocá <b>Copiar CUIL y abrir el padrón</b> (o <b>Copiar DNI…</b>). Se abre la página de la SSS en otra pestaña.</li>
          <li>Pegá con <b>Ctrl+V</b> en el campo CUIL (o Número de documento) y tocá <b>Buscar</b>.</li>
          <li>Con el resultado en pantalla, apretá <b>Ctrl+A</b> y <b>Ctrl+C</b>, volvé a la pestaña del sistema y pegá con <b>Ctrl+V</b> en <b>Resultado de la SSS</b>: se completan solos la obra social (por su código RNAS), el parentesco, la fecha de alta y el CUIL, o se marca <b>No figura</b>. Si no lo reconoce, se completa a mano.</li>
          <li>Tocá <b>Registrar consulta</b>. Con <b>Actualizar la cobertura</b> tildado, si la obra social es otra, la ficha pasa a la nueva y hay que cargar el plan y el nº de afiliado.</li>
        </ol>
        <p>Si el paciente no tiene CUIL cargado, se calcula con el DNI y el sexo: confirmalo con el paciente, porque hay personas con otro CUIL. Cada consulta queda registrada con la fecha y el usuario, y la última se ve en la ficha.</p>
        <p class="apagado">Para que la cobertura se actualice sola, cada obra social del centro tiene que tener su <b>código RNAS</b> en Ajustes → Obras sociales. Las prepagas y las obras sociales provinciales (IOMA, IPS, etc.) no están en este padrón.</p>
      </article>

      <article class="tema card" id="coberturas">
        <h3>Obras sociales y coseguros</h3>
        <p>Cada paciente tiene su cobertura (o Particular). Al darle un turno, el turno toma esa cobertura y el importe a cobrar:</p>
        <ul>
          <li><b>Con obra social</b>: el <b>coseguro</b> de esa obra social (puede ser $ 0: entonces no se cobra nada en el centro).</li>
          <li><b>Particular</b>: el precio particular de la prestación.</li>
        </ul>
        <p>Si un profesional no atiende la obra social del paciente, al dar el turno aparece el aviso; si el paciente acepta atenderse como particular, cambiá la cobertura a <b>Particular</b>.</p>
      </article>

      <article class="tema card" id="cobrar">
        <h3>Cobrar</h3>
        <p>Con la caja abierta, tocá <b>Cobrar</b> en el turno, en Inicio, en Caja o en la ficha. Se abre la pantalla de cobro del paciente:</p>
        <ol>
          <li><b>Turnos</b>: aparecen sus turnos con algo para cobrar, también los futuros (pago adelantado). Cada uno dice si es coseguro o particular. Vienen tildados el que elegiste y los de hoy. El importe de cada uno se puede ajustar.</li>
          <li><b>Otros conceptos</b>: con <b>Agregar concepto</b> se suman certificados, insumos, aplicaciones, con cantidad y precio.</li>
          <li><b>Descuento</b>: en pesos (<code>5000</code>) o en porcentaje (<code>10%</code>).</li>
          <li><b>Pago</b>: elegí el medio. Si paga con dos medios, tocá <b>Dividir en otro medio de pago</b>: el último renglón se completa solo con lo que falta.</li>
          <li>Si paga en efectivo, escribí en <b>Paga con</b> el billete que te da y el sistema calcula el vuelto.</li>
          <li>Tocá <b>Registrar cobro</b>. Se abre el <b>recibo</b> para imprimir.</li>
        </ol>
        <p class="apagado">El recibo dice "Comprobante no válido como factura". Cobrar no cambia el estado del turno: un paciente que pagó al llegar sigue en la sala de espera.</p>
      </article>

      <article class="tema card" id="cobro-sin-turno">
        <h3>Cobro sin turno</h3>
        <p>Para cobrar algo que no es un turno (un certificado, un insumo): <b>Caja → Cobro sin turno</b>. Podés buscar al paciente (si lo elegís, se ven sus turnos pendientes) o dejarlo sin paciente, y cargar los conceptos en <b>Otros conceptos</b>.</p>
      </article>

      <article class="tema card" id="caja">
        <h3>Caja: movimientos y cierre</h3>
        <p>En <b>Caja</b> se ve lo cobrado en el día por medio de pago, la lista de cobros con su recibo y cuánto efectivo debería haber en el cajón.</p>
        <ul>
          <li><b>Registrar ingreso o egreso</b>: plata que entra o sale de la caja sin ser un cobro (compra de descartables, retiro, cambio agregado). Elegí el tipo, monto, medio y concepto.</li>
          <li><b>Cerrar caja</b> al final del día: contá el efectivo, escribilo en <b>Efectivo contado</b> y tocá <b>Cerrar caja</b>. El sistema guarda la diferencia con lo que debería haber.</li>
        </ul>
        <p class="apagado">Si quedan turnos pendientes de cobro, avisa antes de cerrar; se pueden cobrar en la caja del día siguiente. Una vez cerrada, sus cobros no se pueden anular.</p>
      </article>

      <article class="tema card" id="celular">
        <h3>Usar desde otra PC o el celular</h3>
        <p>Desde cualquier equipo conectado a la red del centro se entra con el navegador a la dirección de la PC que hace de servidor, por ejemplo <code>http://192.168.0.10:8030/</code> (el administrador te la pasa). Si la app está publicada en internet, se entra con su dirección web desde cualquier lugar.</p>
        <p>Cada persona entra con su usuario. Al terminar, tocá <b>Salir</b> (abajo en el menú), sobre todo en equipos compartidos: hay datos de salud de los pacientes.</p>
      </article>

      <article class="tema card" id="dudas">
        <h3>Dudas frecuentes</h3>
        <details><summary>No me deja guardar el turno</summary>
          <p>Leé el mensaje rojo: si el consultorio está ocupado por otro profesional a esa hora, cambiá el consultorio o el horario. Si falta el paciente, elegilo de la lista o cargá sus datos con <b>Es nuevo</b>.</p></details>
        <details><summary>Me aparece un aviso amarillo</summary>
          <p>No es un error: avisa que el turno se superpone, queda fuera de horario, el profesional no hace esa prestación o no atiende la obra social. Si es correcto, tildá <b>Entendido, guardar igual</b> y guardá de nuevo. Si es un sobreturno, tildá <b>Sobreturno</b>.</p></details>
        <details><summary>El paciente cambió de obra social</summary>
          <p>Corregila en su ficha (<b>Editar datos</b>). Los turnos que ya tiene dados conservan la cobertura anterior: si hace falta, cambiala en cada uno con <b>Reprogramar o editar</b>.</p></details>
        <details><summary>Me equivoqué en un cobro</summary>
          <p>Pedile a un administrador que lo anule desde el recibo (sólo se puede mientras la caja de ese día siga abierta). Los turnos vuelven a quedar sin cobrar y lo cobrás de nuevo bien.</p></details>
        <details><summary>No vino o avisó que no viene</summary>
          <p>Si no vino ni avisó: <b>No vino</b>. Si avisó: <b>Cancelar turno</b> con el motivo. En los dos casos el horario queda libre. En la búsqueda de pacientes al dar un turno se ve si faltó sin avisar en el último año.</p></details>
        <details><summary>El WhatsApp abre otro número o no lo encuentra</summary>
          <p>Corregí el teléfono en la ficha: código de área sin 0 ni 15, por ejemplo <code>11 5555-1234</code>.</p></details>
        <details><summary>No puedo cobrar: "La caja está cerrada"</summary>
          <p>Abrí la caja en <b>Caja</b> con el efectivo inicial.</p></details>
      </article>
    </section>
<?php endif; ?>

    <section class="ayuda-seccion">
      <h2>Profesionales</h2>

      <article class="tema card" id="mi-agenda">
        <h3>Mi agenda</h3>
        <p>En <b>Mi agenda</b> está tu columna primero y al lado las de quienes comparten alguna especialidad con vos. Con el selector de especialidades ves sólo una. Tocando tu nombre arriba de la columna ves tu <b>semana</b>.</p>
        <p class="apagado">Los turnos los da recepción. Podés ver los turnos de tus colegas de especialidad, pero sólo podés cambiar el estado y escribir la evolución de los tuyos. El signo <span class="t-marca m-alerta">!</span> indica que el paciente tiene antecedentes cargados.</p>
      </article>

      <article class="tema card" id="sala-espera">
        <h3>Sala de espera</h3>
        <p>En <b>Inicio</b> está tu <b>Sala de espera</b>: los pacientes que recepción marcó que llegaron, en orden de llegada, con la edad, la cobertura y hace cuánto esperan. El número junto a <b>Inicio</b> en el menú es cuántos te están esperando. La pantalla se actualiza sola cada minuto.</p>
        <p>Tocá <b>Atender</b> para llamar al paciente: el turno pasa a <?= badge_turno('en_atencion') ?> y se abre, listo para escribir la evolución.</p>
        <p>Abajo están todos tus turnos de hoy y, a la derecha, los de los próximos 7 días.</p>
      </article>

      <article class="tema card" id="atender">
        <h3>Atender un turno</h3>
        <ol>
          <li>Abrí el turno (desde la sala de espera, Inicio o la agenda). Si el paciente tiene <b>antecedentes</b> (alergias, medicación, enfermedades), aparecen arriba en rojo: leelos antes de empezar.</li>
          <li>A la derecha está el resumen de su <b>historia clínica</b>: las últimas evoluciones, de cualquier profesional del centro.</li>
          <li>Escribí la <b>evolución</b> (ver el tema siguiente). Al guardarla, el turno queda <b>atendido</b>.</li>
        </ol>
        <p>Si el paciente vino sin pasar por recepción, desde el turno podés marcar <b>Pasa a atención</b> o <b>Marcar atendido</b> directamente. Si no se presentó, <b>No vino</b>.</p>
      </article>

      <article class="tema card" id="evolucion">
        <h3>Escribir la evolución</h3>
        <p>En cada turno tuyo está el formulario <b>Evolución</b>:</p>
        <ul>
          <li><b>Motivo de consulta</b>.</li>
          <li><b>Evolución</b>: anamnesis, examen físico, estudios, conducta (obligatoria).</li>
          <li><b>Diagnóstico</b>: escribí unas letras o el código y elegí de la lista <a href="#cie10">CIE-10</a>; el código queda guardado y el texto se puede completar a mano. Si ninguno corresponde, escribilo libre.</li>
          <li><b>Indicaciones</b> (medicación, estudios pedidos, próximo control).</li>
        </ul>
        <p>Tocá <b>Guardar evolución</b>. Con <b>Marcar el turno como atendido</b> tildado (viene así), el turno queda atendido en el mismo paso.</p>
        <p><b>Correcciones</b>: durante las primeras <?= HC_HORAS_EDICION ?> horas podés corregirla con <b>Corregir</b>. Después queda cerrada y no se modifica ni se borra, como corresponde a una historia clínica: si hace falta aclarar algo, agregá una evolución nueva.</p>
        <p><b>Evolución sin turno</b>: para anotar el resultado de un estudio o una consulta telefónica, en la historia clínica del paciente tocá <b>Nueva evolución</b>.</p>
      </article>

      <article class="tema card" id="historia">
        <h3>Historia clínica y antecedentes</h3>
        <p>En <b>Pacientes</b> ves a los pacientes que atendieron vos o tu especialidad. En la ficha está el resumen y con <b>Historia clínica</b> (o <b>Ver completa</b>) se abre la historia completa:</p>
        <ul>
          <li>Los datos del paciente: DNI, edad, sexo y cobertura.</li>
          <li>Los <b>antecedentes</b>: alergias, medicación habitual, enfermedades crónicas, cirugías. Para cambiarlos, <b>Actualizar antecedentes</b>: queda registrado quién los actualizó y cuándo, y desde ese momento los ven todos los profesionales en cada turno del paciente.</li>
          <li>Todas las <b>evoluciones</b>, de la más reciente a la más vieja, con fecha, profesional, matrícula y especialidad.</li>
        </ul>
        <p>Con <b>Imprimir</b> sale la historia completa en papel (o en PDF, eligiendo "Guardar como PDF" en la impresora), por ejemplo para una derivación.</p>
      </article>

      <article class="tema card" id="confidencialidad">
        <h3>Quién ve la historia clínica</h3>
        <p>La historia clínica es confidencial. En el sistema la ven y la escriben <b>sólo los usuarios con rol Profesional</b>:</p>
        <ul>
          <li>Cada profesional ve la historia de los pacientes que atendieron él o sus colegas de especialidad. Es una historia única: ve todas las evoluciones del paciente, de cualquier especialidad.</li>
          <li>Recepción y el administrador ven datos, cobertura, turnos y cobros, pero no los antecedentes ni las evoluciones.</li>
          <li>Los avisos por WhatsApp no llevan datos clínicos.</li>
        </ul>
        <p class="apagado">No compartas tu usuario y cerrá la sesión con <b>Salir</b> en equipos que usan otras personas.</p>
      </article>

      <?php if ($ver['recetas']): ?>
      <article class="tema card" id="firma">
        <h3>Mi firma para recetas</h3>
        <p>Antes de la primera receta, en <b>Recetas → Mi firma</b>:</p>
        <ol>
          <li><b>Firma</b>: dibujala con el mouse o con el dedo en el recuadro, o subí una foto o un escaneo (PNG o JPG, mejor con fondo blanco). Es la imagen que sale impresa.</li>
          <li><b>Sello</b>: el texto que va debajo de la firma (nombre, especialidad, matrícula). Viene armado con tus datos; corregilo si hace falta.</li>
          <li><b>PIN</b>: de 4 a 8 números. Se pide cada vez que firmás una receta, así nadie puede firmar con tu firma aunque use tu sesión. No lo anotes ni lo compartas.</li>
        </ol>
        <p>Con <?= FIRMA_INTENTOS ?> PIN equivocados seguidos, la firma se bloquea <?= FIRMA_BLOQUEO_MIN ?> minutos. Para cambiar la firma o el PIN se pide el PIN actual; si lo olvidaste, el administrador puede borrar tu firma desde tu ficha de profesional y la cargás de nuevo.</p>
      </article>

      <article class="tema card" id="recetas">
        <h3>Hacer una receta</h3>
        <p>Desde el turno (<b>Hacer receta</b>, debajo de la evolución), desde la ficha o la historia clínica del paciente (<b>Nueva receta</b>) o en <b>Recetas</b> buscando al paciente.</p>
        <ol>
          <li>En cada renglón, <b>Buscar medicamento</b>: escribí la droga (principio activo), la marca o el laboratorio. Se consulta el <a href="#vademecum">listado oficial</a> y al elegir uno se completan la droga, la presentación y la marca.</li>
          <li>Completá <b>Envases</b> y <b>Posología</b>. En la receta va el <b>nombre genérico</b> (Ley 25.649); la marca sale como sugerida y es opcional: si la borrás, la receta queda sólo por genérico. Si el medicamento no aparece, escribí la droga y la presentación a mano.</li>
          <li><b>Diagnóstico</b>: si venís desde el turno, se trae el de la evolución. Se elige de la lista CIE-10 como en la evolución.</li>
          <li>Escribí tu <b>PIN</b> y tocá <b>Firmar receta</b>. Si todavía no está lista, <b>Guardar borrador</b>: queda en <b>Recetas</b> para terminarla después.</li>
        </ol>
        <p class="apagado">La cantidad de renglones por receta se cambia en Ajustes (lo habitual son 3). A la derecha se ven los antecedentes del paciente (alergias) y sus últimas recetas.</p>
      </article>

      <article class="tema card" id="receta-imprimir">
        <h3>Imprimir, anular o repetir</h3>
        <ul>
          <li><b>Imprimir</b>: sale en hoja A4 con los datos del centro, del paciente y su cobertura, los medicamentos, el diagnóstico, tu firma y sello, la fecha y hora de la firma y el <b>código de verificación</b>. <b>Con duplicado</b> imprime original y duplicado en dos hojas. Con "Guardar como PDF" se puede mandar por mail o WhatsApp.</li>
          <li><b>Anular</b>: una receta firmada no se modifica. Si tiene un error, anulala (con el motivo) y hacé otra con <b>Repetir receta</b>, que copia los medicamentos y el diagnóstico en una receta nueva.</li>
          <li><b>Repetir receta</b> también sirve para la medicación crónica de todos los meses.</li>
          <li><b>Verificar</b>: con el código (o escaneando el QR, si el centro configuró su dirección pública) cualquiera puede comprobar en <code>verificar.php</code> que la receta existe, que no fue modificada y si está anulada. La verificación muestra sólo las iniciales del paciente.</li>
        </ul>
      </article>

      <article class="tema card" id="ordenes">
        <h3>Órdenes de laboratorio y estudios</h3>
        <p>Desde el turno (<b>Orden de laboratorio</b> u <b>Orden de estudios</b>, debajo de la evolución), desde la ficha del paciente o en <b>Órdenes</b>.</p>
        <ol>
          <li>Elegí <b>Laboratorio</b> o <b>Estudios</b>. El <b>nomenclador</b> se propone solo: para laboratorio, el de Ajustes (Bioquímicas); para estudios, el de la obra social del paciente (o el PMO). Se puede cambiar.</li>
          <li>En laboratorio, tocá las <b>determinaciones frecuentes</b> (hemograma, glucemia, colesterol…) para agregarlas o sacarlas. Cualquier otra práctica se busca en <b>Agregar práctica</b> por nombre o código (ej. <code>ecografia tiroidea</code>, <code>340601</code>). Si no está en el nomenclador, <b>+ Práctica sin código</b> y escribila.</li>
          <li>Cada práctica admite una cantidad y una observación (ej. "bilateral").</li>
          <li><b>Diagnóstico presuntivo</b> (CIE-10): si venís del turno se trae el de la evolución. Es obligatorio para firmar, porque las obras sociales lo piden para autorizar.</li>
          <li><b>Indicaciones / preparación</b> (ayuno, concurrir con la orden) y <b>Urgente</b> si corresponde.</li>
          <li>Tu <b>PIN</b> y <b>Firmar orden</b>, o <b>Guardar borrador</b> para terminarla después.</li>
        </ol>
        <p class="apagado">La orden lleva la cobertura con su RNAS y el número de afiliado del momento de la firma. La validez legal es la misma que la de la receta (ver <a href="#receta-validez">Validez de la receta</a>): desde la Resolución 2214/2025 las órdenes de estudios también deben ser electrónicas.</p>
      </article>

      <article class="tema card" id="orden-imprimir">
        <h3>Imprimir y anular una orden</h3>
        <p>La orden firmada sale en A4 con el título (<i>Orden de laboratorio</i> u <i>Orden de estudios</i>), el número, los datos del paciente y su cobertura, cada práctica con su código y el nomenclador, el diagnóstico, las indicaciones, tu firma y sello y el código de verificación (y el QR si hay dirección pública). <b>Con duplicado</b> imprime dos hojas. <b>Anular</b> (con motivo), <b>Repetir orden</b> y <b>Registrar CUIR</b> funcionan igual que en las recetas, y <code>verificar.php</code> verifica recetas y órdenes con el mismo código.</p>
      </article>

      <article class="tema card" id="receta-validez">
        <h3>Validez de la receta</h3>
        <p>Desde 2025 la receta de medicamentos tiene que ser <b>electrónica</b> (Ley 27.553 y Decreto 345/2024) y emitirse en una <b>plataforma inscripta en el ReNaPDiS</b> del Ministerio de Salud, que le asigna una clave única, la <b>CUIR</b>. La receta en papel queda sólo para contingencias.</p>
        <p>La receta de este sistema lleva <b>firma electrónica</b> (imagen + PIN + código de verificación), que acredita quién la hizo y que no cambió, pero <b>no es una firma digital</b> con certificado ni tiene CUIR. Por eso:</p>
        <ul>
          <li>Sirve como registro en la historia clínica, para indicaciones al paciente y como receta en papel en los casos que se admiten.</li>
          <li>Para que la farmacia la dispense como receta electrónica, emitila también en la plataforma de la obra social o en una plataforma registrada y anotá la <b>CUIR</b> con <b>Registrar CUIR</b>: queda vinculada a la receta y sale impresa.</li>
          <li>Los psicotrópicos y estupefacientes (listas de la Ley 19.303 y 17.818) tienen requisitos propios: consultá la normativa vigente de tu jurisdicción.</li>
        </ul>
      </article>
      <?php endif; ?>

      <article class="tema card" id="vademecum">
        <h3>Vademécum</h3>
        <p>En <b>Vademécum</b> se buscan medicamentos por <b>droga o principio activo</b> (ibuprofeno), marca, laboratorio o troquel. La búsqueda consulta el <b>listado oficial de precios de medicamentos</b> del Ministerio de Salud (el mismo de argentina.gob.ar/precios-de-medicamentos) y muestra presentación, laboratorio, condición de venta, precio de venta al público y precio con descuento PAMI.</p>
        <ul>
          <li>Los resultados se agrupan por principio activo y se pueden filtrar por droga o laboratorio.</li>
          <li>Todo lo encontrado queda <b>guardado</b> en el sistema. Si se repite una búsqueda dentro de los <?= MEDICAMENTOS_DIAS_CACHE ?> días, se muestra lo guardado; con <b>Volver a consultar el listado oficial</b> se fuerza la consulta.</li>
          <li>Si no hay internet o el listado no responde, se busca en lo guardado y aparece un aviso.</li>
          <li>Cada búsqueda queda registrada (quién, cuándo, cuántos resultados y si hubo error) en <b>Últimas búsquedas</b>.</li>
        </ul>
        <p class="apagado">Los precios son los informados por los laboratorios al Ministerio y cambian seguido: son orientativos.</p>
      </article>

      <article class="tema card" id="cie10">
        <h3>Diagnósticos CIE-10</h3>
        <p>El sistema trae la <b>CIE-10</b> (Clasificación Internacional de Enfermedades, 10.ª revisión, en español): más de 14.000 códigos. En la evolución y en la receta, el campo <b>Diagnóstico</b> busca mientras escribís, por palabras (<code>faringitis aguda</code>) o por código (<code>J02</code>, <code>j029</code>).</p>
        <p>En <b>Recetas → CIE-10</b> (o <b>Ajustes → CIE-10</b>) se puede consultar la tabla completa por capítulo.</p>
        <p class="apagado">Los textos de la tabla de origen no tenían tildes; se restauraron automáticamente, así que puede quedar alguna palabra sin tilde.</p>
      </article>
    </section>

<?php if ($ver['admin']): ?>
    <section class="ayuda-seccion">
      <h2>Administrador</h2>

      <article class="tema card" id="prestaciones">
        <h3>Prestaciones</h3>
        <p>En <b>Prestaciones</b> están las consultas, estudios y prácticas que se dan con turno, agrupadas por especialidad, con su duración, precio particular y quién las hace. Las que dicen <i>Nadie asignado</i> en rojo no tienen profesional.</p>
        <p><b>Nueva prestación</b> o <b>Editar</b>:</p>
        <ul>
          <li><b>Duración</b> en minutos. Vacía: usa la duración de turno de cada profesional (lo normal para las consultas). Para estudios con duración fija, poné los minutos.</li>
          <li><b>Precio particular</b>: lo que paga quien no tiene obra social. Con obra social se cobra el coseguro.</li>
          <li><b>Se hace en</b>: elegí una sala sólo si la prestación necesita un lugar o equipo en particular (ej. ergometría). Si no, el turno va al consultorio del profesional.</li>
          <li><b>Quién la hace</b>: tildá los profesionales. Al dar un turno se sugieren sólo ellos.</li>
          <li><b>Orden</b> define la posición en las listas. <b>Activa</b> la muestra u oculta al dar turnos.</li>
        </ul>
        <p class="apagado">Una prestación con turnos no se borra: se desactiva. Los turnos ya dados conservan su nombre e importe.</p>
      </article>

      <article class="tema card" id="precios">
        <h3>Actualizar precios</h3>
        <p>En <b>Prestaciones → Actualizar precios particulares</b>: escribí el porcentaje (<code>8</code> sube un 8 %, <code>-5</code> baja un 5 %), elegí el redondeo ($ 100, $ 500 o $ 1.000) y si es para todas o una especialidad. Los turnos ya dados mantienen el importe con el que se dieron. Los coseguros se cambian en <a href="#obras-sociales">Obras sociales</a>.</p>
      </article>

      <article class="tema card" id="especialidades">
        <h3>Especialidades</h3>
        <p>En <b>Prestaciones → Especialidades</b> (o en <b>Ajustes</b>). Agrupan las prestaciones y los profesionales; un profesional puede tener varias.</p>
        <p>Se usan para filtrar la agenda, para dar turnos a "cualquier profesional libre" de la especialidad y para decidir qué agenda y qué pacientes ve cada profesional. Una especialidad en uso no se borra: se desactiva destildando <b>Activa</b>.</p>
      </article>

      <article class="tema card" id="profesionales">
        <h3>Profesionales y horarios</h3>
        <p>En <b>Profesionales</b> está la lista con sus especialidades, horario y consultorio, duración de turno, coberturas y turnos por delante. En cada profesional:</p>
        <ul>
          <li><b>Datos</b>: nombre como se muestra (<code>Dra. Laura Méndez</code>), matrícula, teléfono (para <a href="#aviso-profesional">avisarle por WhatsApp</a>), email, <b>duración del turno</b> (para las prestaciones que no fijan otra), <b>color</b> y <b>orden</b> de su columna en la agenda (también decide a quién se asigna primero un turno de "cualquier profesional libre").</li>
          <li><b>Especialidades</b>: si marcás una y no tenía ninguna prestación de ella, se tildan todas; después destildá las que no hace.</li>
          <li><b>Obras sociales que atiende</b>: <b>Atiende todas</b> o sólo las que marques. Al dar un turno con otra, el sistema avisa.</li>
          <li><b>Horario de atención y consultorio</b>: hasta dos franjas por día, cada una con su consultorio. Día vacío = no atiende. <b>Copiar el horario del lunes de martes a viernes</b> ahorra tiempo. Si al guardar otro profesional usa el mismo consultorio a la misma hora, aparece un aviso.</li>
          <li><b>Prestaciones que realiza</b>.</li>
          <li><b>Activo</b>: destildado, deja de aparecer en la agenda. Quien tiene turnos o evoluciones no se borra: se desactiva.</li>
        </ul>
        <p>Para que un profesional entre al sistema y escriba la historia clínica, creale un usuario con el rol <b>Profesional</b> (ver <a href="#usuarios">Usuarios</a>).</p>
      </article>

      <article class="tema card" id="consultorios">
        <h3>Consultorios</h3>
        <p>En <b>Prestaciones → Consultorios</b>. Cargá los consultorios y las salas o equipos que no pueden usarse en dos turnos a la vez (ej. sala de ergometría). Cada franja del horario de un profesional va en un consultorio, y sus turnos lo toman solos.</p>
        <p>El sistema no deja dar un turno en un consultorio que a esa hora tiene otro profesional. Los turnos superpuestos del mismo profesional (sobreturnos) sí se permiten.</p>
      </article>

      <article class="tema card" id="obras-sociales">
        <h3>Obras sociales</h3>
        <p>En <b>Prestaciones → Obras sociales</b> (o en <b>Ajustes</b>). Cargá las obras sociales y prepagas con las que trabaja el centro, cada una con su código <b>RNAS</b> y su <b>coseguro</b>.</p>
        <p>El <b>RNAS</b> es el número de 6 cifras del Registro Nacional de Agentes del Seguro de la Superintendencia de Servicios de Salud (ej. OSDE <code>400800</code>, PAMI <code>500807</code>). En la columna RNAS escribí la sigla, parte del nombre o el número y elegilo de la lista: abajo aparece la denominación oficial y, si es una obra social nueva, se completa el nombre. Sale en la ficha del paciente y en la receta. Las obras sociales provinciales (IOMA, IPS…) y las prepagas que no son agentes del seguro no tienen RNAS: dejalo vacío.</p>
        <p>El <b>coseguro</b>: lo que paga el paciente en el centro por cada turno ($ 0 si no paga nada). El coseguro se propone solo al dar el turno y se puede cambiar en cada uno.</p>
        <p class="apagado">Una obra social que tienen pacientes o turnos no se borra: se desactiva destildando <b>Activa</b> y deja de aparecer en las listas.</p>
      </article>

      <article class="tema card" id="nomencladores">
        <h3>Nomencladores de prácticas</h3>
        <p>Las listas de prácticas con su código. El sistema trae cargados los de gestion_adm: <b>PMO</b>, <b>Bioquímicas</b>, <b>PAMI</b>, <b>OBSBA</b> y <b>NBU</b> (9.750 códigos), y se pueden agregar otros. Cada código vale sólo dentro de su nomenclador (el 010101 del PMO y el de OBSBA son prácticas distintas) y es texto: <code>001</code> y <code>0001</code> son códigos diferentes.</p>
        <ol>
          <li>En <b>Ajustes → Nomencladores</b> se ve cada uno con sus códigos; se busca por código o descripción y se filtra por capítulo. Los códigos se corrigen en la misma tabla; los que no se usan se desactivan (no se borran).</li>
          <li><b>Importar códigos</b> desde un CSV (Excel → "Guardar como CSV"): <code>código;descripción;valor;capítulo</code>, con valor y capítulo opcionales. Los códigos que ya existen se actualizan.</li>
          <li><b>Valores por vigencia</b>: los valores van aparte del código, en ediciones con fecha de inicio (<i>Los valores rigen desde</i>). Al importar una edición nueva, la anterior termina el día antes y queda guardada, así se sabe cuánto valía una práctica en cada fecha. Una vigencia importada por error se elimina con sus valores.</li>
          <li>En cada <b>obra social</b> (Prestaciones → Obras sociales) se elige con qué nomenclador se le factura. Las que no tienen uno usan el <b>nomenclador por defecto</b> (el PMO).</li>
          <li>En cada <b>prestación</b>, en <b>Códigos de nomenclador</b>, se elige su código en cada nomenclador.</li>
        </ol>
        <p>Al dar un turno queda guardado el código que corresponde a la obra social del paciente, y recepción lo ve en el detalle (<i>Código 420101 · PMO</i>). Las <a href="#ordenes">órdenes</a> de laboratorio y estudios se arman con estos códigos.</p>
      </article>

      <article class="tema card" id="anular">
        <h3>Anular un cobro</h3>
        <p>Abrí el recibo (desde <b>Caja</b>, la ficha o el turno) y tocá <b>Anular cobro</b>, con el motivo. Se descuenta de la caja y los turnos vuelven a quedar sin cobrar. Sólo se pueden anular cobros de la caja que está abierta.</p>
      </article>

      <article class="tema card" id="caja-admin">
        <h3>Control de cajas</h3>
        <p>En <b>Caja → Cajas anteriores</b> está cada día con lo cobrado, el efectivo esperado, el contado y la diferencia (en rojo si no coincide). Con <b>Ver</b> se abre el detalle: cobros por medio de pago, ingresos y egresos, la lista de recibos y las observaciones del cierre.</p>
      </article>

      <article class="tema card" id="ajustes">
        <h3>Ajustes</h3>
        <ul>
          <li><b>Datos del centro</b>: nombre (menú, ingreso, recibos e historia clínica impresa), dirección, teléfono y mensaje al pie del recibo.</li>
          <li><b>Agenda</b>: desde y hasta qué hora se muestra (se amplía sola si alguien atiende fuera de ese rango) y de a cuántos minutos se redondea al hacer clic.</li>
          <li><b>Recordatorio por WhatsApp</b>: el texto del mensaje. Se reemplazan solos <code>{nombre}</code>, <code>{profesional}</code>, <code>{especialidad}</code>, <code>{prestacion}</code>, <code>{dia}</code>, <code>{hora}</code>, <code>{consultorio}</code>, <code>{centro}</code> y <code>{direccion}</code>.</li>
          <li><b>Órdenes</b>: el nomenclador de laboratorio, las determinaciones frecuentes (códigos separados por comas, en el orden de los botones) y la leyenda al pie.</li>
          <li><b>Recetas</b>: cuántos medicamentos por receta, la leyenda al pie y la <b>dirección pública del sistema</b> (ej. <code>https://turnos.micentro.com.ar/centro_medico</code>). Con la dirección, cada receta lleva un QR para verificarla desde el celular; si el sistema sólo se usa en la red del centro, dejala vacía.</li>
          <li><b>Medios de pago</b>: marcá como <b>Es efectivo</b> sólo los que entran al cajón (para el arqueo y el vuelto).</li>
        </ul>
      </article>

      <article class="tema card" id="usuarios">
        <h3>Usuarios y permisos</h3>
        <div class="tabla-scroll">
        <table class="tabla">
          <thead><tr><th>Rol</th><th>Puede</th></tr></thead>
          <tr><td><b>Administrador</b></td><td>Todo lo administrativo: prestaciones y precios, profesionales y horarios, especialidades, consultorios, obras sociales, usuarios, anular cobros, ajustes. Además, lo mismo que recepción. <b>No ve la historia clínica.</b></td></tr>
          <tr><td><b>Recepción</b></td><td>Agenda de todos, dar y reprogramar turnos, sala de espera, pacientes y coberturas, cobrar, abrir y cerrar caja, vademécum. <b>No ve la historia clínica ni las recetas.</b></td></tr>
          <tr><td><b>Profesional</b></td><td>Su agenda y la de su especialidad, su sala de espera, estados de sus turnos, sus pacientes, antecedentes y evoluciones (historia clínica), recetas con su firma, vademécum y CIE-10. No ve precios ni caja.</td></tr>
        </table>
        </div>
        <p class="mt">En <b>Ajustes → Usuarios</b>: nombre, usuario para ingresar (sin espacios), contraseña (mínimo 6 caracteres) y rol. Para el rol Profesional hay que elegir qué profesional es. Si el director médico también administra, conviene que tenga dos usuarios: uno Profesional (para la historia clínica) y uno Administrador. Para cambiar una contraseña, escribí la nueva; vacía no se cambia. Destildando <b>Activo</b> el usuario ya no puede entrar. Siempre tiene que quedar al menos un administrador activo.</p>
      </article>
    </section>
<?php endif; ?>

<?php if ($ver['tecnico']): ?>
    <section class="ayuda-seccion">
      <h2>Técnico</h2>

      <article class="tema card" id="tec-estructura">
        <h3>Cómo está armada</h3>
        <p>PHP (7.4 o superior) con MySQL o MariaDB, sin frameworks. Todo lo que usa el navegador (estilos, scripts, tipografías) está en la carpeta, así que funciona sin internet en la red del centro.</p>
        <ul>
          <li><code>config.php</code>: configuración sin contraseñas. En la PC lee <code>config.local.php</code>; en la nube, las variables de entorno.</li>
          <li><code>inc/</code>: lógica (conexión, permisos, agenda, historia clínica, caja, cobros, actualizaciones de la base). <code>api/</code>: respuestas JSON para la búsqueda de pacientes y la disponibilidad.</li>
          <li><code>sql/database.sql</code>: tablas iniciales. <code>sql/migraciones/</code>: cambios de versiones posteriores. <code>sql/datos_ejemplo.sql</code>: datos de prueba.</li>
          <li><code>iis/</code>: scripts para crear el sitio de IIS y subir a GitHub. <code>inc</code>, <code>sql</code> e <code>iis</code> no se pueden abrir desde el navegador.</li>
        </ul>
      </article>

      <article class="tema card" id="tec-iis">
        <h3>Servidor local (IIS)</h3>
        <ol>
          <li>PHP tiene que estar configurado en IIS (FastCGI) con la extensión <code>pdo_mysql</code> (el mismo que usan las otras apps).</li>
          <li>Doble clic en <code>iis\configurar-iis.bat</code>: crea el sitio <b>centro_medico</b> en el puerto <b>8030</b> apuntando a la carpeta, da los permisos, abre el puerto en el firewall (redes privadas) y prueba que responda. Se puede volver a correr sin problema. Otro puerto: <code>configurar-iis.bat -Puerto 8031</code>.</li>
          <li><code>config.local.php</code> (copia de <code>config.local.example.php</code>) con los datos de MySQL y <code>DB_NAME</code> = <code>centro_medico</code>.</li>
          <li>Primera vez: <code>http://localhost:8030/instalar.php</code>. Después borrá o renombrá <code>instalar.php</code>.</li>
        </ol>
        <p><code>diagnostico.php</code> revisa PHP, la extensión, el puerto, el usuario y la base, y dice qué corregir.</p>
      </article>

      <article class="tema card" id="tec-red">
        <h3>Acceso desde la red</h3>
        <ul>
          <li>En la PC servidor, <code>ipconfig</code> da la IP (ej. <code>192.168.0.10</code>). Desde otro equipo: <code>http://192.168.0.10:8030/</code>.</li>
          <li>La red de Windows tiene que estar como <b>Privada</b>: la regla del firewall que crea el script no abre el puerto en redes públicas.</li>
          <li>Conviene reservar esa IP en el router (DHCP) para que no cambie.</li>
        </ul>
      </article>

      <article class="tema card" id="tec-railway">
        <h3>GitHub y Railway</h3>
        <ol>
          <li><code>iis\subir-github.bat</code> sube los cambios al repositorio privado (la primera vez lo crea). <code>config.local.php</code> nunca se sube.</li>
          <li>En Railway, el servicio de la app está conectado al repositorio y se vuelve a desplegar solo con cada subida, usando el <code>Dockerfile</code>.</li>
          <li>Variables del servicio: <code>MYSQL_URL</code> = <code>${{MySQL.MYSQL_URL}}</code> (referencia al servicio MySQL del mismo proyecto), <code>DB_NAME</code> = <code>centro_medico</code>, <code>INSTALL_KEY</code> = clave para instalar.</li>
          <li>La app queda en <code>https://DOMINIO/centro_medico/</code>. Primera vez: <code>/centro_medico/instalar.php</code> con la <code>INSTALL_KEY</code>; después queda bloqueado.</li>
        </ol>
        <p class="apagado">La base de Railway y la de la PC son independientes: se sincroniza el código, no los datos. Si se publica en internet, tiene datos de salud: usá contraseñas fuertes y HTTPS (Railway lo da).</p>
      </article>

      <article class="tema card" id="tec-actualizar">
        <h3>Actualizar la app</h3>
        <p>Hacé un backup de la base y reemplazá los archivos (sin tocar <code>config.local.php</code>). En Railway, subí los cambios con <code>subir-github.bat</code>.</p>
        <p>Si la versión nueva necesita cambios en la base, trae un archivo en <code>sql/migraciones/</code> (ej. <code>002_algo.sql</code>) y sube <code>DB_VERSION_APP</code> en <code>inc/migraciones.php</code>. Al abrir cualquier página, la app ve que la base está atrasada (<code>db_version</code> en la tabla <code>configuracion</code>) y aplica lo que falta, una sola vez y sin tocar los datos.</p>
      </article>

      <article class="tema card" id="tec-recetas">
        <h3>Recetas y listado de medicamentos</h3>
        <ul>
          <li><b>Listado de medicamentos</b>: el vademécum consulta la API pública que usa argentina.gob.ar/precios-de-medicamentos (<code>https://cnpm.msal.gov.ar/api/vademecum</code>). El servidor necesita salida a internet por HTTPS y la extensión <code>curl</code> (o <code>openssl</code>) de PHP: <code>diagnostico.php</code> lo revisa. Si el Ministerio cambia esa dirección, se puede definir <code>CNPM_API</code> en <code>config.local.php</code>.</li>
          <li>Tablas: <code>medicamentos</code> (copia local de lo encontrado, por clave del listado), <code>medicamento_busquedas</code> (registro de cada búsqueda), <code>cie10</code>, <code>agentes_seguro</code> (RNAS), los nomencladores con la estructura de gestion_adm (<code>prest_nomenclador</code>, <code>prest_nomenclador_capitulo</code>, <code>prest_nomenclador_codigo</code>, <code>prest_nomenclador_vigencia</code>, <code>prest_nomenclador_valor</code>, con los ids de origen: PMO 1, Bioquímicas 2, PAMI 5, OBSBA 6, NBU 7), <code>prestacion_codigo</code>, <code>profesional_firmas</code>, <code>recetas</code>, <code>receta_items</code>, <code>ordenes</code> y <code>orden_items</code>.</li>
          <li><b>Integridad de la receta</b>: al firmar se guarda un hash SHA-256 del contenido y un código de verificación calculado con la clave <code>firma_clave</code> de la tabla <code>configuracion</code> (se genera sola al instalar). No la cambies: las recetas ya firmadas dejarían de verificarse.</li>
          <li><code>verificar.php</code> es la única página que se abre sin ingresar.</li>
        </ul>
      </article>

      <article class="tema card" id="tec-backup">
        <h3>Copias de seguridad</h3>
        <p>La base tiene historias clínicas: el backup diario es imprescindible. Con una tarea programada de Windows:</p>
        <pre>"C:\Program Files\MySQL\MySQL Server 8.0\bin\mysqldump.exe" -u root -p centro_medico &gt; D:\backups\centro_medico_%date:~-4%%date:~3,2%%date:~0,2%.sql</pre>
        <p>Guardá una copia fuera de la PC (disco externo o nube). En Railway: pestaña <b>Backups</b> del servicio MySQL.</p>
      </article>

      <article class="tema card" id="tec-problemas">
        <h3>Problemas comunes</h3>
        <div class="tabla-scroll">
        <table class="tabla">
          <thead><tr><th>Síntoma</th><th>Causa probable</th><th>Solución</th></tr></thead>
          <tr><td>"No se pudo conectar a MySQL"</td><td>Servicio detenido, clave o puerto incorrectos</td><td>Abrir <code>diagnostico.php</code>: dice el paso que falla y qué hacer.</td></tr>
          <tr><td>La página se ve sin formato</td><td>Archivos viejos en caché o código desactualizado</td><td>Recargar con <kbd>Ctrl</kbd>+<kbd>F5</kbd>; en IIS, reciclar el grupo de aplicaciones <b>centro_medico</b>.</td></tr>
          <tr><td>"Usuario o contraseña incorrectos" con la clave correcta</td><td>El navegador autocompleta la clave de otro sistema</td><td>Escribir usuario y clave a mano.</td></tr>
          <tr><td>Un profesional entra y dice "no está vinculado"</td><td>Su usuario no tiene profesional elegido</td><td>En <b>Usuarios</b>, editarlo y elegir qué profesional es.</td></tr>
          <tr><td>Olvido de la clave de admin</td><td>—</td><td>Resetearla con la consulta de abajo.</td></tr>
          <tr><td>Otra PC o el celular no abre la app</td><td>Otra red, firewall o la IP de la PC cambió</td><td>Revisar Wi‑Fi, red Privada, regla del puerto 8030 e <code>ipconfig</code>.</td></tr>
          <tr><td>"La base se está actualizando"</td><td>Se está aplicando una migración</td><td>Esperar unos segundos y recargar. Si sigue, ver el mensaje de error que muestra.</td></tr>
          <tr><td>Railway: el diagnóstico muestra servidor 127.0.0.1</td><td>No llegan las variables de MySQL</td><td>Ver "Variables de entorno" en el diagnóstico; revisar el nombre del servicio en la referencia <code>${{...}}</code>.</td></tr>
          <tr><td>El recibo o la historia clínica salen cortados</td><td>Configuración de impresión</td><td>En el diálogo de impresión: márgenes Predeterminados o Ninguno, sin encabezados ni pies de página.</td></tr>
        </table>
        </div>
        <p class="mt"><b>Resetear la clave de admin</b> a <code>admin123</code> (en HeidiSQL, Workbench o la consola), y después cambiarla en Usuarios:</p>
        <pre>UPDATE usuarios
   SET password_hash = '$2y$10$a38VaQ8bd96UD.v1Dd5jieFyw6zLV0HXzHr639u07ACJpGmc1KRJO', activo = 1
 WHERE usuario = 'admin';</pre>
      </article>
    </section>
<?php endif; ?>

  </div>
</div>

<script>
(function () {
  // Buscador: oculta los temas que no contienen todas las palabras
  var input = document.getElementById('ayuda-buscar');
  var temas = Array.prototype.slice.call(document.querySelectorAll('.ayuda .tema'));
  var secciones = Array.prototype.slice.call(document.querySelectorAll('.ayuda-seccion'));
  var norm = function (s) { return s.toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, ''); };
  temas.forEach(function (t) { t._texto = norm(t.textContent); });
  input.addEventListener('input', function () {
    var palabras = norm(input.value).split(/\s+/).filter(Boolean);
    var visibles = 0;
    temas.forEach(function (t) {
      var ok = palabras.every(function (p) { return t._texto.indexOf(p) >= 0; });
      t.hidden = !ok;
      if (ok) visibles++;
      if (ok && palabras.length) t.querySelectorAll('details').forEach(function (d) { d.open = true; });
    });
    secciones.forEach(function (s) { s.hidden = !s.querySelector('.tema:not([hidden])'); });
    document.getElementById('ayuda-sin').hidden = visibles > 0;
  });
  // En pantallas chicas el índice arranca cerrado
  if (window.matchMedia('(max-width: 1100px)').matches) document.getElementById('ayuda-nav').open = false;
  // Resaltar el tema al llegar desde el botón "?" de otra pantalla
  if (location.hash) {
    var t = document.querySelector(location.hash);
    if (t) t.classList.add('tema-destacado');
  }
  // Al imprimir se abren todas las respuestas
  window.addEventListener('beforeprint', function () { document.querySelectorAll('.ayuda details').forEach(function (d) { d.open = true; }); });
})();
</script>
<?php layout_footer(); ?>
