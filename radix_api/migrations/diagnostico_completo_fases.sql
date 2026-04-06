-- ============================================================
-- RADIX - DIAGNOSTICO COMPLETO DE TODAS LAS FASES
-- Solo lectura. Seguro ejecutar en produccion.
-- ============================================================

-- SECCION 1: ENUM de pagos.tipo
-- Debe incluir utilidad_master en la lista
SELECT
    'ENUM pagos.tipo' AS verificacion,
    COLUMN_TYPE       AS valor_actual
FROM INFORMATION_SCHEMA.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME   = 'pagos'
  AND COLUMN_NAME  = 'tipo';

-- SECCION 2: Configuracion de fases
-- Fase 3 debe tener activa=1 y fase_siguiente=NULL
SELECT
    fase_numero,
    nombre,
    activa,
    fase_siguiente,
    descripcion
FROM fases_config
ORDER BY fase_numero;

-- SECCION 3: Configuracion de tableros por fase
-- Deben existir A, B, C para fases 0, 1, 2 y 3
SELECT
    fase_numero,
    tablero_tipo,
    activa,
    monto_entrada,
    ganancia_directa,
    aporte_tesoreria,
    reserva_siguiente_tablero,
    ganancia_bruta_cierre,
    semilla_siguiente_fase,
    monto_reentrada
FROM fases_tableros_config
ORDER BY fase_numero, FIELD(tablero_tipo, 'A', 'B', 'C');

-- SECCION 4: Conteo total de tableros configurados
-- Minimo esperado: 12 filas (4 fases x 3 tableros)
SELECT COUNT(*) AS total_tableros_config FROM fases_tableros_config;

-- SECCION 5: Usuarios activos por fase y tablero
SELECT
    tp.fase_numero,
    tp.tablero_tipo,
    tp.ciclo,
    COUNT(*) AS total_usuarios,
    SUM(CASE WHEN tp.estado = 'en_progreso' THEN 1 ELSE 0 END) AS en_progreso,
    SUM(CASE WHEN tp.estado = 'finalizado'  THEN 1 ELSE 0 END) AS finalizados
FROM tableros_progreso tp
JOIN usuarios u ON tp.usuario_id = u.id
WHERE u.tipo_usuario = 'real'
GROUP BY tp.fase_numero, tp.tablero_tipo, tp.ciclo
ORDER BY tp.fase_numero, tp.ciclo, FIELD(tp.tablero_tipo, 'A', 'B', 'C');

-- SECCION 6: Pagos por tipo y fase
SELECT
    fase_numero,
    tipo,
    COUNT(*)   AS cantidad,
    SUM(monto) AS total_usdt,
    MAX(fecha_pago) AS ultimo_pago
FROM pagos
WHERE estado = 'completado'
GROUP BY fase_numero, tipo
ORDER BY fase_numero, tipo;

-- SECCION 7: Verificacion especifica Fase 3
SELECT 'Fase 3 - utilidad_master' AS concepto,
    COUNT(*) AS cantidad, COALESCE(SUM(monto), 0) AS total_usdt
FROM pagos
WHERE fase_numero = 3 AND tipo = 'utilidad_master' AND estado = 'completado'
UNION ALL
SELECT 'Fase 3 - reentrada',
    COUNT(*), COALESCE(SUM(monto), 0)
FROM pagos
WHERE fase_numero = 3 AND tipo = 'reentrada' AND estado = 'completado'
UNION ALL
SELECT 'Fase 3 - ganancia_tablero',
    COUNT(*), COALESCE(SUM(monto), 0)
FROM pagos
WHERE fase_numero = 3 AND tipo = 'ganancia_tablero'
  AND propietario_flujo = 'usuario' AND estado = 'completado'
UNION ALL
SELECT 'Fase 3 - cierres Tablero C',
    COUNT(*), 0
FROM tableros_progreso
WHERE fase_numero = 3 AND tablero_tipo = 'C' AND estado = 'finalizado';

-- SECCION 8: Balance de tesoreria
SELECT clave, valor_decimal AS saldo_usdt
FROM sistema_config
WHERE clave = 'tesoreria_balance';

-- SECCION 9: Ultimos 20 movimientos de tesoreria
SELECT id, tipo, monto, motivo, fecha
FROM tesoreria_movimientos
ORDER BY id DESC
LIMIT 20;

-- SECCION 10: Ultimos 15 eventos de auditoria
SELECT
    al.id,
    al.usuario_id,
    u.nickname,
    al.fase_numero,
    al.accion,
    al.detalles,
    al.fecha
FROM auditoria_logs al
LEFT JOIN usuarios u ON al.usuario_id = u.id
ORDER BY al.id DESC
LIMIT 15;

-- SECCION 11: Usuarios con Tablero C de Fase 3 en progreso
SELECT
    u.id,
    u.nickname,
    tp.ciclo,
    tp.estado,
    tp.fecha_inicio,
    (
        SELECT COUNT(*)
        FROM referidos r
        WHERE r.id_padre    = u.id
          AND r.fase_numero = 3
          AND r.ciclo       = tp.ciclo
    ) AS refs_en_red
FROM tableros_progreso tp
JOIN usuarios u ON tp.usuario_id = u.id
WHERE tp.fase_numero  = 3
  AND tp.tablero_tipo = 'C'
  AND tp.estado       = 'en_progreso'
ORDER BY tp.fecha_inicio;

-- FIN DEL DIAGNOSTICO
