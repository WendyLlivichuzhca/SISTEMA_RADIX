-- ============================================================
-- FIX COMPLETO: Colocaciones incorrectas en ciclo=2
-- Usuarios 1190, 1191, 1193 fueron insertados en referidos
-- con ciclo=2 cuando deberían estar en ciclo=1.
-- Causa: bug en registro.php (ya corregido).
-- ============================================================
-- EJECUTAR EN ORDEN. Leer resultado de cada paso antes de
-- continuar con el siguiente.
-- ============================================================


-- ============================================================
-- PASO 1: VERIFICACIÓN — Ver las entradas incorrectas
-- (solo lectura, no modifica nada)
-- ============================================================
SELECT
    r.id,
    r.id_padre,
    u_padre.nickname AS padre_nickname,
    r.id_hijo,
    u_hijo.nickname AS hijo_nickname,
    r.ciclo AS ciclo_referidos,
    tp.ciclo AS ciclo_tablero,
    tp.tablero_tipo,
    tp.estado
FROM referidos r
JOIN usuarios u_padre ON u_padre.id = r.id_padre
JOIN usuarios u_hijo  ON u_hijo.id  = r.id_hijo
LEFT JOIN tableros_progreso tp
    ON tp.usuario_id = r.id_hijo
    AND tp.fase_numero = r.fase_numero
    AND tp.ciclo = r.ciclo
WHERE r.ciclo > 1
  AND r.fase_numero = 0
  AND (
      tp.id IS NULL
      OR tp.ciclo < r.ciclo
  );
-- Resultado esperado: 3 filas (IDs 159, 160, 162)


-- ============================================================
-- PASO 2: VERIFICAR posiciones disponibles en ciclo=1
-- para los padres correctos (1049 y 1050)
-- (solo lectura)
-- ============================================================
SELECT
    r.id_padre,
    u.nickname,
    COUNT(*) AS hijos_actuales,
    GROUP_CONCAT(r.posicion ORDER BY r.posicion) AS posiciones_ocupadas
FROM referidos r
JOIN usuarios u ON u.id = r.id_padre
WHERE r.id_padre IN (1049, 1050)
  AND r.fase_numero = 0
  AND r.ciclo = 1
GROUP BY r.id_padre, u.nickname;
-- Resultado esperado:
--   1049 (TRON_TVYr / Luis Covarrubias) → 2 hijos, posiciones 1,2  → libre posicion 3
--   1050 (TRON_TJm4 / Ana Sanchez)      → 0 hijos                  → libres posiciones 1,2


-- ============================================================
-- PASO 3: ELIMINAR las 3 entradas incorrectas en ciclo=2
-- ============================================================
DELETE FROM referidos WHERE id IN (159, 160, 162);
-- Verifica que se eliminaron exactamente 3 filas.


-- ============================================================
-- PASO 4: RE-INSERTAR en posiciones correctas de ciclo=1
-- ============================================================

-- Usuario 1190 (TRON_TRrW / Ronald Porras Villalobos)
-- → bajo 1049 (Luis Covarrubias), posicion 3, spillover nivel 3
INSERT INTO referidos (id_padre, id_hijo, fase_numero, posicion, nivel_en_red, ciclo, fecha_union)
VALUES (1049, 1190, 0, 3, 1, 1, NOW());

-- Usuario 1191 (TRON_TEvo / Maria Yolanda Mora)
-- → bajo 1050 (Ana Sanchez), posicion 1, spillover nivel 3
INSERT INTO referidos (id_padre, id_hijo, fase_numero, posicion, nivel_en_red, ciclo, fecha_union)
VALUES (1050, 1191, 0, 1, 1, 1, NOW());

-- Usuario 1193 (TRON_TUg5 / Margarita Martinez)
-- → bajo 1050 (Ana Sanchez), posicion 2, spillover nivel 3
-- (pago aún en estado=pendiente al momento de esta corrección)
INSERT INTO referidos (id_padre, id_hijo, fase_numero, posicion, nivel_en_red, ciclo, fecha_union)
VALUES (1050, 1193, 0, 2, 1, 1, NOW());


-- ============================================================
-- PASO 5: CORREGIR beneficiarios en tabla pagos
-- Los pagos 286 y 287 ya se transfirieron al beneficiario
-- incorrecto. Esto corrige el registro en BD para que quede
-- el historial correcto. El dinero ya fue transferido y debe
-- gestionarse manualmente con los beneficiarios.
-- Pago 289 (1193) está pendiente — se corrige preventivamente.
-- ============================================================
UPDATE pagos SET beneficiario_usuario_id = 1049 WHERE id = 286;
-- Pago de 1190 → debe ir a 1049 (Luis Covarrubias), no a 1001
UPDATE pagos SET beneficiario_usuario_id = 1050 WHERE id = 287;
-- Pago de 1191 → debe ir a 1050 (Ana Sanchez), no a 1002 (Wendy)
UPDATE pagos SET beneficiario_usuario_id = 1050 WHERE id = 289;
-- Pago de 1193 → debe ir a 1050 (Ana Sanchez) cuando confirme


-- ============================================================
-- PASO 6: VERIFICACIÓN FINAL — confirmar que todo quedó bien
-- ============================================================

-- 6a) Ver las nuevas entradas en ciclo=1
SELECT
    r.id,
    r.id_padre,
    u_padre.nickname AS padre,
    r.id_hijo,
    u_hijo.nickname AS hijo,
    r.posicion,
    r.ciclo,
    r.fecha_union
FROM referidos r
JOIN usuarios u_padre ON u_padre.id = r.id_padre
JOIN usuarios u_hijo  ON u_hijo.id  = r.id_hijo
WHERE r.id_hijo IN (1190, 1191, 1193)
  AND r.fase_numero = 0
ORDER BY r.id_hijo;
-- Resultado esperado:
--   1190 → padre 1049, posicion 3, ciclo 1
--   1191 → padre 1050, posicion 1, ciclo 1
--   1193 → padre 1050, posicion 2, ciclo 1

-- 6b) Confirmar que NO quedan entradas con ciclo=2 inválidas
SELECT r.id, r.id_hijo, r.ciclo
FROM referidos r
LEFT JOIN tableros_progreso tp
    ON tp.usuario_id = r.id_hijo
    AND tp.fase_numero = r.fase_numero
    AND tp.ciclo = r.ciclo
WHERE r.id_hijo IN (1190, 1191, 1193)
  AND (tp.id IS NULL OR tp.ciclo < r.ciclo);
-- Resultado esperado: 0 filas (tabla vacía)

-- 6c) Ver estado final de tableros de los 3 usuarios
SELECT tp.usuario_id, u.nickname, tp.tablero_tipo, tp.ciclo, tp.estado
FROM tableros_progreso tp
JOIN usuarios u ON u.id = tp.usuario_id
WHERE tp.usuario_id IN (1190, 1191, 1193)
ORDER BY tp.usuario_id;
-- 1190 y 1191: deben tener tablero A, ciclo=1, estado=activo
-- 1193: aún no tiene tablero (pago pendiente) → resultado vacío para 1193 es normal

-- ============================================================
-- NOTAS FINALES:
-- * Después de ejecutar este script, subir registro.php corregido
--   al hosting (corporativoqbank.com) para que el bug no se repita.
-- * El dinero de pagos 286 ($10) y 287 ($10) ya fue enviado a
--   1001 y 1002 respectivamente. Coordinar manualmente la
--   transferencia correcta a 1049 (Luis Covarrubias) y 1050
--   (Ana Sanchez).
-- * Cuando el pago 289 (1193) sea confirmado, verificar_pago.php
--   creará automáticamente el tablero en ciclo=1 correctamente.
-- ============================================================
