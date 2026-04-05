-- RADIX
-- Activa la Fase 2 (Radix High-Level) en paralelo.
-- Patron identico a enable_parallel_phase_1.sql.
--
-- Plan financiero Fase 2:
--   Tablero A: entrada $1,000 | ganancia $1,000 | tesoreria $1,000 | reserva→B $2,000
--   Tablero B: entrada $2,000 | ganancia $2,000 | tesoreria $2,000 | reserva→C $4,000
--   Tablero C: entrada $4,000 | bruto $12,000   | tesoreria $4,000
--              semilla Fase 3 $10,000 | reentrada $1,000 | neto $1,000
--   Ganancia neta ciclo completo: $4,000 (ROI 400%)

START TRANSACTION;

-- 1. Activar Fase 2 en la tabla maestra de fases
UPDATE fases_config
SET    activa = 1
WHERE  fase_numero = 2;

-- 2. Insertar (o actualizar) la configuracion de tableros de Fase 2
INSERT INTO fases_tableros_config (
    fase_numero, tablero_tipo,
    monto_entrada, ganancia_directa, aporte_tesoreria,
    reserva_siguiente_tablero, ganancia_bruta_cierre,
    semilla_siguiente_fase, monto_reentrada,
    clon_permitido, clon_monto, activa
)
VALUES
    (2, 'A', 1000.00, 1000.00, 1000.00, 2000.00,     0.00,     0.00,    0.00, 1, 1000.00, 1),
    (2, 'B', 2000.00, 2000.00, 2000.00, 4000.00,     0.00,     0.00,    0.00, 1, 2000.00, 1),
    (2, 'C', 4000.00,    0.00, 4000.00,    0.00, 12000.00, 10000.00, 1000.00, 1, 4000.00, 1)
ON DUPLICATE KEY UPDATE
    monto_entrada             = VALUES(monto_entrada),
    ganancia_directa          = VALUES(ganancia_directa),
    aporte_tesoreria          = VALUES(aporte_tesoreria),
    reserva_siguiente_tablero = VALUES(reserva_siguiente_tablero),
    ganancia_bruta_cierre     = VALUES(ganancia_bruta_cierre),
    semilla_siguiente_fase    = VALUES(semilla_siguiente_fase),
    monto_reentrada           = VALUES(monto_reentrada),
    clon_permitido            = VALUES(clon_permitido),
    clon_monto                = VALUES(clon_monto),
    activa                    = VALUES(activa);

-- 3. Asegurar que los tableros de Fase 2 queden activos
UPDATE fases_tableros_config
SET    activa = 1
WHERE  fase_numero = 2;

COMMIT;
