-- =============================================================
--  Datos de ejemplo (opcional). Correr DESPUÉS de database.sql
--  Los turnos se cargan alrededor de la fecha de hoy.
--  Todos los nombres, DNI, teléfonos y números de afiliado son inventados.
-- =============================================================
SET NAMES utf8mb4;

UPDATE configuracion SET valor = 'Centro Médico del Parque' WHERE clave = 'nombre_centro';
UPDATE configuracion SET valor = 'Av. Rivadavia 5420, CABA' WHERE clave = 'direccion';
UPDATE configuracion SET valor = '11 4902-3311' WHERE clave = 'telefono';

INSERT INTO especialidades (id, nombre, orden) VALUES
 (1, 'Clínica médica', 1),
 (2, 'Cardiología', 2),
 (3, 'Pediatría', 3),
 (4, 'Traumatología', 4),
 (5, 'Ginecología', 5);

INSERT INTO consultorios (id, nombre, orden) VALUES
 (1, 'Consultorio 1', 1),
 (2, 'Consultorio 2', 2),
 (3, 'Consultorio 3', 3),
 (4, 'Consultorio 4', 4),
 (5, 'Sala de estudios cardiológicos', 5);

-- rnas: código del Registro Nacional de Agentes del Seguro (IOMA es provincial: no tiene)
INSERT INTO obras_sociales (id, nombre, rnas, coseguro, orden) VALUES
 (1, 'OSDE', '400800', 0, 1),
 (2, 'Swiss Medical', '900805', 0, 2),
 (3, 'Galeno', '901105', 0, 3),
 (4, 'OSECAC', '126205', 3000, 4),
 (5, 'PAMI', '500807', 0, 5),
 (6, 'Medifé', '901402', 0, 6),
 (7, 'IOMA', NULL, 2500, 7);

INSERT INTO profesionales (id, nombre, matricula, telefono, email, color, duracion_turno, orden) VALUES
 (1, 'Dra. Laura Méndez',   'MN 98765',  '11 6120-4471', 'lmendez@ejemplo.com',  '#1f6fb2', 20, 1),
 (6, 'Dr. Ignacio Paz',     'MN 110432', '11 3920-1187', NULL,                   '#0e7c86', 20, 2),
 (2, 'Dr. Pablo Iturbe',    'MN 87120',  '11 5833-9025', 'piturbe@ejemplo.com',  '#b8486a', 30, 3),
 (3, 'Dra. Sofía Lagos',    'MN 102344', '11 4410-7782', NULL,                   '#7a4fb5', 20, 4),
 (4, 'Dr. Martín Quiroga',  'MN 76551',  '11 3398-1150', NULL,                   '#c06b2c', 15, 5),
 (5, 'Dra. Valeria Campos', 'MN 91208',  '11 2290-6634', NULL,                   '#2e7d5b', 20, 6);

INSERT INTO profesional_especialidades (profesional_id, especialidad_id) VALUES
 (1,1),(6,1),(2,2),(3,3),(4,4),(5,5);

-- Cada franja en su consultorio, sin pisarse entre profesionales
INSERT INTO profesional_horarios (profesional_id, dia, desde, hasta, consultorio_id) VALUES
 (1,1,'08:00','13:00',1),(1,2,'08:00','13:00',1),(1,3,'08:00','13:00',1),(1,4,'08:00','13:00',1),(1,5,'08:00','13:00',1),
 (6,1,'14:00','19:00',1),(6,2,'14:00','19:00',1),(6,3,'14:00','19:00',1),(6,4,'14:00','19:00',1),(6,5,'14:00','19:00',1),(6,6,'09:00','13:00',1),
 (2,1,'09:00','13:00',2),(2,3,'09:00','13:00',2),(2,5,'09:00','13:00',2),(2,2,'15:00','19:00',2),(2,4,'15:00','19:00',2),
 (3,1,'09:00','12:00',3),(3,1,'16:00','19:00',3),(3,2,'09:00','12:00',3),(3,2,'16:00','19:00',3),(3,3,'09:00','12:00',3),(3,3,'16:00','19:00',3),
 (3,4,'09:00','12:00',3),(3,4,'16:00','19:00',3),(3,5,'09:00','12:00',3),(3,5,'16:00','19:00',3),
 (4,2,'08:00','12:00',4),(4,4,'08:00','12:00',4),(4,6,'09:00','13:00',2),
 (5,1,'14:00','19:00',4),(5,3,'14:00','19:00',4),(5,5,'14:00','19:00',4),(5,2,'13:00','18:00',4),(5,4,'13:00','18:00',4);

-- El Dr. Iturbe sólo atiende estas coberturas (los demás, todas)
INSERT INTO profesional_obras_sociales (profesional_id, obra_social_id) VALUES
 (2,1),(2,2),(2,3),(2,6);

-- duracion NULL = la duración de turno de cada profesional
INSERT INTO prestaciones (id, especialidad_id, nombre, duracion, precio, consultorio_id, orden) VALUES
 (1,  1, 'Consulta clínica',               NULL, 35000, NULL, 1),
 (2,  1, 'Certificado de aptitud física',  15,   20000, NULL, 2),
 (3,  2, 'Consulta cardiológica',          NULL, 45000, NULL, 1),
 (4,  2, 'Electrocardiograma',             15,   20000, NULL, 2),
 (5,  2, 'Ergometría',                     45,   60000, 5,    3),
 (6,  3, 'Consulta pediátrica',            NULL, 35000, NULL, 1),
 (7,  3, 'Control de niño sano',           30,   35000, NULL, 2),
 (8,  4, 'Consulta traumatológica',        NULL, 40000, NULL, 1),
 (9,  4, 'Infiltración',                   20,   30000, NULL, 2),
 (10, 5, 'Consulta ginecológica',          NULL, 40000, NULL, 1),
 (11, 5, 'PAP y colposcopía',              30,   30000, NULL, 2);

INSERT INTO prestacion_profesional (prestacion_id, profesional_id) VALUES
 (1,1),(1,6),(2,1),(2,6),(3,2),(4,2),(5,2),(6,3),(7,3),(8,4),(9,4),(10,5),(11,5);

INSERT INTO pacientes (id, nombre, apellido, dni, fecha_nac, sexo, telefono, email, obra_social_id, plan, nro_afiliado, antecedentes, antecedentes_prof, antecedentes_fecha, notas) VALUES
 (1,  'Valentina', 'Gómez',     '38455120', '1994-03-12', 'F', '11 5623-8841', 'vale.gomez@ejemplo.com', 1, '310', '61 234567 8 01', 'Alergia a la penicilina.', 1, NOW() - INTERVAL 40 DAY, NULL),
 (2,  'Jorge',     'Ramírez',   '14322981', '1958-06-02', 'M', '11 4478-2290', NULL, 5, NULL, '150123456789 00', 'HTA en tratamiento con enalapril.\nDBT tipo 2 (metformina 850 mg).\nEx tabaquista (30 paquetes/año).', 1, NOW() - INTERVAL 14 DAY, 'Prefiere turnos temprano.'),
 (3,  'Camila',    'Rodríguez', '40221334', '1997-07-21', 'F', '11 3365-1102', 'cami.rod@ejemplo.com', 2, 'SMG20', '80012345/01', NULL, NULL, NULL, NULL),
 (4,  'Tomás',     'Martínez',  '56870451', '2019-01-30', 'M', '11 6012-7745', NULL, 1, '210', '61 998877 2 03', 'Asma leve intermitente.\nVacunas al día.', 3, NOW() - INTERVAL 7 DAY, 'Lo trae la mamá, Lorena.'),
 (5,  'Julieta',   'López',     '42115879', '1999-09-09', 'F', '11 5590-3318', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL),
 (6,  'Mariana',   'Acosta',    '29554013', '1982-05-17', 'F', '11 4123-6650', 'mariana.acosta@ejemplo.com', 3, 'Plata', '2-345678-01', 'Hipotiroidismo (levotiroxina 75 mcg).', 5, NOW() - INTERVAL 2 DAY, NULL),
 (7,  'Lucas',     'Herrera',   '36998214', '1992-12-04', 'M', '11 3874-0912', NULL, 4, NULL, '123456/00', 'Esguince de tobillo derecho en 2024.', 4, NOW() - INTERVAL 5 DAY, NULL),
 (8,  'Paula',     'Giménez',   '31002457', '1985-08-25', 'F', '11 6650-2238', NULL, 6, NULL, 'MF-551203', 'Embarazo en curso, controla con obstetricia.', 1, NOW() - INTERVAL 20 DAY, NULL),
 (9,  'Agostina',  'Torres',    '43501226', '2001-02-14', 'F', '11 2290-4417', NULL, 7, NULL, '4350122601', NULL, NULL, NULL, NULL),
 (10, 'Roberto',   'Fernández', '20745102', '1968-06-11', 'M', '11 5047-3381', 'rfernandez@ejemplo.com', 1, '410', '61 445566 1 01', 'Cardiopatía isquémica, angioplastia con stent en 2021.\nAAS 100 mg, atorvastatina 40 mg, bisoprolol 2,5 mg.', 2, NOW() - INTERVAL 10 DAY, NULL),
 (11, 'Natalia',   'Romero',    '30418876', '1983-10-03', 'F', '11 4936-5520', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL),
 (12, 'Daniel',    'Suárez',    '39887120', '1996-04-27', 'M', '11 3011-8864', NULL, 2, 'SMG10', '80098765/00', NULL, NULL, NULL, NULL),
 (13, 'Emma',      'Paz',       '57012334', '2021-08-15', 'F', '11 5833-9026', NULL, 3, 'Azul', '2-778899-03', NULL, NULL, NULL, 'La trae el papá, Federico.'),
 (14, 'Héctor',    'Ibáñez',    '11234567', '1949-11-20', 'M', '11 4410-7783', NULL, 5, NULL, '150987654321 00', 'Fibrilación auricular, anticoagulado con apixabán.\nMarcapasos desde 2019.', 2, NOW() - INTERVAL 30 DAY, 'Viene con su hija.');

-- Usuarios de prueba: recepcion / recepcion123  y  laura / laura123 (profesional: Dra. Laura Méndez)
INSERT INTO usuarios (nombre, usuario, password_hash, rol, profesional_id) VALUES
 ('Recepción', 'recepcion', '$2y$10$puGXCC/IGZm.1b86Z2Fwue3VBCD6xmJxsENHd0YxMGdo0Gs3mHH86', 'recepcion', NULL),
 ('Dra. Laura Méndez', 'laura', '$2y$10$afqQKrs4nHcBUsdPUOGurOVVYyujiPYZE8FaKgKr0j9OcSoHC7GGm', 'profesional', 1);

-- Turnos: dia = días desde hoy (0 = hoy, -7 = hace una semana). part = 1: se atiende como particular aunque tenga obra social
CREATE TEMPORARY TABLE tmp_turnos (
  pac INT, prof INT, pres INT, dia INT, hora TIME, estado VARCHAR(20), part TINYINT,
  motivo VARCHAR(255) NULL, texto TEXT NULL, diag VARCHAR(255) NULL, ind TEXT NULL
);
INSERT INTO tmp_turnos VALUES
 (2,  1, 1,  -14, '09:00', 'atendido', 0, 'Control de HTA y diabetes',
     'TA 150/90 mmHg. Glucemia en ayunas 142 mg/dl (laboratorio que trae). Refiere buena adherencia al tratamiento. Sin edemas.',
     'HTA no controlada. DBT tipo 2.', 'Enalapril 10 mg cada 12 h. Se solicita HbA1c, perfil lipídico y función renal. Volver con resultados.'),
 (10, 2, 3,  -10, '10:00', 'atendido', 0, 'Control cardiológico anual',
     'Asintomático. TA 130/80 mmHg, FC 68 lpm. ECG: ritmo sinusal, sin cambios respecto del previo.',
     'Cardiopatía isquémica estable.', 'Continúa igual tratamiento. Ergometría de control.'),
 (5,  5, 10, -9,  '16:00', 'atendido', 0, 'Control ginecológico',
     'Asintomática. Examen mamario y ginecológico normales.', 'Control normal.', 'Volver en un año.'),
 (4,  3, 6,  -7,  '09:20', 'atendido', 0, 'Tos y fiebre de 2 días',
     'T 38,2 °C. Auscultación: sibilancias aisladas bilaterales, sin tiraje. Saturación 97 %. Faringe congestiva.',
     'Broncoespasmo leve asociado a cuadro viral.', 'Salbutamol 2 puff cada 6 h con aerocámara por 5 días. Paracetamol si hay fiebre. Control en 48 h o antes si tiene dificultad para respirar.'),
 (11, 6, 1,  -6,  '14:20', 'atendido', 0, 'Cefalea de una semana',
     'Cefalea frontal opresiva, vespertina, sin signos de alarma. Examen neurológico normal. TA 120/80 mmHg.',
     'Cefalea tensional.', 'Ibuprofeno 400 mg si dolor. Pautas de descanso. Volver si cambia el patrón.'),
 (7,  4, 8,  -5,  '08:30', 'atendido', 0, 'Dolor en rodilla derecha',
     'Dolor medial de 3 semanas tras un partido de fútbol. Sin derrame. McMurray positivo medial. Rodilla estable.',
     'Probable lesión de menisco interno.', 'RMN de rodilla derecha. Antiinflamatorios por 7 días. Hielo. Volver con el estudio.'),
 (9,  1, 1,  -3,  '10:00', 'ausente', 0, NULL, NULL, NULL, NULL),
 (6,  5, 10, -2,  '15:00', 'atendido', 0, 'Control ginecológico anual',
     'Asintomática. Examen mamario normal. Se toma muestra para PAP.', 'Control normal.', 'Mamografía y ecografía mamaria. Volver con resultados.'),
 (2,  1, 1,  0,   '08:20', 'atendido', 0, 'Resultados de laboratorio',
     'HbA1c 7,8 %. LDL 142 mg/dl. Función renal normal. TA 135/85 mmHg con el ajuste.',
     'DBT tipo 2 con control subóptimo. Dislipemia.', 'Se agrega atorvastatina 20 mg. Plan alimentario. Control en 3 meses.'),
 (12, 1, 2,  0,   '09:00', 'atendido', 1, 'Apto físico para el gimnasio',
     'Sin antecedentes relevantes. TA 120/70 mmHg, FC 64 lpm. Examen cardiovascular y respiratorio normales.',
     'Apto para actividad física.', NULL),
 (5,  1, 1,  0,   '10:00', 'en_atencion', 0, NULL, NULL, NULL, NULL),
 (3,  1, 1,  0,   '10:20', 'presente', 0, NULL, NULL, NULL, NULL),
 (11, 1, 1,  0,   '11:00', 'confirmado', 0, NULL, NULL, NULL, NULL),
 (8,  1, 1,  0,   '11:40', 'reservado', 0, NULL, NULL, NULL, NULL),
 (10, 2, 4,  0,   '09:30', 'atendido', 0, 'ECG de control',
     'Ritmo sinusal, FC 66 lpm, sin cambios respecto del previo.', NULL, NULL),
 (14, 2, 3,  0,   '10:00', 'confirmado', 1, NULL, NULL, NULL, NULL),
 (13, 3, 7,  0,   '09:00', 'atendido', 0, 'Control de niño sano',
     'Peso 19 kg, talla 110 cm (percentil 50). Desarrollo acorde a la edad. Examen físico normal.',
     'Niña sana.', 'Completar las vacunas de ingreso escolar.'),
 (4,  3, 6,  0,   '16:20', 'reservado', 0, NULL, NULL, NULL, NULL),
 (6,  6, 1,  0,   '15:00', 'reservado', 0, NULL, NULL, NULL, NULL),
 (9,  6, 1,  0,   '16:00', 'confirmado', 0, NULL, NULL, NULL, NULL),
 (1,  5, 10, 1,   '14:20', 'reservado', 0, NULL, NULL, NULL, NULL),
 (2,  2, 3,  1,   '15:30', 'reservado', 1, NULL, NULL, NULL, NULL),
 (7,  4, 8,  2,   '09:00', 'reservado', 0, NULL, NULL, NULL, NULL),
 (10, 2, 5,  3,   '09:00', 'reservado', 0, NULL, NULL, NULL, NULL),
 (14, 1, 1,  4,   '08:40', 'reservado', 0, NULL, NULL, NULL, NULL),
 (3,  5, 11, 5,   '15:00', 'reservado', 0, NULL, NULL, NULL, NULL);

INSERT INTO turnos (paciente_id, profesional_id, especialidad_id, prestacion_id, prestacion_nombre, consultorio_id, obra_social_id,
                    inicio, fin, precio, estado, llegada, usuario_id, creado)
SELECT x.pac, x.prof, pr.especialidad_id, pr.id, pr.nombre,
       COALESCE(pr.consultorio_id,
                (SELECT h.consultorio_id FROM profesional_horarios h
                  WHERE h.profesional_id = x.prof AND h.dia = WEEKDAY(CURDATE() + INTERVAL x.dia DAY) + 1
                    AND x.hora >= h.desde AND x.hora < h.hasta LIMIT 1),
                (SELECT h2.consultorio_id FROM profesional_horarios h2 WHERE h2.profesional_id = x.prof ORDER BY h2.dia, h2.desde LIMIT 1)),
       IF(x.part = 1, NULL, pa.obra_social_id),
       TIMESTAMP(CURDATE() + INTERVAL x.dia DAY, x.hora),
       TIMESTAMP(CURDATE() + INTERVAL x.dia DAY, x.hora) + INTERVAL COALESCE(pr.duracion, pf.duracion_turno) MINUTE,
       IF(x.part = 1 OR pa.obra_social_id IS NULL, pr.precio, os.coseguro),
       x.estado,
       IF(x.estado IN ('presente', 'en_atencion', 'atendido'), TIMESTAMP(CURDATE() + INTERVAL x.dia DAY, x.hora) - INTERVAL 8 MINUTE, NULL),
       1, TIMESTAMP(CURDATE() + INTERVAL LEAST(x.dia, 0) - 3 DAY, '12:00')
  FROM tmp_turnos x
  JOIN prestaciones pr ON pr.id = x.pres
  JOIN profesionales pf ON pf.id = x.prof
  JOIN pacientes pa ON pa.id = x.pac
  LEFT JOIN obras_sociales os ON os.id = pa.obra_social_id;

-- Evoluciones de los turnos ya atendidos (la historia clínica)
INSERT INTO evoluciones (paciente_id, turno_id, profesional_id, usuario_id, fecha, motivo, texto, diagnostico, indicaciones, creado)
SELECT t.paciente_id, t.id, t.profesional_id, 1, t.inicio, x.motivo, x.texto, x.diag, x.ind, t.fin
  FROM turnos t
  JOIN tmp_turnos x ON x.pac = t.paciente_id AND x.prof = t.profesional_id AND t.inicio = TIMESTAMP(CURDATE() + INTERVAL x.dia DAY, x.hora)
 WHERE x.texto IS NOT NULL;

-- Diagnóstico codificado en CIE-10
UPDATE evoluciones SET cie10_codigo = CASE diagnostico
    WHEN 'HTA no controlada. DBT tipo 2.'                 THEN 'I10'
    WHEN 'Cardiopatía isquémica estable.'                 THEN 'I25.9'
    WHEN 'Broncoespasmo leve asociado a cuadro viral.'    THEN 'J20.9'
    WHEN 'Cefalea tensional.'                             THEN 'G44.2'
    WHEN 'Probable lesión de menisco interno.'            THEN 'S83.2'
    WHEN 'DBT tipo 2 con control subóptimo. Dislipemia.'  THEN 'E11.9'
    WHEN 'Apto para actividad física.'                    THEN 'Z02.5'
    WHEN 'Niña sana.'                                     THEN 'Z00.1'
    ELSE cie10_codigo END;
UPDATE evoluciones e JOIN turnos t ON t.id = e.turno_id SET e.cie10_codigo = 'Z01.4'
 WHERE e.diagnostico = 'Control normal.' AND t.profesional_id = 5;

DROP TEMPORARY TABLE tmp_turnos;

-- Código de cada prestación en el PMO (nomenclador 1, cargado por la migración 005)
INSERT INTO prestacion_codigo (prestacion_id, codigo_id)
SELECT x.pres, c.id FROM (
  SELECT 1 AS pres, '420101' AS cod UNION ALL SELECT 2, '420101' UNION ALL SELECT 3, '420106' UNION ALL SELECT 4, '170101'
  UNION ALL SELECT 5, '170111' UNION ALL SELECT 6, '420107' UNION ALL SELECT 7, '420107' UNION ALL SELECT 8, '420106'
  UNION ALL SELECT 9, '120001' UNION ALL SELECT 10, '420106' UNION ALL SELECT 11, '220102'
) x JOIN prest_nomenclador_codigo c ON c.id_nomenclador = 1 AND c.codigo = x.cod;
UPDATE configuracion SET valor = '1' WHERE clave = 'nomenclador_defecto';
-- El código queda guardado en cada turno (como al darlo desde la agenda)
UPDATE turnos t JOIN prestacion_codigo pc ON pc.prestacion_id = t.prestacion_id
  JOIN prest_nomenclador_codigo c ON c.id = pc.codigo_id AND c.id_nomenclador = 1
   SET t.practica_codigo = c.codigo, t.practica_nomenclador = 'PMO';

-- Turnos con algo para cobrar atendidos en días anteriores: quedan cobrados (en efectivo) en cajas cerradas
INSERT INTO cajas (fecha_apertura, usuario_apertura_id, monto_inicial, fecha_cierre, usuario_cierre_id, efectivo_esperado, efectivo_contado, estado)
SELECT d.dia + INTERVAL 8 HOUR, 1, 20000, d.dia + INTERVAL 19 HOUR + INTERVAL 30 MINUTE, 1, 20000 + d.total, 20000 + d.total, 'cerrada'
  FROM (SELECT DATE(inicio) AS dia, SUM(precio) AS total FROM turnos
         WHERE inicio < CURDATE() AND estado = 'atendido' AND precio > 0 GROUP BY DATE(inicio)) d;

INSERT INTO cobros (caja_id, numero, fecha, paciente_id, subtotal, descuento, total, usuario_id)
SELECT c.id, t.id, t.fin, t.paciente_id, t.precio, 0, t.precio, 1
  FROM turnos t JOIN cajas c ON DATE(c.fecha_apertura) = DATE(t.inicio)
 WHERE t.inicio < CURDATE() AND t.estado = 'atendido' AND t.precio > 0;

INSERT INTO cobro_items (cobro_id, turno_id, descripcion, cantidad, precio_unitario, subtotal)
SELECT co.id, t.id, CONCAT(t.prestacion_nombre, IF(os.nombre IS NULL, ' (particular)', CONCAT(' (coseguro ', os.nombre, ')'))), 1, t.precio, t.precio
  FROM cobros co JOIN turnos t ON t.id = co.numero LEFT JOIN obras_sociales os ON os.id = t.obra_social_id;

INSERT INTO cobro_pagos (cobro_id, medio_pago_id, monto) SELECT id, 1, total FROM cobros;

UPDATE turnos t JOIN cobros co ON co.numero = t.id SET t.cobro_id = co.id;
