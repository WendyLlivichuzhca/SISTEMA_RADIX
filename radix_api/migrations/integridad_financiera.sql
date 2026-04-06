-- ============================================================
-- RADIX — DIAGNÓSTICO DE INTEGRIDAD FINANCIERA
-- Solo lectura. Seguro ejecutar en producción en cualquier momento.
-- Ejecutar en phpMyAdmin sobre la base corporat_RADIX.
-- Cualquier resultado NO VACÍO en las secciones de ALERTA
-- indica un problema que debes revisar antes de seguir operando.
-- ============================================================

-- ============================================================
-- SECCIÓN 1: PAGOS DUPLICADOS (mismo pago_id procesado 2+ veces)
-- ALERTA si retorna filas.
-- ============================================================
SELECT
    'ALERTA: Pago duplicado' AS tipo_alerta,
    id_emisor,
    id_receptor,
    fase_numero,
    tablero_tipo,
    ciclo,
    tipo,
    COUNT(*) AS veces,
    SUM(monto) AS total_duplicado
FROM pagos
WHERE estado = 'completado'
  AND tipo IN ('ganancia_tablero', 'reentrada', 'tesoreria_clon',
               'salto_fase_1', 'salto_fase_2', 'salto_fase_3', 'utilidad_master')
GROUP BY id_emisor, id_receptor, fase_numero, tablero_tipo, ciclo, tipo
HAVING COUNT(*) > 1
ORDER BY veces DESC;

-- ============================================================
-- SECCIÓN 2: TX_HASH USADOS MÁS DE UNA VEZ
-- ALERTA si retorna filas (mismo hash en 2 pagos = alguien reutilizó una tx).
-- ============================================================
SELECT
    'ALERTA: tx_hash duplicado' AS tipo_alerta,
    tx_hash,
    COUNT(*) AS veces_usado,
    GROUP_CONCAT(id ORDER BY id) AS pagos_ids
FROM pagos
WHERE tx_hash IS NOT NULL
  AND tx_hash != ''
GROUP BY tx_hash
HAVING COUNT(*) > 1;

SELECT
    'ALERTA: tx_hash_2 duplicado' AS tipo_alerta,
    tx_hash_2,
    COUNT(*) AS veces_usado,
    GROUP_CONCAT(id ORDER BY id) AS pagos_ids
FROM pagos
WHERE tx_hash_2 IS NOT NULL
  AND tx_hash_2 != ''
GROUP BY tx_hash_2
HAVING COUNT(*) > 1;

-- ============================================================
-- SECCIÓN 3: TABLEROS COMPLETADOS SIN SU PAGO DE GANANCIA
-- ALERTA si retorna filas (tablero cerrado pero sin ganancia registrada).
-- ============================================================
SELECT
    'ALERTA: Tablero cerrado sin ganancia' AS tipo_alerta,
    tp.usuario_id,
    u.nickname,
    tp.fase_numero,
    tp.tablero_tipo,
    tp.ciclo,
    tp.fecha_fin
FROM tableros_progreso tp
JOIN usuarios u ON tp.usuario_id = u.id
WHERE tp.estado = 'completado'
  AND u.tipo_usuario = 'real'
  AND NOT EXISTS (
      SELECT 1 FROM pagos p
      WHERE p.beneficiario_usuario_id = tp.usuario_id
        AND p.fase_numero = tp.fase_numero
        AND p.tablero_tipo = tp.tablero_tipo
        AND p.ciclo = tp.ciclo
        AND p.tipo = 'ganancia_tablero'
        AND p.estado = 'completado'
  )
ORDER BY tp.fase_numero, tp.usuario_id;

-- ============================================================
-- SECCIÓN 4: TABLEROS C COMPLETADOS SIN SEMILLA REGISTRADA
-- ALERTA si retorna filas (cierre de Fase sin semilla = la siguiente fase
-- nunca se abrió ni se registró el aporte a tesorería).
-- ============================================================
SELECT
    'ALERTA: Cierre C sin semilla' AS tipo_alerta,
    tp.usuario_id,
    u.nickname,
    tp.fase_numero,
    tp.ciclo,
    tp.fecha_fin
FROM tableros_progreso tp
JOIN usuarios u ON tp.usuario_id = u.id
WHERE tp.tablero_tipo = 'C'
  AND tp.estado = 'completado'
  AND u.tipo_usuario = 'real'
  AND NOT EXISTS (
      SELECT 1 FROM pagos p
      WHERE p.id_emisor = tp.usuario_id
        AND p.fase_numero = tp.fase_numero
        AND p.ciclo = tp.ciclo
        AND p.tipo IN ('salto_fase_1', 'salto_fase_2', 'salto_fase_3', 'utilidad_master')
        AND p.estado = 'completado'
  )
ORDER BY tp.fase_numero, tp.usuario_id;

-- ============================================================
-- SECCIÓN 5: BALANCE DE TESORERÍA VS SUMA DE MOVIMIENTOS
-- ALERTA si la diferencia entre ingresos y egresos no coincide con
-- el balance registrado en sistema_config (indica escritura corrupta).
-- ============================================================
SELECT
    'VERIFICACIÓN: Balance tesorería' AS tipo_alerta,
    (SELECT valor_decimal FROM sistema_config WHERE clave = 'tesoreria_balance') AS balance_sistema_config,
    (SELECT COALESCE(SUM(CASE WHEN tipo = 'ingreso' THEN monto ELSE -monto END), 0)
     FROM tesoreria_movimientos) AS balance_calculado_movimientos,
    (SELECT valor_decimal FROM sistema_config WHERE clave = 'tesoreria_balance') -
    (SELECT COALESCE(SUM(CASE WHEN tipo = 'ingreso' THEN monto ELSE -monto END), 0)
     FROM tesoreria_movimientos) AS diferencia;

-- Si 'diferencia' es distinta de 0.00, hay una discrepancia en tesorería.

-- ============================================================
-- SECCIÓN 6: TESORERÍA NEGATIVA
-- ALERTA si retorna valor negativo.
-- ============================================================
SELECT
    'ALERTA: Tesorería negativa' AS tipo_alerta,
    valor_decimal AS balance_actual
FROM sistema_config
WHERE clave = 'tesoreria_balance'
  AND valor_decimal < 0;

-- ============================================================
-- SECCIÓN 7: USUARIOS CON CRÉDITO NEGATIVO
-- ALERTA si retorna filas (no debería ocurrir nunca).
-- ============================================================
SELECT
    'ALERTA: Crédito negativo' AS tipo_alerta,
    id,
    nickname,
    credito_saldo
FROM usuarios
WHERE credito_saldo < 0
  AND tipo_usuario = 'real';

-- ============================================================
-- SECCIÓN 8: USUARIOS INACTIVOS CON CORREO COMPARTIDO
-- ALERTA si retorna filas (el login podría confundirse entre cuentas).
-- ============================================================
SELECT
    'ALERTA: Email compartido entre inactivo y real' AS tipo_alerta,
    u_inactivo.id AS id_inactivo,
    u_real.id AS id_real,
    u_inactivo.correo_electronico AS email_compartido,
    u_inactivo.nickname AS nickname_inactivo,
    u_real.nickname AS nickname_real
FROM usuarios u_inactivo
JOIN usuarios u_real
    ON u_inactivo.correo_electronico = u_real.correo_electronico
   AND u_inactivo.correo_electronico IS NOT NULL
   AND u_inactivo.id != u_real.id
WHERE u_inactivo.tipo_usuario = 'inactivo'
  AND u_real.tipo_usuario IN ('real', 'master');

-- ============================================================
-- SECCIÓN 9: RESUMEN DE PAGOS PENDIENTES VIEJOS (> 48 horas)
-- INFORMATIVO: pagos que llevan más de 2 días sin confirmarse.
-- ============================================================
SELECT
    'INFO: Pago pendiente viejo' AS tipo_alerta,
    p.id,
    u.nickname AS emisor,
    p.fase_numero,
    p.tablero_tipo,
    p.monto,
    p.fecha_pago,
    TIMESTAMPDIFF(HOUR, p.fecha_pago, NOW()) AS horas_pendiente
FROM pagos p
JOIN usuarios u ON p.id_emisor = u.id
WHERE p.estado = 'pendiente'
  AND p.fecha_pago < NOW() - INTERVAL 48 HOUR
ORDER BY p.fecha_pago ASC
LIMIT 30;

-- ============================================================
-- SECCIÓN 10: RESUMEN GENERAL DE SALUD DEL SISTEMA
-- ============================================================
SELECT
    'RESUMEN' AS tipo,
    (SELECT COUNT(*) FROM usuarios WHERE tipo_usuario = 'real') AS usuarios_reales,
    (SELECT COUNT(*) FROM usuarios WHERE tipo_usuario = 'clon') AS clones_activos,
    (SELECT COUNT(*) FROM tableros_progreso WHERE estado = 'en_progreso') AS tableros_activos,
    (SELECT COUNT(*) FROM tableros_progreso WHERE estado = 'completado') AS tableros_completados,
    (SELECT COUNT(*) FROM pagos WHERE estado = 'pendiente') AS pagos_pendientes,
    (SELECT COUNT(*) FROM pagos WHERE estado = 'completado') AS pagos_completados,
    (SELECT COALESCE(SUM(monto), 0) FROM pagos WHERE estado = 'completado' AND tipo = 'ganancia_tablero' AND propietario_flujo = 'usuario') AS total_ganado_usuarios,
    (SELECT valor_decimal FROM sistema_config WHERE clave = 'tesoreria_balance') AS balance_tesoreria;
