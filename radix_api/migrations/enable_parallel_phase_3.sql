-- RADIX
-- Activa la Fase 3 (Radix PLATINUM — Nivel Final) en paralelo.
-- Patron identico a enable_parallel_phase_2.sql.
--
-- Plan financiero Fase 3 (FASE FINAL — sin Fase 4):
--   Tablero A: entrada $10,000 | ganancia $10,000 | tesoreria $10,000 | reserva-B $20,000
--   Tablero B: entrada $20,000 | ganancia $20,000 | tesoreria $20,000 | reserva-C $40,000
--   Tablero C: entrada $40,000 | bruto $120,000   | tesoreria $40,000 (P0 entrada)
--              utilidad_master (tesoreria final) $40,000 | reentrada $10,000 | neto C $70,000
--   Ganancia neta ciclo completo (A+B+C): $100,000 (ROI 1000%)
--   fase_siguiente = NULL → no hay activacion de fase siguiente al cerrar C.

START TRANSACTION;

-- ─────────────────────────────────────────────────────────────────────────────
-- 1. Agregar 'utilidad_master' al ENUM de pagos.tipo
--    (tipo usado para el aporte final de Fase 3 a la tesoreria master)
-- ─────────────────────────────────────────────────────────────────────────────
ALTER TABLE pagos
  MODIFY COLUMN tipo ENUM(
    'regalo',
    'ganancia_tablero',
    'tesoreria_clon',
    'salto_fase_1',
    'salto_fase_2',
    'salto_fase_3',
    'utilidad_master',
    'reentrada'
  ) NOT NULL;

-- ─────────────────────────────────────────────────────────────────────────────
-- 2. Activar Fase 3 en la tabla maestra de fases
-- ─────────────────────────────────────────────────────────────────────────────
UPDATE fases_config
SET    activa      = 1,
       nombre      = 'Fase 3',
       descripcion = 'Fase Platinum — Nivel Final. El ciclo cumbre del sistema RADIX.'
WHERE  fase_numero = 3;

-- ─────────────────────────────────────────────────────────────────────────────
-- 3. Insertar (o actualizar) la configuracion de tableros de Fase 3
-- ─────────────────────────────────────────────────────────────────────────────
INSERT INTO fases_tableros_config (
    fase_numero, tablero_tipo,
    monto_entrada, ganancia_directa, aporte_tesoreria,
    reserva_siguiente_tablero, ganancia_bruta_cierre,
    semilla_siguiente_fase, monto_reentrada,
    clon_permitido, clon_monto, activa
)
VALUES
    -- Tablero A: $10k entrada | $10k ganancia directa | $10k tesoreria | $20k reserva → B
    (3, 'A', 10000.00, 10000.00, 10000.00, 20000.00,      0.00,     0.00,    0.00, 1, 10000.00, 1),
    -- Tablero B: $20k entrada | $20k ganancia directa | $20k tesoreria | $40k reserva → C
    (3, 'B', 20000.00, 20000.00, 20000.00, 40000.00,      0.00,     0.00,    0.00, 1, 20000.00, 1),
    -- Tablero C: $40k entrada | $120k bruto | $40k tesoreria (P0) | $40k utilidad_master | $10k reentrada
    --            semilla_siguiente_fase = $40,000 → va a tesoreria (no hay Fase 4), tipo: utilidad_master
    (3, 'C', 40000.00,     0.00, 40000.00,     0.00, 120000.00, 40000.00, 10000.00, 1, 40000.00, 1)
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

-- ─────────────────────────────────────────────────────────────────────────────
-- 4. Asegurar que los tableros de Fase 3 queden activos
-- ─────────────────────────────────────────────────────────────────────────────
UPDATE fases_tableros_config
SET    activa = 1
WHERE  fase_numero = 3;

COMMIT;
