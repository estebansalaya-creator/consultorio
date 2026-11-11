-- =============================================================
--  Versión 2: CIE-10, nomencladores de prácticas, firma de los profesionales,
--  recetas y vademécum (listado oficial de precios de medicamentos).
--  Se aplica sola al abrir la app (ver inc/migraciones.php).
-- =============================================================

-- ---------- CIE-10 (los códigos se cargan en la versión 3) ----------
CREATE TABLE IF NOT EXISTS cie10 (
  codigo      VARCHAR(7)   NOT NULL PRIMARY KEY COMMENT 'A00 (categoría) o A00.0 (subcategoría)',
  descripcion VARCHAR(255) NOT NULL,
  categoria   CHAR(3)      NOT NULL,
  nivel       TINYINT      NOT NULL DEFAULT 4 COMMENT '3 = categoría, 4 = subcategoría',
  KEY idx_categoria (categoria),
  KEY idx_descripcion (descripcion(80))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Diagnóstico codificado de cada evolución
ALTER TABLE evoluciones ADD COLUMN cie10_codigo VARCHAR(7) NULL AFTER diagnostico;
ALTER TABLE evoluciones ADD KEY idx_cie10 (cie10_codigo);

-- ---------- Nomencladores de prácticas ----------
-- Puede haber varios: el Nomenclador Nacional, el de cada obra social, uno propio del centro
CREATE TABLE IF NOT EXISTS nomencladores (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  nombre      VARCHAR(100) NOT NULL,
  descripcion VARCHAR(255) NULL COMMENT 'Versión, resolución o vigencia',
  orden       INT NOT NULL DEFAULT 0,
  activo      TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS nomenclador_practicas (
  id             INT AUTO_INCREMENT PRIMARY KEY,
  nomenclador_id INT NOT NULL,
  codigo         VARCHAR(20)  NOT NULL,
  descripcion    VARCHAR(255) NOT NULL,
  capitulo       VARCHAR(120) NULL,
  valor          DECIMAL(12,2) NULL COMMENT 'Valor de referencia del nomenclador (para la futura liquidación)',
  activo         TINYINT(1) NOT NULL DEFAULT 1,
  UNIQUE KEY uk_codigo (nomenclador_id, codigo),
  KEY idx_desc (descripcion(60)),
  CONSTRAINT fk_np_nom FOREIGN KEY (nomenclador_id) REFERENCES nomencladores(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Con qué nomenclador se le factura a cada obra social (NULL = el nomenclador por defecto de Ajustes)
ALTER TABLE obras_sociales ADD COLUMN nomenclador_id INT NULL AFTER coseguro;
ALTER TABLE obras_sociales ADD CONSTRAINT fk_os_nom FOREIGN KEY (nomenclador_id) REFERENCES nomencladores(id) ON DELETE SET NULL;

-- Código de cada prestación en cada nomenclador (una práctica por nomenclador)
CREATE TABLE IF NOT EXISTS prestacion_practicas (
  prestacion_id INT NOT NULL,
  practica_id   INT NOT NULL,
  PRIMARY KEY (prestacion_id, practica_id),
  KEY idx_practica (practica_id),
  CONSTRAINT fk_pp2_pres FOREIGN KEY (prestacion_id) REFERENCES prestaciones(id) ON DELETE CASCADE,
  CONSTRAINT fk_pp2_prac FOREIGN KEY (practica_id) REFERENCES nomenclador_practicas(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Código de práctica con el que queda cada turno (copia al darlo, como el nombre de la prestación)
ALTER TABLE turnos ADD COLUMN practica_codigo VARCHAR(20) NULL AFTER prestacion_nombre;
ALTER TABLE turnos ADD COLUMN practica_nomenclador VARCHAR(100) NULL AFTER practica_codigo;

-- ---------- Firma de los profesionales ----------
-- Firma electrónica: imagen de la firma + sello + PIN personal para firmar recetas
CREATE TABLE IF NOT EXISTS profesional_firmas (
  profesional_id INT NOT NULL PRIMARY KEY,
  imagen         MEDIUMTEXT NULL COMMENT 'PNG o JPG como data URL',
  sello          VARCHAR(255) NULL COMMENT 'Texto del sello: nombre, especialidad, matrícula (una línea por renglón)',
  pin_hash       VARCHAR(255) NULL,
  intentos       TINYINT NOT NULL DEFAULT 0 COMMENT 'PIN equivocados seguidos',
  bloqueado_hasta DATETIME NULL,
  actualizado    DATETIME NULL,
  CONSTRAINT fk_pf_prof FOREIGN KEY (profesional_id) REFERENCES profesionales(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------- Vademécum: listado oficial de precios de medicamentos ----------
-- Cada medicamento encontrado en una búsqueda online se guarda (o se actualiza) acá
CREATE TABLE IF NOT EXISTS medicamentos (
  clave          INT NOT NULL PRIMARY KEY COMMENT 'CLAVE del listado oficial',
  nombre         VARCHAR(150) NOT NULL COMMENT 'Nombre comercial',
  droga          VARCHAR(255) NULL COMMENT 'Principio activo',
  presentacion   VARCHAR(200) NULL,
  laboratorio    VARCHAR(120) NULL,
  forma          VARCHAR(150) NULL,
  via            VARCHAR(80)  NULL,
  accion         VARCHAR(150) NULL COMMENT 'Acción terapéutica',
  tipo_venta     VARCHAR(120) NULL,
  controlado     VARCHAR(120) NULL,
  unidades       INT NULL,
  tipo_unidad    VARCHAR(80)  NULL,
  potencia       VARCHAR(60)  NULL,
  importado      VARCHAR(40)  NULL,
  precio         DECIMAL(12,2) NULL,
  precio_pami    DECIMAL(12,2) NULL COMMENT 'Lo que paga el afiliado PAMI',
  descuento_pami VARCHAR(80)  NULL,
  troquel        VARCHAR(20)  NULL,
  codigo_barras  VARCHAR(20)  NULL,
  snomed         VARCHAR(30)  NULL,
  fecha_precio   DATE NULL COMMENT 'Fecha del precio en el listado',
  baja           TINYINT(1) NOT NULL DEFAULT 0,
  actualizado    DATETIME NOT NULL,
  KEY idx_droga (droga(60)),
  KEY idx_nombre (nombre),
  KEY idx_troquel (troquel)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Registro de cada búsqueda
CREATE TABLE IF NOT EXISTS medicamento_busquedas (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  termino    VARCHAR(100) NOT NULL,
  origen     ENUM('online','local') NOT NULL,
  resultados INT NOT NULL DEFAULT 0,
  nuevos     INT NOT NULL DEFAULT 0 COMMENT 'Medicamentos que no estaban guardados',
  error      VARCHAR(255) NULL,
  usuario_id INT NULL,
  fecha      DATETIME NOT NULL,
  KEY idx_termino (termino, fecha),
  KEY idx_fecha (fecha)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------- Recetas ----------
CREATE TABLE IF NOT EXISTS recetas (
  id                  INT AUTO_INCREMENT PRIMARY KEY,
  numero              INT NULL COMMENT 'Número correlativo, se asigna al firmar',
  paciente_id         INT NOT NULL,
  profesional_id      INT NOT NULL,
  turno_id            INT NULL,
  fecha               DATE NOT NULL,
  obra_social_id      INT NULL,
  obra_social_nombre  VARCHAR(80) NULL COMMENT 'Copia al firmar',
  plan                VARCHAR(60) NULL,
  nro_afiliado        VARCHAR(40) NULL,
  cie10_codigo        VARCHAR(7)  NULL,
  diagnostico         VARCHAR(255) NULL,
  indicaciones        TEXT NULL,
  estado              ENUM('borrador','firmada','anulada') NOT NULL DEFAULT 'borrador',
  firmada_en          DATETIME NULL,
  hash                CHAR(64) NULL COMMENT 'SHA-256 del contenido firmado',
  codigo_verificacion VARCHAR(16) NULL,
  cuir                VARCHAR(40) NULL COMMENT 'Si se emitió también en una plataforma registrada (ReNaPDiS)',
  plataforma          VARCHAR(80) NULL,
  motivo_anulacion    VARCHAR(255) NULL,
  anulada_en          DATETIME NULL,
  usuario_id          INT NOT NULL,
  creado              DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  actualizado         DATETIME NULL,
  UNIQUE KEY uk_numero (numero),
  UNIQUE KEY uk_codigo (codigo_verificacion),
  KEY idx_paciente (paciente_id, fecha),
  KEY idx_profesional (profesional_id, fecha),
  CONSTRAINT fk_r_pac FOREIGN KEY (paciente_id) REFERENCES pacientes(id),
  CONSTRAINT fk_r_prof FOREIGN KEY (profesional_id) REFERENCES profesionales(id),
  CONSTRAINT fk_r_turno FOREIGN KEY (turno_id) REFERENCES turnos(id) ON DELETE SET NULL,
  CONSTRAINT fk_r_os FOREIGN KEY (obra_social_id) REFERENCES obras_sociales(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Medicamentos de cada receta, por nombre genérico (Ley 25.649)
CREATE TABLE IF NOT EXISTS receta_items (
  id               INT AUTO_INCREMENT PRIMARY KEY,
  receta_id        INT NOT NULL,
  orden            TINYINT NOT NULL DEFAULT 1,
  medicamento_clave INT NULL COMMENT 'Del listado oficial, si se eligió de la búsqueda',
  droga            VARCHAR(255) NOT NULL COMMENT 'Nombre genérico / principio activo',
  presentacion     VARCHAR(200) NULL COMMENT 'Concentración y forma: 500 mg comprimidos x 16',
  nombre_comercial VARCHAR(150) NULL COMMENT 'Opcional: marca sugerida',
  cantidad         TINYINT NOT NULL DEFAULT 1 COMMENT 'Envases',
  posologia        VARCHAR(255) NULL,
  KEY idx_receta (receta_id),
  CONSTRAINT fk_ri_receta FOREIGN KEY (receta_id) REFERENCES recetas(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------- Configuración nueva ----------
INSERT IGNORE INTO configuracion (clave, valor) VALUES
 ('nomenclador_defecto', ''),
 ('receta_max_items', '3'),
 ('receta_leyenda', 'Prescripción por nombre genérico (Ley 25.649).'),
 ('url_publica', ''),
 ('cnpm_vigencia', ''),
 ('firma_clave', SHA2(CONCAT(UUID(), RAND(), NOW(6)), 256));
