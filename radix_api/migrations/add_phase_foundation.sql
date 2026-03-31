-- RADIX
-- Base segura para soportar Fase 0, Fase 1, Fase 2 y Fase 3
-- Esta migracion NO cambia la logica actual. Solo prepara la base.

START TRANSACTION;

-- 1. Configuracion general de fases
CREATE TABLE IF NOT EXISTS fases_config (
  fase_numero INT NOT NULL,
  nombre VARCHAR(100) NOT NULL,
  descripcion TEXT DEFAULT NULL,
  fase_siguiente INT DEFAULT NULL,
  activa TINYINT(1) NOT NULL DEFAULT 0,
  fecha_creacion TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  fecha_actualizacion TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (fase_numero)
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

-- 2. Configuracion por tablero dentro de cada fase
CREATE TABLE IF NOT EXISTS fases_tableros_config (
  id INT NOT NULL AUTO_INCREMENT,
  fase_numero INT NOT NULL,
  tablero_tipo ENUM('A','B','C') NOT NULL,
  monto_entrada DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  ganancia_directa DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  aporte_tesoreria DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  reserva_siguiente_tablero DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  ganancia_bruta_cierre DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  semilla_siguiente_fase DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  monto_reentrada DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  clon_permitido TINYINT(1) NOT NULL DEFAULT 1,
  clon_monto DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  activa TINYINT(1) NOT NULL DEFAULT 1,
  fecha_creacion TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  fecha_actualizacion TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uk_fase_tablero (fase_numero, tablero_tipo),
  KEY idx_fase_activa (fase_numero, activa),
  CONSTRAINT fk_fases_tableros_config_fase
    FOREIGN KEY (fase_numero) REFERENCES fases_config (fase_numero)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

-- 3. Marcar fase en tablas actuales para no mezclar Fase 0 con futuras fases
ALTER TABLE tableros_progreso
  ADD COLUMN IF NOT EXISTS fase_numero INT NOT NULL DEFAULT 0 AFTER usuario_id,
  ADD KEY idx_usuario_fase_estado (usuario_id, fase_numero, estado),
  ADD KEY idx_fase_tablero_ciclo (fase_numero, tablero_tipo, ciclo);

ALTER TABLE pagos
  ADD COLUMN IF NOT EXISTS fase_numero INT NOT NULL DEFAULT 0 AFTER beneficiario_usuario_id,
  ADD KEY idx_fase_tipo_estado (fase_numero, tipo, estado),
  ADD KEY idx_usuario_fase_ciclo (id_emisor, fase_numero, ciclo);

ALTER TABLE reservas_tablero
  ADD COLUMN IF NOT EXISTS fase_numero INT NOT NULL DEFAULT 0 AFTER usuario_id,
  ADD KEY idx_usuario_fase_destino (usuario_id, fase_numero, hacia_destino);

ALTER TABLE referidos
  ADD COLUMN IF NOT EXISTS fase_numero INT NOT NULL DEFAULT 0 AFTER id_hijo,
  ADD KEY idx_padre_fase_ciclo (id_padre, fase_numero, ciclo),
  ADD KEY idx_hijo_fase_ciclo (id_hijo, fase_numero, ciclo);

ALTER TABLE auditoria_logs
  ADD COLUMN IF NOT EXISTS fase_numero INT DEFAULT NULL AFTER usuario_id,
  ADD KEY idx_usuario_fase_fecha (usuario_id, fase_numero, fecha);

-- 4. Backfill: todo lo actual pertenece a Fase 0
UPDATE tableros_progreso SET fase_numero = 0 WHERE fase_numero IS NULL OR fase_numero <> 0;
UPDATE pagos SET fase_numero = 0 WHERE fase_numero IS NULL OR fase_numero <> 0;
UPDATE reservas_tablero SET fase_numero = 0 WHERE fase_numero IS NULL OR fase_numero <> 0;
UPDATE referidos SET fase_numero = 0 WHERE fase_numero IS NULL OR fase_numero <> 0;

-- 5. Seed basico de fases
INSERT INTO fases_config (fase_numero, nombre, descripcion, fase_siguiente, activa)
VALUES
  (0, 'Fase 0', 'Fase actual operativa del sistema RADIX.', 1, 1),
  (1, 'Fase 1', 'Fase x10 basada en la semilla generada al cerrar la Fase 0.', 2, 0),
  (2, 'Fase 2', 'Fase futura preparada a nivel de estructura.', 3, 0),
  (3, 'Fase 3', 'Fase futura preparada a nivel de estructura.', NULL, 0)
ON DUPLICATE KEY UPDATE
  nombre = VALUES(nombre),
  descripcion = VALUES(descripcion),
  fase_siguiente = VALUES(fase_siguiente);

-- 6. Seed de tableros para Fase 0
INSERT INTO fases_tableros_config (
  fase_numero, tablero_tipo, monto_entrada, ganancia_directa, aporte_tesoreria,
  reserva_siguiente_tablero, ganancia_bruta_cierre, semilla_siguiente_fase,
  monto_reentrada, clon_permitido, clon_monto, activa
)
VALUES
  (0, 'A', 10.00, 10.00, 10.00, 20.00,   0.00,   0.00,  0.00, 1, 10.00, 1),
  (0, 'B', 20.00, 20.00, 20.00, 40.00,   0.00,   0.00,  0.00, 1, 20.00, 1),
  (0, 'C', 40.00,  0.00, 40.00,  0.00, 120.00, 100.00, 10.00, 1, 40.00, 1)
ON DUPLICATE KEY UPDATE
  monto_entrada = VALUES(monto_entrada),
  ganancia_directa = VALUES(ganancia_directa),
  aporte_tesoreria = VALUES(aporte_tesoreria),
  reserva_siguiente_tablero = VALUES(reserva_siguiente_tablero),
  ganancia_bruta_cierre = VALUES(ganancia_bruta_cierre),
  semilla_siguiente_fase = VALUES(semilla_siguiente_fase),
  monto_reentrada = VALUES(monto_reentrada),
  clon_permitido = VALUES(clon_permitido),
  clon_monto = VALUES(clon_monto),
  activa = VALUES(activa);

-- 7. Seed de tableros para Fase 1
INSERT INTO fases_tableros_config (
  fase_numero, tablero_tipo, monto_entrada, ganancia_directa, aporte_tesoreria,
  reserva_siguiente_tablero, ganancia_bruta_cierre, semilla_siguiente_fase,
  monto_reentrada, clon_permitido, clon_monto, activa
)
VALUES
  (1, 'A', 100.00, 100.00, 100.00, 200.00,    0.00,    0.00,   0.00, 1, 100.00, 1),
  (1, 'B', 200.00, 200.00, 200.00, 400.00,    0.00,    0.00,   0.00, 1, 200.00, 1),
  (1, 'C', 400.00,   0.00, 400.00,   0.00, 1200.00, 1000.00, 100.00, 1, 400.00, 1)
ON DUPLICATE KEY UPDATE
  monto_entrada = VALUES(monto_entrada),
  ganancia_directa = VALUES(ganancia_directa),
  aporte_tesoreria = VALUES(aporte_tesoreria),
  reserva_siguiente_tablero = VALUES(reserva_siguiente_tablero),
  ganancia_bruta_cierre = VALUES(ganancia_bruta_cierre),
  semilla_siguiente_fase = VALUES(semilla_siguiente_fase),
  monto_reentrada = VALUES(monto_reentrada),
  clon_permitido = VALUES(clon_permitido),
  clon_monto = VALUES(clon_monto),
  activa = VALUES(activa);

COMMIT;
