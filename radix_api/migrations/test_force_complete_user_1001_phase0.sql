-- FORZAR CIERRE DE FASE 0 PARA USUARIO 1001 EN COPIA LOCAL
-- Uso recomendado: SOLO en tu base local de pruebas.
-- Este script simula el cierre del Tablero C del ciclo 1 para el usuario 1001,
-- aunque todavia no cumpla naturalmente los 3 referidos calificados.
--
-- Efectos principales:
-- 1. Marca completado el Tablero C de Fase 0.
-- 2. Inserta la ganancia de cierre.
-- 3. Registra tesoreria, semilla para Fase 1 y reentrada al ciclo 2.
-- 4. Abre Fase 1 como raiz para el usuario 1001.
--
-- Antes de ejecutarlo:
-- - Haz un export rapido de la base local si quieres poder volver exacto.
-- - Ejecutalo una sola vez.

START TRANSACTION;

SET @user_id := 1001;
SET @fase_actual := 0;
SET @ciclo_actual := 1;
SET @already_done := (
    SELECT COUNT(*)
    FROM auditoria_logs
    WHERE usuario_id = @user_id
      AND fase_numero = @fase_actual
      AND accion = 'CICLO_COMPLETADO_C1'
);

-- 1. Marcar Tablero C del ciclo 1 como completado.
UPDATE tableros_progreso
SET estado = 'completado',
    fecha_fin = NOW()
WHERE usuario_id = @user_id
  AND fase_numero = @fase_actual
  AND tablero_tipo = 'C'
  AND ciclo = @ciclo_actual
  AND @already_done = 0;

-- 2. Ganancia bruta de cierre del Tablero C.
INSERT INTO pagos (
    id_emisor,
    id_receptor,
    beneficiario_usuario_id,
    fase_numero,
    propietario_flujo,
    wallet_destino_real,
    tablero_tipo,
    ciclo,
    origen_fondos,
    monto,
    tipo,
    estado
)
SELECT
    1000,
    @user_id,
    @user_id,
    @fase_actual,
    'usuario',
    NULL,
    'C',
    @ciclo_actual,
    'reserva_interna',
    120.00,
    'ganancia_tablero',
    'completado'
FROM DUAL
WHERE @already_done = 0;

-- 3. Aporte a tesoreria por cierre de C.
UPDATE sistema_config
SET valor_decimal = valor_decimal + 40.00
WHERE clave = 'tesoreria_balance'
  AND @already_done = 0;

INSERT INTO pagos (
    id_emisor,
    id_receptor,
    beneficiario_usuario_id,
    fase_numero,
    propietario_flujo,
    wallet_destino_real,
    tablero_tipo,
    ciclo,
    origen_fondos,
    monto,
    tipo,
    estado
)
SELECT
    @user_id,
    1000,
    1000,
    @fase_actual,
    'sistema',
    NULL,
    'C',
    @ciclo_actual,
    'reserva_interna',
    40.00,
    'tesoreria_clon',
    'completado'
FROM DUAL
WHERE @already_done = 0;

-- 4. Semilla para Fase 1.
INSERT INTO pagos (
    id_emisor,
    id_receptor,
    beneficiario_usuario_id,
    fase_numero,
    propietario_flujo,
    wallet_destino_real,
    tablero_tipo,
    ciclo,
    origen_fondos,
    monto,
    tipo,
    estado
)
SELECT
    @user_id,
    1000,
    1000,
    @fase_actual,
    'usuario',
    NULL,
    'C',
    @ciclo_actual,
    'reserva_interna',
    100.00,
    'salto_fase_1',
    'completado'
FROM DUAL
WHERE @already_done = 0;

INSERT INTO reservas_tablero (
    usuario_id,
    fase_numero,
    propietario_flujo,
    desde_tablero,
    hacia_destino,
    ciclo_origen,
    ciclo_destino,
    monto,
    estado,
    detalle,
    fecha_uso
)
SELECT
    @user_id,
    @fase_actual,
    'usuario',
    'C',
    'FASE1',
    @ciclo_actual,
    NULL,
    100.00,
    'usado',
    'Semilla interna de Fase 1 generada al cerrar ciclo 1',
    NOW()
FROM DUAL
WHERE @already_done = 0;

-- 5. Reentrada automatica al Tablero A del ciclo 2.
INSERT INTO pagos (
    id_emisor,
    id_receptor,
    beneficiario_usuario_id,
    fase_numero,
    propietario_flujo,
    wallet_destino_real,
    tablero_tipo,
    ciclo,
    origen_fondos,
    monto,
    tipo,
    estado
)
SELECT
    @user_id,
    1000,
    @user_id,
    @fase_actual,
    'usuario',
    NULL,
    'C',
    @ciclo_actual,
    'reserva_interna',
    10.00,
    'reentrada',
    'completado'
FROM DUAL
WHERE @already_done = 0;

INSERT INTO reservas_tablero (
    usuario_id,
    fase_numero,
    propietario_flujo,
    desde_tablero,
    hacia_destino,
    ciclo_origen,
    ciclo_destino,
    monto,
    estado,
    detalle,
    fecha_uso
)
SELECT
    @user_id,
    @fase_actual,
    'usuario',
    'C',
    'REENTRADA_A',
    @ciclo_actual,
    2,
    10.00,
    'usado',
    'Reentrada automatica a Tablero A del ciclo 2',
    NOW()
FROM DUAL
WHERE @already_done = 0;

INSERT INTO tesoreria_movimientos (
    tipo,
    monto,
    motivo,
    relacion_id
)
SELECT
    'ingreso',
    100.00,
    'Salto Fase 1 - Usuario ID 1001 (ciclo 1)',
    @user_id
FROM DUAL
WHERE @already_done = 0;

-- 6. Abrir nuevo ciclo en Fase 0.
INSERT INTO tableros_progreso (
    usuario_id,
    fase_numero,
    tablero_tipo,
    ciclo,
    estado
)
SELECT
    @user_id,
    @fase_actual,
    'A',
    2,
    'en_progreso'
FROM DUAL
WHERE @already_done = 0
  AND NOT EXISTS (
      SELECT 1
      FROM tableros_progreso
      WHERE usuario_id = @user_id
        AND fase_numero = @fase_actual
        AND tablero_tipo = 'A'
        AND ciclo = 2
  );

-- 7. Abrir Fase 1 en paralelo como raiz.
INSERT INTO tableros_progreso (
    usuario_id,
    fase_numero,
    tablero_tipo,
    ciclo,
    estado
)
SELECT
    @user_id,
    1,
    'A',
    1,
    'en_progreso'
FROM DUAL
WHERE @already_done = 0
  AND NOT EXISTS (
      SELECT 1
      FROM tableros_progreso
      WHERE usuario_id = @user_id
        AND fase_numero = 1
  );

-- 8. Auditoria final del cierre forzado.
INSERT INTO auditoria_logs (
    usuario_id,
    fase_numero,
    accion,
    tabla_afectada,
    detalles
)
SELECT
    @user_id,
    @fase_actual,
    'CICLO_COMPLETADO_C1',
    'tableros_progreso',
    'Tablero C completado manualmente en entorno local de pruebas. Referidos calificados forzados: 3'
FROM DUAL
WHERE @already_done = 0;

COMMIT;

-- Verificacion rapida.
SELECT id, usuario_id, fase_numero, tablero_tipo, ciclo, estado, fecha_inicio, fecha_fin
FROM tableros_progreso
WHERE usuario_id = 1001
ORDER BY fase_numero, ciclo, FIELD(tablero_tipo, 'A', 'B', 'C'), id;

SELECT id, id_emisor, id_receptor, beneficiario_usuario_id, fase_numero, tablero_tipo, ciclo, monto, tipo, estado
FROM pagos
WHERE (id_emisor = 1001 OR id_receptor = 1001)
ORDER BY id DESC
LIMIT 12;

SELECT id, usuario_id, fase_numero, accion, detalles, fecha
FROM auditoria_logs
WHERE usuario_id = 1001
ORDER BY id DESC
LIMIT 5;
