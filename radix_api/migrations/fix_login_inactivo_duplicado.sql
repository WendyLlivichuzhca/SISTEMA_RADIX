-- ============================================================
-- FIX: Usuarios bloqueados por cuenta inactivo duplicada
-- Fecha: 2026-04-06
-- Problema: Ronald (ronaldpv66@gmail.com) y Yolanda (yolandamoraj@gmail.com)
--   fueron reemplazados por clones RADIX el 2026-04-05, sus cuentas
--   originales quedaron tipo_usuario='inactivo'.
--   Se registraron de nuevo con el mismo correo (IDs 1190 y 1191),
--   pero el login encontraba primero las cuentas viejas (IDs 1150 y 1151).
--
-- SOLUCION SQL INMEDIATA: limpiar correo/telefono de las cuentas inactivas.
-- SOLUCION CODIGO: user_login.php ya fue corregido para excluir inactivos.
-- ============================================================

-- Verificar estado ANTES de aplicar el fix
SELECT id, nickname, correo_electronico, telefono, tipo_usuario, estado, fecha_registro
FROM usuarios
WHERE id IN (1150, 1151, 1190, 1191)
ORDER BY id;

-- ============================================================
-- APLICAR FIX: Limpiar email y telefono de cuentas inactivas
-- para que no haya colision con las cuentas nuevas activas.
-- ============================================================

UPDATE usuarios
SET
    correo_electronico = NULL,
    telefono           = NULL
WHERE id IN (1150, 1151)
  AND tipo_usuario = 'inactivo';

-- Verificar resultado DESPUES del fix
SELECT id, nickname, correo_electronico, telefono, tipo_usuario, estado
FROM usuarios
WHERE id IN (1150, 1151, 1190, 1191)
ORDER BY id;

-- Confirmar que los usuarios activos son accesibles por correo
SELECT id, nickname, correo_electronico, tipo_usuario
FROM usuarios
WHERE correo_electronico IN ('ronaldpv66@gmail.com', 'yolandamoraj@gmail.com')
ORDER BY id;

-- El resultado debe mostrar SOLO las cuentas activas (IDs 1190 y 1191)
