-- RADIX
-- Paso 1: separar propiedad economica del flujo
-- Este cambio NO altera la logica actual.
-- Solo prepara la base para distinguir dinero de usuarios reales vs dinero del sistema/clones.

START TRANSACTION;

-- 1. Marcar a quien pertenece el flujo economico en pagos
ALTER TABLE pagos
  ADD COLUMN IF NOT EXISTS propietario_flujo ENUM('usuario','sistema') NOT NULL DEFAULT 'usuario' AFTER fase_numero,
  ADD KEY idx_fase_propietario_tipo (fase_numero, propietario_flujo, tipo),
  ADD KEY idx_propietario_estado (propietario_flujo, estado);

-- 2. Marcar a quien pertenece la reserva interna
ALTER TABLE reservas_tablero
  ADD COLUMN IF NOT EXISTS propietario_flujo ENUM('usuario','sistema') NOT NULL DEFAULT 'usuario' AFTER fase_numero,
  ADD KEY idx_usuario_propietario_estado (usuario_id, propietario_flujo, estado);

-- 3. Backfill conservador
-- Todo lo existente arranca como flujo de usuario salvo los casos obvios del sistema/clones.
UPDATE pagos
SET propietario_flujo = 'usuario'
WHERE propietario_flujo IS NULL
   OR propietario_flujo NOT IN ('usuario', 'sistema');

UPDATE reservas_tablero
SET propietario_flujo = 'usuario'
WHERE propietario_flujo IS NULL
   OR propietario_flujo NOT IN ('usuario', 'sistema');

-- 4. Pagos emitidos por clones pasan a ser del sistema
UPDATE pagos p
JOIN usuarios u ON p.id_emisor = u.id
SET p.propietario_flujo = 'sistema'
WHERE u.tipo_usuario = 'clon';

-- 5. Aportes a tesoreria y pagos del sistema se consideran del sistema
UPDATE pagos
SET propietario_flujo = 'sistema'
WHERE tipo IN ('tesoreria_clon')
   OR id_emisor IN (1000)
   OR id_receptor IN (1000);

-- 6. Reservas pertenecientes a clones pasan a ser del sistema
UPDATE reservas_tablero rt
JOIN usuarios u ON rt.usuario_id = u.id
SET rt.propietario_flujo = 'sistema'
WHERE u.tipo_usuario = 'clon';

COMMIT;
