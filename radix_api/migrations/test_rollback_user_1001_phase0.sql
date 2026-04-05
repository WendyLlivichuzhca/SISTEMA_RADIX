-- ROLLBACK DE LA PRUEBA FORZADA PARA USUARIO 1001
-- Revierte el script test_force_complete_user_1001_phase0.sql en la copia local.

START TRANSACTION;

SET @user_id := 1001;
SET @has_test := (
    SELECT COUNT(*)
    FROM auditoria_logs
    WHERE usuario_id = @user_id
      AND fase_numero = 0
      AND accion = 'CICLO_COMPLETADO_C1'
      AND detalles LIKE 'Tablero C completado manualmente en entorno local%'
);

DELETE FROM auditoria_logs
WHERE usuario_id = @user_id
  AND fase_numero = 0
  AND accion = 'CICLO_COMPLETADO_C1'
  AND detalles LIKE 'Tablero C completado manualmente en entorno local%'
  AND @has_test > 0;

DELETE FROM tesoreria_movimientos
WHERE relacion_id = @user_id
  AND motivo = 'Salto Fase 1 - Usuario ID 1001 (ciclo 1)'
  AND monto = 100.00
  AND @has_test > 0;

DELETE FROM reservas_tablero
WHERE usuario_id = @user_id
  AND fase_numero = 0
  AND desde_tablero = 'C'
  AND hacia_destino IN ('FASE1', 'REENTRADA_A')
  AND ciclo_origen = 1
  AND @has_test > 0;

DELETE FROM pagos
WHERE @has_test > 0
  AND (
      (
          id_emisor = 1000
          AND id_receptor = @user_id
          AND beneficiario_usuario_id = @user_id
          AND fase_numero = 0
          AND tablero_tipo = 'C'
          AND ciclo = 1
          AND monto = 120.00
          AND tipo = 'ganancia_tablero'
          AND estado = 'completado'
      )
      OR (
          id_emisor = @user_id
          AND id_receptor = 1000
          AND beneficiario_usuario_id = 1000
          AND fase_numero = 0
          AND tablero_tipo = 'C'
          AND ciclo = 1
          AND monto = 40.00
          AND tipo = 'tesoreria_clon'
          AND estado = 'completado'
      )
      OR (
          id_emisor = @user_id
          AND id_receptor = 1000
          AND beneficiario_usuario_id = 1000
          AND fase_numero = 0
          AND tablero_tipo = 'C'
          AND ciclo = 1
          AND monto = 100.00
          AND tipo = 'salto_fase_1'
          AND estado = 'completado'
      )
      OR (
          id_emisor = @user_id
          AND id_receptor = 1000
          AND beneficiario_usuario_id = @user_id
          AND fase_numero = 0
          AND tablero_tipo = 'C'
          AND ciclo = 1
          AND monto = 10.00
          AND tipo = 'reentrada'
          AND estado = 'completado'
      )
  );

UPDATE sistema_config
SET valor_decimal = valor_decimal - 40.00
WHERE clave = 'tesoreria_balance'
  AND @has_test > 0;

DELETE FROM tableros_progreso
WHERE usuario_id = @user_id
  AND (
      (fase_numero = 0 AND tablero_tipo = 'A' AND ciclo = 2)
      OR (fase_numero = 1 AND tablero_tipo = 'A' AND ciclo = 1)
  )
  AND @has_test > 0;

UPDATE tableros_progreso
SET estado = 'en_progreso',
    fecha_fin = NULL
WHERE usuario_id = @user_id
  AND fase_numero = 0
  AND tablero_tipo = 'C'
  AND ciclo = 1
  AND @has_test > 0;

COMMIT;

SELECT id, usuario_id, fase_numero, tablero_tipo, ciclo, estado, fecha_inicio, fecha_fin
FROM tableros_progreso
WHERE usuario_id = 1001
ORDER BY fase_numero, ciclo, FIELD(tablero_tipo, 'A', 'B', 'C'), id;
