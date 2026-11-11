-- =============================================================
--  Gestión para centro médico
--  Esquema MySQL 5.7+ / MariaDB 10.3+
-- =============================================================
SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ---------- Configuración general (clave/valor) ----------
CREATE TABLE IF NOT EXISTS configuracion (
  clave VARCHAR(50) PRIMARY KEY,
  valor TEXT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------- Especialidades (Clínica médica, Cardiología, Pediatría…) ----------
CREATE TABLE IF NOT EXISTS especialidades (
  id     INT AUTO_INCREMENT PRIMARY KEY,
  nombre VARCHAR(80) NOT NULL,
  orden  INT NOT NULL DEFAULT 0,
  activo TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------- Consultorios, salas o equipos que no pueden usar dos profesionales a la vez ----------
CREATE TABLE IF NOT EXISTS consultorios (
  id     INT AUTO_INCREMENT PRIMARY KEY,
  nombre VARCHAR(60) NOT NULL,
  orden  INT NOT NULL DEFAULT 0,
  activo TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------- Obras sociales y prepagas ----------
CREATE TABLE IF NOT EXISTS obras_sociales (
  id       INT AUTO_INCREMENT PRIMARY KEY,
  nombre   VARCHAR(80) NOT NULL,
  coseguro DECIMAL(12,2) NOT NULL DEFAULT 0 COMMENT 'Lo que paga el paciente en el centro por cada turno (0 = nada)',
  orden    INT NOT NULL DEFAULT 0,
  activo   TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------- Profesionales ----------
CREATE TABLE IF NOT EXISTS profesionales (
  id             INT AUTO_INCREMENT PRIMARY KEY,
  nombre         VARCHAR(100) NOT NULL COMMENT 'Como se muestra: Dra. Laura Méndez',
  matricula      VARCHAR(40)  NULL COMMENT 'MN 12345 / MP 6789',
  telefono       VARCHAR(40)  NULL,
  email          VARCHAR(120) NULL,
  color          VARCHAR(7)   NOT NULL DEFAULT '#1f6fb2' COMMENT 'Color de sus turnos en la agenda',
  duracion_turno INT NOT NULL DEFAULT 20 COMMENT 'Minutos por turno cuando la prestación no fija otra duración',
  orden          INT NOT NULL DEFAULT 0,
  activo         TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Un profesional puede tener varias especialidades
CREATE TABLE IF NOT EXISTS profesional_especialidades (
  profesional_id  INT NOT NULL,
  especialidad_id INT NOT NULL,
  PRIMARY KEY (profesional_id, especialidad_id),
  KEY idx_esp (especialidad_id),
  CONSTRAINT fk_pe_prof FOREIGN KEY (profesional_id) REFERENCES profesionales(id) ON DELETE CASCADE,
  CONSTRAINT fk_pe_esp FOREIGN KEY (especialidad_id) REFERENCES especialidades(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Horario semanal. dia: 1 = lunes ... 7 = domingo. Hasta dos franjas por día, cada una en su consultorio.
CREATE TABLE IF NOT EXISTS profesional_horarios (
  id             INT AUTO_INCREMENT PRIMARY KEY,
  profesional_id INT NOT NULL,
  dia            TINYINT NOT NULL,
  desde          TIME NOT NULL,
  hasta          TIME NOT NULL,
  consultorio_id INT NULL COMMENT 'Dónde atiende en esa franja',
  KEY idx_prof_dia (profesional_id, dia),
  KEY idx_cons_dia (consultorio_id, dia),
  CONSTRAINT fk_ph_prof FOREIGN KEY (profesional_id) REFERENCES profesionales(id) ON DELETE CASCADE,
  CONSTRAINT fk_ph_cons FOREIGN KEY (consultorio_id) REFERENCES consultorios(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Obras sociales que atiende cada profesional (sin filas = atiende todas)
CREATE TABLE IF NOT EXISTS profesional_obras_sociales (
  profesional_id INT NOT NULL,
  obra_social_id INT NOT NULL,
  PRIMARY KEY (profesional_id, obra_social_id),
  CONSTRAINT fk_pos_prof FOREIGN KEY (profesional_id) REFERENCES profesionales(id) ON DELETE CASCADE,
  CONSTRAINT fk_pos_os FOREIGN KEY (obra_social_id) REFERENCES obras_sociales(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------- Usuarios ----------
-- rol: admin (todo menos la historia clínica) / recepcion (agenda, pacientes, cobros, caja)
--      profesional (su agenda, sus pacientes y la historia clínica)
CREATE TABLE IF NOT EXISTS usuarios (
  id             INT AUTO_INCREMENT PRIMARY KEY,
  nombre         VARCHAR(100) NOT NULL,
  usuario        VARCHAR(50)  NOT NULL,
  password_hash  VARCHAR(255) NOT NULL,
  rol            ENUM('admin','recepcion','profesional') NOT NULL DEFAULT 'recepcion',
  profesional_id INT NULL COMMENT 'Para el rol profesional: qué profesional es',
  activo         TINYINT(1) NOT NULL DEFAULT 1,
  creado         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uk_usuario (usuario),
  CONSTRAINT fk_u_prof FOREIGN KEY (profesional_id) REFERENCES profesionales(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------- Prestaciones (consultas, estudios, prácticas) ----------
CREATE TABLE IF NOT EXISTS prestaciones (
  id              INT AUTO_INCREMENT PRIMARY KEY,
  especialidad_id INT NULL,
  nombre          VARCHAR(100) NOT NULL,
  duracion        INT NULL COMMENT 'Minutos. NULL = la duración de turno de cada profesional',
  precio          DECIMAL(12,2) NOT NULL DEFAULT 0 COMMENT 'Precio particular',
  consultorio_id  INT NULL COMMENT 'Sala o equipo que requiere (si no, el consultorio del horario del profesional)',
  online          TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'Para la futura reserva online',
  orden           INT NOT NULL DEFAULT 0,
  activo          TINYINT(1) NOT NULL DEFAULT 1,
  KEY idx_esp (especialidad_id),
  CONSTRAINT fk_pr_esp FOREIGN KEY (especialidad_id) REFERENCES especialidades(id) ON DELETE SET NULL,
  CONSTRAINT fk_pr_cons FOREIGN KEY (consultorio_id) REFERENCES consultorios(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Qué profesionales hacen cada prestación
CREATE TABLE IF NOT EXISTS prestacion_profesional (
  prestacion_id  INT NOT NULL,
  profesional_id INT NOT NULL,
  PRIMARY KEY (prestacion_id, profesional_id),
  CONSTRAINT fk_pp_pres FOREIGN KEY (prestacion_id) REFERENCES prestaciones(id) ON DELETE CASCADE,
  CONSTRAINT fk_pp_prof FOREIGN KEY (profesional_id) REFERENCES profesionales(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------- Pacientes ----------
CREATE TABLE IF NOT EXISTS pacientes (
  id                 INT AUTO_INCREMENT PRIMARY KEY,
  nombre             VARCHAR(80)  NOT NULL,
  apellido           VARCHAR(80)  NOT NULL DEFAULT '',
  dni                VARCHAR(15)  NULL,
  fecha_nac          DATE NULL,
  sexo               ENUM('F','M','X') NULL,
  telefono           VARCHAR(40)  NULL,
  email              VARCHAR(120) NULL,
  direccion          VARCHAR(200) NULL,
  obra_social_id     INT NULL COMMENT 'NULL = particular',
  plan               VARCHAR(60)  NULL,
  nro_afiliado       VARCHAR(40)  NULL,
  antecedentes       TEXT NULL COMMENT 'Historia clínica: alergias, medicación habitual, enfermedades, cirugías',
  antecedentes_prof  INT NULL COMMENT 'Profesional que actualizó los antecedentes por última vez',
  antecedentes_fecha DATETIME NULL,
  notas              TEXT NULL COMMENT 'Administrativas: cómo prefiere que lo contacten, acompañante, etc.',
  activo             TINYINT(1) NOT NULL DEFAULT 1,
  creado             DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_nombre (apellido, nombre),
  KEY idx_tel (telefono),
  KEY idx_dni (dni),
  KEY idx_afiliado (nro_afiliado),
  CONSTRAINT fk_pa_os FOREIGN KEY (obra_social_id) REFERENCES obras_sociales(id) ON DELETE SET NULL,
  CONSTRAINT fk_pa_prof FOREIGN KEY (antecedentes_prof) REFERENCES profesionales(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------- Caja ----------
CREATE TABLE IF NOT EXISTS medios_pago (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  nombre      VARCHAR(50) NOT NULL,
  es_efectivo TINYINT(1) NOT NULL DEFAULT 0,
  orden       INT NOT NULL DEFAULT 0,
  activo      TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS cajas (
  id                  INT AUTO_INCREMENT PRIMARY KEY,
  fecha_apertura      DATETIME NOT NULL,
  usuario_apertura_id INT NOT NULL,
  monto_inicial       DECIMAL(12,2) NOT NULL DEFAULT 0,
  fecha_cierre        DATETIME NULL,
  usuario_cierre_id   INT NULL,
  efectivo_esperado   DECIMAL(12,2) NULL,
  efectivo_contado    DECIMAL(12,2) NULL,
  observaciones       TEXT NULL,
  estado              ENUM('abierta','cerrada') NOT NULL DEFAULT 'abierta',
  KEY idx_estado (estado)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Ingresos/egresos manuales (compra de insumos, retiro, cambio, etc.)
CREATE TABLE IF NOT EXISTS caja_movimientos (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  caja_id       INT NOT NULL,
  fecha         DATETIME NOT NULL,
  tipo          ENUM('ingreso','egreso') NOT NULL,
  medio_pago_id INT NOT NULL,
  monto         DECIMAL(12,2) NOT NULL,
  concepto      VARCHAR(200) NOT NULL,
  usuario_id    INT NOT NULL,
  KEY idx_caja (caja_id),
  CONSTRAINT fk_cm_caja FOREIGN KEY (caja_id) REFERENCES cajas(id),
  CONSTRAINT fk_cm_mp FOREIGN KEY (medio_pago_id) REFERENCES medios_pago(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------- Cobros ----------
CREATE TABLE IF NOT EXISTS cobros (
  id               INT AUTO_INCREMENT PRIMARY KEY,
  caja_id          INT NOT NULL,
  numero           INT NOT NULL COMMENT 'Número de recibo correlativo',
  fecha            DATETIME NOT NULL,
  paciente_id      INT NULL,
  subtotal         DECIMAL(12,2) NOT NULL DEFAULT 0,
  descuento        DECIMAL(12,2) NOT NULL DEFAULT 0,
  total            DECIMAL(12,2) NOT NULL DEFAULT 0,
  vuelto           DECIMAL(12,2) NOT NULL DEFAULT 0,
  observaciones    VARCHAR(255) NULL,
  estado           ENUM('valido','anulado') NOT NULL DEFAULT 'valido',
  motivo_anulacion VARCHAR(255) NULL,
  usuario_id       INT NOT NULL,
  KEY idx_caja (caja_id),
  KEY idx_fecha (fecha),
  KEY idx_paciente (paciente_id),
  UNIQUE KEY uk_numero (numero),
  CONSTRAINT fk_c_caja FOREIGN KEY (caja_id) REFERENCES cajas(id),
  CONSTRAINT fk_c_pac FOREIGN KEY (paciente_id) REFERENCES pacientes(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Qué se cobró: turnos (consulta particular o coseguro) u otros conceptos (certificado, insumos, etc.)
CREATE TABLE IF NOT EXISTS cobro_items (
  id              INT AUTO_INCREMENT PRIMARY KEY,
  cobro_id        INT NOT NULL,
  turno_id        INT NULL,
  descripcion     VARCHAR(150) NOT NULL,
  cantidad        INT NOT NULL DEFAULT 1,
  precio_unitario DECIMAL(12,2) NOT NULL,
  subtotal        DECIMAL(12,2) NOT NULL,
  KEY idx_cobro (cobro_id),
  KEY idx_turno (turno_id),
  CONSTRAINT fk_ci_cobro FOREIGN KEY (cobro_id) REFERENCES cobros(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Cómo se pagó: puede dividirse en varios medios (ej. parte efectivo, parte transferencia)
CREATE TABLE IF NOT EXISTS cobro_pagos (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  cobro_id      INT NOT NULL,
  medio_pago_id INT NOT NULL,
  monto         DECIMAL(12,2) NOT NULL,
  KEY idx_cobro (cobro_id),
  CONSTRAINT fk_cp_cobro FOREIGN KEY (cobro_id) REFERENCES cobros(id) ON DELETE CASCADE,
  CONSTRAINT fk_cp_mp FOREIGN KEY (medio_pago_id) REFERENCES medios_pago(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------- Turnos ----------
-- estado presente = llegó y está en la sala de espera
CREATE TABLE IF NOT EXISTS turnos (
  id                INT AUTO_INCREMENT PRIMARY KEY,
  paciente_id       INT NOT NULL,
  profesional_id    INT NOT NULL,
  especialidad_id   INT NULL,
  prestacion_id     INT NULL,
  prestacion_nombre VARCHAR(100) NOT NULL COMMENT 'Copia del nombre al dar el turno',
  consultorio_id    INT NULL,
  obra_social_id    INT NULL COMMENT 'Cobertura con la que se atiende (NULL = particular)',
  inicio            DATETIME NOT NULL,
  fin               DATETIME NOT NULL,
  precio            DECIMAL(12,2) NOT NULL DEFAULT 0 COMMENT 'Lo que paga el paciente: particular o coseguro',
  estado            ENUM('reservado','confirmado','presente','en_atencion','atendido','ausente','cancelado') NOT NULL DEFAULT 'reservado',
  sobreturno        TINYINT(1) NOT NULL DEFAULT 0,
  asignado_auto     TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Dado a cualquier profesional libre de la especialidad',
  origen            ENUM('interno','online') NOT NULL DEFAULT 'interno',
  notas             VARCHAR(255) NULL COMMENT 'Aclaraciones administrativas de la reserva',
  llegada           DATETIME NULL COMMENT 'Cuándo llegó a la sala de espera',
  cobro_id          INT NULL,
  motivo_cancelado  VARCHAR(255) NULL,
  recordado         DATETIME NULL COMMENT 'Cuándo se envió el recordatorio',
  usuario_id        INT NULL,
  creado            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  actualizado       DATETIME NULL,
  KEY idx_prof_inicio (profesional_id, inicio),
  KEY idx_cons_inicio (consultorio_id, inicio),
  KEY idx_inicio (inicio),
  KEY idx_paciente (paciente_id, inicio),
  KEY idx_os (obra_social_id, inicio),
  KEY idx_cobro (cobro_id),
  CONSTRAINT fk_t_pac FOREIGN KEY (paciente_id) REFERENCES pacientes(id),
  CONSTRAINT fk_t_prof FOREIGN KEY (profesional_id) REFERENCES profesionales(id),
  CONSTRAINT fk_t_esp FOREIGN KEY (especialidad_id) REFERENCES especialidades(id) ON DELETE SET NULL,
  CONSTRAINT fk_t_pres FOREIGN KEY (prestacion_id) REFERENCES prestaciones(id) ON DELETE SET NULL,
  CONSTRAINT fk_t_cons FOREIGN KEY (consultorio_id) REFERENCES consultorios(id) ON DELETE SET NULL,
  CONSTRAINT fk_t_os FOREIGN KEY (obra_social_id) REFERENCES obras_sociales(id) ON DELETE SET NULL,
  CONSTRAINT fk_t_cobro FOREIGN KEY (cobro_id) REFERENCES cobros(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------- Historia clínica: evoluciones ----------
-- Una por turno atendido (o sueltas, sin turno). Sólo la ven y la escriben los profesionales.
-- Quien la escribió puede corregirla durante las primeras 24 horas; después queda cerrada.
CREATE TABLE IF NOT EXISTS evoluciones (
  id             INT AUTO_INCREMENT PRIMARY KEY,
  paciente_id    INT NOT NULL,
  turno_id       INT NULL,
  profesional_id INT NOT NULL,
  usuario_id     INT NOT NULL,
  fecha          DATETIME NOT NULL COMMENT 'Fecha de la atención',
  motivo         VARCHAR(255) NULL COMMENT 'Motivo de consulta',
  texto          TEXT NOT NULL COMMENT 'Evolución: examen, estudios, conducta',
  diagnostico    VARCHAR(255) NULL,
  indicaciones   TEXT NULL,
  creado         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  actualizado    DATETIME NULL,
  KEY idx_paciente (paciente_id, fecha),
  UNIQUE KEY uk_turno (turno_id),
  CONSTRAINT fk_e_pac FOREIGN KEY (paciente_id) REFERENCES pacientes(id),
  CONSTRAINT fk_e_turno FOREIGN KEY (turno_id) REFERENCES turnos(id) ON DELETE SET NULL,
  CONSTRAINT fk_e_prof FOREIGN KEY (profesional_id) REFERENCES profesionales(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;

-- =============================================================
--  Datos base
-- =============================================================
INSERT INTO configuracion (clave, valor) VALUES
 ('db_version', '1'),
 ('nombre_centro', 'Mi Centro Médico'),
 ('direccion', ''),
 ('telefono', ''),
 ('agenda_desde', '08:00'),
 ('agenda_hasta', '20:00'),
 ('agenda_intervalo', '10'),
 ('pie_recibo', 'Gracias por su visita.'),
 ('msj_recordatorio', 'Hola {nombre}! Te recordamos tu turno con {profesional} ({especialidad}) el {dia} a las {hora} hs en {centro}, {direccion}. Traé tu DNI y la credencial de tu obra social. Si no podés venir, avisanos así liberamos el turno.');

INSERT INTO medios_pago (nombre, es_efectivo, orden) VALUES
 ('Efectivo', 1, 1),
 ('Transferencia', 0, 2),
 ('Mercado Pago', 0, 3),
 ('Débito', 0, 4),
 ('Crédito', 0, 5);

-- Usuario inicial: admin / admin123  (el instalador le pone la contraseña elegida)
INSERT INTO usuarios (nombre, usuario, password_hash, rol) VALUES
 ('Administrador', 'admin', '$2y$10$a38VaQ8bd96UD.v1Dd5jieFyw6zLV0HXzHr639u07ACJpGmc1KRJO', 'admin');
