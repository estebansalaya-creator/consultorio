-- =============================================================
--  Versión 6: consulta del padrón de beneficiarios de la SSS
--  CUIL del paciente y registro de cada consulta al padrón
--  (https://publico.sssalud.gob.ar/padron). Se aplica sola al abrir la app.
-- =============================================================
SET NAMES utf8mb4;

ALTER TABLE pacientes ADD COLUMN cuil CHAR(11) NULL AFTER dni, ADD KEY idx_cuil (cuil);

CREATE TABLE IF NOT EXISTS padron_consultas (
  id              INT AUTO_INCREMENT PRIMARY KEY,
  paciente_id     INT NOT NULL,
  fecha           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  cuil            CHAR(11)     NULL,
  dni             VARCHAR(15)  NULL,
  resultado       ENUM('con_cobertura','sin_cobertura') NOT NULL,
  rnas            CHAR(6)      NULL COMMENT 'Código del agente del seguro que figura en el padrón',
  obra_social_txt VARCHAR(255) NULL COMMENT 'Obra social como figura en el padrón',
  parentesco      VARCHAR(40)  NULL,
  obra_social_id  INT NULL COMMENT 'Obra social del centro con ese RNAS',
  actualizo       TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1 = con esta consulta se cambió la cobertura del paciente',
  nota            VARCHAR(255) NULL,
  usuario_id      INT NULL,
  KEY idx_pc_paciente (paciente_id, fecha),
  CONSTRAINT fk_pc_pac FOREIGN KEY (paciente_id) REFERENCES pacientes(id) ON DELETE CASCADE,
  CONSTRAINT fk_pc_os  FOREIGN KEY (obra_social_id) REFERENCES obras_sociales(id) ON DELETE SET NULL,
  CONSTRAINT fk_pc_usr FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
